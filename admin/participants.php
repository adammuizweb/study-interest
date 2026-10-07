<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.contacts.view', false);
$canManage = study_interest_admin_can('plugin.study-interest.contacts.manage');
$canExport = study_interest_admin_can('plugin.study-interest.workspace.export')
    && study_interest_admin_can('plugin.study-interest.sessions.view')
    && study_interest_admin_can('plugin.study-interest.results.view');
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 120);
$status = trim((string)($_GET['status'] ?? ''));
if (!in_array($status, ['', 'started', 'completed'], true)) $status = '';
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 25;
$where = ['1=1'];
$params = [];
if ($status !== '') { $where[] = 's.status=?'; $params[] = $status; }
if ($q !== '') {
    $needle = '%' . $q . '%';
    $where[] = "(s.public_id LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(s.contact_json,'$.name')) LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(s.contact_json,'$.school')) LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(s.contact_json,'$.email')) LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(s.contact_json,'$.phone')) LIKE ?)";
    array_push($params, $needle, $needle, $needle, $needle, $needle);
}
$whereSql = implode(' AND ', $where);
$count = $pdo->prepare("SELECT COUNT(*) FROM study_interest_sessions s WHERE {$whereSql}");
$count->execute($params);
$total = (int)$count->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;
$statement = $pdo->prepare("SELECT s.id,s.public_id,s.status,s.contact_consent_at_utc,s.started_at_utc,s.completed_at_utc,s.last_activity_at_utc,s.contact_json,v.version_code,
    (SELECT COUNT(*) FROM study_interest_answers a WHERE a.session_id=s.id) AS answer_count,v.expected_question_count
    FROM study_interest_sessions s JOIN study_interest_test_versions v ON v.id=s.version_id WHERE {$whereSql}
    ORDER BY s.last_activity_at_utc DESC,s.id DESC LIMIT {$perPage} OFFSET {$offset}");
$statement->execute($params);
$rows = $statement->fetchAll(PDO::FETCH_ASSOC);
$summary = $pdo->query("SELECT COUNT(*) AS total,SUM(status='completed') AS completed,SUM(contact_consent_at_utc IS NOT NULL) AS contactable FROM study_interest_sessions")->fetch(PDO::FETCH_ASSOC) ?: [];
$query = ['q' => $q, 'status' => $status];
?>
<div class="sie-admin sie-workspace-page">
  <?php study_interest_admin_nav('participants'); ?>
  <header class="sie-admin-head">
    <div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Participant records')) ?></span><h1 class="page-heading"><?= study_interest_h(__('Participants')) ?></h1><p><?= study_interest_h(__('Identify who started the assessment and open the exact session snapshot behind each participant record.')) ?></p></div>
    <?php if ($canExport): ?><form class="sie-admin-actions" method="post" action="<?= study_interest_h(study_interest_admin_action_url('workspace-export')) ?>"><input type="hidden" name="csrf_token" value="<?= study_interest_h(csrf_token()) ?>"><input type="hidden" name="dataset" value="participants"><input type="hidden" name="q" value="<?= study_interest_h($q) ?>"><input type="hidden" name="status" value="<?= study_interest_h($status) ?>"><button class="adam-button" type="submit" name="format" value="xlsx"><?= study_interest_h(__('Export Excel')) ?></button><button class="adam-button secondary" type="submit" name="format" value="csv"><?= study_interest_h(__('Export CSV')) ?></button></form><?php endif; ?>
  </header>
  <section class="sie-admin-summary"><div><span><?= study_interest_h(__('Participant records')) ?></span><strong><?= (int)($summary['total'] ?? 0) ?></strong><small><?= study_interest_h(__('one identity snapshot per session')) ?></small></div><div><span><?= study_interest_h(__('Completed')) ?></span><strong><?= (int)($summary['completed'] ?? 0) ?></strong><small><?= study_interest_h(__('with result snapshots')) ?></small></div><div><span><?= study_interest_h(__('Contact consent')) ?></span><strong><?= (int)($summary['contactable'] ?? 0) ?></strong><small><?= study_interest_h(__('may be contacted')) ?></small></div><div><span><?= study_interest_h(__('Current view')) ?></span><strong><?= $total ?></strong><small><?= study_interest_h(__('matching records')) ?></small></div></section>
  <form class="sie-list-filter is-compact" method="get" action="<?= study_interest_h(rtrim((string)ADMIN_BASE_PATH, '/') . '/') ?>"><input type="hidden" name="page" value="admin/tools/study-interest/participants"><label class="is-search"><span><?= study_interest_h(__('Search participant')) ?></span><input class="adam-input" name="q" value="<?= study_interest_h($q) ?>" placeholder="<?= study_interest_h(__('Name, school, email, phone, or session')) ?>"></label><label><span><?= study_interest_h(__('Status')) ?></span><select class="adam-input" name="status"><option value=""><?= study_interest_h(__('All statuses')) ?></option><option value="started" <?= $status === 'started' ? 'selected' : '' ?>><?= study_interest_h(__('In progress')) ?></option><option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>><?= study_interest_h(__('Completed')) ?></option></select></label><div><button class="adam-button" type="submit"><?= study_interest_h(__('Filter')) ?></button><a class="adam-button secondary" href="<?= study_interest_h(study_interest_admin_url('participants')) ?>"><?= study_interest_h(__('Reset')) ?></a></div></form>
  <div class="sie-resultbar"><span><?= study_interest_h(sprintf(__('Showing %d-%d of %d participant records.'), $total > 0 ? $offset + 1 : 0, min($offset + $perPage, $total), $total)) ?></span><?php if ($canExport): ?><span><?= study_interest_h(__('Exports use the active filters.')) ?></span><?php endif; ?></div>
  <div class="sie-admin-table"><table><thead><tr><th><?= study_interest_h(__('Participant')) ?></th><th><?= study_interest_h(__('Assessment')) ?></th><th><?= study_interest_h(__('Progress')) ?></th><th><?= study_interest_h(__('Last activity')) ?></th><th><?= study_interest_h(__('Action')) ?></th></tr></thead><tbody>
    <?php if ($rows === []): ?><tr><td colspan="5"><div class="sie-table-empty"><strong><?= study_interest_h(__('No participant records match this view')) ?></strong></div></td></tr><?php endif; ?>
    <?php foreach ($rows as $row): ?><?php $contact = study_interest_admin_contact((string)($row['contact_json'] ?? '')); ?><tr><td data-label="<?= study_interest_h(__('Participant')) ?>" class="sie-participant-cell"><strong><?= study_interest_h((string)($contact['name'] ?? __('Unnamed participant'))) ?></strong><small><?= study_interest_h((string)($contact['school'] ?? '')) ?><?= !empty($contact['class_level']) ? ' &middot; ' . study_interest_h((string)$contact['class_level']) : '' ?></small><div><?php if (!empty($contact['email'])): ?><span><?= study_interest_h((string)$contact['email']) ?></span><?php endif; ?><?php if (!empty($contact['phone'])): ?><span><?= study_interest_h((string)$contact['phone']) ?></span><?php endif; ?></div></td><td data-label="<?= study_interest_h(__('Assessment')) ?>"><b><?= study_interest_h((string)$row['version_code']) ?></b><small><code><?= study_interest_h(substr((string)$row['public_id'], 0, 8)) ?>&hellip;</code></small></td><td data-label="<?= study_interest_h(__('Progress')) ?>"><span class="sie-status is-<?= study_interest_h((string)$row['status']) ?>"><i></i><?= study_interest_h((string)$row['status'] === 'completed' ? __('Completed') : __('In progress')) ?></span><small><?= (int)$row['answer_count'] ?>/<?= (int)$row['expected_question_count'] ?> <?= study_interest_h(__('answered')) ?></small></td><td data-label="<?= study_interest_h(__('Last activity')) ?>"><?= study_interest_h(study_interest_admin_datetime((string)$row['last_activity_at_utc'])) ?></td><td data-label="<?= study_interest_h(__('Action')) ?>"><div class="sie-row-actions"><a class="sie-row-action" href="<?= study_interest_h(study_interest_admin_url('sessions/view', ['s' => (string)$row['public_id']])) ?>"><?= study_interest_h(__('Session')) ?></a><?php if ($canManage): ?><a class="sie-row-action" href="<?= study_interest_h(study_interest_admin_url('participants/edit', ['s' => (string)$row['public_id']])) ?>"><?= study_interest_h(__('Edit')) ?> <span aria-hidden="true">&rarr;</span></a><?php endif; ?></div></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if ($pages > 1): ?><nav class="sie-pagination" aria-label="<?= study_interest_h(__('Participant pages')) ?>"><?php if ($page > 1): ?><a href="<?= study_interest_h(study_interest_admin_page_url('participants', $query, $page - 1)) ?>"><?= study_interest_h(__('Previous')) ?></a><?php endif; ?><span><?= study_interest_h(sprintf(__('Page %d of %d'), $page, $pages)) ?></span><?php if ($page < $pages): ?><a href="<?= study_interest_h(study_interest_admin_page_url('participants', $query, $page + 1)) ?>"><?= study_interest_h(__('Next')) ?></a><?php endif; ?></nav><?php endif; ?>
</div>
