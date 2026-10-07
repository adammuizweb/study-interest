<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.config.view', false);

$versions = $pdo->query("SELECT v.*,t.code AS test_code,t.title AS test_title,t.description AS test_description,
    (SELECT COUNT(*) FROM study_interest_sessions s WHERE s.version_id=v.id) AS session_count
    FROM study_interest_test_versions v JOIN study_interest_tests t ON t.id=v.test_id
    ORDER BY (v.status='published') DESC,v.id DESC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($versions as &$version) {
    $versionConfiguration = json_decode((string)$version['configuration_json'], true);
    $version['configuration_title'] = is_array($versionConfiguration) && trim((string)($versionConfiguration['title'] ?? '')) !== ''
        ? trim((string)$versionConfiguration['title']) : (string)$version['test_title'];
}
unset($version);
$requested = filter_var($_GET['version_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$selected = null;
foreach ($versions as $version) if ($requested && (int)$version['id'] === (int)$requested) { $selected = $version; break; }
if ($selected === null && $versions !== []) $selected = $versions[0];
$configuration = [];
$validation = [];
if ($selected !== null) {
    $decoded = json_decode((string)$selected['configuration_json'], true);
    $configuration = is_array($decoded) ? $decoded : [];
    if ($configuration !== [] && !hash_equals((string)$selected['configuration_hash'], hash('sha256', study_interest_configuration_json($configuration)))) throw new RuntimeException('Assessment configuration integrity check failed.');
    if ((string)$selected['status'] === 'draft') $validation = study_interest_publish_errors($pdo, (int)$selected['id']);
}
$canManage = study_interest_admin_can('plugin.study-interest.config.manage');
?>
<div class="sie-admin sie-workspace-page">
  <?php study_interest_admin_nav('assessments'); ?>
  <header class="sie-admin-head">
    <div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Configuration')) ?></span><h1 class="page-heading"><?= study_interest_h(__('Assessments')) ?></h1><p><?= study_interest_h(__('Inspect immutable releases, create a working draft, and review the complete scoring model before publication.')) ?></p></div>
    <div class="sie-admin-actions"><?php if ($canManage): ?><a class="adam-button" href="<?= study_interest_h(study_interest_admin_url('assessments/create')) ?>"><?= study_interest_h(__('New assessment')) ?></a><?php endif; ?><?php if ($selected !== null && $canManage && (string)$selected['status'] === 'draft'): ?><a class="adam-button secondary" href="<?= study_interest_h(study_interest_admin_url('assessments/edit', ['version_id' => (int)$selected['id']])) ?>"><?= study_interest_h(__('Edit assessment')) ?></a><?php endif; ?><?php if ($selected !== null): ?><a class="adam-button secondary" href="<?= study_interest_h(study_interest_admin_url('questions', ['version_id' => (int)$selected['id']])) ?>"><?= study_interest_h(__('Open question bank')) ?></a><?php endif; ?></div>
  </header>

  <section class="sie-filter-bar">
    <div><span><?= study_interest_h(__('Configuration library')) ?></span><strong><?= count($versions) ?> <?= study_interest_h(__('version(s)')) ?></strong></div>
    <form method="get" action="<?= study_interest_h(rtrim((string)ADMIN_BASE_PATH, '/') . '/') ?>"><input type="hidden" name="page" value="admin/tools/study-interest/assessments"><label><span><?= study_interest_h(__('Assessment version')) ?></span><select class="adam-input" name="version_id" data-sie-autosubmit><?php foreach ($versions as $version): ?><option value="<?= (int)$version['id'] ?>" <?= $selected !== null && (int)$version['id'] === (int)$selected['id'] ? 'selected' : '' ?>><?= study_interest_h((string)$version['configuration_title'] . ' · ' . (string)$version['version_code']) ?> &middot; <?= study_interest_h(ucfirst((string)$version['status'])) ?></option><?php endforeach; ?></select></label></form>
  </section>

  <?php if ($selected === null): ?>
    <div class="sie-analytics-empty"><h2><?= study_interest_h(__('No assessment configuration yet')) ?></h2><p><?= study_interest_h(__('Create an assessment from the packaged model, then customize it entirely from this dashboard.')) ?></p><?php if ($canManage): ?><a class="adam-button" href="<?= study_interest_h(study_interest_admin_url('assessments/create')) ?>"><?= study_interest_h(__('Create assessment')) ?></a><?php endif; ?></div>
  <?php else: ?>
    <section class="sie-version-hero">
      <div><span class="sie-status is-<?= study_interest_h((string)$selected['status']) ?>"><i></i><?= study_interest_h(ucfirst((string)$selected['status'])) ?></span><h2><?= study_interest_h((string)($configuration['title'] ?? $selected['test_title'])) ?></h2><p><?= study_interest_h((string)($configuration['description'] ?? $selected['test_description'] ?? '')) ?></p></div>
      <dl><div><dt><?= study_interest_h(__('Version')) ?></dt><dd><?= study_interest_h((string)$selected['version_code']) ?></dd></div><div><dt><?= study_interest_h(__('Algorithm')) ?></dt><dd><?= study_interest_h((string)$selected['algorithm_version']) ?></dd></div><div><dt><?= study_interest_h(__('Questions')) ?></dt><dd><?= (int)$selected['expected_question_count'] ?></dd></div><div><dt><?= study_interest_h(__('Sessions')) ?></dt><dd><?= (int)$selected['session_count'] ?></dd></div></dl>
    </section>

    <?php if ($validation !== []): ?><div class="sie-callout is-warning"><strong><?= study_interest_h(__('Publication checks need attention')) ?></strong><ul><?php foreach ($validation as $error): ?><li><?= study_interest_h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <div class="sie-config-grid">
      <section class="sie-config-panel"><div class="sie-panel-title"><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Structure')) ?></span><h2><?= study_interest_h(__('Sections')) ?></h2></div><strong><?= count($configuration['sections'] ?? []) ?></strong></div><div class="sie-definition-list"><?php foreach ($configuration['sections'] ?? [] as $code => $section): ?><div><span><code><?= study_interest_h((string)$code) ?></code><b><?= study_interest_h((string)($section['label'] ?? $code)) ?></b></span><small><?= (int)($section['question_count'] ?? 0) ?> <?= study_interest_h(__('questions')) ?> &middot; <?= number_format((float)($section['weight'] ?? 0) * 100, 0) ?>%</small></div><?php endforeach; ?></div></section>
      <section class="sie-config-panel"><div class="sie-panel-title"><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Scoring model')) ?></span><h2><?= study_interest_h(__('Dimensions')) ?></h2></div><strong><?= count($configuration['dimensions'] ?? []) ?></strong></div><div class="sie-definition-list"><?php foreach ($configuration['dimensions'] ?? [] as $code => $dimension): ?><div><span><code><?= study_interest_h((string)$code) ?></code><b><?= study_interest_h((string)($dimension['label'] ?? $code)) ?></b></span><small><?= study_interest_h((string)($dimension['description'] ?? '')) ?></small></div><?php endforeach; ?></div></section>
    </div>

    <section class="sie-admin-section"><div class="sie-admin-section__head"><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Recommendation model')) ?></span><h2><?= study_interest_h(__('Programs and dimension weights')) ?></h2></div><p><?= study_interest_h(__('Weights describe how each interest dimension contributes to a study direction.')) ?></p></div><div class="sie-program-grid"><?php foreach ($configuration['programs'] ?? [] as $code => $program): ?><article><span><?= study_interest_h((string)($program['recommendation_type'] ?? '')) ?></span><h3><?= study_interest_h((string)($program['label'] ?? $code)) ?></h3><div><?php foreach ($program['weights'] ?? [] as $dimension => $weight): ?><?php if ((float)$weight <= 0) continue; ?><small><b><?= study_interest_h((string)$dimension) ?></b><?= number_format((float)$weight * 100, 0) ?>%</small><?php endforeach; ?></div></article><?php endforeach; ?></div></section>

    <?php if ($canManage && (string)$selected['status'] !== 'draft'): ?>
      <section class="sie-admin-section sie-draft-panel"><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Safe authoring')) ?></span><h2><?= study_interest_h(__('Create a new working draft')) ?></h2><p><?= study_interest_h(__('Published and retired versions stay immutable. A draft starts as an exact copy and can then be edited independently.')) ?></p></div><form method="post" action="<?= study_interest_h(study_interest_admin_action_url('clone-version')) ?>"><input type="hidden" name="csrf_token" value="<?= study_interest_h(csrf_token()) ?>"><input type="hidden" name="source_version_id" value="<?= (int)$selected['id'] ?>"><label><span><?= study_interest_h(__('New version code')) ?></span><input class="adam-input" name="version_code" required maxlength="80" pattern="[A-Za-z0-9][A-Za-z0-9._-]{0,79}" placeholder="<?= study_interest_h((string)$selected['version_code'] . '-revision-2') ?>"></label><button class="adam-button" type="submit"><?= study_interest_h(__('Create draft')) ?></button></form></section>
    <?php endif; ?>
  <?php endif; ?>
</div>
