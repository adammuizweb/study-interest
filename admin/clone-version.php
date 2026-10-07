<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.config.manage', false);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { adiwira_render_404(); return; }
if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) study_interest_admin_redirect('error', __('The security token expired. Please try again.'), study_interest_admin_url('assessments'));
$sourceId = filter_var($_POST['source_version_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$versionCode = trim((string)($_POST['version_code'] ?? ''));
if ($sourceId === false || preg_match('/\A[a-z0-9][a-z0-9._-]{0,79}\z/i', $versionCode) !== 1) study_interest_admin_redirect('error', __('Enter a valid version code.'), study_interest_admin_url('assessments'));

try {
    $pdo->beginTransaction();
    if (!authorization_lock_actor_permissions($pdo, $studyInterestUserId) || !user_can($pdo, $studyInterestUserId, 'plugin.study-interest.config.manage')) throw new RuntimeException('Configuration permission changed.');
    $identity = $pdo->prepare('SELECT test_id FROM study_interest_test_versions WHERE id=? LIMIT 1');
    $identity->execute([(int)$sourceId]);
    $testId = (int)$identity->fetchColumn();
    if ($testId < 1) throw new DomainException('Source assessment version was not found.');
    $test = $pdo->prepare('SELECT id FROM study_interest_tests WHERE id=? LIMIT 1 FOR UPDATE');
    $test->execute([$testId]);
    if ((int)$test->fetchColumn() !== $testId) throw new DomainException('Source assessment was not found.');
    $source = $pdo->prepare('SELECT configuration_json,configuration_hash,version_code FROM study_interest_test_versions WHERE id=? AND test_id=? LIMIT 1 FOR UPDATE');
    $source->execute([(int)$sourceId, $testId]);
    $row = $source->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) throw new DomainException('Source assessment version was not found.');
    $exists = $pdo->prepare('SELECT id FROM study_interest_test_versions WHERE version_code=? LIMIT 1 FOR UPDATE');
    $exists->execute([$versionCode]);
    if ($exists->fetchColumn() !== false) throw new DomainException('That version code already exists.');
    $configuration = json_decode((string)$row['configuration_json'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($configuration)) throw new DomainException('Source configuration is invalid.');
    if (!hash_equals((string)$row['configuration_hash'], hash('sha256', study_interest_configuration_json($configuration)))) throw new RuntimeException('Source configuration integrity check failed.');
    $configuration['version'] = $versionCode;
    $newId = study_interest_import_configuration($pdo, $configuration, $studyInterestUserId);
    study_interest_audit($pdo, $studyInterestUserId, 'configuration.cloned', 'test_version', (string)$newId, null, ['source_version_id' => (int)$sourceId, 'version_code' => $versionCode]);
    $pdo->commit();
    study_interest_admin_redirect('success', __('Working draft created.'), study_interest_admin_url('questions', ['version_id' => $newId]));
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[study-interest] version clone failed: ' . $error->getMessage());
    study_interest_admin_redirect('error', $error instanceof DomainException ? $error->getMessage() : __('The draft could not be created.'), study_interest_admin_url('assessments', ['version_id' => (int)$sourceId]));
}
