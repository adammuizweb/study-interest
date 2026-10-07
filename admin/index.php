<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.dashboard.access', false);
$manifest = plugin_manifest('study-interest');
$version = is_array($manifest) ? (string)($manifest['version'] ?? '') : '';
$versions = $pdo->query("SELECT v.id,v.version_code,v.status,v.algorithm_version,v.expected_question_count,v.configuration_hash,v.published_at_utc,
    (SELECT COUNT(*) FROM study_interest_sessions s WHERE s.version_id=v.id) AS session_count,
    (SELECT COUNT(*) FROM study_interest_sessions s WHERE s.version_id=v.id AND s.status='completed') AS completed_count
    FROM study_interest_test_versions v ORDER BY v.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$canManage = function_exists('user_can') && user_can($pdo, $studyInterestUserId, 'plugin.study-interest.config.manage');
$canPublish = function_exists('user_can') && user_can($pdo, $studyInterestUserId, 'plugin.study-interest.config.publish');
$canViewResults = function_exists('user_can') && user_can($pdo, $studyInterestUserId, 'plugin.study-interest.results.view');
$notice = (string)($_GET['notice'] ?? '');
$messages = [
    'imported' => __('The packaged baseline was imported as a draft.'),
    'published' => __('The assessment version was published.'),
    'invalid' => __('The assessment version did not pass publication validation.'),
    'failed' => __('The requested operation could not be completed.'),
];
echo '<div class="wrap"><h1>' . study_interest_h(__('Study Interest Explorer')) . '</h1>';
echo '<p>' . study_interest_h(sprintf(__('Plugin version %s. Public assessments use only an explicitly published immutable version.'), $version)) . '</p>';
if (isset($messages[$notice])) echo '<div class="notice ' . ($notice === 'imported' || $notice === 'published' ? 'notice-success' : 'notice-error') . '"><p>' . study_interest_h($messages[$notice]) . '</p></div>';
$packagedVersion = (string)(study_interest_baseline_configuration()['version'] ?? '');
$packagedImported = false;
foreach ($versions as $row) if ((string)$row['version_code'] === $packagedVersion) $packagedImported = true;
if (!$packagedImported && $canManage) {
    echo '<form method="post" action="' . study_interest_h(ADMIN_BASE_PATH . '/?page=admin/tools/study-interest/import-baseline') . '">'
        . '<input type="hidden" name="csrf_token" value="' . study_interest_h(csrf_token()) . '">'
        . '<p><button class="button button-primary" type="submit">' . study_interest_h(__('Import packaged baseline as draft')) . '</button></p></form>';
}
echo '<table class="widefat striped"><thead><tr><th>' . study_interest_h(__('Version')) . '</th><th>' . study_interest_h(__('Status'))
    . '</th><th>' . study_interest_h(__('Questions')) . '</th><th>' . study_interest_h(__('Sessions')) . '</th><th>' . study_interest_h(__('Completed'))
    . '</th><th>' . study_interest_h(__('Configuration hash')) . '</th><th>' . study_interest_h(__('Action')) . '</th></tr></thead><tbody>';
if ($versions === []) echo '<tr><td colspan="7">' . study_interest_h(__('No assessment version has been imported.')) . '</td></tr>';
foreach ($versions as $row) {
    echo '<tr><td><strong>' . study_interest_h($row['version_code']) . '</strong><br><small>' . study_interest_h($row['algorithm_version']) . '</small></td>'
        . '<td>' . study_interest_h(__(ucfirst((string)$row['status']))) . '</td><td>' . (int)$row['expected_question_count'] . '</td>'
        . '<td>' . (int)$row['session_count'] . '</td><td>' . (int)$row['completed_count'] . '</td><td><code>' . study_interest_h(substr((string)$row['configuration_hash'], 0, 12)) . '...</code></td><td>';
    if ((string)$row['status'] === 'draft' && $canPublish) {
        $errors = study_interest_publish_errors($pdo, (int)$row['id']);
        if ($errors === []) {
            echo '<form method="post" action="' . study_interest_h(ADMIN_BASE_PATH . '/?page=admin/tools/study-interest/publish') . '">'
                . '<input type="hidden" name="csrf_token" value="' . study_interest_h(csrf_token()) . '"><input type="hidden" name="version_id" value="' . (int)$row['id'] . '">'
                . '<button class="button button-primary" type="submit">' . study_interest_h(__('Publish')) . '</button></form>';
        } else echo '<span title="' . study_interest_h(implode(' ', $errors)) . '">' . study_interest_h(__('Validation required')) . '</span>';
    } else echo '&mdash;';
    echo '</td></tr>';
}
echo '</tbody></table><p><a class="button" href="/study-interest/" target="_blank" rel="noopener">' . study_interest_h(__('Open public assessment')) . '</a>';
if ($canViewResults) echo ' <a class="button" href="' . study_interest_h(ADMIN_BASE_PATH . '/?page=admin/tools/study-interest/results') . '">' . study_interest_h(__('View result analytics')) . '</a>';
echo '</p></div>';
