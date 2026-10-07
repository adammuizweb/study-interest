<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.presentation.view', false);
$policy = study_interest_result_presentation($pdo);
$canManage = study_interest_admin_can('plugin.study-interest.presentation.manage');
$modeLabels = [
    'full' => [__('Full result'), __('Show the result page and apply the section rules below.')],
    'masked' => [__('Masked page'), __('Show a blurred, non-sensitive result preview with a managed-access notice.')],
    'hidden' => [__('Hidden page'), __('Show only a minimal unavailable-result state to participants.')],
];
$sections = [
    'hero' => [__('Profile overview'), __('Opening statement and the three strongest interest dimensions.')],
    'directions' => [__('Study directions'), __('Closest programs, interest indexes, and classifications.')],
    'dimensions' => [__('Dimension map'), __('The complete dimension score list and visual meters.')],
    'interpretation' => [__('Pattern interpretation'), __('Context shown when leading study directions are close together.')],
    'pathways' => [__('Professional pathways'), __('Additional professional or continuation pathways when applicable.')],
    'next_steps' => [__('Next steps'), __('Guidance for discussing and exploring the result further.')],
    'disclaimer' => [__('Result disclaimer'), __('The explanatory note about the exploratory nature of the result.')],
];
$stateLabels = ['show' => __('Show'), 'mask' => __('Mask'), 'hide' => __('Hide')];
?>
<div class="sie-admin sie-workspace-page">
  <?php study_interest_admin_nav('result-page'); ?>
  <header class="sie-admin-head"><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Participant experience')) ?></span><h1 class="page-heading"><?= study_interest_h(__('Result page')) ?></h1><p><?= study_interest_h(__('Control what completed participants can see without changing their scores, snapshots, or assessment versions.')) ?></p></div><div class="sie-admin-actions"><a class="adam-button secondary" href="/study-interest/" target="_blank" rel="noopener"><?= study_interest_h(__('Open public assessment')) ?> <span aria-hidden="true">&nearr;</span></a></div></header>

  <section class="sie-guidance-card"><strong><?= study_interest_h(__('Safe masking')) ?></strong><p><?= study_interest_h(__('Masked sections show a blurred, non-sensitive preview generated on the server. Actual labels, scores, and interpretations are not sent in the participant-facing HTML. Hidden sections are omitted completely.')) ?></p></section>

  <form class="sie-presentation-form" method="post" action="<?= study_interest_h(study_interest_admin_action_url('result-page/save')) ?>">
    <input type="hidden" name="csrf_token" value="<?= study_interest_h(csrf_token()) ?>"><input type="hidden" name="expected_hash" value="<?= study_interest_h(study_interest_result_presentation_hash($policy)) ?>">
    <section class="sie-editor-panel"><div class="sie-panel-title"><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Page availability')) ?></span><h2><?= study_interest_h(__('Overall participant access')) ?></h2></div><span class="sie-state-pill is-<?= study_interest_h((string)$policy['mode'] === 'full' ? 'ready' : 'pending') ?>"><?= study_interest_h($modeLabels[(string)$policy['mode']][0]) ?></span></div><div class="sie-policy-modes"><?php foreach ($modeLabels as $mode => [$label, $description]): ?><label class="sie-policy-mode"><input type="radio" name="mode" value="<?= study_interest_h($mode) ?>" <?= (string)$policy['mode'] === $mode ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>><span><b><?= study_interest_h($label) ?></b><small><?= study_interest_h($description) ?></small></span></label><?php endforeach; ?></div></section>

    <section class="sie-editor-panel"><div class="sie-panel-title"><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Section rules')) ?></span><h2><?= study_interest_h(__('Fine-grained visibility')) ?></h2></div><strong><?= count($sections) ?></strong></div><p class="sie-panel-note"><?= study_interest_h(__('These rules apply only when overall access is Full result. Show renders the section, Mask shows a safe blurred preview, and Hide omits it.')) ?></p><div class="sie-policy-sections"><?php foreach ($sections as $key => [$label, $description]): ?><article><div><strong><?= study_interest_h($label) ?></strong><small><?= study_interest_h($description) ?></small></div><div class="sie-segment-control" role="group" aria-label="<?= study_interest_h($label) ?>"><?php foreach ($stateLabels as $state => $stateLabel): ?><label><input type="radio" name="sections[<?= study_interest_h($key) ?>]" value="<?= study_interest_h($state) ?>" <?= (string)$policy['sections'][$key] === $state ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>><span><?= study_interest_h($stateLabel) ?></span></label><?php endforeach; ?></div></article><?php endforeach; ?></div></section>

    <section class="sie-editor-panel"><div class="sie-panel-title"><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Participant copy')) ?></span><h2><?= study_interest_h(__('Messages shown when access is limited')) ?></h2></div></div><div class="sie-form-grid"><label><span><?= study_interest_h(__('Masked page title')) ?></span><input class="adam-input" name="masked_title" maxlength="120" required value="<?= study_interest_h((string)$policy['masked_title']) ?>" <?= $canManage ? '' : 'readonly' ?>></label><label><span><?= study_interest_h(__('Hidden page title')) ?></span><input class="adam-input" name="hidden_title" maxlength="120" required value="<?= study_interest_h((string)$policy['hidden_title']) ?>" <?= $canManage ? '' : 'readonly' ?>></label><label><span><?= study_interest_h(__('Masked page message')) ?></span><textarea class="adam-input" name="masked_message" maxlength="500" required <?= $canManage ? '' : 'readonly' ?>><?= study_interest_h((string)$policy['masked_message']) ?></textarea></label><label><span><?= study_interest_h(__('Hidden page message')) ?></span><textarea class="adam-input" name="hidden_message" maxlength="500" required <?= $canManage ? '' : 'readonly' ?>><?= study_interest_h((string)$policy['hidden_message']) ?></textarea></label><label class="is-wide"><span><?= study_interest_h(__('Masked section message')) ?></span><textarea class="adam-input" name="section_mask_message" maxlength="500" required <?= $canManage ? '' : 'readonly' ?>><?= study_interest_h((string)$policy['section_mask_message']) ?></textarea></label></div></section>

    <?php if ($canManage): ?><div class="sie-sticky-save"><div><strong><?= study_interest_h(__('Global result-page policy')) ?></strong><span><?= study_interest_h(__('Changes affect existing and future completed sessions immediately and are recorded in Activity log.')) ?></span></div><button class="adam-button" type="submit"><?= study_interest_h(__('Save result page')) ?></button></div><?php else: ?><div class="sie-callout"><strong><?= study_interest_h(__('Read-only policy')) ?></strong><p><?= study_interest_h(__('You can review participant visibility but do not have permission to change it.')) ?></p></div><?php endif; ?>
  </form>
</div>
