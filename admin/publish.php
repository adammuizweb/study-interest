<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.config.publish', false);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { adiwira_render_404(); return; }
if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) study_interest_admin_redirect('error', __('The security token expired. Please try again.'));
$versionId = filter_var($_POST['version_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($versionId === false) study_interest_admin_redirect('error', __('Select a valid assessment version.'));

try {
    $pdo->beginTransaction();
    if (!function_exists('authorization_lock_actor_permissions') || !authorization_lock_actor_permissions($pdo, $studyInterestUserId)) throw new RuntimeException('Unable to lock actor permissions.');
    if (!function_exists('user_can') || !user_can($pdo, $studyInterestUserId, 'plugin.study-interest.config.publish')) throw new RuntimeException('Publishing permission changed.');
    study_interest_publish_version($pdo, (int)$versionId, $studyInterestUserId);
    $pdo->commit();
    study_interest_admin_redirect('success', __('The assessment version is now live.'));
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (!$error instanceof DomainException) error_log('[study-interest] publish failed: ' . $error->getMessage());
    study_interest_admin_redirect('error', $error instanceof DomainException
        ? __('The assessment version did not pass publication validation.')
        : __('The assessment version could not be published.'));
}
