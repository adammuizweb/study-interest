<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.results.export', false);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { adiwira_render_404(); return; }
$resultsUrl = $studyInterestBase . '/results';
if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) study_interest_admin_redirect('error', __('The security token expired. Please try again.'), $resultsUrl);
$versionId = filter_var($_POST['version_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($versionId === false) study_interest_admin_redirect('error', __('Select a valid assessment version.'), $resultsUrl);

try {
    $pdo->beginTransaction();
    if (!function_exists('authorization_lock_actor_permissions') || !authorization_lock_actor_permissions($pdo, $studyInterestUserId)) throw new RuntimeException('Unable to lock actor permissions.');
    if (!function_exists('user_can') || !user_can($pdo, $studyInterestUserId, 'plugin.study-interest.results.export')) throw new RuntimeException('Export permission changed.');
    $version = $pdo->prepare('SELECT version_code FROM study_interest_test_versions WHERE id=? LIMIT 1 FOR UPDATE');
    $version->execute([(int)$versionId]);
    $versionCode = $version->fetchColumn();
    if (!is_string($versionCode) || $versionCode === '') throw new DomainException('Assessment version is unavailable.');
    $count = $pdo->prepare("SELECT COUNT(*) FROM study_interest_sessions WHERE version_id=? AND status='completed'");
    $count->execute([(int)$versionId]);
    $rowCount = (int)$count->fetchColumn();
    study_interest_audit($pdo, $studyInterestUserId, 'results.exported', 'result_export', (string)$versionId, null, ['row_count' => $rowCount, 'version_code' => $versionCode]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[study-interest] result export failed: ' . $error->getMessage());
    study_interest_admin_redirect('error', $error instanceof DomainException ? __('The selected assessment version is unavailable.') : __('The result export could not be created.'), $resultsUrl);
}

$rows = $pdo->prepare("SELECT s.public_id,v.version_code,s.started_at_utc,s.completed_at_utc,
    (SELECT p.program_code FROM study_interest_program_results p WHERE p.session_id=s.id AND p.is_recommended=1 AND p.rank_position=1 LIMIT 1) AS top_program,
    (SELECT p.score FROM study_interest_program_results p WHERE p.session_id=s.id AND p.is_recommended=1 AND p.rank_position=1 LIMIT 1) AS top_score,
    (SELECT COUNT(*) FROM study_interest_result_flags f WHERE f.session_id=s.id) AS flag_count
    FROM study_interest_sessions s JOIN study_interest_test_versions v ON v.id=s.version_id
    WHERE s.version_id=? AND s.status='completed' ORDER BY s.completed_at_utc ASC,s.id ASC");
$rows->execute([(int)$versionId]);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="study-interest-results-' . gmdate('Ymd-His') . '.csv"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$output = fopen('php://output', 'wb');
if ($output === false) exit;
fputcsv($output, ['session_uuid', 'version', 'started_at_utc', 'completed_at_utc', 'top_program', 'top_score', 'flag_count'], ',', '"', '');
while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
    $values = [$row['public_id'], $row['version_code'], $row['started_at_utc'], $row['completed_at_utc'], $row['top_program'], $row['top_score'], $row['flag_count']];
    foreach ($values as &$value) if (is_string($value) && preg_match('/\A[=+\-@]/', $value) === 1) $value = "'" . $value;
    unset($value);
    fputcsv($output, $values, ',', '"', '');
}
fclose($output);
exit;
