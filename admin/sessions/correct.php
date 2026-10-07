<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.results.correct', false);
adiwira_require_permission($pdo, 'plugin.study-interest.responses.view', false);
adiwira_require_permission($pdo, 'plugin.study-interest.results.view', false);
$publicId = trim((string)($_GET['s'] ?? ''));
if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $publicId) !== 1) { http_response_code(404); echo study_interest_h(__('Session not found.')); return; }
$canViewContacts = study_interest_admin_can('plugin.study-interest.contacts.view');
$contactColumn = $canViewContacts ? 's.contact_json' : 'NULL AS contact_json';
$statement = $pdo->prepare("SELECT s.id,s.public_id,s.status,s.result_revision,{$contactColumn},v.version_code,v.algorithm_version,v.configuration_hash,v.configuration_json FROM study_interest_sessions s JOIN study_interest_test_versions v ON v.id=s.version_id WHERE s.public_id=? LIMIT 1");
$statement->execute([$publicId]); $session = $statement->fetch(PDO::FETCH_ASSOC);
if (!is_array($session) || (string)$session['status'] !== 'completed') { http_response_code(404); echo study_interest_h(__('Completed session not found.')); return; }
$configuration = study_interest_configuration_from_session($session);
$answers = study_interest_effective_answer_map($pdo, (int)$session['id'], (int)$session['result_revision']);
$contact = $canViewContacts ? study_interest_admin_contact((string)($session['contact_json'] ?? '')) : [];
?>
<div class="sie-admin sie-workspace-page">
  <?php study_interest_admin_nav('sessions'); ?>
  <a class="sie-back-link" href="<?= study_interest_h(study_interest_admin_url('sessions/view', ['s' => $publicId])) ?>"><span aria-hidden="true">&larr;</span> <?= study_interest_h(__('Session detail')) ?></a>
  <header class="sie-admin-head"><div><span class="sie-admin-eyebrow"><?= study_interest_h((string)$session['version_code']) ?> &middot; <?= study_interest_h(sprintf(__('Current revision %d'), (int)$session['result_revision'])) ?></span><h1 class="page-heading"><?= study_interest_h(__('Correct responses')) ?></h1><p><?= study_interest_h(__('Change only responses that are known to be incorrect. Scores and recommendations will be recalculated with the original assessment version.')) ?></p></div></header>
  <div class="sie-callout is-warning"><strong><?= study_interest_h(__('This is a controlled correction, not free-form score editing.')) ?></strong><p><?= study_interest_h(__('The original answers and result snapshot remain in append-only revision history. A reason is mandatory and every change is audited.')) ?></p></div>
  <form class="sie-correction-form" method="post" action="<?= study_interest_h(study_interest_admin_action_url('sessions/save-correction')) ?>" data-sie-confirm data-sie-confirm-title="<?= study_interest_h(__('Create a corrected result revision?')) ?>" data-sie-confirm-message="<?= study_interest_h(__('The participant-facing result and analytics will use the recalculated revision. Original evidence remains preserved.')) ?>" data-sie-confirm-text="<?= study_interest_h(__('Save correction')) ?>">
    <input type="hidden" name="csrf_token" value="<?= study_interest_h(csrf_token()) ?>"><input type="hidden" name="session_public_id" value="<?= study_interest_h($publicId) ?>"><input type="hidden" name="expected_revision" value="<?= (int)$session['result_revision'] ?>">
    <section class="sie-correction-meta"><div><span><?= study_interest_h(__('Participant')) ?></span><strong><?= study_interest_h((string)($contact['name'] ?? substr($publicId, 0, 12) . '…')) ?></strong></div><label><span><?= study_interest_h(__('Correction reason')) ?></span><textarea class="adam-input" name="reason" minlength="10" maxlength="500" required placeholder="<?= study_interest_h(__('Explain what was corrected and how it was verified.')) ?>"></textarea></label></section>
    <div class="sie-correction-list"><?php foreach ($configuration['questions'] ?? [] as $index => $question): ?><article><span><?= str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) ?></span><div><small><?= study_interest_h((string)$question['code'] . ' · ' . (string)$question['section']) ?><?= !empty($question['is_reverse']) ? ' · ' . study_interest_h(__('reverse item')) : '' ?></small><h2><?= study_interest_h((string)($question['title'] ?? $question['prompt'])) ?></h2><?php if (!empty($question['title'])): ?><p><?= study_interest_h((string)$question['prompt']) ?></p><?php endif; ?><label><span><?= study_interest_h(__('Effective answer')) ?></span><select class="adam-input" name="answers[<?= study_interest_h((string)$question['code']) ?>]" required><?php foreach ($question['options'] ?? [] as $option): ?><option value="<?= study_interest_h((string)$option['code']) ?>" <?= (string)($answers[(string)$question['code']] ?? '') === (string)$option['code'] ? 'selected' : '' ?>><?= study_interest_h((string)$option['code'] . ' · ' . (string)$option['label']) ?></option><?php endforeach; ?></select></label></div></article><?php endforeach; ?></div>
    <div class="sie-sticky-save"><div><strong><?= study_interest_h(__('Result correction')) ?></strong><span><?= study_interest_h(__('Recalculate using the frozen version and preserve the original revision.')) ?></span></div><button class="adam-button" type="submit"><?= study_interest_h(__('Review and save correction')) ?></button></div>
  </form>
</div>
