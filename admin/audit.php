<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.audit.view', false);
$action = mb_substr(trim((string)($_GET['action'] ?? '')), 0, 80);
$entity = mb_substr(trim((string)($_GET['entity'] ?? '')), 0, 80);
$page = max(1, (int)($_GET['p'] ?? 1)); $perPage = 50;
$where = ['1=1']; $params = [];
if ($action !== '') { $where[] = 'action=?'; $params[] = $action; }
if ($entity !== '') { $where[] = 'entity_type=?'; $params[] = $entity; }
$whereSql = implode(' AND ', $where);
$count = $pdo->prepare("SELECT COUNT(*) FROM study_interest_audit_log WHERE {$whereSql}"); $count->execute($params); $total = (int)$count->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage)); $page = min($page, $pages); $offset = ($page - 1) * $perPage;
$statement = $pdo->prepare("SELECT * FROM study_interest_audit_log WHERE {$whereSql} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}"); $statement->execute($params); $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
$actions = $pdo->query('SELECT DISTINCT action FROM study_interest_audit_log ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$entities = $pdo->query('SELECT DISTINCT entity_type FROM study_interest_audit_log ORDER BY entity_type')->fetchAll(PDO::FETCH_COLUMN);
$query = ['action' => $action, 'entity' => $entity];
$actionLabels = [
    'configuration.imported' => __('Configuration imported'), 'configuration.published' => __('Assessment published'),
    'question.created' => __('Question created'), 'question.updated' => __('Question updated'), 'question.deleted' => __('Question deleted'),
    'participant.updated' => __('Participant updated'), 'session.deleted' => __('Session deleted'),
    'result.corrected' => __('Result corrected'), 'results.exported' => __('Anonymous results exported'), 'workspace.exported' => __('Workspace data exported'),
    'result_presentation.updated' => __('Result page policy updated'),
];
$entityLabels = ['version' => __('Assessment version'), 'question' => __('Question'), 'session' => __('Participant session'), 'result_export' => __('Result export'), 'participants_export' => __('Participant export'), 'sessions_export' => __('Session export'), 'result_presentation' => __('Result page policy')];
?>
<div class="sie-admin sie-workspace-page">
  <?php study_interest_admin_nav('audit'); ?>
  <header class="sie-admin-head"><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Governance')) ?></span><h1 class="page-heading"><?= study_interest_h(__('Activity log')) ?></h1><p><?= study_interest_h(__('See who performed sensitive workspace actions, when they occurred, and what record was affected.')) ?></p></div></header>
  <section class="sie-guidance-card"><strong><?= study_interest_h(__('How to use this log')) ?></strong><p><?= study_interest_h(__('Use filters during operational review or incident follow-up. Expand change metadata to verify the recorded before-and-after evidence; participant contact values are represented by hashes rather than copied into the log.')) ?></p></section>
  <form class="sie-list-filter is-compact" method="get" action="<?= study_interest_h(rtrim((string)ADMIN_BASE_PATH, '/') . '/') ?>"><input type="hidden" name="page" value="admin/tools/study-interest/audit"><label><span><?= study_interest_h(__('Action')) ?></span><select class="adam-input" name="action"><option value=""><?= study_interest_h(__('All actions')) ?></option><?php foreach ($actions as $value): ?><option value="<?= study_interest_h((string)$value) ?>" <?= (string)$value === $action ? 'selected' : '' ?>><?= study_interest_h($actionLabels[(string)$value] ?? ucwords(str_replace(['.', '_'], ' ', (string)$value))) ?></option><?php endforeach; ?></select></label><label><span><?= study_interest_h(__('Record type')) ?></span><select class="adam-input" name="entity"><option value=""><?= study_interest_h(__('All record types')) ?></option><?php foreach ($entities as $value): ?><option value="<?= study_interest_h((string)$value) ?>" <?= (string)$value === $entity ? 'selected' : '' ?>><?= study_interest_h($entityLabels[(string)$value] ?? ucwords(str_replace('_', ' ', (string)$value))) ?></option><?php endforeach; ?></select></label><div><button class="adam-button" type="submit"><?= study_interest_h(__('Filter')) ?></button><a class="adam-button secondary" href="<?= study_interest_h(study_interest_admin_url('audit')) ?>"><?= study_interest_h(__('Reset')) ?></a></div></form>
  <div class="sie-resultbar"><span><?= study_interest_h(sprintf(__('%d activity event(s).'), $total)) ?></span></div>
  <div class="sie-audit-list"><?php if ($rows === []): ?><div class="sie-table-empty"><strong><?= study_interest_h(__('No activity events match this view')) ?></strong></div><?php endif; ?><?php foreach ($rows as $row): ?><?php $before = json_decode((string)($row['before_json'] ?? ''), true); $after = json_decode((string)($row['after_json'] ?? ''), true); ?><article><div class="sie-audit-marker"></div><div class="sie-audit-content"><div><span><?= study_interest_h($actionLabels[(string)$row['action']] ?? ucwords(str_replace(['.', '_'], ' ', (string)$row['action']))) ?></span><time><?= study_interest_h(study_interest_admin_datetime((string)$row['created_at_utc'])) ?></time></div><h2><?= study_interest_h($entityLabels[(string)$row['entity_type']] ?? ucwords(str_replace('_', ' ', (string)$row['entity_type']))) ?><?= $row['entity_id'] !== null ? ' &middot; ' . study_interest_h((string)$row['entity_id']) : '' ?></h2><p><?= study_interest_h(sprintf(__('Actor #%d'), (int)$row['actor_id'])) ?><?php if ($row['request_id']): ?> &middot; <code><?= study_interest_h((string)$row['request_id']) ?></code><?php endif; ?></p><?php if (is_array($before) || is_array($after)): ?><details><summary><?= study_interest_h(__('Change metadata')) ?></summary><div class="sie-audit-json"><?php if (is_array($before)): ?><div><b><?= study_interest_h(__('Before')) ?></b><pre><?= study_interest_h(json_encode($before, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre></div><?php endif; ?><?php if (is_array($after)): ?><div><b><?= study_interest_h(__('After')) ?></b><pre><?= study_interest_h(json_encode($after, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre></div><?php endif; ?></div></details><?php endif; ?></div></article><?php endforeach; ?></div>
  <?php if ($pages > 1): ?><nav class="sie-pagination" aria-label="<?= study_interest_h(__('Activity pages')) ?>"><?php if ($page > 1): ?><a href="<?= study_interest_h(study_interest_admin_page_url('audit', $query, $page - 1)) ?>"><?= study_interest_h(__('Previous')) ?></a><?php endif; ?><span><?= study_interest_h(sprintf(__('Page %d of %d'), $page, $pages)) ?></span><?php if ($page < $pages): ?><a href="<?= study_interest_h(study_interest_admin_page_url('audit', $query, $page + 1)) ?>"><?= study_interest_h(__('Next')) ?></a><?php endif; ?></nav><?php endif; ?>
</div>
