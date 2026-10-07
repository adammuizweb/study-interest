<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.config.manage', false);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { adiwira_render_404(); return; }
if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) study_interest_admin_redirect('error', __('The security token expired. Please try again.'));

try {
    $pdo->beginTransaction();
    if (!function_exists('authorization_lock_actor_permissions') || !authorization_lock_actor_permissions($pdo, $studyInterestUserId)) throw new RuntimeException('Unable to lock actor permissions.');
    if (!function_exists('user_can') || !user_can($pdo, $studyInterestUserId, 'plugin.study-interest.config.manage')) throw new RuntimeException('Configuration permission changed.');
    study_interest_import_configuration($pdo, study_interest_baseline_configuration(), $studyInterestUserId);
    $pdo->commit();
    study_interest_admin_redirect('success', __('The packaged assessment was imported as a draft.'));
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[study-interest] baseline import failed: ' . $error->getMessage());
    study_interest_admin_redirect('error', $error instanceof DomainException
        ? __('The packaged assessment did not pass validation.')
        : __('The assessment could not be imported.'));
}
