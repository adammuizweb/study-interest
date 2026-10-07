<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.config.manage', false);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { adiwira_render_404(); return; }
$versionId = filter_var($_POST['version_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$code = trim((string)($_POST['original_code'] ?? ''));
$expectedHash = trim((string)($_POST['expected_hash'] ?? ''));
$returnUrl = study_interest_admin_url('questions', ['version_id' => (int)$versionId]);
if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) study_interest_admin_redirect('error', __('The security token expired. Please try again.'), $returnUrl);
try {
    if ($versionId === false || $versionId === null || $code === '' || preg_match('/\A[a-f0-9]{64}\z/', $expectedHash) !== 1) throw new DomainException('Question is invalid.');
    $pdo->beginTransaction();
    if (!authorization_lock_actor_permissions($pdo, $studyInterestUserId) || !user_can($pdo, $studyInterestUserId, 'plugin.study-interest.config.manage')) throw new RuntimeException('Configuration permission changed.');
    $statement = $pdo->prepare('SELECT configuration_json,configuration_hash,status FROM study_interest_test_versions WHERE id=? LIMIT 1');
    $statement->execute([(int)$versionId]);
    $version = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($version) || (string)$version['status'] !== 'draft') throw new DomainException('Only draft questions can be deleted.');
    $configuration = json_decode((string)$version['configuration_json'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($configuration)) throw new DomainException('Stored configuration is invalid.');
    if (!hash_equals((string)$version['configuration_hash'], hash('sha256', study_interest_configuration_json($configuration)))) throw new RuntimeException('Assessment configuration integrity check failed.');
    $before = count($configuration['questions'] ?? []);
    $configuration['questions'] = array_values(array_filter($configuration['questions'] ?? [], static fn(array $question): bool => (string)($question['code'] ?? '') !== $code));
    if (count($configuration['questions']) === $before) throw new DomainException('Question was not found.');
    study_interest_replace_draft_configuration($pdo, (int)$versionId, $configuration, $studyInterestUserId, 'question.deleted', ['question_code' => $code], $expectedHash);
    $pdo->commit();
    study_interest_admin_redirect('success', __('Question deleted from the draft.'), $returnUrl);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[study-interest] question delete failed: ' . $error->getMessage());
    study_interest_admin_redirect('error', $error instanceof DomainException ? $error->getMessage() : __('The question could not be deleted.'), study_interest_admin_url('questions/edit', ['version_id' => (int)$versionId, 'code' => $code]));
}
