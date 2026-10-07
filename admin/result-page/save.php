<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.presentation.manage', false);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { adiwira_render_404(); return; }
$returnUrl = study_interest_admin_url('result-page');
if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) study_interest_admin_redirect('error', __('The security token expired. Please try again.'), $returnUrl);

try {
    $mode = (string)($_POST['mode'] ?? '');
    if (!in_array($mode, ['full', 'masked', 'hidden'], true)) throw new DomainException('Select a valid result-page mode.');
    $defaults = study_interest_result_presentation_defaults();
    $postedSections = is_array($_POST['sections'] ?? null) ? $_POST['sections'] : [];
    $sections = [];
    foreach ($defaults['sections'] as $key => $_default) {
        $state = (string)($postedSections[$key] ?? '');
        if (!in_array($state, ['show', 'mask', 'hide'], true)) throw new DomainException("Select a valid visibility rule for {$key}.");
        $sections[$key] = $state;
    }
    $rawText = [];
    foreach (['masked_title' => 120, 'masked_message' => 500, 'hidden_title' => 120, 'hidden_message' => 500, 'section_mask_message' => 500] as $key => $maximum) {
        $text = trim((string)($_POST[$key] ?? ''));
        if ($text === '' || mb_strlen($text) > $maximum || preg_match('//u', $text) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) === 1) throw new DomainException('Participant messages must be valid, bounded text.');
        $rawText[$key] = $text;
    }
    $policy = study_interest_result_presentation_normalize(['mode' => $mode, 'sections' => $sections] + $rawText);
    $expectedHash = trim((string)($_POST['expected_hash'] ?? ''));
    if (preg_match('/\A[a-f0-9]{64}\z/', $expectedHash) !== 1) throw new DomainException('The result-page policy is invalid.');

    $pdo->beginTransaction();
    if (!authorization_lock_actor_permissions($pdo, $studyInterestUserId) || !user_can($pdo, $studyInterestUserId, 'plugin.study-interest.presentation.manage')) throw new RuntimeException('Result-page management permission changed.');
    $defaultJson = json_encode(study_interest_result_presentation_fail_closed(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare('INSERT INTO settings (`key`,`value`,`autoload`) VALUES (?,?,1) ON DUPLICATE KEY UPDATE `key`=VALUES(`key`)')
        ->execute([study_interest_result_presentation_setting_key(), $defaultJson]);
    $setting = $pdo->prepare('SELECT value FROM settings WHERE `key`=? LIMIT 1 FOR UPDATE');
    $setting->execute([study_interest_result_presentation_setting_key()]);
    $stored = $setting->fetchColumn();
    $before = study_interest_result_presentation_defaults();
    if (is_string($stored) && $stored !== '') {
        try {
            $decoded = json_decode($stored, true, 32, JSON_THROW_ON_ERROR);
            $before = study_interest_result_presentation_is_valid($decoded)
                ? study_interest_result_presentation_normalize($decoded)
                : study_interest_result_presentation_fail_closed();
        } catch (Throwable) {
            $before = study_interest_result_presentation_fail_closed();
        }
    }
    if (!hash_equals(study_interest_result_presentation_hash($before), $expectedHash)) throw new DomainException('The result-page policy changed while this form was open. Review the latest settings and try again.');
    $json = json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!function_exists('settings_set') || !settings_set($pdo, study_interest_result_presentation_setting_key(), $json, 1)) throw new RuntimeException('Result-page policy could not be persisted.');
    study_interest_audit($pdo, $studyInterestUserId, 'result_presentation.updated', 'result_presentation', null, $before, $policy);
    $pdo->commit();
    study_interest_admin_redirect('success', __('Result-page visibility saved.'), $returnUrl);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[study-interest] result-page policy update failed: ' . $error->getMessage());
    study_interest_admin_redirect('error', $error instanceof DomainException ? $error->getMessage() : __('The result-page policy could not be saved.'), $returnUrl);
}
