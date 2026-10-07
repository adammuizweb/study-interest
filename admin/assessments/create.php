<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.config.manage', false);
$baseline = study_interest_baseline_configuration();
?>
<div class="sie-admin sie-workspace-page">
  <?php study_interest_admin_nav('assessments'); ?>
  <a class="sie-back-link" href="<?= study_interest_h(study_interest_admin_url('assessments')) ?>"><span aria-hidden="true">&larr;</span> <?= study_interest_h(__('Assessments')) ?></a>
  <header class="sie-admin-head"><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('New assessment')) ?></span><h1 class="page-heading"><?= study_interest_h(__('Create an editable assessment')) ?></h1><p><?= study_interest_h(__('Start from the packaged study-interest model, then edit its identity, questions, answer options, scoring, and result messages before publication.')) ?></p></div></header>
  <form class="sie-assessment-editor" method="post" action="<?= study_interest_h(study_interest_admin_action_url('assessments/create-save')) ?>">
    <input type="hidden" name="csrf_token" value="<?= study_interest_h(csrf_token()) ?>">
    <section class="sie-editor-panel">
      <div class="sie-panel-title"><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Identity')) ?></span><h2><?= study_interest_h(__('Test and first draft')) ?></h2></div></div>
      <div class="sie-form-grid">
        <label><span><?= study_interest_h(__('Test code')) ?></span><input class="adam-input" name="test_code" maxlength="80" pattern="[A-Za-z0-9][A-Za-z0-9._-]{0,79}" required placeholder="career-interest"></label>
        <label><span><?= study_interest_h(__('Version code')) ?></span><input class="adam-input" name="version_code" maxlength="80" pattern="[A-Za-z0-9][A-Za-z0-9._-]{0,79}" required placeholder="career-interest-1.0"></label>
        <label class="is-wide"><span><?= study_interest_h(__('Test name')) ?></span><input class="adam-input" name="title" maxlength="255" required value="<?= study_interest_h((string)($baseline['title'] ?? '')) ?>"></label>
        <label class="is-wide"><span><?= study_interest_h(__('Description')) ?></span><textarea class="adam-input" name="description" maxlength="2000"><?= study_interest_h((string)($baseline['description'] ?? '')) ?></textarea></label>
      </div>
    </section>
    <div class="sie-callout"><strong><?= study_interest_h(__('Nothing is published automatically')) ?></strong><p><?= study_interest_h(__('The new assessment is created as a complete draft. You can add or remove questions and answer options before publishing it.')) ?></p></div>
    <div class="sie-sticky-save"><div><strong><?= study_interest_h(__('New assessment draft')) ?></strong><span><?= study_interest_h(__('Creates an independent test identity and editable first version.')) ?></span></div><button class="adam-button" type="submit"><?= study_interest_h(__('Create assessment')) ?></button></div>
  </form>
</div>
