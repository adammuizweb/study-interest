<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.results.view', false);
$summary = $pdo->query("SELECT COUNT(*) AS total,
    SUM(status='completed') AS completed,
    SUM(status='started') AS in_progress
    FROM study_interest_sessions")->fetch(PDO::FETCH_ASSOC) ?: [];
$programs = $pdo->query("SELECT program_code,COUNT(*) AS total,ROUND(AVG(score),2) AS average_score
    FROM study_interest_program_results WHERE is_recommended=1 AND rank_position=1
    GROUP BY program_code ORDER BY total DESC,program_code ASC")->fetchAll(PDO::FETCH_ASSOC);
$dimensions = $pdo->query("SELECT dimension_code,ROUND(AVG(final_score),2) AS average_score
    FROM study_interest_dimension_results GROUP BY dimension_code ORDER BY average_score DESC,dimension_code ASC")->fetchAll(PDO::FETCH_ASSOC);
$flags = $pdo->query("SELECT flag_code,severity,COUNT(*) AS total FROM study_interest_result_flags
    GROUP BY flag_code,severity ORDER BY total DESC,flag_code ASC")->fetchAll(PDO::FETCH_ASSOC);
$canExport = function_exists('user_can') && user_can($pdo, $studyInterestUserId, 'plugin.study-interest.results.export');

echo '<div class="wrap"><h1>' . study_interest_h(__('Study Interest Result Analytics')) . '</h1>';
echo '<p><a class="button" href="' . study_interest_h($studyInterestBase) . '">' . study_interest_h(__('Back to assessment versions')) . '</a></p>';
echo '<div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin:20px 0">';
foreach ([__('Sessions') => (int)($summary['total'] ?? 0), __('Completed') => (int)($summary['completed'] ?? 0), __('In progress') => (int)($summary['in_progress'] ?? 0)] as $label => $value) {
    echo '<div class="card" style="padding:18px"><div style="font-size:2rem;font-weight:700">' . $value . '</div><div>' . study_interest_h($label) . '</div></div>';
}
echo '</div><div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px">';
echo '<section><h2>' . study_interest_h(__('Top recommendation distribution')) . '</h2><table class="widefat striped"><thead><tr><th>' . study_interest_h(__('Program code')) . '</th><th>' . study_interest_h(__('Top results')) . '</th><th>' . study_interest_h(__('Average score')) . '</th></tr></thead><tbody>';
if ($programs === []) echo '<tr><td colspan="3">' . study_interest_h(__('No completed results.')) . '</td></tr>';
foreach ($programs as $row) echo '<tr><td>' . study_interest_h($row['program_code']) . '</td><td>' . (int)$row['total'] . '</td><td>' . number_format((float)$row['average_score'], 2) . '</td></tr>';
echo '</tbody></table></section><section><h2>' . study_interest_h(__('Average dimension scores')) . '</h2><table class="widefat striped"><thead><tr><th>' . study_interest_h(__('Dimension')) . '</th><th>' . study_interest_h(__('Average score')) . '</th></tr></thead><tbody>';
if ($dimensions === []) echo '<tr><td colspan="2">' . study_interest_h(__('No completed results.')) . '</td></tr>';
foreach ($dimensions as $row) echo '<tr><td>' . study_interest_h($row['dimension_code']) . '</td><td>' . number_format((float)$row['average_score'], 2) . '</td></tr>';
echo '</tbody></table></section></div><section><h2>' . study_interest_h(__('Response quality flags')) . '</h2><table class="widefat striped"><thead><tr><th>' . study_interest_h(__('Flag')) . '</th><th>' . study_interest_h(__('Severity')) . '</th><th>' . study_interest_h(__('Count')) . '</th></tr></thead><tbody>';
if ($flags === []) echo '<tr><td colspan="3">' . study_interest_h(__('No quality flags.')) . '</td></tr>';
foreach ($flags as $row) echo '<tr><td>' . study_interest_h($row['flag_code']) . '</td><td>' . study_interest_h($row['severity']) . '</td><td>' . (int)$row['total'] . '</td></tr>';
echo '</tbody></table></section>';
if ($canExport) echo '<form method="post" action="' . study_interest_h(ADMIN_BASE_PATH . '/?page=admin/tools/study-interest/export') . '" style="margin-top:20px"><input type="hidden" name="csrf_token" value="' . study_interest_h(csrf_token()) . '"><button class="button" type="submit">' . study_interest_h(__('Export anonymized result CSV')) . '</button></form>';
echo '</div>';
