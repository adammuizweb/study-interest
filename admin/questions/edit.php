<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.config.view', false);

$versionId = filter_var($_GET['version_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($versionId === false || $versionId === null) { http_response_code(404); echo study_interest_h(__('Assessment version not found.')); return; }
$statement = $pdo->prepare('SELECT v.*,t.title AS test_title FROM study_interest_test_versions v JOIN study_interest_tests t ON t.id=v.test_id WHERE v.id=? LIMIT 1');
$statement->execute([(int)$versionId]);
$version = $statement->fetch(PDO::FETCH_ASSOC);
if (!is_array($version)) { http_response_code(404); echo study_interest_h(__('Assessment version not found.')); return; }
$configuration = json_decode((string)$version['configuration_json'], true);
if (!is_array($configuration)) throw new RuntimeException('Stored assessment configuration is invalid.');
if (!hash_equals((string)$version['configuration_hash'], hash('sha256', study_interest_configuration_json($configuration)))) throw new RuntimeException('Assessment configuration integrity check failed.');
$requestedCode = trim((string)($_GET['code'] ?? ''));
$question = null;
foreach ($configuration['questions'] ?? [] as $candidate) if ((string)($candidate['code'] ?? '') === $requestedCode) { $question = $candidate; break; }
$isNew = $requestedCode === '';
if (!$isNew && !is_array($question)) { http_response_code(404); echo study_interest_h(__('Question not found.')); return; }
$canManage = study_interest_admin_can('plugin.study-interest.config.manage');
$editable = (string)$version['status'] === 'draft' && $canManage;
if ($isNew && !$editable) { http_response_code(403); echo study_interest_h(__('A question can only be added to an editable draft.')); return; }
$question ??= ['code' => '', 'section' => array_key_first($configuration['sections'] ?? []) ?: '', 'title' => '', 'prompt' => '', 'type' => 'single_choice', 'dimension' => '', 'is_reverse' => false, 'options' => []];
$options = is_array($question['options'] ?? null) ? array_values($question['options']) : [];
if ($isNew) while (count($options) < 5) $options[] = ['code' => '', 'label' => '', 'scores' => []];
?>
<div class="sie-admin sie-workspace-page">
  <?php study_interest_admin_nav('questions'); ?>
  <a class="sie-back-link" href="<?= study_interest_h(study_interest_admin_url('questions', ['version_id' => (int)$versionId])) ?>"><span aria-hidden="true">&larr;</span> <?= study_interest_h(__('Question bank')) ?></a>
  <header class="sie-admin-head"><div><span class="sie-admin-eyebrow"><?= study_interest_h((string)$version['version_code']) ?> &middot; <?= study_interest_h(ucfirst((string)$version['status'])) ?></span><h1 class="page-heading"><?= study_interest_h($isNew ? __('Add question') : (string)$question['code']) ?></h1><p><?= study_interest_h($editable ? __('Edit the participant-facing prompt, answer options, and hidden scoring. Changes remain private until this draft is published.') : __('Inspect the exact prompt and scoring model frozen into this release.')) ?></p></div><?php if (!$editable): ?><div class="sie-admin-actions"><a class="adam-button secondary" href="<?= study_interest_h(study_interest_admin_url('assessments', ['version_id' => (int)$versionId])) ?>"><?= study_interest_h(__('Create editable draft')) ?></a></div><?php endif; ?></header>

  <?php if (!$editable): ?><div class="sie-callout"><strong><?= study_interest_h(__('Read-only version')) ?></strong><p><?= study_interest_h(__('Published and retired questions cannot be edited in place. Create a draft to add, edit, or delete questions and answers.')) ?></p></div><?php endif; ?>

  <form class="sie-editor" method="post" action="<?= study_interest_h(study_interest_admin_action_url('questions/save')) ?>">
    <input type="hidden" name="csrf_token" value="<?= study_interest_h(csrf_token()) ?>"><input type="hidden" name="version_id" value="<?= (int)$versionId ?>"><input type="hidden" name="original_code" value="<?= study_interest_h($requestedCode) ?>"><input type="hidden" name="expected_hash" value="<?= study_interest_h((string)$version['configuration_hash']) ?>">
    <div class="sie-editor-layout">
      <div class="sie-editor-main">
        <section class="sie-editor-panel"><div class="sie-panel-title"><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Participant content')) ?></span><h2><?= study_interest_h(__('Prompt')) ?></h2></div></div><label><span><?= study_interest_h(__('Question title')) ?></span><input class="adam-input" name="title" maxlength="255" value="<?= study_interest_h((string)($question['title'] ?? '')) ?>" <?= $editable ? '' : 'readonly' ?>></label><label><span><?= study_interest_h(__('Question prompt')) ?></span><textarea class="adam-input sie-prompt-input" name="prompt" maxlength="4000" required <?= $editable ? '' : 'readonly' ?>><?= study_interest_h((string)$question['prompt']) ?></textarea></label></section>

        <section class="sie-editor-panel">
          <div class="sie-panel-title"><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Answer model')) ?></span><h2><?= study_interest_h(__('Answer options and hidden scores')) ?></h2></div><strong data-sie-option-count><?= count($options) ?></strong></div>
          <p class="sie-panel-note"><?= study_interest_h((string)($configuration['algorithm_version'] ?? '') === 'baseline-1.0' ? __('Add, edit, or remove answer choices here. Every saved option needs a unique code, participant label, and at least one dimension score.') : __('Add, edit, or remove answer choices here. Scores may be zero; publication checks ensure every dimension is meaningfully measured.')) ?></p>
          <div class="sie-option-editor" data-sie-option-editor data-next-index="<?= count($options) ?>">
            <?php foreach ($options as $index => $option): ?>
              <article data-sie-option-row>
                <div class="sie-option-row-title"><strong><?= study_interest_h(sprintf(__('Answer %d'), $index + 1)) ?></strong><?php if ($editable): ?><button class="sie-row-action" type="button" data-sie-remove-option><?= study_interest_h(__('Remove answer')) ?></button><?php endif; ?></div>
                <div class="sie-option-head"><label><span><?= study_interest_h(__('Code')) ?></span><input class="adam-input" name="options[<?= $index ?>][code]" maxlength="40" value="<?= study_interest_h((string)($option['code'] ?? '')) ?>" <?= $editable ? '' : 'readonly' ?>></label><label><span><?= study_interest_h(__('Participant label')) ?></span><input class="adam-input" name="options[<?= $index ?>][label]" maxlength="500" value="<?= study_interest_h((string)($option['label'] ?? '')) ?>" <?= $editable ? '' : 'readonly' ?>></label></div>
                <details <?= $index === 0 ? 'open' : '' ?>><summary><?= study_interest_h(__('Dimension scores')) ?></summary><div class="sie-score-grid"><?php foreach ($configuration['dimensions'] as $dimensionCode => $dimension): ?><label><span><b><?= study_interest_h((string)$dimensionCode) ?></b><?= study_interest_h((string)$dimension['label']) ?></span><input class="adam-input" type="number" step="0.0001" min="-1000" max="1000" name="options[<?= $index ?>][scores][<?= study_interest_h((string)$dimensionCode) ?>]" value="<?= study_interest_h((string)($option['scores'][$dimensionCode] ?? 0)) ?>" <?= $editable ? '' : 'readonly' ?>></label><?php endforeach; ?></div></details>
              </article>
            <?php endforeach; ?>
          </div>
          <?php if ($editable): ?><button class="adam-button secondary sie-add-option" type="button" data-sie-add-option><?= study_interest_h(__('Add answer option')) ?></button><?php endif; ?>
        </section>
      </div>

      <aside class="sie-editor-side">
        <section class="sie-editor-panel"><span class="sie-admin-eyebrow"><?= study_interest_h(__('Question settings')) ?></span><label><span><?= study_interest_h(__('Question code')) ?></span><input class="adam-input" name="question_code" maxlength="40" pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,39}" required value="<?= study_interest_h((string)$question['code']) ?>" <?= $editable ? '' : 'readonly' ?>></label><label><span><?= study_interest_h(__('Section')) ?></span><select class="adam-input" name="section" <?= $editable ? '' : 'disabled' ?>><?php foreach ($configuration['sections'] as $code => $section): ?><option value="<?= study_interest_h((string)$code) ?>" <?= (string)$question['section'] === (string)$code ? 'selected' : '' ?>><?= study_interest_h((string)$code . ' · ' . (string)$section['label']) ?></option><?php endforeach; ?></select></label><label><span><?= study_interest_h(__('Question type')) ?></span><select class="adam-input" name="question_type" <?= $editable ? '' : 'disabled' ?>><option value="likert" <?= (string)$question['type'] === 'likert' ? 'selected' : '' ?>>Likert</option><option value="single_choice" <?= (string)$question['type'] === 'single_choice' ? 'selected' : '' ?>>Single choice</option></select></label><label><span><?= study_interest_h(__('Primary dimension')) ?></span><select class="adam-input" name="dimension" <?= $editable ? '' : 'disabled' ?>><option value=""><?= study_interest_h(__('Not applicable')) ?></option><?php foreach ($configuration['dimensions'] as $code => $dimension): ?><option value="<?= study_interest_h((string)$code) ?>" <?= (string)($question['dimension'] ?? '') === (string)$code ? 'selected' : '' ?>><?= study_interest_h((string)$code . ' · ' . (string)$dimension['label']) ?></option><?php endforeach; ?></select></label><label class="sie-check-field"><input type="checkbox" name="is_reverse" value="1" <?= !empty($question['is_reverse']) ? 'checked' : '' ?> <?= $editable ? '' : 'disabled' ?>><span><?= study_interest_h(__('Reverse score this item')) ?></span></label><?php if ($editable): ?><button class="adam-button sie-save-button" type="submit"><?= study_interest_h($isNew ? __('Create question') : __('Save question')) ?></button><?php endif; ?></section>
      </aside>
    </div>
  </form>

  <?php if ($editable): ?>
    <template data-sie-option-template><article data-sie-option-row><div class="sie-option-row-title"><strong><?= study_interest_h(__('New answer')) ?></strong><button class="sie-row-action" type="button" data-sie-remove-option><?= study_interest_h(__('Remove answer')) ?></button></div><div class="sie-option-head"><label><span><?= study_interest_h(__('Code')) ?></span><input class="adam-input" name="options[__INDEX__][code]" maxlength="40"></label><label><span><?= study_interest_h(__('Participant label')) ?></span><input class="adam-input" name="options[__INDEX__][label]" maxlength="500"></label></div><details open><summary><?= study_interest_h(__('Dimension scores')) ?></summary><div class="sie-score-grid"><?php foreach ($configuration['dimensions'] as $dimensionCode => $dimension): ?><label><span><b><?= study_interest_h((string)$dimensionCode) ?></b><?= study_interest_h((string)$dimension['label']) ?></span><input class="adam-input" type="number" step="0.0001" min="-1000" max="1000" name="options[__INDEX__][scores][<?= study_interest_h((string)$dimensionCode) ?>]" value="0"></label><?php endforeach; ?></div></details></article></template>
  <?php endif; ?>

  <?php if ($editable && !$isNew): ?><section class="sie-editor-panel sie-danger-zone"><span class="sie-admin-eyebrow"><?= study_interest_h(__('Danger zone')) ?></span><h2><?= study_interest_h(__('Delete question')) ?></h2><p class="sie-panel-note"><?= study_interest_h(__('Removes this prompt, every answer option, and its hidden scores from the current draft.')) ?></p><form method="post" action="<?= study_interest_h(study_interest_admin_action_url('questions/delete')) ?>" data-sie-confirm data-sie-confirm-title="<?= study_interest_h(__('Delete this draft question?')) ?>" data-sie-confirm-message="<?= study_interest_h(__('The question and its answer options will be removed from this draft.')) ?>" data-sie-confirm-text="<?= study_interest_h(__('Delete question')) ?>"><input type="hidden" name="csrf_token" value="<?= study_interest_h(csrf_token()) ?>"><input type="hidden" name="version_id" value="<?= (int)$versionId ?>"><input type="hidden" name="original_code" value="<?= study_interest_h($requestedCode) ?>"><input type="hidden" name="expected_hash" value="<?= study_interest_h((string)$version['configuration_hash']) ?>"><button class="adam-button secondary sie-danger-button" type="submit"><?= study_interest_h(__('Delete question')) ?></button></form></section><?php endif; ?>
</div>
