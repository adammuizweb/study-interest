<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.config.manage', false);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { adiwira_render_404(); return; }
$versionId = filter_var($_POST['version_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$originalCode = trim((string)($_POST['original_code'] ?? ''));
$expectedHash = trim((string)($_POST['expected_hash'] ?? ''));
$returnUrl = study_interest_admin_url('questions/edit', ['version_id' => (int)$versionId] + ($originalCode !== '' ? ['code' => $originalCode] : []));
if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) study_interest_admin_redirect('error', __('The security token expired. Please try again.'), $returnUrl);

try {
    if ($versionId === false || $versionId === null) throw new DomainException('Assessment version is invalid.');
    if (preg_match('/\A[a-f0-9]{64}\z/', $expectedHash) !== 1) throw new DomainException('Draft revision is invalid.');
    $pdo->beginTransaction();
    if (!authorization_lock_actor_permissions($pdo, $studyInterestUserId) || !user_can($pdo, $studyInterestUserId, 'plugin.study-interest.config.manage')) throw new RuntimeException('Configuration permission changed.');
    $versionStatement = $pdo->prepare('SELECT configuration_json,configuration_hash,status FROM study_interest_test_versions WHERE id=? LIMIT 1');
    $versionStatement->execute([(int)$versionId]);
    $version = $versionStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($version) || (string)$version['status'] !== 'draft') throw new DomainException('Only draft questions can be changed.');
    $configuration = json_decode((string)$version['configuration_json'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($configuration)) throw new DomainException('Stored configuration is invalid.');
    if (!hash_equals((string)$version['configuration_hash'], hash('sha256', study_interest_configuration_json($configuration)))) throw new RuntimeException('Assessment configuration integrity check failed.');

    $code = strtoupper(trim((string)($_POST['question_code'] ?? '')));
    $section = trim((string)($_POST['section'] ?? ''));
    $type = trim((string)($_POST['question_type'] ?? ''));
    $dimension = trim((string)($_POST['dimension'] ?? ''));
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 255);
    $prompt = mb_substr(trim((string)($_POST['prompt'] ?? '')), 0, 4000);
    if (preg_match('/\A[A-Z0-9][A-Z0-9_-]{0,39}\z/', $code) !== 1) throw new DomainException('Question code is invalid.');
    if (!isset($configuration['sections'][$section])) throw new DomainException('Select a valid section.');
    if (!in_array($type, ['likert', 'single_choice'], true)) throw new DomainException('Select a valid question type.');
    if ($prompt === '') throw new DomainException('Question prompt is required.');
    if ($section === 'A' && !isset($configuration['dimensions'][$dimension])) throw new DomainException('Section A questions require a primary dimension.');
    if ($section === 'A' && $type !== 'likert') throw new DomainException('Section A questions must use the 1-5 Likert type.');
    if ($dimension !== '' && !isset($configuration['dimensions'][$dimension])) throw new DomainException('Primary dimension is invalid.');

    $existingQuestion = null;
    foreach ($configuration['questions'] ?? [] as $candidate) if ($originalCode !== '' && (string)($candidate['code'] ?? '') === $originalCode) { $existingQuestion = $candidate; break; }
    $existingOptions = [];
    $existingOptionList = array_values(is_array($existingQuestion['options'] ?? null) ? $existingQuestion['options'] : []);
    foreach ($existingOptionList as $existingOption) $existingOptions[(string)($existingOption['code'] ?? '')] = $existingOption;
    $options = [];
    foreach (array_slice(is_array($_POST['options'] ?? null) ? $_POST['options'] : [], 0, 100) as $optionIndex => $rawOption) {
        if (!is_array($rawOption)) continue;
        $optionCode = strtoupper(trim((string)($rawOption['code'] ?? '')));
        $label = mb_substr(trim((string)($rawOption['label'] ?? '')), 0, 500);
        if ($optionCode === '' && $label === '') continue;
        if (preg_match('/\A[A-Z0-9][A-Z0-9_-]{0,39}\z/', $optionCode) !== 1 || $label === '') throw new DomainException('Every answer option requires a valid code and label.');
        if (isset($options[$optionCode])) throw new DomainException('Answer option codes must be unique.');
        $existingOption = is_array($existingOptions[$optionCode] ?? null) ? $existingOptions[$optionCode] : (is_array($existingOptionList[$optionIndex] ?? null) ? $existingOptionList[$optionIndex] : []);
        $existingScores = is_array($existingOption['scores'] ?? null) ? $existingOption['scores'] : [];
        $scores = [];
        $rawScores = is_array($rawOption['scores'] ?? null) ? $rawOption['scores'] : [];
        foreach ($configuration['dimensions'] as $dimensionCode => $_meta) {
            $value = $rawScores[$dimensionCode] ?? null;
            if (!is_scalar($value) || !is_numeric((string)$value) || !is_finite((float)$value) || (float)$value < -1000 || (float)$value > 1000) throw new DomainException('Every dimension score must be a number between -1000 and 1000.');
            $score = round((float)$value, 4);
            if ($score !== 0.0 || array_key_exists($dimensionCode, $existingScores)) $scores[$dimensionCode] = $score;
        }
        if ($scores === []) throw new DomainException('Every answer option must score at least one dimension.');
        $options[$optionCode] = $existingOption + ['code' => $optionCode];
        $options[$optionCode]['code'] = $optionCode;
        $options[$optionCode]['label'] = $label;
        $options[$optionCode]['scores'] = $scores;
    }
    if (count($options) < 2) throw new DomainException('At least two answer options are required.');
    if ($section === 'A') {
        $likertCodes = array_map('strval', array_keys($options));
        sort($likertCodes, SORT_STRING);
        if ($likertCodes !== ['1', '2', '3', '4', '5']) throw new DomainException('Section A questions must use the complete 1-5 Likert scale.');
    }
    $question = is_array($existingQuestion) ? $existingQuestion : [];
    $question['code'] = $code;
    $question['section'] = $section;
    $question['prompt'] = $prompt;
    $question['type'] = $type;
    $question['is_reverse'] = isset($_POST['is_reverse']);
    $question['options'] = array_values($options);
    if ($title !== '') $question['title'] = $title; else unset($question['title']);
    if ($dimension !== '') $question['dimension'] = $dimension; else unset($question['dimension']);

    $questions = array_values(is_array($configuration['questions'] ?? null) ? $configuration['questions'] : []);
    $found = false;
    foreach ($questions as $index => $existing) {
        $existingCode = (string)($existing['code'] ?? '');
        if ($originalCode !== '' && $existingCode === $originalCode) { $questions[$index] = $question; $found = true; continue; }
        if ($existingCode === $code) throw new DomainException('Question code already exists in this version.');
    }
    if ($originalCode !== '' && !$found) throw new DomainException('The question being edited no longer exists.');
    if ($originalCode === '') $questions[] = $question;
    $configuration['questions'] = $questions;
    study_interest_replace_draft_configuration($pdo, (int)$versionId, $configuration, $studyInterestUserId, $originalCode === '' ? 'question.created' : 'question.updated', ['question_code' => $code], $expectedHash);
    $pdo->commit();
    study_interest_admin_redirect('success', __('Question saved.'), study_interest_admin_url('questions/edit', ['version_id' => (int)$versionId, 'code' => $code]));
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[study-interest] question save failed: ' . $error->getMessage());
    study_interest_admin_redirect('error', $error instanceof DomainException ? $error->getMessage() : __('The question could not be saved.'), $returnUrl);
}
