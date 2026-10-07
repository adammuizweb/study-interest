<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.sessions.delete', false);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { adiwira_render_404(); return; }
$publicId = trim((string)($_POST['session_public_id'] ?? ''));
$returnTo = (string)($_POST['return_to'] ?? '') === 'participants' ? 'participants' : 'sessions';
$returnUrl = study_interest_admin_url($returnTo);
if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) study_interest_admin_redirect('error', __('The security token expired. Please try again.'), $returnUrl);
try {
    if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $publicId) !== 1) throw new DomainException('Session is invalid.');
    $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 500);
    if (mb_strlen($reason) < 5) throw new DomainException('A deletion reason of at least 5 characters is required.');
    $pdo->beginTransaction();
    if (!authorization_lock_actor_permissions($pdo, $studyInterestUserId) || !user_can($pdo, $studyInterestUserId, 'plugin.study-interest.sessions.delete')) throw new RuntimeException('Session deletion permission changed.');
    $statement = $pdo->prepare('SELECT s.id,s.status,s.version_id,s.result_revision,s.contact_json,s.result_snapshot_json,(SELECT COUNT(*) FROM study_interest_answers a WHERE a.session_id=s.id) AS answer_count FROM study_interest_sessions s WHERE s.public_id=? LIMIT 1 FOR UPDATE');
    $statement->execute([$publicId]);
    $session = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($session)) throw new DomainException('Session was not found.');
    study_interest_audit($pdo, $studyInterestUserId, 'session.deleted', 'session', $publicId,
        ['status' => (string)$session['status'], 'version_id' => (int)$session['version_id'], 'result_revision' => (int)$session['result_revision'], 'answer_count' => (int)$session['answer_count'], 'contact_hash' => hash('sha256', (string)$session['contact_json']), 'result_hash' => $session['result_snapshot_json'] !== null ? hash('sha256', (string)$session['result_snapshot_json']) : null],
        ['reason' => $reason]);
    $delete = $pdo->prepare('DELETE FROM study_interest_sessions WHERE id=?');
    $delete->execute([(int)$session['id']]);
    if ($delete->rowCount() !== 1) throw new RuntimeException('Session deletion conflicted with another request.');
    $pdo->commit();
    study_interest_admin_redirect('success', __('Participant session permanently deleted.'), $returnUrl);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[study-interest] session deletion failed: ' . $error->getMessage());
    study_interest_admin_redirect('error', $error instanceof DomainException ? $error->getMessage() : __('The participant session could not be deleted.'), $returnUrl);
}
