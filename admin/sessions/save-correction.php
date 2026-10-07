<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.results.correct', false);
adiwira_require_permission($pdo, 'plugin.study-interest.responses.view', false);
adiwira_require_permission($pdo, 'plugin.study-interest.results.view', false);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { adiwira_render_404(); return; }
$publicId = trim((string)($_POST['session_public_id'] ?? ''));
$returnUrl = study_interest_admin_url('sessions/view', ['s' => $publicId]);
if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) study_interest_admin_redirect('error', __('The security token expired. Please try again.'), $returnUrl);

try {
    if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $publicId) !== 1) throw new DomainException('Session is invalid.');
    $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 500);
    if (mb_strlen($reason) < 10) throw new DomainException('A specific correction reason of at least 10 characters is required.');
    $expectedRevision = filter_var($_POST['expected_revision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($expectedRevision === false || $expectedRevision === null) throw new DomainException('Result revision is invalid.');
    $rawAnswers = is_array($_POST['answers'] ?? null) ? $_POST['answers'] : [];

    $pdo->beginTransaction();
    if (!authorization_lock_actor_permissions($pdo, $studyInterestUserId)
        || !user_can($pdo, $studyInterestUserId, 'plugin.study-interest.results.correct')
        || !user_can($pdo, $studyInterestUserId, 'plugin.study-interest.responses.view')
        || !user_can($pdo, $studyInterestUserId, 'plugin.study-interest.results.view')) throw new RuntimeException('Result correction permission changed.');
    $statement = $pdo->prepare('SELECT s.*,v.version_code,v.algorithm_version,v.configuration_hash,v.configuration_json FROM study_interest_sessions s JOIN study_interest_test_versions v ON v.id=s.version_id WHERE s.public_id=? LIMIT 1 FOR UPDATE');
    $statement->execute([$publicId]); $session = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($session) || (string)$session['status'] !== 'completed') throw new DomainException('Completed session was not found.');
    if ((int)$session['result_revision'] !== (int)$expectedRevision) throw new DomainException('This result changed while the correction form was open. Review the latest revision and try again.');
    $configuration = study_interest_configuration_from_session($session);
    $currentAnswers = study_interest_effective_answer_map($pdo, (int)$session['id']);
    $answers = [];
    foreach ($configuration['questions'] as $question) {
        $code = (string)$question['code'];
        $selected = is_scalar($rawAnswers[$code] ?? null) ? (string)$rawAnswers[$code] : '';
        $valid = false;
        foreach ($question['options'] ?? [] as $option) if ((string)$option['code'] === $selected) { $valid = true; break; }
        if (!$valid) throw new DomainException("Select a valid answer for {$code}.");
        $answers[$code] = $selected;
    }
    ksort($answers);
    ksort($currentAnswers);
    if ($answers === $currentAnswers) throw new DomainException('No response changed. Update at least one answer before creating a revision.');
    $changedQuestions = [];
    foreach ($answers as $code => $selected) {
        $before = (string)($currentAnswers[$code] ?? '');
        if ($before !== $selected) $changedQuestions[] = $code;
    }

    $currentSnapshot = json_decode((string)$session['result_snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($currentSnapshot)) throw new RuntimeException('Current result snapshot is invalid.');
    $duration = max(0, (int)($currentSnapshot['duration_seconds'] ?? 0));
    $currentResult = study_interest_score_versioned((string)$session['algorithm_version'], $configuration, $currentAnswers, $duration);
    if (!study_interest_snapshot_matches_result($currentSnapshot, $currentResult)) throw new RuntimeException('Current answers do not reproduce the stored result. The correction was stopped to preserve evidence.');
    $result = study_interest_score_versioned((string)$session['algorithm_version'], $configuration, $answers, $duration);
    $nextRevision = (int)$session['result_revision'] + 1;
    $now = study_interest_now_utc();
    $snapshot = [
        'schema' => max(1, (int)($currentSnapshot['schema'] ?? 1)),
        'session_public_id' => $publicId,
        'version_code' => (string)$session['version_code'],
        'algorithm_version' => (string)$session['algorithm_version'],
        'configuration_hash' => (string)$session['configuration_hash'],
        'completed_at_utc' => (string)($currentSnapshot['completed_at_utc'] ?? $session['completed_at_utc']),
        'corrected_at_utc' => $now,
        'result_revision' => $nextRevision,
        'result_text' => $configuration['result_text'] ?? [],
    ] + $result;
    $snapshotJson = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    $answersJson = json_encode($answers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $revisionInsert = $pdo->prepare('INSERT INTO study_interest_result_revisions (session_id,revision_number,answers_json,snapshot_json,revision_hash,reason,created_by,created_at_utc) VALUES (?,?,?,?,?,?,?,?)');
    if ((int)$session['result_revision'] === 0) {
        $originalAnswers = study_interest_answer_map($pdo, (int)$session['id']);
        ksort($originalAnswers);
        $originalAnswersJson = json_encode($originalAnswers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $originalReason = 'Original completed result';
        $originalCreatedAt = (string)$session['completed_at_utc'];
        $revisionInsert->execute([(int)$session['id'], 0, $originalAnswersJson, (string)$session['result_snapshot_json'], study_interest_result_revision_hash((int)$session['id'], 0, $originalAnswersJson, (string)$session['result_snapshot_json'], $originalReason, null, $originalCreatedAt), $originalReason, null, $originalCreatedAt]);
    }
    $revisionInsert->execute([(int)$session['id'], $nextRevision, $answersJson, $snapshotJson, study_interest_result_revision_hash((int)$session['id'], $nextRevision, $answersJson, $snapshotJson, $reason, $studyInterestUserId, $now), $reason, $studyInterestUserId, $now]);
    study_interest_materialize_result($pdo, (int)$session['id'], $result);
    $update = $pdo->prepare('UPDATE study_interest_sessions SET result_snapshot_json=?,result_revision=?,result_updated_by=?,result_updated_at_utc=? WHERE id=? AND result_revision=?');
    $update->execute([$snapshotJson, $nextRevision, $studyInterestUserId, $now, (int)$session['id'], (int)$expectedRevision]);
    if ($update->rowCount() !== 1) throw new RuntimeException('Result revision conflicted with another request.');
    study_interest_audit($pdo, $studyInterestUserId, 'result.corrected', 'session', $publicId,
        ['revision' => (int)$expectedRevision, 'snapshot_hash' => hash('sha256', (string)$session['result_snapshot_json'])],
        ['revision' => $nextRevision, 'snapshot_hash' => hash('sha256', $snapshotJson), 'reason' => $reason, 'changed_questions' => $changedQuestions]);
    $pdo->commit();
    study_interest_admin_redirect('success', sprintf(__('Corrected result revision %d created.'), $nextRevision), $returnUrl);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[study-interest] result correction failed: ' . $error->getMessage());
    study_interest_admin_redirect('error', $error instanceof DomainException ? $error->getMessage() : __('The corrected result could not be saved.'), study_interest_admin_url('sessions/correct', ['s' => $publicId]));
}
