<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.contacts.manage', false);
$publicId = trim((string)($_GET['s'] ?? ''));
if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $publicId) !== 1) { http_response_code(404); echo study_interest_h(__('Participant record not found.')); return; }
$statement = $pdo->prepare('SELECT s.id,s.public_id,s.status,s.contact_json,s.contact_consent_at_utc,s.started_at_utc,v.version_code,v.configuration_json,v.configuration_hash FROM study_interest_sessions s JOIN study_interest_test_versions v ON v.id=s.version_id WHERE s.public_id=? LIMIT 1');
$statement->execute([$publicId]);
$session = $statement->fetch(PDO::FETCH_ASSOC);
if (!is_array($session)) { http_response_code(404); echo study_interest_h(__('Participant record not found.')); return; }
$contact = study_interest_admin_contact((string)($session['contact_json'] ?? ''));
$contactHash = study_interest_participant_snapshot_hash((string)($session['contact_json'] ?? ''), $session['contact_consent_at_utc'] !== null ? (string)$session['contact_consent_at_utc'] : null);
$configuration = json_decode((string)$session['configuration_json'], true);
if (!is_array($configuration) || !hash_equals((string)$session['configuration_hash'], hash('sha256', study_interest_configuration_json($configuration)))) throw new RuntimeException('Assessment configuration integrity check failed.');
$generic = (int)($configuration['schema_version'] ?? 1) >= 2;
$participantFields = $generic ? study_interest_intake_fields($configuration) : [
    'name' => ['enabled' => true, 'required' => true, 'label' => __('Full name'), 'maximum' => 120, 'type' => 'text'],
    'school' => ['enabled' => true, 'required' => true, 'label' => __('School or institution'), 'maximum' => 191, 'type' => 'text'],
    'class_level' => ['enabled' => true, 'required' => true, 'label' => __('Class or current stage'), 'maximum' => 40, 'type' => 'text'],
    'email' => ['enabled' => true, 'required' => false, 'label' => __('Email'), 'maximum' => 191, 'type' => 'email'],
    'phone' => ['enabled' => true, 'required' => false, 'label' => __('Phone'), 'maximum' => 40, 'type' => 'tel'],
];
$contactFieldsEnabled = !empty($participantFields['email']['enabled']) || !empty($participantFields['phone']['enabled']);
$canDelete = study_interest_admin_can('plugin.study-interest.sessions.delete');
?>
<div class="sie-admin sie-workspace-page">
  <?php study_interest_admin_nav('participants'); ?>
  <a class="sie-back-link" href="<?= study_interest_h(study_interest_admin_url('participants')) ?>"><span aria-hidden="true">&larr;</span> <?= study_interest_h(__('Participants')) ?></a>
  <header class="sie-admin-head"><div><span class="sie-admin-eyebrow"><?= study_interest_h((string)$session['version_code']) ?> &middot; <?= study_interest_h((string)$session['status']) ?></span><h1 class="page-heading"><?= study_interest_h(__('Edit participant')) ?></h1><p><?= study_interest_h(__('Update the identity snapshot attached to this assessment session. This does not change answers or scores.')) ?></p></div><div class="sie-admin-actions"><a class="adam-button secondary" href="<?= study_interest_h(study_interest_admin_url('sessions/view', ['s' => $publicId])) ?>"><?= study_interest_h(__('Open session')) ?></a></div></header>
  <div class="sie-editor-layout">
    <form class="sie-editor-main" method="post" action="<?= study_interest_h(study_interest_admin_action_url('participants/save')) ?>">
      <input type="hidden" name="csrf_token" value="<?= study_interest_h(csrf_token()) ?>"><input type="hidden" name="session_public_id" value="<?= study_interest_h($publicId) ?>"><input type="hidden" name="expected_contact_hash" value="<?= study_interest_h($contactHash) ?>">
      <section class="sie-editor-panel"><div class="sie-panel-title"><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Identity snapshot')) ?></span><h2><?= study_interest_h(__('Participant information')) ?></h2></div></div><div class="sie-form-grid"><?php foreach ($participantFields as $key => $field): ?><?php if (empty($field['enabled']) && !array_key_exists($key, $contact)) continue; ?><label><span><?= study_interest_h((string)$field['label']) ?></span><input class="adam-input" type="<?= study_interest_h((string)$field['type']) ?>" name="contact[<?= study_interest_h($key) ?>]" maxlength="<?= (int)$field['maximum'] ?>"<?= !empty($field['required']) ? ' required' : '' ?> value="<?= study_interest_h((string)($contact[$key] ?? '')) ?>"></label><?php endforeach; ?><?php if ($contactFieldsEnabled): ?><label class="sie-check-field is-wide"><input type="checkbox" name="contact_consent" value="1" <?= $session['contact_consent_at_utc'] !== null ? 'checked' : '' ?>><span><?= study_interest_h(__('Participant contact consent is documented')) ?></span></label><?php endif; ?></div></section>
      <button class="adam-button sie-save-button" type="submit"><?= study_interest_h(__('Save participant')) ?></button>
    </form>
    <aside class="sie-editor-side">
      <section class="sie-editor-panel"><span class="sie-admin-eyebrow"><?= study_interest_h(__('Session identity')) ?></span><dl class="sie-meta-list"><div><dt><?= study_interest_h(__('Session UUID')) ?></dt><dd><code><?= study_interest_h($publicId) ?></code></dd></div><div><dt><?= study_interest_h(__('Started')) ?></dt><dd><?= study_interest_h(study_interest_admin_datetime((string)$session['started_at_utc'])) ?></dd></div><div><dt><?= study_interest_h(__('Contact consent')) ?></dt><dd><?= $session['contact_consent_at_utc'] !== null ? study_interest_h(__('Granted')) : study_interest_h(__('Not granted')) ?></dd></div></dl></section>
      <?php if ($canDelete): ?><section class="sie-editor-panel sie-danger-zone"><span class="sie-admin-eyebrow"><?= study_interest_h(__('Danger zone')) ?></span><h2><?= study_interest_h(__('Delete participant session')) ?></h2><p class="sie-panel-note"><?= study_interest_h(__('This permanently removes the identity snapshot, answers, result, flags, and revisions for this session.')) ?></p><form method="post" action="<?= study_interest_h(study_interest_admin_action_url('sessions/delete')) ?>" data-sie-confirm data-sie-confirm-title="<?= study_interest_h(__('Permanently delete this participant session?')) ?>" data-sie-confirm-message="<?= study_interest_h(__('This action cannot be undone. A non-identifying deletion event remains in the Activity log.')) ?>" data-sie-confirm-text="<?= study_interest_h(__('Delete permanently')) ?>"><input type="hidden" name="csrf_token" value="<?= study_interest_h(csrf_token()) ?>"><input type="hidden" name="session_public_id" value="<?= study_interest_h($publicId) ?>"><input type="hidden" name="return_to" value="participants"><label><span><?= study_interest_h(__('Deletion reason')) ?></span><textarea class="adam-input" name="reason" minlength="5" maxlength="500" required></textarea></label><button class="adam-button secondary sie-danger-button" type="submit"><?= study_interest_h(__('Delete participant')) ?></button></form></section><?php endif; ?>
    </aside>
  </div>
</div>
