<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.results.export', false);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { adiwira_render_404(); return; }
if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) study_interest_admin_redirect('failed');
try {
    $pdo->beginTransaction();
    if (!function_exists('authorization_lock_actor_permissions') || !authorization_lock_actor_permissions($pdo, $studyInterestUserId)) throw new RuntimeException('Unable to lock actor permissions.');
    if (!function_exists('user_can') || !user_can($pdo, $studyInterestUserId, 'plugin.study-interest.results.export')) throw new RuntimeException('Export permission changed.');
    $rows = $pdo->query("SELECT s.public_id,v.version_code,s.started_at_utc,s.completed_at_utc,
        (SELECT p.program_code FROM study_interest_program_results p WHERE p.session_id=s.id AND p.rank_position=1 LIMIT 1) AS top_program,
        (SELECT p.score FROM study_interest_program_results p WHERE p.session_id=s.id AND p.rank_position=1 LIMIT 1) AS top_score,
        (SELECT COUNT(*) FROM study_interest_result_flags f WHERE f.session_id=s.id) AS flag_count
        FROM study_interest_sessions s JOIN study_interest_test_versions v ON v.id=s.version_id
        WHERE s.status='completed' ORDER BY s.completed_at_utc ASC,s.id ASC")->fetchAll(PDO::FETCH_ASSOC);
    study_interest_audit($pdo, $studyInterestUserId, 'results.exported', 'result_export', null, null, ['row_count' => count($rows)]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[study-interest] result export failed: ' . $error->getMessage());
    study_interest_admin_redirect('failed');
}
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="study-interest-results-' . gmdate('Ymd-His') . '.csv"');
header('Cache-Control: no-store');
$output = fopen('php://output', 'wb');
fputcsv($output, ['session_uuid', 'version', 'started_at_utc', 'completed_at_utc', 'top_program', 'top_score', 'flag_count'], ',', '"', '');
foreach ($rows as $row) fputcsv($output, [$row['public_id'], $row['version_code'], $row['started_at_utc'], $row['completed_at_utc'], $row['top_program'], $row['top_score'], $row['flag_count']], ',', '"', '');
fclose($output);
exit;
