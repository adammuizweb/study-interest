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

function study_interest_supported_algorithm_versions(): array
{
    return ['baseline-1.0'];
}

function study_interest_configuration_errors(array $configuration): array
{
    $errors = [];
    foreach (['code', 'version', 'algorithm_version'] as $identityKey) {
        $identity = (string)($configuration[$identityKey] ?? '');
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,79}\z/i', $identity) !== 1) $errors[] = "Configuration {$identityKey} is invalid.";
    }
    if (!in_array((string)($configuration['algorithm_version'] ?? ''), study_interest_supported_algorithm_versions(), true)) $errors[] = 'The scoring algorithm version is not supported.';
    $title = trim((string)($configuration['title'] ?? ''));
    $description = trim((string)($configuration['description'] ?? ''));
    if ($title === '' || mb_strlen($title) > 255) $errors[] = 'Configuration title is required and must not exceed 255 characters.';
    if (mb_strlen($description) > 2000) $errors[] = 'Configuration description must not exceed 2000 characters.';
    $resultText = is_array($configuration['result_text'] ?? null) ? $configuration['result_text'] : [];
    foreach (['score_name' => 255, 'no_dominant_title' => 255, 'no_dominant_body' => 2000, 'multidisciplinary' => 2000, 'biomedical_biotechnology_overlap' => 2000, 'midwifery_overlap' => 2000, 'professional_pathway' => 255, 'professional_pathway_body' => 2000, 'disclaimer' => 2000] as $key => $maximum) {
        $value = $resultText[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $maximum || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) $errors[] = "Result message {$key} is invalid.";
    }
    $dimensions = is_array($configuration['dimensions'] ?? null) ? $configuration['dimensions'] : [];
    $sections = is_array($configuration['sections'] ?? null) ? $configuration['sections'] : [];
    $questions = is_array($configuration['questions'] ?? null) ? $configuration['questions'] : [];
    $programs = is_array($configuration['programs'] ?? null) ? $configuration['programs'] : [];
    if (count($dimensions) !== 8) $errors[] = 'The baseline must define exactly eight dimensions.';
    foreach ($dimensions as $code => $dimension) {
        if (preg_match('/\A[A-Z][A-Z0-9_]{0,19}\z/', (string)$code) !== 1) $errors[] = 'Dimension codes are invalid.';
        if (isset($dimension['is_active']) && empty($dimension['is_active'])) $errors[] = "Dimension {$code} cannot be inactive in this algorithm version.";
    }
    if (count($sections) < 1) $errors[] = 'At least one section is required.';
    foreach (array_keys($sections) as $code) if (preg_match('/\A[A-Z0-9][A-Z0-9_-]{0,19}\z/i', (string)$code) !== 1) $errors[] = 'Section codes are invalid.';
    $sectionDisplayOrders = array_map(static fn(array $section): int => (int)($section['display_order'] ?? 0), $sections);
    if (count(array_unique($sectionDisplayOrders)) !== count($sectionDisplayOrders)) $errors[] = 'Section display orders must be unique.';
    foreach ($sections as $code => $section) {
        $weight = $section['weight'] ?? null;
        if (!is_numeric($weight) || !is_finite((float)$weight) || (float)$weight < 0) $errors[] = "Section {$code} weight is invalid.";
    }
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
        if (trim((string)($question['prompt'] ?? '')) === '') $errors[] = "Question {$code} requires a prompt.";
        if (!in_array((string)($question['type'] ?? ''), ['likert', 'single_choice'], true)) $errors[] = "Question {$code} type is invalid.";
        if ($section === 'A' && !isset($dimensions[(string)($question['dimension'] ?? '')])) $errors[] = "Question {$code} requires a valid primary dimension.";
        if (isset($question['required']) && empty($question['required'])) $errors[] = "Question {$code} cannot be optional in this algorithm version.";
        $order = $index + 1;
        if (isset($orders[$section][$order])) $errors[] = "Question display order is duplicated in section {$section}.";
        $orders[$section][$order] = true;
        $options = is_array($question['options'] ?? null) ? $question['options'] : [];
        if (count($options) < 2) $errors[] = "Question {$code} must have at least two options.";
        if ($section === 'A' && (string)($question['type'] ?? '') !== 'likert') $errors[] = "Section A question {$code} must use the Likert type.";
        $optionCodes = [];
        foreach ($options as $option) {
            $optionCode = is_array($option) ? trim((string)($option['code'] ?? '')) : '';
            if (preg_match('/\A[A-Z0-9][A-Z0-9_-]{0,39}\z/i', $optionCode) !== 1 || isset($optionCodes[$optionCode])) $errors[] = "Question {$code} option codes must be valid and unique.";
            $optionCodes[$optionCode] = true;
            if (!is_array($option) || trim((string)($option['label'] ?? '')) === '') $errors[] = "Question {$code} has an option without a label.";
            $scores = is_array($option['scores'] ?? null) ? $option['scores'] : [];
            if ($scores === []) $errors[] = "Question {$code} has an option without scoring.";
            foreach ($scores as $dimension => $score) {
                if (!isset($dimensions[$dimension]) || !is_numeric($score) || !is_finite((float)$score)) $errors[] = "Question {$code} has an invalid dimension score.";
            }
        }
        if ($section === 'A') {
            $likertCodes = array_map('strval', array_keys($optionCodes));
            sort($likertCodes, SORT_STRING);
            if ($likertCodes !== ['1', '2', '3', '4', '5']) $errors[] = "Section A question {$code} must use the complete 1-5 Likert scale.";
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
        if (trim((string)($program['label'] ?? '')) === '') $errors[] = "Program {$code} requires a label.";
        if (!in_array((string)($program['recommendation_type'] ?? ''), ['DIRECT_ENTRY', 'PROFESSIONAL_PATHWAY'], true)) $errors[] = "Program {$code} recommendation type is invalid.";
        if (isset($program['is_active']) && empty($program['is_active'])) $errors[] = "Program {$code} cannot be inactive in this algorithm version.";
        $weights = is_array($program['weights'] ?? null) ? $program['weights'] : [];
        if (array_diff(array_keys($dimensions), array_keys($weights)) !== [] || array_diff(array_keys($weights), array_keys($dimensions)) !== []) {
            $errors[] = "Program {$code} must define every dimension weight.";
        }
        foreach ($weights as $weight) if (!is_numeric($weight) || !is_finite((float)$weight) || (float)$weight < 0) $errors[] = "Program {$code} has an invalid dimension weight.";
        if (abs(array_sum(array_map('floatval', $weights)) - 1.0) > 0.00001) $errors[] = "Program {$code} weights must total 100%.";
        if (($program['recommendation_type'] ?? '') === 'DIRECT_ENTRY') $hasDirect = true;
    }
    if (!$hasDirect) $errors[] = 'At least one direct-entry program is required.';
    return array_values(array_unique($errors));
}

function study_interest_configuration_with_question_counts(array $configuration): array
{
    if (is_array($configuration['sections'] ?? null)) {
        uasort($configuration['sections'], static fn(array $left, array $right): int => (int)($left['display_order'] ?? 0) <=> (int)($right['display_order'] ?? 0));
    }
    $counts = array_fill_keys(array_keys(is_array($configuration['sections'] ?? null) ? $configuration['sections'] : []), 0);
    foreach ($configuration['questions'] ?? [] as $question) {
        $section = is_array($question) ? (string)($question['section'] ?? '') : '';
        if (array_key_exists($section, $counts)) $counts[$section]++;
    }
    foreach ($counts as $section => $count) $configuration['sections'][$section]['question_count'] = $count;
    $sectionOrder = [];
    foreach ($configuration['sections'] as $code => $section) $sectionOrder[(string)$code] = (int)($section['display_order'] ?? 0);
    $configuration['questions'] = array_values(is_array($configuration['questions'] ?? null) ? $configuration['questions'] : []);
    usort($configuration['questions'], static fn(array $left, array $right): int => ($sectionOrder[(string)($left['section'] ?? '')] ?? PHP_INT_MAX) <=> ($sectionOrder[(string)($right['section'] ?? '')] ?? PHP_INT_MAX));
    return $configuration;
}

function study_interest_configuration_normalized_projection(array $configuration): array
{
    $projection = ['dimensions' => [], 'sections' => [], 'questions' => [], 'programs' => []];
    foreach ($configuration['dimensions'] ?? [] as $code => $dimension) {
        $projection['dimensions'][] = ['code' => (string)$code, 'label' => (string)($dimension['label'] ?? ''), 'description' => (string)($dimension['description'] ?? ''), 'is_active' => !isset($dimension['is_active']) || !empty($dimension['is_active'])];
    }
    foreach ($configuration['sections'] ?? [] as $code => $section) {
        $projection['sections'][] = ['code' => (string)$code, 'label' => (string)($section['label'] ?? ''), 'weight' => round((float)($section['weight'] ?? 0), 5), 'display_order' => (int)($section['display_order'] ?? 0)];
    }
    foreach ($configuration['questions'] ?? [] as $question) {
        $item = ['code' => (string)($question['code'] ?? ''), 'section' => (string)($question['section'] ?? ''), 'title' => (string)($question['title'] ?? ''), 'prompt' => (string)($question['prompt'] ?? ''), 'type' => (string)($question['type'] ?? ''), 'is_reverse' => !empty($question['is_reverse']), 'required' => !isset($question['required']) || !empty($question['required']), 'options' => []];
        foreach ($question['options'] ?? [] as $option) {
            $scores = [];
            foreach ($option['scores'] ?? [] as $dimension => $score) $scores[(string)$dimension] = round((float)$score, 4);
            ksort($scores);
            $item['options'][] = ['code' => (string)($option['code'] ?? ''), 'label' => (string)($option['label'] ?? ''), 'scores' => $scores];
        }
        $projection['questions'][] = $item;
    }
    foreach ($configuration['programs'] ?? [] as $code => $program) {
        $weights = [];
        foreach ($program['weights'] ?? [] as $dimension => $weight) $weights[(string)$dimension] = round((float)$weight, 5);
        ksort($weights);
        $projection['programs'][] = ['code' => (string)$code, 'label' => (string)($program['label'] ?? ''), 'recommendation_type' => (string)($program['recommendation_type'] ?? ''), 'is_active' => !isset($program['is_active']) || !empty($program['is_active']), 'weights' => $weights];
    }
    return $projection;
}

function study_interest_database_normalized_projection(PDO $pdo, int $versionId): array
{
    $projection = ['dimensions' => [], 'sections' => [], 'questions' => [], 'programs' => []];
    $dimensions = $pdo->prepare('SELECT id,code,label,description,is_active FROM study_interest_dimensions WHERE version_id=? ORDER BY id');
    $dimensions->execute([$versionId]);
    $dimensionCodes = [];
    foreach ($dimensions->fetchAll(PDO::FETCH_ASSOC) as $dimension) {
        $dimensionCodes[(int)$dimension['id']] = (string)$dimension['code'];
        $projection['dimensions'][] = ['code' => (string)$dimension['code'], 'label' => (string)$dimension['label'], 'description' => (string)($dimension['description'] ?? ''), 'is_active' => (int)$dimension['is_active'] === 1];
    }
    $sections = $pdo->prepare('SELECT id,code,label,weight,display_order FROM study_interest_sections WHERE version_id=? ORDER BY display_order,id');
    $sections->execute([$versionId]);
    foreach ($sections->fetchAll(PDO::FETCH_ASSOC) as $section) $projection['sections'][] = ['code' => (string)$section['code'], 'label' => (string)$section['label'], 'weight' => round((float)$section['weight'], 5), 'display_order' => (int)$section['display_order']];
    $questions = $pdo->prepare('SELECT q.id,q.question_code,q.title,q.prompt,q.question_type,q.is_reverse,q.required,s.code AS section_code FROM study_interest_questions q JOIN study_interest_sections s ON s.id=q.section_id WHERE q.version_id=? ORDER BY s.display_order,q.display_order,q.id');
    $questions->execute([$versionId]);
    $optionStatement = $pdo->prepare('SELECT id,option_code,label FROM study_interest_options WHERE question_id=? ORDER BY display_order,id');
    $scoreStatement = $pdo->prepare('SELECT dimension_id,score FROM study_interest_option_scores WHERE option_id=? ORDER BY dimension_id');
    foreach ($questions->fetchAll(PDO::FETCH_ASSOC) as $question) {
        $item = ['code' => (string)$question['question_code'], 'section' => (string)$question['section_code'], 'title' => (string)($question['title'] ?? ''), 'prompt' => (string)$question['prompt'], 'type' => (string)$question['question_type'], 'is_reverse' => (int)$question['is_reverse'] === 1, 'required' => (int)$question['required'] === 1, 'options' => []];
        $optionStatement->execute([(int)$question['id']]);
        foreach ($optionStatement->fetchAll(PDO::FETCH_ASSOC) as $option) {
            $scores = [];
            $scoreStatement->execute([(int)$option['id']]);
            foreach ($scoreStatement->fetchAll(PDO::FETCH_ASSOC) as $score) $scores[$dimensionCodes[(int)$score['dimension_id']]] = round((float)$score['score'], 4);
            ksort($scores);
            $item['options'][] = ['code' => (string)$option['option_code'], 'label' => (string)$option['label'], 'scores' => $scores];
        }
        $projection['questions'][] = $item;
    }
    $programs = $pdo->prepare('SELECT id,code,label,recommendation_type,is_active FROM study_interest_programs WHERE version_id=? ORDER BY id');
    $programs->execute([$versionId]);
    $weightStatement = $pdo->prepare('SELECT dimension_id,weight FROM study_interest_program_weights WHERE program_id=? ORDER BY dimension_id');
    foreach ($programs->fetchAll(PDO::FETCH_ASSOC) as $program) {
        $weights = [];
        $weightStatement->execute([(int)$program['id']]);
        foreach ($weightStatement->fetchAll(PDO::FETCH_ASSOC) as $weight) $weights[$dimensionCodes[(int)$weight['dimension_id']]] = round((float)$weight['weight'], 5);
        ksort($weights);
        $projection['programs'][] = ['code' => (string)$program['code'], 'label' => (string)$program['label'], 'recommendation_type' => (string)$program['recommendation_type'], 'is_active' => (int)$program['is_active'] === 1, 'weights' => $weights];
    }
    return $projection;
}

function study_interest_insert_normalized_configuration(PDO $pdo, int $versionId, array $configuration): void
{
    $dimensionIds = [];
    $insertDimension = $pdo->prepare('INSERT INTO study_interest_dimensions (version_id,code,label,description,is_active) VALUES (?,?,?,?,?)');
    foreach ($configuration['dimensions'] as $code => $dimension) {
        $insertDimension->execute([$versionId, $code, (string)$dimension['label'], (string)($dimension['description'] ?? '') ?: null, !isset($dimension['is_active']) || !empty($dimension['is_active']) ? 1 : 0]);
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
            (string)$question['prompt'], (string)$question['type'], !empty($question['is_reverse']) ? 1 : 0, !isset($question['required']) || !empty($question['required']) ? 1 : 0, $sectionOrder[$sectionCode]]);
        $questionId = (int)$pdo->lastInsertId();
        foreach (array_values($question['options']) as $optionIndex => $option) {
            $insertOption->execute([$questionId, (string)$option['code'], (string)$option['label'], $optionIndex + 1]);
            $optionId = (int)$pdo->lastInsertId();
            foreach ($option['scores'] as $dimension => $score) $insertScore->execute([$optionId, $dimensionIds[$dimension], (float)$score]);
        }
    }
    $insertProgram = $pdo->prepare('INSERT INTO study_interest_programs (version_id,code,label,recommendation_type,is_active) VALUES (?,?,?,?,?)');
    $insertWeight = $pdo->prepare('INSERT INTO study_interest_program_weights (program_id,dimension_id,weight) VALUES (?,?,?)');
    foreach ($configuration['programs'] as $code => $program) {
        $insertProgram->execute([$versionId, $code, (string)$program['label'], (string)$program['recommendation_type'], !isset($program['is_active']) || !empty($program['is_active']) ? 1 : 0]);
        $programId = (int)$pdo->lastInsertId();
        foreach ($program['weights'] as $dimension => $weight) $insertWeight->execute([$programId, $dimensionIds[$dimension], (float)$weight]);
    }
}

function study_interest_replace_draft_configuration(PDO $pdo, int $versionId, array $configuration, int $actorId, string $action, array $details = [], ?string $expectedHash = null): void
{
    $configuration = study_interest_configuration_with_question_counts($configuration);
    $errors = study_interest_configuration_errors($configuration);
    if ($errors !== []) throw new DomainException(implode(' ', $errors));

    $identity = $pdo->prepare('SELECT test_id FROM study_interest_test_versions WHERE id=? LIMIT 1');
    $identity->execute([$versionId]);
    $testId = (int)$identity->fetchColumn();
    if ($testId < 1) throw new DomainException('Assessment version was not found.');
    $test = $pdo->prepare('SELECT code FROM study_interest_tests WHERE id=? LIMIT 1 FOR UPDATE');
    $test->execute([$testId]);
    $testCode = $test->fetchColumn();
    if (!is_string($testCode) || $testCode === '') throw new DomainException('Assessment was not found.');
    $version = $pdo->prepare('SELECT * FROM study_interest_test_versions WHERE id=? AND test_id=? LIMIT 1 FOR UPDATE');
    $version->execute([$versionId, $testId]);
    $row = $version->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) throw new DomainException('Assessment version was not found.');
    if ((string)$row['status'] !== 'draft') throw new DomainException('Published assessment versions cannot be changed. Create a new draft first.');
    if ($expectedHash !== null && !hash_equals((string)$row['configuration_hash'], $expectedHash)) throw new DomainException('This draft changed while the editor was open. Review the latest version and try again.');
    if ((string)($configuration['version'] ?? '') !== (string)$row['version_code'] || (string)($configuration['code'] ?? '') !== $testCode) {
        throw new DomainException('Configuration identity cannot be changed from the question editor.');
    }
    $sessions = $pdo->prepare('SELECT id FROM study_interest_sessions WHERE version_id=? LIMIT 1 FOR UPDATE');
    $sessions->execute([$versionId]);
    if ($sessions->fetchColumn() !== false) throw new DomainException('A draft with participant sessions cannot be changed.');

    $beforeHash = (string)$row['configuration_hash'];
    $json = study_interest_configuration_json($configuration);
    $afterHash = hash('sha256', $json);
    $pdo->prepare('DELETE FROM study_interest_programs WHERE version_id=?')->execute([$versionId]);
    $pdo->prepare('DELETE FROM study_interest_questions WHERE version_id=?')->execute([$versionId]);
    $pdo->prepare('DELETE FROM study_interest_sections WHERE version_id=?')->execute([$versionId]);
    $pdo->prepare('DELETE FROM study_interest_dimensions WHERE version_id=?')->execute([$versionId]);
    study_interest_insert_normalized_configuration($pdo, $versionId, $configuration);
    $now = study_interest_now_utc();
    $pdo->prepare('UPDATE study_interest_test_versions SET algorithm_version=?,expected_question_count=?,configuration_hash=?,configuration_json=?,updated_at_utc=? WHERE id=?')
        ->execute([(string)$configuration['algorithm_version'], count($configuration['questions']), $afterHash, $json, $now, $versionId]);
    study_interest_audit($pdo, $actorId, $action, 'test_version', (string)$versionId,
        ['configuration_hash' => $beforeHash], ['configuration_hash' => $afterHash] + $details);
}

function study_interest_import_configuration(PDO $pdo, array $configuration, int $actorId): int
{
    $configuration = study_interest_configuration_with_question_counts($configuration);
    $errors = study_interest_configuration_errors($configuration);
    if ($errors !== []) throw new DomainException(implode(' ', $errors));
    $versionCode = trim((string)($configuration['version'] ?? ''));
    $algorithmVersion = trim((string)($configuration['algorithm_version'] ?? ''));
    $testCode = trim((string)($configuration['code'] ?? ''));
    if ($versionCode === '' || $algorithmVersion === '' || $testCode === '') throw new DomainException('Configuration identity is incomplete.');

    $now = study_interest_now_utc();
    $pdo->prepare('INSERT INTO study_interest_tests (code,title,description,created_at_utc,updated_at_utc) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE code=VALUES(code)')
        ->execute([$testCode, (string)($configuration['title'] ?? $testCode), (string)($configuration['description'] ?? '') ?: null, $now, $now]);
    $test = $pdo->prepare('SELECT id FROM study_interest_tests WHERE code=? LIMIT 1 FOR UPDATE');
    $test->execute([$testCode]);
    $testId = (int)$test->fetchColumn();
    if ($testId < 1) throw new RuntimeException('Assessment identity could not be created.');

    $json = study_interest_configuration_json($configuration);
    $configurationHash = hash('sha256', $json);
    $existing = $pdo->prepare('SELECT id,test_id,configuration_hash FROM study_interest_test_versions WHERE version_code=? LIMIT 1 FOR UPDATE');
    $existing->execute([$versionCode]);
    $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
    if (is_array($existingRow)) {
        if ((int)$existingRow['test_id'] !== $testId || !hash_equals((string)$existingRow['configuration_hash'], $configurationHash)) throw new DomainException('The version code already belongs to a different configuration.');
        return (int)$existingRow['id'];
    }
    $expected = count($configuration['questions']);
    $pdo->prepare('INSERT INTO study_interest_test_versions (test_id,version_code,status,algorithm_version,expected_question_count,configuration_hash,configuration_json,created_by,created_at_utc,updated_at_utc) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$testId, $versionCode, 'draft', $algorithmVersion, $expected, $configurationHash, $json, $actorId ?: null, $now, $now]);
    $versionId = (int)$pdo->lastInsertId();

    study_interest_insert_normalized_configuration($pdo, $versionId, $configuration);
    study_interest_audit($pdo, $actorId, 'configuration.imported', 'test_version', (string)$versionId, null, ['version_code' => $versionCode, 'configuration_hash' => $configurationHash]);
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
    if (study_interest_configuration_normalized_projection($configuration) !== study_interest_database_normalized_projection($pdo, $versionId)) {
        $errors[] = 'Normalized assessment content does not match the immutable configuration snapshot.';
    }
    $unscored = $pdo->prepare('SELECT COUNT(*) FROM study_interest_options o JOIN study_interest_questions q ON q.id=o.question_id LEFT JOIN study_interest_option_scores s ON s.option_id=o.id WHERE q.version_id=? GROUP BY o.id HAVING COUNT(s.id)=0');
    $unscored->execute([$versionId]);
    if ($unscored->fetchColumn() !== false) $errors[] = 'Every option must have hidden scoring.';
    return array_values(array_unique($errors));
}

function study_interest_publish_version(PDO $pdo, int $versionId, int $actorId): void
{
    $publicationLock = $pdo->prepare('SELECT value FROM settings WHERE `key`=? LIMIT 1 FOR UPDATE');
    $publicationLock->execute(['study_interest_publication_lock']);
    if ($publicationLock->fetchColumn() === false) throw new RuntimeException('Assessment publication lock is unavailable. Reactivate the plugin to run migrations.');
    $identity = $pdo->prepare('SELECT test_id FROM study_interest_test_versions WHERE id=? LIMIT 1');
    $identity->execute([$versionId]);
    $testId = (int)$identity->fetchColumn();
    if ($testId < 1) throw new DomainException('Assessment version was not found.');
    $test = $pdo->prepare('SELECT id FROM study_interest_tests WHERE id=? LIMIT 1 FOR UPDATE');
    $test->execute([$testId]);
    if ((int)$test->fetchColumn() !== $testId) throw new DomainException('Assessment was not found.');
    $version = $pdo->prepare('SELECT * FROM study_interest_test_versions WHERE id=? AND test_id=? LIMIT 1 FOR UPDATE');
    $version->execute([$versionId, $testId]);
    $row = $version->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) throw new DomainException('Assessment version was not found.');
    $errors = study_interest_publish_errors($pdo, $versionId);
    if ($errors !== []) throw new DomainException(implode(' ', $errors));
    $now = study_interest_now_utc();
    $pdo->prepare("UPDATE study_interest_test_versions SET status='retired',retired_at_utc=?,updated_at_utc=? WHERE status='published' AND id<>?")
        ->execute([$now, $now, $versionId]);
    $pdo->prepare("UPDATE study_interest_test_versions SET status='published',published_at_utc=?,retired_at_utc=NULL,updated_at_utc=? WHERE id=? AND status='draft'")
        ->execute([$now, $now, $versionId]);
    study_interest_audit($pdo, $actorId, 'configuration.published', 'test_version', (string)$versionId,
        ['status' => 'draft'], ['status' => 'published', 'configuration_hash' => (string)$row['configuration_hash']]);
}
