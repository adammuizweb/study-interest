<?php
declare(strict_types=1);

function study_interest_baseline_configuration(): array
{
    $configuration = require __DIR__ . '/../config/baseline.php';
    if (!is_array($configuration)) throw new RuntimeException('The packaged assessment configuration is invalid.');
    return $configuration;
}

function study_interest_configuration_json(array $configuration): string
{
    return json_encode($configuration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

function study_interest_configuration_errors(array $configuration): array
{
    $errors = [];
    foreach (['code', 'version', 'algorithm_version'] as $identityKey) {
        $identity = (string)($configuration[$identityKey] ?? '');
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,79}\z/i', $identity) !== 1) $errors[] = "Configuration {$identityKey} is invalid.";
    }
    $dimensions = is_array($configuration['dimensions'] ?? null) ? $configuration['dimensions'] : [];
    $sections = is_array($configuration['sections'] ?? null) ? $configuration['sections'] : [];
    $questions = is_array($configuration['questions'] ?? null) ? $configuration['questions'] : [];
    $programs = is_array($configuration['programs'] ?? null) ? $configuration['programs'] : [];
    if (count($dimensions) !== 8) $errors[] = 'The baseline must define exactly eight dimensions.';
    foreach (array_keys($dimensions) as $code) if (preg_match('/\A[A-Z][A-Z0-9_]{0,19}\z/', (string)$code) !== 1) $errors[] = 'Dimension codes are invalid.';
    if (count($sections) < 1) $errors[] = 'At least one section is required.';
    foreach (array_keys($sections) as $code) if (preg_match('/\A[A-Z0-9][A-Z0-9_-]{0,19}\z/i', (string)$code) !== 1) $errors[] = 'Section codes are invalid.';
    $sectionWeight = array_sum(array_map(static fn(array $section): float => (float)($section['weight'] ?? 0), $sections));
    if (abs($sectionWeight - 1.0) > 0.00001) $errors[] = 'Section weights must total 100%.';

    $questionCodes = [];
    $orders = [];
    $sectionCounts = array_fill_keys(array_keys($sections), 0);
    foreach ($questions as $index => $question) {
        if (!is_array($question)) { $errors[] = 'Every question must be an object.'; continue; }
        $code = trim((string)($question['code'] ?? ''));
        $section = (string)($question['section'] ?? '');
        if (preg_match('/\A[A-Z0-9][A-Z0-9_-]{0,39}\z/i', $code) !== 1 || isset($questionCodes[$code])) $errors[] = 'Question codes must be valid and unique.';
        $questionCodes[$code] = true;
        if (!isset($sections[$section])) $errors[] = "Question {$code} references an unknown section.";
        else $sectionCounts[$section]++;
        $order = $index + 1;
        if (isset($orders[$section][$order])) $errors[] = "Question display order is duplicated in section {$section}.";
        $orders[$section][$order] = true;
        $options = is_array($question['options'] ?? null) ? $question['options'] : [];
        if (count($options) < 2) $errors[] = "Question {$code} must have at least two options.";
        $optionCodes = [];
        foreach ($options as $option) {
            $optionCode = is_array($option) ? trim((string)($option['code'] ?? '')) : '';
            if (preg_match('/\A[A-Z0-9][A-Z0-9_-]{0,39}\z/i', $optionCode) !== 1 || isset($optionCodes[$optionCode])) $errors[] = "Question {$code} option codes must be valid and unique.";
            $optionCodes[$optionCode] = true;
            $scores = is_array($option['scores'] ?? null) ? $option['scores'] : [];
            if ($scores === []) $errors[] = "Question {$code} has an option without scoring.";
            foreach ($scores as $dimension => $score) {
                if (!isset($dimensions[$dimension]) || !is_numeric($score)) $errors[] = "Question {$code} has an invalid dimension score.";
            }
        }
    }
    foreach ($sections as $code => $section) {
        $expected = (int)($section['question_count'] ?? -1);
        if (($sectionCounts[$code] ?? 0) !== $expected) $errors[] = "Section {$code} must contain {$expected} questions.";
    }
    $expectedQuestions = array_sum(array_map(static fn(array $section): int => (int)($section['question_count'] ?? 0), $sections));
    if ($expectedQuestions < 1 || count($questions) < 1) $errors[] = 'At least one question is required.';
    if (count($questions) !== $expectedQuestions) $errors[] = "The assessment must contain {$expectedQuestions} questions.";

    $hasDirect = false;
    foreach ($programs as $code => $program) {
        if (preg_match('/\A[a-z0-9][a-z0-9_-]{0,39}\z/i', (string)$code) !== 1) $errors[] = 'Program codes are invalid.';
        if (!is_array($program)) { $errors[] = "Program {$code} is invalid."; continue; }
        $weights = is_array($program['weights'] ?? null) ? $program['weights'] : [];
        if (array_diff(array_keys($dimensions), array_keys($weights)) !== [] || array_diff(array_keys($weights), array_keys($dimensions)) !== []) {
            $errors[] = "Program {$code} must define every dimension weight.";
        }
        if (abs(array_sum(array_map('floatval', $weights)) - 1.0) > 0.00001) $errors[] = "Program {$code} weights must total 100%.";
        if (($program['recommendation_type'] ?? '') === 'DIRECT_ENTRY') $hasDirect = true;
    }
    if (!$hasDirect) $errors[] = 'At least one direct-entry program is required.';
    return array_values(array_unique($errors));
}

function study_interest_import_configuration(PDO $pdo, array $configuration, int $actorId): int
{
    $errors = study_interest_configuration_errors($configuration);
    if ($errors !== []) throw new DomainException(implode(' ', $errors));
    $versionCode = trim((string)($configuration['version'] ?? ''));
    $algorithmVersion = trim((string)($configuration['algorithm_version'] ?? ''));
    $testCode = trim((string)($configuration['code'] ?? ''));
    if ($versionCode === '' || $algorithmVersion === '' || $testCode === '') throw new DomainException('Configuration identity is incomplete.');

    $existing = $pdo->prepare('SELECT id FROM study_interest_test_versions WHERE version_code=? LIMIT 1 FOR UPDATE');
    $existing->execute([$versionCode]);
    $existingId = (int)$existing->fetchColumn();
    if ($existingId > 0) return $existingId;

    $now = study_interest_now_utc();
    $pdo->prepare('INSERT INTO study_interest_tests (code,title,description,created_at_utc,updated_at_utc) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE code=VALUES(code)')
        ->execute([$testCode, (string)($configuration['title'] ?? $testCode), (string)($configuration['description'] ?? '') ?: null, $now, $now]);
    $test = $pdo->prepare('SELECT id FROM study_interest_tests WHERE code=? LIMIT 1 FOR UPDATE');
    $test->execute([$testCode]);
    $testId = (int)$test->fetchColumn();
    if ($testId < 1) throw new RuntimeException('Assessment identity could not be created.');

    $json = study_interest_configuration_json($configuration);
    $expected = count($configuration['questions']);
    $pdo->prepare('INSERT INTO study_interest_test_versions (test_id,version_code,status,algorithm_version,expected_question_count,configuration_hash,configuration_json,created_by,created_at_utc,updated_at_utc) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$testId, $versionCode, 'draft', $algorithmVersion, $expected, hash('sha256', $json), $json, $actorId ?: null, $now, $now]);
    $versionId = (int)$pdo->lastInsertId();

    $dimensionIds = [];
    $insertDimension = $pdo->prepare('INSERT INTO study_interest_dimensions (version_id,code,label,description) VALUES (?,?,?,?)');
    foreach ($configuration['dimensions'] as $code => $dimension) {
        $insertDimension->execute([$versionId, $code, (string)$dimension['label'], (string)($dimension['description'] ?? '') ?: null]);
        $dimensionIds[$code] = (int)$pdo->lastInsertId();
    }
    $sectionIds = [];
    $insertSection = $pdo->prepare('INSERT INTO study_interest_sections (version_id,code,label,weight,display_order) VALUES (?,?,?,?,?)');
    foreach ($configuration['sections'] as $code => $section) {
        $insertSection->execute([$versionId, $code, (string)$section['label'], (float)$section['weight'], (int)$section['display_order']]);
        $sectionIds[$code] = (int)$pdo->lastInsertId();
    }
    $insertQuestion = $pdo->prepare('INSERT INTO study_interest_questions (version_id,section_id,question_code,title,prompt,question_type,is_reverse,required,display_order) VALUES (?,?,?,?,?,?,?,?,?)');
    $insertOption = $pdo->prepare('INSERT INTO study_interest_options (question_id,option_code,label,display_order) VALUES (?,?,?,?)');
    $insertScore = $pdo->prepare('INSERT INTO study_interest_option_scores (option_id,dimension_id,score) VALUES (?,?,?)');
    $sectionOrder = [];
    foreach ($configuration['questions'] as $question) {
        $sectionCode = (string)$question['section'];
        $sectionOrder[$sectionCode] = ($sectionOrder[$sectionCode] ?? 0) + 1;
        $insertQuestion->execute([$versionId, $sectionIds[$sectionCode], (string)$question['code'], (string)($question['title'] ?? '') ?: null,
            (string)$question['prompt'], (string)$question['type'], !empty($question['is_reverse']) ? 1 : 0, 1, $sectionOrder[$sectionCode]]);
        $questionId = (int)$pdo->lastInsertId();
        foreach (array_values($question['options']) as $optionIndex => $option) {
            $insertOption->execute([$questionId, (string)$option['code'], (string)$option['label'], $optionIndex + 1]);
            $optionId = (int)$pdo->lastInsertId();
            foreach ($option['scores'] as $dimension => $score) $insertScore->execute([$optionId, $dimensionIds[$dimension], (float)$score]);
        }
    }
    $insertProgram = $pdo->prepare('INSERT INTO study_interest_programs (version_id,code,label,recommendation_type) VALUES (?,?,?,?)');
    $insertWeight = $pdo->prepare('INSERT INTO study_interest_program_weights (program_id,dimension_id,weight) VALUES (?,?,?)');
    foreach ($configuration['programs'] as $code => $program) {
        $insertProgram->execute([$versionId, $code, (string)$program['label'], (string)$program['recommendation_type']]);
        $programId = (int)$pdo->lastInsertId();
        foreach ($program['weights'] as $dimension => $weight) $insertWeight->execute([$programId, $dimensionIds[$dimension], (float)$weight]);
    }
    study_interest_audit($pdo, $actorId, 'configuration.imported', 'test_version', (string)$versionId, null, ['version_code' => $versionCode, 'configuration_hash' => hash('sha256', $json)]);
    return $versionId;
}

function study_interest_publish_errors(PDO $pdo, int $versionId): array
{
    $statement = $pdo->prepare('SELECT * FROM study_interest_test_versions WHERE id=? LIMIT 1');
    $statement->execute([$versionId]);
    $version = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($version)) return ['Assessment version was not found.'];
    if ((string)$version['status'] !== 'draft') return ['Only draft versions can be published.'];
    try { $configuration = json_decode((string)$version['configuration_json'], true, 512, JSON_THROW_ON_ERROR); }
    catch (Throwable) { return ['Stored configuration is invalid.']; }
    $errors = study_interest_configuration_errors(is_array($configuration) ? $configuration : []);
    if (!hash_equals((string)$version['configuration_hash'], hash('sha256', study_interest_configuration_json($configuration)))) $errors[] = 'Stored configuration hash does not match.';
    $counts = [
        'questions' => ['study_interest_questions', (int)$version['expected_question_count']],
        'dimensions' => ['study_interest_dimensions', count($configuration['dimensions'] ?? [])],
        'sections' => ['study_interest_sections', count($configuration['sections'] ?? [])],
        'programs' => ['study_interest_programs', count($configuration['programs'] ?? [])],
    ];
    foreach ($counts as $label => [$table, $expected]) {
        $count = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE version_id=?");
        $count->execute([$versionId]);
        if ((int)$count->fetchColumn() !== $expected) $errors[] = "Normalized {$label} do not match the configuration snapshot.";
    }
    $unscored = $pdo->prepare('SELECT COUNT(*) FROM study_interest_options o JOIN study_interest_questions q ON q.id=o.question_id LEFT JOIN study_interest_option_scores s ON s.option_id=o.id WHERE q.version_id=? GROUP BY o.id HAVING COUNT(s.id)=0');
    $unscored->execute([$versionId]);
    if ($unscored->fetchColumn() !== false) $errors[] = 'Every option must have hidden scoring.';
    return array_values(array_unique($errors));
}

function study_interest_publish_version(PDO $pdo, int $versionId, int $actorId): void
{
    $version = $pdo->prepare('SELECT * FROM study_interest_test_versions WHERE id=? LIMIT 1 FOR UPDATE');
    $version->execute([$versionId]);
    $row = $version->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) throw new DomainException('Assessment version was not found.');
    $errors = study_interest_publish_errors($pdo, $versionId);
    if ($errors !== []) throw new DomainException(implode(' ', $errors));
    $now = study_interest_now_utc();
    $pdo->prepare("UPDATE study_interest_test_versions SET status='retired',retired_at_utc=?,updated_at_utc=? WHERE test_id=? AND status='published' AND id<>?")
        ->execute([$now, $now, (int)$row['test_id'], $versionId]);
    $pdo->prepare("UPDATE study_interest_test_versions SET status='published',published_at_utc=?,retired_at_utc=NULL,updated_at_utc=? WHERE id=? AND status='draft'")
        ->execute([$now, $now, $versionId]);
    study_interest_audit($pdo, $actorId, 'configuration.published', 'test_version', (string)$versionId,
        ['status' => 'draft'], ['status' => 'published', 'configuration_hash' => (string)$row['configuration_hash']]);
}
