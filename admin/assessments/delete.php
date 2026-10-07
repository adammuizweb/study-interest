<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.config.manage', false);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { adiwira_render_404(); return; }
$versionId = filter_var($_POST['version_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$expectedHash = trim((string)($_POST['expected_hash'] ?? ''));
$returnUrl = study_interest_admin_url('assessments', ['version_id' => (int)$versionId]);
if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) study_interest_admin_redirect('error', __('The security token expired. Please try again.'), $returnUrl);

try {
    $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 500);
    if ($versionId === false || $versionId === null || preg_match('/\A[a-f0-9]{64}\z/', $expectedHash) !== 1) throw new DomainException('Assessment draft is invalid.');
    if (mb_strlen($reason) < 5) throw new DomainException('A deletion reason of at least 5 characters is required.');
    $pdo->beginTransaction();
    if (!authorization_lock_actor_permissions($pdo, $studyInterestUserId) || !user_can($pdo, $studyInterestUserId, 'plugin.study-interest.config.manage')) throw new RuntimeException('Configuration permission changed.');
    $identity = $pdo->prepare('SELECT test_id FROM study_interest_test_versions WHERE id=? LIMIT 1');
    $identity->execute([(int)$versionId]);
    $testId = (int)$identity->fetchColumn();
    if ($testId < 1) throw new DomainException('Assessment draft was not found.');
    $testStatement = $pdo->prepare('SELECT code,title FROM study_interest_tests WHERE id=? LIMIT 1 FOR UPDATE');
    $testStatement->execute([$testId]);
    $test = $testStatement->fetch(PDO::FETCH_ASSOC);
    $statement = $pdo->prepare('SELECT id,test_id,version_code,status,configuration_hash FROM study_interest_test_versions WHERE id=? AND test_id=? LIMIT 1 FOR UPDATE');
    $statement->execute([(int)$versionId, $testId]);
    $version = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($version) || (string)$version['status'] !== 'draft') throw new DomainException('Only an unpublished draft can be deleted.');
    if (!hash_equals((string)$version['configuration_hash'], $expectedHash)) throw new DomainException('This draft changed while the form was open. Review it and try again.');
    $sessions = $pdo->prepare('SELECT id FROM study_interest_sessions WHERE version_id=? LIMIT 1 FOR UPDATE');
    $sessions->execute([(int)$versionId]);
    if ($sessions->fetchColumn() !== false) throw new DomainException('A draft with participant sessions cannot be deleted.');
    $versionCount = $pdo->prepare('SELECT id FROM study_interest_test_versions WHERE test_id=? FOR UPDATE');
    $versionCount->execute([(int)$version['test_id']]);
    $deleteTest = count($versionCount->fetchAll(PDO::FETCH_COLUMN)) === 1;
    study_interest_audit($pdo, $studyInterestUserId, 'configuration.deleted', 'test_version', (string)$versionId,
        ['test_code' => (string)($test['code'] ?? ''), 'test_title' => (string)($test['title'] ?? ''), 'version_code' => (string)$version['version_code'], 'configuration_hash' => (string)$version['configuration_hash']],
        ['reason' => $reason, 'test_deleted' => $deleteTest]);
    $pdo->prepare('DELETE FROM study_interest_test_versions WHERE id=?')->execute([(int)$versionId]);
    if ($deleteTest) $pdo->prepare('DELETE FROM study_interest_tests WHERE id=?')->execute([(int)$version['test_id']]);
    $pdo->commit();
    study_interest_admin_redirect('success', __('Assessment draft deleted.'), study_interest_admin_url('assessments'));
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[study-interest] assessment delete failed: ' . $error->getMessage());
    study_interest_admin_redirect('error', $error instanceof DomainException ? $error->getMessage() : __('The assessment draft could not be deleted.'), $returnUrl);
}
