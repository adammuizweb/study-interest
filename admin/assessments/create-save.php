<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.config.manage', false);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { adiwira_render_404(); return; }
$returnUrl = study_interest_admin_url('assessments/create');
if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) study_interest_admin_redirect('error', __('The security token expired. Please try again.'), $returnUrl);

try {
    $testCode = strtolower(trim((string)($_POST['test_code'] ?? '')));
    $versionCode = strtolower(trim((string)($_POST['version_code'] ?? '')));
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 255);
    $description = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 2000);
    $starter = (string)($_POST['starter'] ?? 'neutral');
    if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,79}\z/', $testCode) !== 1) throw new DomainException('Enter a valid test code.');
    if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,79}\z/', $versionCode) !== 1) throw new DomainException('Enter a valid version code.');
    if ($title === '') throw new DomainException('Test name is required.');
    if (!in_array($starter, ['neutral', 'blank'], true)) throw new DomainException('Select a valid assessment starting point.');

    $pdo->beginTransaction();
    if (!authorization_lock_actor_permissions($pdo, $studyInterestUserId) || !user_can($pdo, $studyInterestUserId, 'plugin.study-interest.config.manage')) throw new RuntimeException('Configuration permission changed.');
    $testExists = $pdo->prepare('SELECT id FROM study_interest_tests WHERE code=? LIMIT 1 FOR UPDATE');
    $testExists->execute([$testCode]);
    if ($testExists->fetchColumn() !== false) throw new DomainException('That test code already exists.');
    $versionExists = $pdo->prepare('SELECT id FROM study_interest_test_versions WHERE version_code=? LIMIT 1 FOR UPDATE');
    $versionExists->execute([$versionCode]);
    if ($versionExists->fetchColumn() !== false) throw new DomainException('That version code already exists.');

    $configuration = $starter === 'blank' ? study_interest_blank_configuration() : study_interest_baseline_configuration();
    $configuration['code'] = $testCode;
    $configuration['version'] = $versionCode;
    $configuration['title'] = $title;
    $configuration['description'] = $description;
    $versionId = study_interest_import_configuration($pdo, $configuration, $studyInterestUserId);
    $test = $pdo->prepare('SELECT id FROM study_interest_tests WHERE code=? LIMIT 1');
    $test->execute([$testCode]);
    study_interest_audit($pdo, $studyInterestUserId, 'test.created', 'test', (string)$test->fetchColumn(), null, ['code' => $testCode, 'title' => $title, 'version_code' => $versionCode, 'starter' => $starter]);
    $pdo->commit();
    study_interest_admin_redirect('success', __('Assessment draft created. You can now edit every part of it.'), $starter === 'blank' ? study_interest_admin_url('structure', ['version_id' => $versionId]) : study_interest_admin_url('assessments/edit', ['version_id' => $versionId]));
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[study-interest] assessment create failed: ' . $error->getMessage());
    study_interest_admin_redirect('error', $error instanceof DomainException ? $error->getMessage() : __('The assessment could not be created.'), $returnUrl);
}
