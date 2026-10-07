<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.config.manage', false);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { adiwira_render_404(); return; }
$versionId = filter_var($_POST['version_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$expectedHash = trim((string)($_POST['expected_hash'] ?? ''));
$returnUrl = study_interest_admin_url('assessments/edit', ['version_id' => (int)$versionId]);
if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) study_interest_admin_redirect('error', __('The security token expired. Please try again.'), $returnUrl);

try {
    if ($versionId === false || $versionId === null || preg_match('/\A[a-f0-9]{64}\z/', $expectedHash) !== 1) throw new DomainException('Assessment draft is invalid.');
    $pdo->beginTransaction();
    if (!authorization_lock_actor_permissions($pdo, $studyInterestUserId) || !user_can($pdo, $studyInterestUserId, 'plugin.study-interest.config.manage')) throw new RuntimeException('Configuration permission changed.');
    $identity = $pdo->prepare('SELECT test_id FROM study_interest_test_versions WHERE id=? LIMIT 1');
    $identity->execute([(int)$versionId]);
    $testId = (int)$identity->fetchColumn();
    if ($testId < 1) throw new DomainException('Assessment draft was not found.');
    $testStatement = $pdo->prepare('SELECT title,description FROM study_interest_tests WHERE id=? LIMIT 1 FOR UPDATE');
    $testStatement->execute([$testId]);
    $test = $testStatement->fetch(PDO::FETCH_ASSOC);
    $statement = $pdo->prepare('SELECT configuration_json,configuration_hash,status FROM study_interest_test_versions WHERE id=? AND test_id=? LIMIT 1 FOR UPDATE');
    $statement->execute([(int)$versionId, $testId]);
    $version = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($version) || (string)$version['status'] !== 'draft') throw new DomainException('Only a draft assessment can be edited.');
    $configuration = json_decode((string)$version['configuration_json'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($configuration) || !hash_equals((string)$version['configuration_hash'], hash('sha256', study_interest_configuration_json($configuration)))) throw new RuntimeException('Assessment configuration integrity check failed.');
    $generic = (int)($configuration['schema_version'] ?? 1) >= 2;
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 255);
    $description = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 2000);
    if ($title === '') throw new DomainException('Assessment title is required.');
    $configuration['title'] = $title;
    $configuration['description'] = $description;
    if ($generic) {
        $locale = trim((string)($_POST['locale'] ?? ''));
        if (preg_match('/\A[a-z]{2}(?:-[A-Z]{2})?\z/', $locale) !== 1) throw new DomainException('Public locale is invalid.');
        $configuration['locale'] = $locale;
    }

    $resultTextLimits = $generic ? study_interest_generic_result_text_keys() : [
        'score_name' => 255,
        'no_dominant_title' => 255,
        'no_dominant_body' => 2000,
        'multidisciplinary' => 2000,
        'biomedical_biotechnology_overlap' => 2000,
        'midwifery_overlap' => 2000,
        'professional_pathway' => 255,
        'professional_pathway_body' => 2000,
        'disclaimer' => 2000,
    ];
    $postedResultText = is_array($_POST['result_text'] ?? null) ? $_POST['result_text'] : [];
    $resultText = [];
    foreach ($resultTextLimits as $key => $maximum) {
        $rawValue = $postedResultText[$key] ?? null;
        if (!is_string($rawValue)) throw new DomainException('Every result message must be plain text.');
        $value = trim($rawValue);
        if ($value === '' || mb_strlen($value) > $maximum || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) throw new DomainException('Every result message must contain valid bounded text.');
        $resultText[$key] = $value;
    }
    $configuration['result_text'] = $resultText;

    if ($generic) {
        $postedPublicCopy = is_array($_POST['public_copy'] ?? null) ? $_POST['public_copy'] : [];
        $publicCopy = [];
        foreach (['landing_kicker', 'question_count_suffix', 'duration_prefix', 'duration_suffix', 'feature_one_title', 'feature_one_body', 'feature_two_title', 'feature_two_body', 'feature_three_title', 'feature_three_body', 'progress_label', 'back_label', 'next_label', 'complete_label', 'ready_label', 'saving_label', 'saved_label', 'save_error', 'preparing_label', 'start_label'] as $key) {
            $value = trim((string)($postedPublicCopy[$key] ?? ''));
            if ($value === '' || mb_strlen($value) > 1000) throw new DomainException("Public interface copy {$key} is invalid.");
            $publicCopy[$key] = $value;
        }
        $configuration['public_copy'] = $publicCopy;
        $postedIntake = is_array($_POST['intake'] ?? null) ? $_POST['intake'] : [];
        $intake = [];
        foreach (['heading', 'introduction', 'privacy_body', 'assessment_consent_label', 'contact_heading', 'contact_introduction', 'contact_consent_label'] as $key) {
            $value = trim((string)($postedIntake[$key] ?? ''));
            if ($value === '' || mb_strlen($value) > 2000) throw new DomainException("Participant intake copy {$key} is invalid.");
            $intake[$key] = $value;
        }
        $privacyUrl = trim((string)($postedIntake['privacy_url'] ?? ''));
        if ($privacyUrl !== '' && (filter_var($privacyUrl, FILTER_VALIDATE_URL) === false || !str_starts_with($privacyUrl, 'https://'))) throw new DomainException('Privacy policy URL must be an HTTPS URL.');
        $intake['privacy_url'] = $privacyUrl;
        $postedFields = is_array($_POST['intake_fields'] ?? null) ? $_POST['intake_fields'] : [];
        $intake['fields'] = [];
        foreach (study_interest_intake_field_defaults() as $key => $default) {
            $input = is_array($postedFields[$key] ?? null) ? $postedFields[$key] : [];
            $field = ['enabled' => isset($input['enabled']), 'required' => isset($input['required']), 'label' => mb_substr(trim((string)($input['label'] ?? '')), 0, 255), 'placeholder' => mb_substr(trim((string)($input['placeholder'] ?? '')), 0, 500), 'help' => mb_substr(trim((string)($input['help'] ?? '')), 0, 500)];
            if ($field['label'] === '' || ($field['required'] && !$field['enabled'])) throw new DomainException("Participant field {$key} is invalid.");
            $intake['fields'][$key] = $field;
        }
        $configuration['intake'] = $intake;
    }

    $postedSections = is_array($_POST['sections'] ?? null) ? $_POST['sections'] : [];
    foreach ($configuration['sections'] as $code => &$section) {
        $input = is_array($postedSections[$code] ?? null) ? $postedSections[$code] : [];
        $label = mb_substr(trim((string)($input['label'] ?? '')), 0, 255);
        $weight = $input['weight'] ?? null;
        if ($label === '' || !is_numeric($weight) || !is_finite((float)$weight) || (float)$weight < 0 || (float)$weight > 1) throw new DomainException("Section {$code} is invalid.");
        $section['label'] = $label;
        $section['weight'] = round((float)$weight, 5);
    }
    unset($section);

    $postedDimensions = is_array($_POST['dimensions'] ?? null) ? $_POST['dimensions'] : [];
    foreach ($configuration['dimensions'] as $code => &$dimension) {
        $input = is_array($postedDimensions[$code] ?? null) ? $postedDimensions[$code] : [];
        $label = mb_substr(trim((string)($input['label'] ?? '')), 0, 255);
        if ($label === '') throw new DomainException("Dimension {$code} requires a label.");
        $dimension['label'] = $label;
        $dimension['description'] = mb_substr(trim((string)($input['description'] ?? '')), 0, 1000);
    }
    unset($dimension);

    $postedPrograms = is_array($_POST['programs'] ?? null) ? $_POST['programs'] : [];
    foreach ($configuration['programs'] as $code => &$program) {
        $input = is_array($postedPrograms[$code] ?? null) ? $postedPrograms[$code] : [];
        $label = mb_substr(trim((string)($input['label'] ?? '')), 0, 255);
        $type = trim((string)($input['recommendation_type'] ?? ''));
        if ($label === '' || !in_array($type, ['DIRECT_ENTRY', 'PROFESSIONAL_PATHWAY'], true)) throw new DomainException("Program {$code} is invalid.");
        $program['label'] = $label;
        $program['recommendation_type'] = $type;
        $weights = [];
        $rawWeights = is_array($input['weights'] ?? null) ? $input['weights'] : [];
        foreach ($configuration['dimensions'] as $dimensionCode => $_dimension) {
            $weight = $rawWeights[$dimensionCode] ?? null;
            if (!is_numeric($weight) || !is_finite((float)$weight) || (float)$weight < 0 || (float)$weight > 1) throw new DomainException("Program {$code} has an invalid {$dimensionCode} weight.");
            $weights[$dimensionCode] = round((float)$weight, 5);
        }
        $program['weights'] = $weights;
    }
    unset($program);

    $thresholds = is_array($_POST['thresholds'] ?? null) ? $_POST['thresholds'] : [];
    $directMinimum = $thresholds['direct_recommendation_minimum'] ?? null;
    $directLimit = filter_var($thresholds['direct_recommendation_limit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $generic ? 50 : 8]]);
    $pathwayMinimum = $thresholds['professional_pathway_minimum'] ?? null;
    if (!is_numeric($directMinimum) || (float)$directMinimum < 0 || (float)$directMinimum > 100 || $directLimit === false || !is_numeric($pathwayMinimum) || (float)$pathwayMinimum < 0 || (float)$pathwayMinimum > 100) throw new DomainException('Recommendation thresholds are invalid.');
    $configuration['thresholds']['direct_recommendation_minimum'] = round((float)$directMinimum, 2);
    $configuration['thresholds']['direct_recommendation_limit'] = (int)$directLimit;
    $configuration['thresholds']['professional_pathway_minimum'] = round((float)$pathwayMinimum, 2);
    if ($generic) {
        $postedClarity = is_array($_POST['profile_clarity'] ?? null) ? $_POST['profile_clarity'] : [];
        $clarity = [];
        foreach (['practically_equal_below', 'multidisciplinary_below', 'fairly_clear_minimum', 'very_clear_minimum'] as $key) {
            $value = $postedClarity[$key] ?? null;
            if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || (float)$value > 100) throw new DomainException('Profile clarity thresholds are invalid.');
            $clarity[$key] = round((float)$value, 2);
        }
        $configuration['thresholds']['profile_clarity'] = $clarity;
        $classifications = [];
        foreach (is_array($_POST['classifications'] ?? null) ? $_POST['classifications'] : [] as $range) {
            if (!is_array($range) || isset($range['delete'])) continue;
            $label = mb_substr(trim((string)($range['label'] ?? '')), 0, 255);
            $minimum = $range['minimum'] ?? null;
            $maximum = $range['maximum'] ?? null;
            if ($label === '' && ($minimum === null || $minimum === '') && ($maximum === null || $maximum === '')) continue;
            if ($label === '' || !is_numeric($minimum) || !is_numeric($maximum) || !is_finite((float)$minimum) || !is_finite((float)$maximum) || (float)$minimum < 0 || (float)$maximum > 100 || (float)$minimum > (float)$maximum) throw new DomainException('A score classification is invalid.');
            $classifications[] = ['minimum' => round((float)$minimum, 2), 'maximum' => round((float)$maximum, 2), 'label' => $label];
        }
        usort($classifications, static fn(array $left, array $right): int => $right['minimum'] <=> $left['minimum']);
        $configuration['thresholds']['classifications'] = $classifications;
        $interpretationRules = [];
        foreach (is_array($_POST['interpretation_rules'] ?? null) ? $_POST['interpretation_rules'] : [] as $rule) {
            if (!is_array($rule) || isset($rule['delete'])) continue;
            $code = trim((string)($rule['code'] ?? ''));
            $message = mb_substr(trim((string)($rule['message'] ?? '')), 0, 2000);
            $directions = array_values(array_unique(array_filter(array_map('trim', explode(',', (string)($rule['directions'] ?? ''))), static fn(string $value): bool => $value !== '')));
            if ($code === '' && $message === '' && $directions === []) continue;
            if (preg_match('/\A[a-z0-9][a-z0-9_-]{0,79}\z/i', $code) !== 1 || count($directions) < 2 || $message === '') throw new DomainException('An interpretation rule is invalid.');
            $interpretationRules[] = ['code' => $code, 'when_all_directions' => $directions, 'message' => $message];
        }
        $configuration['interpretation_rules'] = $interpretationRules;
    }
    study_interest_replace_draft_configuration($pdo, (int)$versionId, $configuration, $studyInterestUserId, 'configuration.updated', ['version_code' => (string)$configuration['version']], $expectedHash);
    $beforeTest = ['title' => (string)($test['title'] ?? ''), 'description' => (string)($test['description'] ?? '')];
    $afterTest = ['title' => $title, 'description' => $description];
    if ($beforeTest !== $afterTest) {
        $pdo->prepare('UPDATE study_interest_tests SET title=?,description=?,updated_at_utc=? WHERE id=?')
            ->execute([$title, $description !== '' ? $description : null, study_interest_now_utc(), $testId]);
        study_interest_audit($pdo, $studyInterestUserId, 'test.updated', 'test', (string)$testId, $beforeTest, $afterTest);
    }
    $pdo->commit();
    study_interest_admin_redirect('success', __('Assessment configuration saved.'), $returnUrl);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[study-interest] assessment configuration save failed: ' . $error->getMessage());
    study_interest_admin_redirect('error', $error instanceof DomainException ? $error->getMessage() : __('The assessment configuration could not be saved.'), $returnUrl);
}
