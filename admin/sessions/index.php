<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.sessions.view', false);
$canViewContacts = study_interest_admin_can('plugin.study-interest.contacts.view');
$canViewResults = study_interest_admin_can('plugin.study-interest.results.view');
$canManageContacts = study_interest_admin_can('plugin.study-interest.contacts.manage');
$canExport = $canViewContacts && $canViewResults && study_interest_admin_can('plugin.study-interest.workspace.export');
$versions = $pdo->query('SELECT id,version_code,status,configuration_json FROM study_interest_test_versions ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
$programLabels = [];
foreach ($versions as $version) {
    $configuration = json_decode((string)$version['configuration_json'], true);
    if (!is_array($configuration)) continue;
    foreach ($configuration['programs'] ?? [] as $code => $program) $programLabels[(int)$version['id']][(string)$code] = (string)($program['label'] ?? $code);
}
$versionId = filter_var($_GET['version_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($versionId === false) $versionId = null;
$status = trim((string)($_GET['status'] ?? ''));
if (!in_array($status, ['', 'started', 'completed'], true)) $status = '';
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 120);
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$fromDate = $dateFrom !== '' && function_exists('app_parse_exact_datetime') ? app_parse_exact_datetime($dateFrom, 'Y-m-d', app_timezone()) : null;
$toDate = $dateTo !== '' && function_exists('app_parse_exact_datetime') ? app_parse_exact_datetime($dateTo, 'Y-m-d', app_timezone()) : null;
if ($dateFrom !== '' && !$fromDate instanceof DateTimeImmutable) $dateFrom = '';
if ($dateTo !== '' && !$toDate instanceof DateTimeImmutable) $dateTo = '';
$perOptions = [25, 50, 100];
$perPage = (int)($_GET['per'] ?? 25);
if (!in_array($perPage, $perOptions, true)) $perPage = 25;
$page = max(1, (int)($_GET['p'] ?? 1));

$where = ['1=1'];
$params = [];
if ($versionId) { $where[] = 's.version_id=?'; $params[] = (int)$versionId; }
if ($status !== '') { $where[] = 's.status=?'; $params[] = $status; }
if ($fromDate instanceof DateTimeImmutable) { $where[] = 's.started_at_utc>=?'; $params[] = app_site_datetime_to_utc_mysql($fromDate); }
if ($toDate instanceof DateTimeImmutable) { $where[] = 's.started_at_utc<?'; $params[] = app_site_datetime_to_utc_mysql($toDate->modify('+1 day')); }
if ($q !== '') {
    $needle = '%' . $q . '%';
    if ($canViewContacts) {
        $where[] = "(s.public_id LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(s.contact_json,'$.name')) LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(s.contact_json,'$.school')) LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(s.contact_json,'$.email')) LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(s.contact_json,'$.phone')) LIKE ?)";
        array_push($params, $needle, $needle, $needle, $needle, $needle);
    } else {
        $where[] = 's.public_id LIKE ?';
        $params[] = $needle;
    }
}
$whereSql = implode(' AND ', $where);
$count = $pdo->prepare("SELECT COUNT(*) FROM study_interest_sessions s WHERE {$whereSql}");
$count->execute($params);
$total = (int)$count->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;
$contactColumn = $canViewContacts ? 's.contact_json' : 'NULL AS contact_json';
$resultColumns = $canViewResults
    ? "s.result_revision,
       (SELECT p.program_code FROM study_interest_program_results p WHERE p.session_id=s.id AND p.recommendation_type='DIRECT_ENTRY' ORDER BY p.score DESC,p.program_code ASC LIMIT 1) AS top_program,
       (SELECT p.score FROM study_interest_program_results p WHERE p.session_id=s.id AND p.recommendation_type='DIRECT_ENTRY' ORDER BY p.score DESC,p.program_code ASC LIMIT 1) AS top_score,
       (SELECT COUNT(*) FROM study_interest_result_flags f WHERE f.session_id=s.id) AS flag_count"
    : '0 AS result_revision,NULL AS top_program,NULL AS top_score,0 AS flag_count';
$statement = $pdo->prepare("SELECT s.id,s.public_id,s.status,s.started_at_utc,s.completed_at_utc,s.last_activity_at_utc,{$resultColumns},{$contactColumn},v.id AS version_id,v.version_code,v.expected_question_count,
    (SELECT COUNT(*) FROM study_interest_answers a WHERE a.session_id=s.id) AS answer_count
    FROM study_interest_sessions s JOIN study_interest_test_versions v ON v.id=s.version_id WHERE {$whereSql}
    ORDER BY s.last_activity_at_utc DESC,s.id DESC LIMIT {$perPage} OFFSET {$offset}");
$statement->execute($params);
$rows = $statement->fetchAll(PDO::FETCH_ASSOC);
$summary = $pdo->query("SELECT COUNT(*) AS total,SUM(status='started') AS started,SUM(status='completed') AS completed,SUM(DATE(started_at_utc)=UTC_DATE()) AS today FROM study_interest_sessions")->fetch(PDO::FETCH_ASSOC) ?: [];
$query = ['version_id' => $versionId, 'status' => $status, 'q' => $q, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'per' => $perPage];
?>
<div class="sie-admin sie-workspace-page">
  <?php study_interest_admin_nav('sessions'); ?>
  <header class="sie-admin-head">
    <div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Operations')) ?></span><h1 class="page-heading"><?= study_interest_h(__('Sessions')) ?></h1><p><?= study_interest_h(__('Monitor every assessment run, answer progress, quality signal, and effective result revision.')) ?></p></div>
    <?php if ($canExport): ?><form class="sie-admin-actions" method="post" action="<?= study_interest_h(study_interest_admin_action_url('workspace-export')) ?>"><input type="hidden" name="csrf_token" value="<?= study_interest_h(csrf_token()) ?>"><input type="hidden" name="dataset" value="sessions"><input type="hidden" name="q" value="<?= study_interest_h($q) ?>"><input type="hidden" name="status" value="<?= study_interest_h($status) ?>"><input type="hidden" name="version_id" value="<?= $versionId !== null ? (int)$versionId : '' ?>"><input type="hidden" name="date_from" value="<?= study_interest_h($dateFrom) ?>"><input type="hidden" name="date_to" value="<?= study_interest_h($dateTo) ?>"><button class="adam-button" type="submit" name="format" value="xlsx"><?= study_interest_h(__('Export Excel')) ?></button><button class="adam-button secondary" type="submit" name="format" value="csv"><?= study_interest_h(__('Export CSV')) ?></button></form><?php endif; ?>
  </header>
  <section class="sie-admin-summary">
    <div><span><?= study_interest_h(__('All sessions')) ?></span><strong><?= (int)($summary['total'] ?? 0) ?></strong><small><?= study_interest_h(__('all versions')) ?></small></div>
    <div><span><?= study_interest_h(__('In progress')) ?></span><strong><?= (int)($summary['started'] ?? 0) ?></strong><small><?= study_interest_h(__('not completed')) ?></small></div>
    <div><span><?= study_interest_h(__('Completed')) ?></span><strong><?= (int)($summary['completed'] ?? 0) ?></strong><small><?= study_interest_h(__('result available')) ?></small></div>
    <div><span><?= study_interest_h(__('Started today')) ?></span><strong><?= (int)($summary['today'] ?? 0) ?></strong><small><?= study_interest_h(__('UTC activity')) ?></small></div>
  </section>
  <form class="sie-list-filter" method="get" action="<?= study_interest_h(rtrim((string)ADMIN_BASE_PATH, '/') . '/') ?>">
    <input type="hidden" name="page" value="admin/tools/study-interest/sessions">
    <label><span><?= study_interest_h(__('Version')) ?></span><select class="adam-input" name="version_id"><option value=""><?= study_interest_h(__('All versions')) ?></option><?php foreach ($versions as $version): ?><option value="<?= (int)$version['id'] ?>" <?= (int)$versionId === (int)$version['id'] ? 'selected' : '' ?>><?= study_interest_h((string)$version['version_code']) ?></option><?php endforeach; ?></select></label>
    <label><span><?= study_interest_h(__('Status')) ?></span><select class="adam-input" name="status"><option value=""><?= study_interest_h(__('All statuses')) ?></option><option value="started" <?= $status === 'started' ? 'selected' : '' ?>><?= study_interest_h(__('In progress')) ?></option><option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>><?= study_interest_h(__('Completed')) ?></option></select></label>
    <label class="is-search"><span><?= study_interest_h(__('Search')) ?></span><input class="adam-input" name="q" value="<?= study_interest_h($q) ?>" placeholder="<?= study_interest_h($canViewContacts ? __('Participant or session UUID') : __('Session UUID')) ?>"></label>
    <label><span><?= study_interest_h(__('From')) ?></span><input class="adam-input" type="date" name="date_from" value="<?= study_interest_h($dateFrom) ?>"></label>
    <label><span><?= study_interest_h(__('To')) ?></span><input class="adam-input" type="date" name="date_to" value="<?= study_interest_h($dateTo) ?>"></label>
    <label><span><?= study_interest_h(__('Per page')) ?></span><select class="adam-input" name="per"><?php foreach ($perOptions as $option): ?><option value="<?= $option ?>" <?= $option === $perPage ? 'selected' : '' ?>><?= $option ?></option><?php endforeach; ?></select></label>
    <div><button class="adam-button" type="submit"><?= study_interest_h(__('Filter')) ?></button><a class="adam-button secondary" href="<?= study_interest_h(study_interest_admin_url('sessions')) ?>"><?= study_interest_h(__('Reset')) ?></a></div>
  </form>
  <div class="sie-resultbar"><span><?= study_interest_h(sprintf(__('Showing %d-%d of %d sessions.'), $total > 0 ? $offset + 1 : 0, min($offset + $perPage, $total), $total)) ?></span><?php if ($canExport): ?><span><?= study_interest_h(__('Exports use the active filters.')) ?></span><?php endif; ?></div>
  <div class="sie-admin-table"><table><thead><tr><th><?= study_interest_h($canViewContacts ? __('Participant') : __('Session')) ?></th><th><?= study_interest_h(__('Version')) ?></th><th><?= study_interest_h(__('Status / progress')) ?></th><th><?= study_interest_h(__('Result')) ?></th><th><?= study_interest_h(__('Activity')) ?></th><th><?= study_interest_h(__('Action')) ?></th></tr></thead><tbody>
    <?php if ($rows === []): ?><tr><td colspan="6"><div class="sie-table-empty"><strong><?= study_interest_h(__('No sessions match this view')) ?></strong></div></td></tr><?php endif; ?>
    <?php foreach ($rows as $row): ?><?php $contact = $canViewContacts ? study_interest_admin_contact((string)($row['contact_json'] ?? '')) : []; ?>
      <tr>
        <td data-label="<?= study_interest_h($canViewContacts ? __('Participant') : __('Session')) ?>" class="sie-participant-cell"><strong><?= study_interest_h($canViewContacts ? (string)($contact['name'] ?? __('Unnamed participant')) : substr((string)$row['public_id'], 0, 12) . '...') ?></strong><?php if ($canViewContacts): ?><small><?= study_interest_h((string)($contact['school'] ?? '')) ?><?= !empty($contact['class_level']) ? ' · ' . study_interest_h((string)$contact['class_level']) : '' ?></small><?php endif; ?><div><code><?= study_interest_h(substr((string)$row['public_id'], 0, 12)) ?>&hellip;</code></div></td>
        <td data-label="<?= study_interest_h(__('Version')) ?>"><b><?= study_interest_h((string)$row['version_code']) ?></b><?php if ($canViewResults && (int)$row['result_revision'] > 0): ?><small><?= study_interest_h(sprintf(__('Revision %d'), (int)$row['result_revision'])) ?></small><?php endif; ?></td>
        <td data-label="<?= study_interest_h(__('Status / progress')) ?>"><span class="sie-status is-<?= study_interest_h((string)$row['status']) ?>"><i></i><?= study_interest_h((string)$row['status'] === 'completed' ? __('Completed') : __('In progress')) ?></span><small><?= (int)$row['answer_count'] ?>/<?= (int)$row['expected_question_count'] ?> <?= study_interest_h(__('answered')) ?></small></td>
        <td data-label="<?= study_interest_h(__('Result')) ?>"><?php if (!$canViewResults): ?><span class="sie-muted"><?= study_interest_h(__('Restricted')) ?></span><?php elseif ($row['top_program'] !== null): ?><b><?= study_interest_h($programLabels[(int)$row['version_id']][(string)$row['top_program']] ?? (string)$row['top_program']) ?></b><small><?= number_format((float)$row['top_score'], 1) ?> / 100 &middot; <?= (int)$row['flag_count'] ?> <?= study_interest_h(__('flag(s)')) ?></small><?php else: ?><span class="sie-muted"><?= study_interest_h(__('Not available')) ?></span><?php endif; ?></td>
        <td data-label="<?= study_interest_h(__('Activity')) ?>"><?= study_interest_h(study_interest_admin_datetime((string)$row['last_activity_at_utc'])) ?></td>
        <td data-label="<?= study_interest_h(__('Action')) ?>"><div class="sie-row-actions"><a class="sie-row-action" href="<?= study_interest_h(study_interest_admin_url('sessions/view', ['s' => (string)$row['public_id']])) ?>"><?= study_interest_h(__('Details')) ?> <span aria-hidden="true">&rarr;</span></a><?php if ($canManageContacts): ?><a class="sie-row-action" href="<?= study_interest_h(study_interest_admin_url('participants/edit', ['s' => (string)$row['public_id']])) ?>"><?= study_interest_h(__('Edit participant')) ?></a><?php endif; ?></div></td>
      </tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?php if ($pages > 1): ?><nav class="sie-pagination" aria-label="<?= study_interest_h(__('Session pages')) ?>"><?php if ($page > 1): ?><a href="<?= study_interest_h(study_interest_admin_page_url('sessions', $query, $page - 1)) ?>"><?= study_interest_h(__('Previous')) ?></a><?php endif; ?><span><?= study_interest_h(sprintf(__('Page %d of %d'), $page, $pages)) ?></span><?php if ($page < $pages): ?><a href="<?= study_interest_h(study_interest_admin_page_url('sessions', $query, $page + 1)) ?>"><?= study_interest_h(__('Next')) ?></a><?php endif; ?></nav><?php endif; ?>
</div>
