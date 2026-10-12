<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.presentation.manage', false);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { adiwira_render_404(); return; }
$returnUrl = study_interest_admin_url('branding');
if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) study_interest_admin_redirect('error', __('The security token expired. Please try again.'), $returnUrl);

try {
    if (is_array($_POST['brand_title'] ?? null) || is_array($_POST['logo_image_url'] ?? null)) throw new DomainException('Branding fields are invalid.');
    $title = trim((string)($_POST['brand_title'] ?? ''));
    $url = trim((string)($_POST['logo_image_url'] ?? ''));
    if (mb_strlen($title) > 120 || preg_match('//u', $title) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $title) === 1) throw new DomainException('Brand title must be valid single-line text up to 120 characters.');
    $urlError = study_interest_public_branding_url_error($url);
    if ($urlError !== null) throw new DomainException($urlError);
    $branding = study_interest_public_branding_normalize(['brand_title' => $title, 'logo_image_url' => $url]);
    $expectedHash = trim((string)($_POST['expected_hash'] ?? ''));
    if (preg_match('/\A[a-f0-9]{64}\z/', $expectedHash) !== 1) throw new DomainException('The public branding state is invalid.');

    $pdo->beginTransaction();
    if (!authorization_lock_actor_permissions($pdo, $studyInterestUserId) || !user_can($pdo, $studyInterestUserId, 'plugin.study-interest.presentation.manage')) throw new RuntimeException('Public presentation permission changed.');
    $defaultJson = json_encode(study_interest_public_branding_defaults(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare('INSERT INTO settings (`key`,`value`,`autoload`) VALUES (?,?,1) ON DUPLICATE KEY UPDATE `key`=VALUES(`key`)')
        ->execute([study_interest_public_branding_setting_key(), $defaultJson]);
    $setting = $pdo->prepare('SELECT value FROM settings WHERE `key`=? LIMIT 1 FOR UPDATE');
    $setting->execute([study_interest_public_branding_setting_key()]);
    $stored = $setting->fetchColumn();
    $before = study_interest_public_branding_defaults();
    if (is_string($stored) && $stored !== '') {
        try {
            $decoded = json_decode($stored, true, 16, JSON_THROW_ON_ERROR);
            if (study_interest_public_branding_is_valid($decoded)) $before = study_interest_public_branding_normalize($decoded);
        } catch (Throwable) {}
    }
    if (!hash_equals(study_interest_public_branding_hash($before), $expectedHash)) throw new DomainException('Public branding changed while this form was open. Review the latest settings and try again.');
    $json = json_encode($branding, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!function_exists('settings_set') || !settings_set($pdo, study_interest_public_branding_setting_key(), $json, 1)) throw new RuntimeException('Public branding could not be persisted.');
    study_interest_audit($pdo, $studyInterestUserId, 'public_branding.updated', 'public_branding', null, $before, $branding);
    $pdo->commit();
    study_interest_admin_redirect('success', __('Public branding saved.'), $returnUrl);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[study-interest] public branding update failed: ' . $error->getMessage());
    study_interest_admin_redirect('error', $error instanceof DomainException ? $error->getMessage() : __('Public branding could not be saved.'), $returnUrl);
}
