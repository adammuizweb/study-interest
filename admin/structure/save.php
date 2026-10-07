<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.config.manage', false);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { adiwira_render_404(); return; }
$versionId = filter_var($_POST['version_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$expectedHash = trim((string)($_POST['expected_hash'] ?? ''));
$returnUrl = study_interest_admin_url('structure', ['version_id' => (int)$versionId]);
if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) study_interest_admin_redirect('error', __('The security token expired. Please try again.'), $returnUrl);

try {
    if ($versionId === false || $versionId === null || preg_match('/\A[a-f0-9]{64}\z/', $expectedHash) !== 1) throw new DomainException('Assessment draft is invalid.');
    $operation = (string)($_POST['operation'] ?? '');
    $code = trim((string)($_POST['code'] ?? ''));
    $pdo->beginTransaction();
    if (!authorization_lock_actor_permissions($pdo, $studyInterestUserId) || !user_can($pdo, $studyInterestUserId, 'plugin.study-interest.config.manage')) throw new RuntimeException('Configuration permission changed.');
    $identity = $pdo->prepare('SELECT test_id FROM study_interest_test_versions WHERE id=? LIMIT 1');
    $identity->execute([(int)$versionId]);
    $testId = (int)$identity->fetchColumn();
    if ($testId < 1) throw new DomainException('Assessment draft was not found.');
    $test = $pdo->prepare('SELECT id FROM study_interest_tests WHERE id=? LIMIT 1 FOR UPDATE');
    $test->execute([$testId]);
    if ((int)$test->fetchColumn() !== $testId) throw new DomainException('Assessment was not found.');
    $statement = $pdo->prepare('SELECT configuration_json,configuration_hash,status FROM study_interest_test_versions WHERE id=? AND test_id=? LIMIT 1 FOR UPDATE');
    $statement->execute([(int)$versionId, $testId]);
    $version = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($version) || (string)$version['status'] !== 'draft') throw new DomainException('Only a draft structure can be changed.');
    if (!hash_equals((string)$version['configuration_hash'], $expectedHash)) throw new DomainException('This draft changed while the form was open. Review it and try again.');
    $configuration = json_decode((string)$version['configuration_json'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($configuration) || (int)($configuration['schema_version'] ?? 1) < 2) throw new DomainException('Create a generic assessment to use the structure builder.');

    if ($operation === 'section_save') {
        $code = strtoupper($code);
        $label = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 255);
        $weight = $_POST['weight'] ?? null;
        $order = filter_var($_POST['display_order'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999]]);
        if (preg_match('/\A[A-Z0-9][A-Z0-9_-]{0,19}\z/', $code) !== 1 || $label === '' || !is_numeric($weight) || (float)$weight < 0 || (float)$weight > 1 || $order === false) throw new DomainException('Section values are invalid.');
        $existing = is_array($configuration['sections'][$code] ?? null) ? $configuration['sections'][$code] : [];
        $configuration['sections'][$code] = $existing + ['question_count' => 0];
        $configuration['sections'][$code]['label'] = $label;
        $configuration['sections'][$code]['weight'] = round((float)$weight, 5);
        $configuration['sections'][$code]['display_order'] = (int)$order;
    } elseif ($operation === 'section_delete') {
        if (!isset($configuration['sections'][$code])) throw new DomainException('Section was not found.');
        foreach ($configuration['questions'] ?? [] as $question) if ((string)($question['section'] ?? '') === $code) throw new DomainException('Move or delete this section’s questions first.');
        unset($configuration['sections'][$code]);
    } elseif ($operation === 'dimension_save') {
        $code = strtoupper($code);
        $label = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 255);
        $description = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 1000);
        $order = filter_var($_POST['display_order'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999]]);
        if (preg_match('/\A[A-Z][A-Z0-9_]{0,19}\z/', $code) !== 1 || $label === '' || $order === false) throw new DomainException('Dimension values are invalid.');
        $configuration['dimensions'][$code] = ['label' => $label, 'description' => $description, 'display_order' => (int)$order, 'is_active' => true];
        foreach ($configuration['programs'] ?? [] as &$program) if (!array_key_exists($code, $program['weights'] ?? [])) $program['weights'][$code] = 0.0;
        unset($program);
    } elseif ($operation === 'dimension_delete') {
        if (!isset($configuration['dimensions'][$code])) throw new DomainException('Dimension was not found.');
        foreach ($configuration['questions'] ?? [] as $question) foreach ($question['options'] ?? [] as $option) if (array_key_exists($code, $option['scores'] ?? [])) throw new DomainException('Remove this dimension from question scoring first.');
        foreach ($configuration['programs'] ?? [] as $program) if (abs((float)($program['weights'][$code] ?? 0)) > 0.00001) throw new DomainException('Set this dimension’s direction weights to zero first.');
        unset($configuration['dimensions'][$code]);
        foreach ($configuration['programs'] ?? [] as &$program) unset($program['weights'][$code]);
        unset($program);
    } elseif ($operation === 'program_save') {
        $code = strtolower($code);
        $label = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 255);
        $type = (string)($_POST['recommendation_type'] ?? '');
        if (preg_match('/\A[a-z0-9][a-z0-9_-]{0,39}\z/', $code) !== 1 || $label === '' || !in_array($type, ['DIRECT_ENTRY', 'PROFESSIONAL_PATHWAY'], true)) throw new DomainException('Study direction values are invalid.');
        $weights = [];
        $postedWeights = is_array($_POST['weights'] ?? null) ? $_POST['weights'] : [];
        foreach ($configuration['dimensions'] ?? [] as $dimensionCode => $_dimension) {
            $weight = $postedWeights[$dimensionCode] ?? null;
            if (!is_numeric($weight) || (float)$weight < 0 || (float)$weight > 1) throw new DomainException('Every dimension weight must be between zero and one.');
            $weights[$dimensionCode] = round((float)$weight, 5);
        }
        $existing = is_array($configuration['programs'][$code] ?? null) ? $configuration['programs'][$code] : [];
        $configuration['programs'][$code] = $existing + ['display_order' => count($configuration['programs'] ?? []) + 1];
        $configuration['programs'][$code]['label'] = $label;
        $configuration['programs'][$code]['recommendation_type'] = $type;
        $configuration['programs'][$code]['is_active'] = true;
        $configuration['programs'][$code]['weights'] = $weights;
    } elseif ($operation === 'program_delete') {
        if (!isset($configuration['programs'][$code])) throw new DomainException('Study direction was not found.');
        foreach ($configuration['interpretation_rules'] ?? [] as $rule) if (in_array($code, $rule['when_all_directions'] ?? [], true)) throw new DomainException('Remove interpretation rules that reference this direction first.');
        unset($configuration['programs'][$code]);
    } else {
        throw new DomainException('Structure operation is invalid.');
    }

    study_interest_replace_draft_configuration($pdo, (int)$versionId, $configuration, $studyInterestUserId, 'structure.updated', ['operation' => $operation, 'code' => $code], $expectedHash);
    $pdo->commit();
    study_interest_admin_redirect('success', __('Assessment structure saved.'), $returnUrl);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[study-interest] structure save failed: ' . $error->getMessage());
    study_interest_admin_redirect('error', $error instanceof DomainException ? $error->getMessage() : __('The assessment structure could not be saved.'), $returnUrl);
}
