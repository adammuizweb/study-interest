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
    $statement = $pdo->prepare('SELECT configuration_json,configuration_hash,status FROM study_interest_test_versions WHERE id=? LIMIT 1');
    $statement->execute([(int)$versionId]);
    $version = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($version) || (string)$version['status'] !== 'draft') throw new DomainException('Only a draft assessment can be edited.');
    $configuration = json_decode((string)$version['configuration_json'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($configuration) || !hash_equals((string)$version['configuration_hash'], hash('sha256', study_interest_configuration_json($configuration)))) throw new RuntimeException('Assessment configuration integrity check failed.');
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 255);
    $description = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 2000);
    if ($title === '') throw new DomainException('Assessment title is required.');
    $configuration['title'] = $title;
    $configuration['description'] = $description;

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
    $directLimit = filter_var($thresholds['direct_recommendation_limit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 8]]);
    $pathwayMinimum = $thresholds['professional_pathway_minimum'] ?? null;
    if (!is_numeric($directMinimum) || (float)$directMinimum < 0 || (float)$directMinimum > 100 || $directLimit === false || !is_numeric($pathwayMinimum) || (float)$pathwayMinimum < 0 || (float)$pathwayMinimum > 100) throw new DomainException('Recommendation thresholds are invalid.');
    $configuration['thresholds']['direct_recommendation_minimum'] = round((float)$directMinimum, 2);
    $configuration['thresholds']['direct_recommendation_limit'] = (int)$directLimit;
    $configuration['thresholds']['professional_pathway_minimum'] = round((float)$pathwayMinimum, 2);
    study_interest_replace_draft_configuration($pdo, (int)$versionId, $configuration, $studyInterestUserId, 'configuration.updated', ['version_code' => (string)$configuration['version']], $expectedHash);
    $pdo->commit();
    study_interest_admin_redirect('success', __('Assessment configuration saved.'), $returnUrl);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[study-interest] assessment configuration save failed: ' . $error->getMessage());
    study_interest_admin_redirect('error', $error instanceof DomainException ? $error->getMessage() : __('The assessment configuration could not be saved.'), $returnUrl);
}
