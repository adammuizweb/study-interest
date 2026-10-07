<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.sessions.view', false);
$publicId = trim((string)($_GET['s'] ?? ''));
if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $publicId) !== 1) {
    http_response_code(404);
    echo study_interest_h(__('Session not found.'));
    return;
}
$canViewContacts = study_interest_admin_can('plugin.study-interest.contacts.view');
$canViewResponses = study_interest_admin_can('plugin.study-interest.responses.view');
$canViewResults = study_interest_admin_can('plugin.study-interest.results.view');
$canViewAudit = study_interest_admin_can('plugin.study-interest.audit.view');
$canManageContacts = study_interest_admin_can('plugin.study-interest.contacts.manage');
$canDelete = study_interest_admin_can('plugin.study-interest.sessions.delete');
$canCorrect = $canViewResponses && $canViewResults && study_interest_admin_can('plugin.study-interest.results.correct');
$contactColumn = $canViewContacts ? 's.contact_json' : 'NULL AS contact_json';
$snapshotColumn = $canViewResults ? 's.result_snapshot_json,s.result_revision,s.result_updated_by,s.result_updated_at_utc' : 'NULL AS result_snapshot_json,0 AS result_revision,NULL AS result_updated_by,NULL AS result_updated_at_utc';

study_interest_admin_begin_snapshot($pdo);
$statement = $pdo->prepare("SELECT s.id,s.public_id,s.test_id,s.version_id,s.status,s.consent_at_utc,s.contact_consent_at_utc,s.started_at_utc,s.completed_at_utc,s.last_activity_at_utc,{$snapshotColumn},{$contactColumn},v.version_code,v.algorithm_version,v.configuration_hash,v.configuration_json,v.expected_question_count,t.title AS test_title
    FROM study_interest_sessions s JOIN study_interest_test_versions v ON v.id=s.version_id JOIN study_interest_tests t ON t.id=s.test_id WHERE s.public_id=? LIMIT 1");
$statement->execute([$publicId]);
$session = $statement->fetch(PDO::FETCH_ASSOC);
if (!is_array($session)) {
    $pdo->rollBack();
    http_response_code(404);
    echo study_interest_h(__('Session not found.'));
    return;
}
$configuration = study_interest_configuration_from_session($session);
$contact = $canViewContacts ? study_interest_admin_contact((string)($session['contact_json'] ?? '')) : [];
$answers = $canViewResponses ? study_interest_effective_answer_map($pdo, (int)$session['id'], $canViewResults ? (int)$session['result_revision'] : null) : [];
$answerCountStatement = $pdo->prepare('SELECT COUNT(*) FROM study_interest_answers WHERE session_id=?');
$answerCountStatement->execute([(int)$session['id']]);
$answerCount = $canViewResponses ? count($answers) : (int)$answerCountStatement->fetchColumn();
$dimensions = $programs = $flags = $revisions = [];
if ($canViewResults) {
    $dimensionStatement = $pdo->prepare('SELECT * FROM study_interest_dimension_results WHERE session_id=? ORDER BY final_score DESC,dimension_code');
    $dimensionStatement->execute([(int)$session['id']]);
    $dimensions = $dimensionStatement->fetchAll(PDO::FETCH_ASSOC);
    $programStatement = $pdo->prepare('SELECT * FROM study_interest_program_results WHERE session_id=? ORDER BY is_recommended DESC,rank_position IS NULL,rank_position,score DESC');
    $programStatement->execute([(int)$session['id']]);
    $programs = $programStatement->fetchAll(PDO::FETCH_ASSOC);
    $flagStatement = $pdo->prepare('SELECT * FROM study_interest_result_flags WHERE session_id=? ORDER BY severity DESC,flag_code');
    $flagStatement->execute([(int)$session['id']]);
    $flags = $flagStatement->fetchAll(PDO::FETCH_ASSOC);
}
if ($canViewAudit) {
    $revisionStatement = $pdo->prepare('SELECT revision_number,answers_json,snapshot_json,revision_hash,reason,created_by,created_at_utc FROM study_interest_result_revisions WHERE session_id=? ORDER BY revision_number DESC');
    $revisionStatement->execute([(int)$session['id']]);
    $revisions = $revisionStatement->fetchAll(PDO::FETCH_ASSOC);
    foreach ($revisions as $revision) {
        $actorId = $revision['created_by'] !== null ? (int)$revision['created_by'] : null;
        $expectedRevisionHash = study_interest_result_revision_hash((int)$session['id'], (int)$revision['revision_number'], (string)$revision['answers_json'], (string)$revision['snapshot_json'], (string)$revision['reason'], $actorId, (string)$revision['created_at_utc']);
        $revisionSnapshot = json_decode((string)$revision['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!hash_equals((string)$revision['revision_hash'], $expectedRevisionHash)
            || !is_array($revisionSnapshot)
            || (string)($revisionSnapshot['session_public_id'] ?? '') !== $publicId
            || (int)($revisionSnapshot['result_revision'] ?? 0) !== (int)$revision['revision_number']
            || !hash_equals((string)$session['configuration_hash'], (string)($revisionSnapshot['configuration_hash'] ?? ''))) {
            throw new RuntimeException('Result revision history integrity check failed.');
        }
    }
}
$dimensionLabels = [];
foreach ($configuration['dimensions'] ?? [] as $code => $meta) $dimensionLabels[(string)$code] = (string)($meta['label'] ?? $code);
$programLabels = [];
foreach ($configuration['programs'] ?? [] as $code => $meta) $programLabels[(string)$code] = (string)($meta['label'] ?? $code);
$leadingProgram = null;
foreach ($programs as $program) {
    if ((string)$program['recommendation_type'] !== 'DIRECT_ENTRY') continue;
    if ($leadingProgram === null || (float)$program['score'] > (float)$leadingProgram['score'] || ((float)$program['score'] === (float)$leadingProgram['score'] && strcmp((string)$program['program_code'], (string)$leadingProgram['program_code']) < 0)) $leadingProgram = $program;
}
$snapshot = $canViewResults ? json_decode((string)($session['result_snapshot_json'] ?? ''), true) : [];
if (!is_array($snapshot)) $snapshot = [];
$pdo->commit();
$flagLabels = ['STRAIGHTLINING' => __('Repeated answer pattern'), 'FAST_COMPLETION' => __('Fast completion'), 'LOW_RESPONSE_CONSISTENCY' => __('Low response consistency')];
?>
<div class="sie-admin sie-workspace-page">
  <?php study_interest_admin_nav('sessions'); ?>
  <a class="sie-back-link" href="<?= study_interest_h(study_interest_admin_url('sessions')) ?>"><span aria-hidden="true">&larr;</span> <?= study_interest_h(__('Sessions')) ?></a>
  <header class="sie-admin-head">
    <div><span class="sie-admin-eyebrow"><?= study_interest_h((string)$session['version_code']) ?> &middot; <?= study_interest_h((string)$session['status'] === 'completed' ? __('Completed') : __('In progress')) ?></span><h1 class="page-heading"><?= study_interest_h($canViewContacts ? (string)($contact['name'] ?? __('Participant session')) : __('Participant session')) ?></h1><p><code><?= study_interest_h((string)$session['public_id']) ?></code></p></div>
    <div class="sie-admin-actions"><?php if ($canManageContacts): ?><a class="adam-button secondary" href="<?= study_interest_h(study_interest_admin_url('participants/edit', ['s' => $publicId])) ?>"><?= study_interest_h(__('Edit participant')) ?></a><?php endif; ?><?php if ($canCorrect && (string)$session['status'] === 'completed'): ?><a class="adam-button" href="<?= study_interest_h(study_interest_admin_url('sessions/correct', ['s' => $publicId])) ?>"><?= study_interest_h(__('Edit submitted answers')) ?></a><?php endif; ?></div>
  </header>
  <section class="sie-session-layout"><div class="sie-session-main">
    <section class="sie-session-summary">
      <article><span><?= study_interest_h(__('Progress')) ?></span><strong><?= $answerCount ?>/<?= (int)$session['expected_question_count'] ?></strong><small><?= study_interest_h($canViewResponses ? __('effective answers') : __('response access restricted')) ?></small></article>
      <article><span><?= study_interest_h(__('Result revision')) ?></span><strong><?= $canViewResults ? (int)$session['result_revision'] : '—' ?></strong><small><?= study_interest_h($canViewResults ? ((int)$session['result_revision'] > 0 ? __('corrected result') : __('original result')) : __('result access restricted')) ?></small></article>
      <article><span><?= study_interest_h(__('Quality flags')) ?></span><strong><?= $canViewResults ? count($flags) : '—' ?></strong><small><?= study_interest_h($canViewResults ? __('signals for review') : __('result access restricted')) ?></small></article>
      <article><span><?= study_interest_h(__('Duration')) ?></span><strong><?= $canViewResults && isset($snapshot['duration_seconds']) ? number_format((int)$snapshot['duration_seconds'] / 60, 1) : '—' ?></strong><small><?= study_interest_h($canViewResults ? __('minutes') : __('result access restricted')) ?></small></article>
    </section>
    <?php if ($canViewResults && (string)$session['status'] === 'completed'): ?>
      <?php if ($leadingProgram !== null): ?><section class="sie-leading-direction"><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Primary outcome')) ?></span><h2><?= study_interest_h(__('Closest study direction')) ?></h2><p><?= study_interest_h(__('This is the program with the highest interest alignment for this participant, even when the overall profile is not yet dominant.')) ?></p></div><div><span><?= study_interest_h($programLabels[(string)$leadingProgram['program_code']] ?? (string)$leadingProgram['program_code']) ?></span><strong><?= number_format((float)$leadingProgram['score'], 1) ?><small>/100</small></strong><b><?= study_interest_h((string)$leadingProgram['classification']) ?></b></div></section><?php endif; ?>
      <div class="sie-config-grid">
        <section class="sie-config-panel"><div class="sie-panel-title"><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Result profile')) ?></span><h2><?= study_interest_h(__('Dimension scores')) ?></h2></div></div><div class="sie-bar-list is-dimensions"><?php foreach ($dimensions as $index => $row): ?><div class="sie-analytics-bar"><div><span><i><?= str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) ?></i><b><?= study_interest_h($dimensionLabels[(string)$row['dimension_code']] ?? (string)$row['dimension_code']) ?></b></span><strong><?= number_format((float)$row['final_score'], 1) ?></strong></div><div class="sie-bar-track"><i style="width:<?= study_interest_h(max(0, min(100, (float)$row['final_score']))) ?>%"></i></div></div><?php endforeach; ?></div></section>
        <section class="sie-config-panel"><div class="sie-panel-title"><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Comparison')) ?></span><h2><?= study_interest_h(__('All study directions')) ?></h2></div></div><div class="sie-program-results"><?php foreach ($programs as $program): ?><div class="<?= $leadingProgram !== null && (string)$program['program_code'] === (string)$leadingProgram['program_code'] ? 'is-recommended' : '' ?>"><span><?= $program['rank_position'] !== null ? '#' . (int)$program['rank_position'] : '—' ?></span><p><b><?= study_interest_h($programLabels[(string)$program['program_code']] ?? (string)$program['program_code']) ?></b><small><?= study_interest_h((string)$program['classification']) ?></small></p><strong><?= number_format((float)$program['score'], 1) ?></strong></div><?php endforeach; ?></div></section>
      </div>
    <?php endif; ?>
    <?php if ($canViewResponses): ?>
      <section class="sie-admin-section"><div class="sie-admin-section__head"><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Response review')) ?></span><h2><?= study_interest_h(__('Questions and effective answers')) ?></h2></div><p><?= study_interest_h(__('These are the effective responses used by the current session result. Original corrections remain protected in revision history.')) ?></p></div><div class="sie-response-list">
        <?php foreach ($configuration['questions'] ?? [] as $index => $question): ?><?php $selectedCode = (string)($answers[(string)$question['code']] ?? ''); $selectedLabel = __('Not answered'); foreach ($question['options'] ?? [] as $option) if ((string)$option['code'] === $selectedCode) { $selectedLabel = (string)$option['label']; break; } ?>
          <article><span><?= str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) ?></span><div><small><?= study_interest_h((string)$question['code'] . ' · ' . (string)$question['section']) ?></small><h3><?= study_interest_h((string)($question['title'] ?? $question['prompt'])) ?></h3><?php if (!empty($question['title'])): ?><p><?= study_interest_h((string)$question['prompt']) ?></p><?php endif; ?><div><b><?= study_interest_h(__('Answer')) ?></b><span><?= study_interest_h($selectedCode !== '' ? $selectedCode . ' · ' . $selectedLabel : $selectedLabel) ?></span></div></div></article>
        <?php endforeach; ?>
      </div></section>
    <?php endif; ?>
  </div><aside class="sie-session-side">
    <?php if ($canViewContacts): ?><section class="sie-editor-panel"><span class="sie-admin-eyebrow"><?= study_interest_h(__('Participant snapshot')) ?></span><dl class="sie-meta-list"><div><dt><?= study_interest_h(__('Name')) ?></dt><dd><?= study_interest_h((string)($contact['name'] ?? '—')) ?></dd></div><div><dt><?= study_interest_h(__('School')) ?></dt><dd><?= study_interest_h((string)($contact['school'] ?? '—')) ?></dd></div><div><dt><?= study_interest_h(__('Class / stage')) ?></dt><dd><?= study_interest_h((string)($contact['class_level'] ?? '—')) ?></dd></div><div><dt><?= study_interest_h(__('Email')) ?></dt><dd><?= study_interest_h((string)($contact['email'] ?? '—')) ?></dd></div><div><dt><?= study_interest_h(__('Phone')) ?></dt><dd><?= study_interest_h((string)($contact['phone'] ?? '—')) ?></dd></div><div><dt><?= study_interest_h(__('Contact consent')) ?></dt><dd><?= $session['contact_consent_at_utc'] ? study_interest_h(__('Granted')) : study_interest_h(__('Not granted')) ?></dd></div></dl></section><?php endif; ?>
    <section class="sie-editor-panel"><span class="sie-admin-eyebrow"><?= study_interest_h(__('Timeline')) ?></span><dl class="sie-meta-list"><div><dt><?= study_interest_h(__('Started')) ?></dt><dd><?= study_interest_h(study_interest_admin_datetime((string)$session['started_at_utc'])) ?></dd></div><div><dt><?= study_interest_h(__('Completed')) ?></dt><dd><?= study_interest_h(study_interest_admin_datetime($session['completed_at_utc'] !== null ? (string)$session['completed_at_utc'] : null)) ?></dd></div><div><dt><?= study_interest_h(__('Last participant activity')) ?></dt><dd><?= study_interest_h(study_interest_admin_datetime((string)$session['last_activity_at_utc'])) ?></dd></div><?php if ($canViewResults): ?><div><dt><?= study_interest_h(__('Result updated')) ?></dt><dd><?= study_interest_h(study_interest_admin_datetime($session['result_updated_at_utc'] !== null ? (string)$session['result_updated_at_utc'] : null)) ?></dd></div><?php endif; ?></dl></section>
    <?php if ($canViewResults): ?><section class="sie-editor-panel"><span class="sie-admin-eyebrow"><?= study_interest_h(__('Quality signals')) ?></span><?php if ($flags === []): ?><p class="sie-panel-note"><?= study_interest_h(__('No quality flags recorded.')) ?></p><?php else: ?><div class="sie-flag-list"><?php foreach ($flags as $flag): ?><div><span class="sie-severity is-<?= study_interest_h(strtolower((string)$flag['severity'])) ?>"><?= study_interest_h((string)$flag['severity']) ?></span><b><?= study_interest_h($flagLabels[(string)$flag['flag_code']] ?? (string)$flag['flag_code']) ?></b></div><?php endforeach; ?></div><?php endif; ?></section><?php endif; ?>
    <?php if ($revisions !== []): ?><section class="sie-editor-panel"><span class="sie-admin-eyebrow"><?= study_interest_h(__('Revision history')) ?></span><div class="sie-revision-list"><?php foreach ($revisions as $revision): ?><article><div><b><?= study_interest_h(sprintf(__('Revision %d'), (int)$revision['revision_number'])) ?></b><small><?= study_interest_h(study_interest_admin_datetime((string)$revision['created_at_utc'])) ?></small></div><p><?= study_interest_h((string)$revision['reason']) ?></p><code><?= study_interest_h(substr((string)$revision['revision_hash'], 0, 12)) ?>&hellip;</code></article><?php endforeach; ?></div></section><?php endif; ?>
    <?php if ($canDelete): ?><section class="sie-editor-panel sie-danger-zone"><span class="sie-admin-eyebrow"><?= study_interest_h(__('Danger zone')) ?></span><h2><?= study_interest_h(__('Delete session')) ?></h2><p><?= study_interest_h(__('Permanently removes participant data, answers, results, flags, and revisions.')) ?></p><form method="post" action="<?= study_interest_h(study_interest_admin_action_url('sessions/delete')) ?>" data-sie-confirm data-sie-confirm-title="<?= study_interest_h(__('Permanently delete this session?')) ?>" data-sie-confirm-message="<?= study_interest_h(__('All participant data and assessment evidence in this session will be deleted. The deletion event remains in Activity log.')) ?>" data-sie-confirm-text="<?= study_interest_h(__('Delete permanently')) ?>"><input type="hidden" name="csrf_token" value="<?= study_interest_h(csrf_token()) ?>"><input type="hidden" name="session_public_id" value="<?= study_interest_h($publicId) ?>"><input type="hidden" name="return_to" value="sessions"><label><span><?= study_interest_h(__('Deletion reason')) ?></span><textarea class="adam-input" name="reason" minlength="5" maxlength="500" required></textarea></label><button class="adam-button secondary sie-danger-button" type="submit"><?= study_interest_h(__('Delete session')) ?></button></form></section><?php endif; ?>
  </aside></section>
</div>
