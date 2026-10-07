<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.config.view', false);

$versions = $pdo->query('SELECT id,version_code,status FROM study_interest_test_versions ORDER BY (status=\'draft\') DESC,(status=\'published\') DESC,id DESC')->fetchAll(PDO::FETCH_ASSOC);
$versionId = filter_var($_GET['version_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$selected = null;
foreach ($versions as $version) if ($versionId && (int)$version['id'] === (int)$versionId) { $selected = $version; break; }
if ($selected === null && $versions !== []) $selected = $versions[0];
$versionId = $selected !== null ? (int)$selected['id'] : 0;
$section = trim((string)($_GET['section'] ?? ''));
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 120);
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 25;
$sections = [];
$rows = [];
$total = 0;
if ($versionId > 0) {
    $sectionStatement = $pdo->prepare('SELECT code,label FROM study_interest_sections WHERE version_id=? ORDER BY display_order,id');
    $sectionStatement->execute([$versionId]);
    $sections = $sectionStatement->fetchAll(PDO::FETCH_ASSOC);
    $validSections = array_column($sections, 'code');
    if ($section !== '' && !in_array($section, $validSections, true)) $section = '';
    $where = ['q.version_id=?'];
    $params = [$versionId];
    if ($section !== '') { $where[] = 's.code=?'; $params[] = $section; }
    if ($q !== '') { $where[] = '(q.question_code LIKE ? OR q.title LIKE ? OR q.prompt LIKE ?)'; $needle = '%' . $q . '%'; array_push($params, $needle, $needle, $needle); }
    $whereSql = implode(' AND ', $where);
    $count = $pdo->prepare("SELECT COUNT(*) FROM study_interest_questions q JOIN study_interest_sections s ON s.id=q.section_id WHERE {$whereSql}");
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $statement = $pdo->prepare("SELECT q.*,s.code AS section_code,s.label AS section_label,(SELECT COUNT(*) FROM study_interest_options o WHERE o.question_id=q.id) AS option_count FROM study_interest_questions q JOIN study_interest_sections s ON s.id=q.section_id WHERE {$whereSql} ORDER BY s.display_order,q.display_order,q.id LIMIT {$perPage} OFFSET {$offset}");
    $statement->execute($params);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
}
$pages = max(1, (int)ceil($total / $perPage));
$canManage = study_interest_admin_can('plugin.study-interest.config.manage');
$editable = $selected !== null && (string)$selected['status'] === 'draft' && $canManage;
$query = ['version_id' => $versionId, 'section' => $section, 'q' => $q];
?>
<div class="sie-admin sie-workspace-page">
  <?php study_interest_admin_nav('questions'); ?>
  <header class="sie-admin-head"><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Authoring')) ?></span><h1 class="page-heading"><?= study_interest_h(__('Question bank')) ?></h1><p><?= study_interest_h(__('Review prompts, answer options, reverse items, and hidden dimension scoring for every version.')) ?></p></div><?php if ($editable): ?><div class="sie-admin-actions"><a class="adam-button" href="<?= study_interest_h(study_interest_admin_url('questions/edit', ['version_id' => $versionId])) ?>"><?= study_interest_h(__('Add question')) ?></a></div><?php endif; ?></header>

  <form class="sie-list-filter" method="get" action="<?= study_interest_h(rtrim((string)ADMIN_BASE_PATH, '/') . '/') ?>"><input type="hidden" name="page" value="admin/tools/study-interest/questions"><label><span><?= study_interest_h(__('Version')) ?></span><select class="adam-input" name="version_id"><?php foreach ($versions as $version): ?><option value="<?= (int)$version['id'] ?>" <?= (int)$version['id'] === $versionId ? 'selected' : '' ?>><?= study_interest_h((string)$version['version_code']) ?> &middot; <?= study_interest_h(ucfirst((string)$version['status'])) ?></option><?php endforeach; ?></select></label><label><span><?= study_interest_h(__('Section')) ?></span><select class="adam-input" name="section"><option value=""><?= study_interest_h(__('All sections')) ?></option><?php foreach ($sections as $item): ?><option value="<?= study_interest_h((string)$item['code']) ?>" <?= (string)$item['code'] === $section ? 'selected' : '' ?>><?= study_interest_h((string)$item['code'] . ' · ' . (string)$item['label']) ?></option><?php endforeach; ?></select></label><label class="is-search"><span><?= study_interest_h(__('Search')) ?></span><input class="adam-input" name="q" value="<?= study_interest_h($q) ?>" placeholder="<?= study_interest_h(__('Code, title, or prompt')) ?>"></label><div><button class="adam-button" type="submit"><?= study_interest_h(__('Filter')) ?></button><a class="adam-button secondary" href="<?= study_interest_h(study_interest_admin_url('questions', ['version_id' => $versionId])) ?>"><?= study_interest_h(__('Reset')) ?></a></div></form>

  <?php if ($selected !== null && (string)$selected['status'] !== 'draft'): ?><div class="sie-callout"><strong><?= study_interest_h(__('Immutable release')) ?></strong><p><?= study_interest_h(__('This question bank is read-only. Create a draft before adding, editing, or deleting questions and answer options.')) ?></p><?php if ($canManage): ?><a class="sie-row-action" href="<?= study_interest_h(study_interest_admin_url('assessments', ['version_id' => (int)$selected['id']])) ?>"><?= study_interest_h(__('Create editable draft')) ?> <span aria-hidden="true">&rarr;</span></a><?php endif; ?></div><?php endif; ?>
  <div class="sie-resultbar"><span><?= study_interest_h(sprintf(__('Showing %d of %d questions.'), count($rows), $total)) ?></span><strong><?= study_interest_h($selected !== null ? (string)$selected['version_code'] : __('No version')) ?></strong></div>
  <div class="sie-admin-table"><table><thead><tr><th><?= study_interest_h(__('Question')) ?></th><th><?= study_interest_h(__('Section')) ?></th><th><?= study_interest_h(__('Type')) ?></th><th><?= study_interest_h(__('Scoring')) ?></th><th><?= study_interest_h(__('Action')) ?></th></tr></thead><tbody><?php if ($rows === []): ?><tr><td colspan="5"><div class="sie-table-empty"><strong><?= study_interest_h(__('No questions match this view')) ?></strong></div></td></tr><?php endif; ?><?php foreach ($rows as $row): ?><tr><td data-label="<?= study_interest_h(__('Question')) ?>" class="sie-question-cell"><code><?= study_interest_h((string)$row['question_code']) ?></code><strong><?= study_interest_h((string)($row['title'] ?: $row['prompt'])) ?></strong><?php if ($row['title']): ?><small><?= study_interest_h((string)$row['prompt']) ?></small><?php endif; ?></td><td data-label="<?= study_interest_h(__('Section')) ?>"><b><?= study_interest_h((string)$row['section_code']) ?></b><small><?= study_interest_h((string)$row['section_label']) ?></small></td><td data-label="<?= study_interest_h(__('Type')) ?>"><?= study_interest_h((string)$row['question_type']) ?><?php if ((int)$row['is_reverse'] === 1): ?><small><?= study_interest_h(__('Reverse scored')) ?></small><?php endif; ?></td><td data-label="<?= study_interest_h(__('Scoring')) ?>"><?= (int)$row['option_count'] ?> <?= study_interest_h(__('options')) ?></td><td data-label="<?= study_interest_h(__('Action')) ?>"><a class="sie-row-action" href="<?= study_interest_h(study_interest_admin_url('questions/edit', ['version_id' => $versionId, 'code' => (string)$row['question_code']])) ?>"><?= study_interest_h($editable ? __('Edit') : __('Inspect')) ?> <span aria-hidden="true">&rarr;</span></a></td></tr><?php endforeach; ?></tbody></table></div>
  <?php if ($pages > 1): ?><nav class="sie-pagination" aria-label="<?= study_interest_h(__('Question pages')) ?>"><?php if ($page > 1): ?><a href="<?= study_interest_h(study_interest_admin_page_url('questions', $query, $page - 1)) ?>"><?= study_interest_h(__('Previous')) ?></a><?php endif; ?><span><?= study_interest_h(sprintf(__('Page %d of %d'), $page, $pages)) ?></span><?php if ($page < $pages): ?><a href="<?= study_interest_h(study_interest_admin_page_url('questions', $query, $page + 1)) ?>"><?= study_interest_h(__('Next')) ?></a><?php endif; ?></nav><?php endif; ?>
</div>
