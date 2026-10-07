<?php
declare(strict_types=1);

function study_interest_answer_map(PDO $pdo, int $sessionId): array
{
    $statement = $pdo->prepare('SELECT q.question_code,o.option_code FROM study_interest_answers a JOIN study_interest_questions q ON q.id=a.question_id JOIN study_interest_options o ON o.id=a.option_id WHERE a.session_id=?');
    $statement->execute([$sessionId]);
    $answers = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) $answers[(string)$row['question_code']] = (string)$row['option_code'];
    return $answers;
}

function study_interest_save_answer(PDO $pdo, array $session, int $questionId, int $optionId): void
{
    if ((string)$session['status'] !== 'started') throw new DomainException('This assessment is already completed.');
    $option = $pdo->prepare('SELECT q.id FROM study_interest_questions q JOIN study_interest_options o ON o.question_id=q.id WHERE q.id=? AND o.id=? AND q.version_id=? LIMIT 1');
    $option->execute([$questionId, $optionId, (int)$session['version_id']]);
    if ((int)$option->fetchColumn() !== $questionId) throw new DomainException('The selected answer is invalid.');
    $now = study_interest_now_utc();
    $statement = $pdo->prepare('INSERT INTO study_interest_answers (session_id,question_id,option_id,answered_at_utc,updated_at_utc) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE option_id=VALUES(option_id),answer_value=NULL,answered_at_utc=VALUES(answered_at_utc),updated_at_utc=VALUES(updated_at_utc)');
    $statement->execute([(int)$session['id'], $questionId, $optionId, $now, $now]);
    $pdo->prepare('UPDATE study_interest_sessions SET last_activity_at_utc=? WHERE id=?')->execute([$now, (int)$session['id']]);
}

function study_interest_materialize_result(PDO $pdo, int $sessionId, array $result): void
{
    $pdo->prepare('DELETE FROM study_interest_result_flags WHERE session_id=?')->execute([$sessionId]);
    $pdo->prepare('DELETE FROM study_interest_program_results WHERE session_id=?')->execute([$sessionId]);
    $pdo->prepare('DELETE FROM study_interest_dimension_results WHERE session_id=?')->execute([$sessionId]);
    $dimensionInsert = $pdo->prepare('INSERT INTO study_interest_dimension_results (session_id,dimension_code,section_scores_json,final_score) VALUES (?,?,?,?)');
    foreach ($result['dimensions'] as $dimension) $dimensionInsert->execute([$sessionId, $dimension['code'], json_encode($dimension['section_scores'], JSON_THROW_ON_ERROR), $dimension['score']]);
    $programInsert = $pdo->prepare('INSERT INTO study_interest_program_results (session_id,program_code,score,rank_position,classification,recommendation_type,is_recommended) VALUES (?,?,?,?,?,?,?)');
    foreach ($result['programs'] as $program) $programInsert->execute([$sessionId, $program['code'], $program['score'], $program['rank'], $program['classification'], $program['recommendation_type'], $program['recommended'] ? 1 : 0]);
    $flagInsert = $pdo->prepare('INSERT INTO study_interest_result_flags (session_id,flag_code,severity,context_json) VALUES (?,?,?,?)');
    foreach ($result['flags'] as $flag) $flagInsert->execute([$sessionId, $flag['code'], $flag['severity'], json_encode($flag['context'], JSON_THROW_ON_ERROR)]);
}

function study_interest_result_revision_hash(int $sessionId, int $revisionNumber, string $answersJson, string $snapshotJson, string $reason, ?int $actorId, string $createdAtUtc): string
{
    return hash('sha256', study_interest_configuration_json([
        'session_id' => $sessionId,
        'revision_number' => $revisionNumber,
        'answers_json' => $answersJson,
        'snapshot_json' => $snapshotJson,
        'reason' => $reason,
        'actor_id' => $actorId,
        'created_at_utc' => $createdAtUtc,
    ]));
}

function study_interest_snapshot_matches_result(array $snapshot, array $result): bool
{
    foreach (['dimensions', 'programs', 'recommendations', 'professional_pathways', 'interpretations', 'profile_clarity', 'flags', 'duration_seconds'] as $key) {
        if (!array_key_exists($key, $snapshot) || !array_key_exists($key, $result)) return false;
        if (study_interest_configuration_json(['value' => $snapshot[$key]]) !== study_interest_configuration_json(['value' => $result[$key]])) return false;
    }
    return true;
}

function study_interest_effective_answer_map(PDO $pdo, int $sessionId, ?int $revisionNumber = null): array
{
    $sessionStatement = $pdo->prepare('SELECT s.public_id,s.result_revision,s.result_snapshot_json,v.configuration_hash FROM study_interest_sessions s JOIN study_interest_test_versions v ON v.id=s.version_id WHERE s.id=? LIMIT 1');
    $sessionStatement->execute([$sessionId]);
    $session = $sessionStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($session)) throw new RuntimeException('Assessment session is unavailable.');
    $revisionNumber ??= (int)$session['result_revision'];
    if ($revisionNumber === 0) {
        $answers = study_interest_answer_map($pdo, $sessionId);
        ksort($answers);
        return $answers;
    }
    $revision = $pdo->prepare('SELECT answers_json,snapshot_json,revision_hash,reason,created_by,created_at_utc FROM study_interest_result_revisions WHERE session_id=? AND revision_number=? LIMIT 1');
    $revision->execute([$sessionId, $revisionNumber]);
    $row = $revision->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) throw new RuntimeException('Effective result revision is unavailable.');
    $expectedHash = study_interest_result_revision_hash($sessionId, $revisionNumber, (string)$row['answers_json'], (string)$row['snapshot_json'], (string)$row['reason'], $row['created_by'] !== null ? (int)$row['created_by'] : null, (string)$row['created_at_utc']);
    if (!hash_equals((string)$row['revision_hash'], $expectedHash)) throw new RuntimeException('Effective result revision integrity check failed.');
    $snapshot = json_decode((string)$row['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($snapshot) || (string)($snapshot['session_public_id'] ?? '') !== (string)$session['public_id'] || (int)($snapshot['result_revision'] ?? 0) !== $revisionNumber || !hash_equals((string)$session['configuration_hash'], (string)($snapshot['configuration_hash'] ?? ''))) throw new RuntimeException('Effective result revision identity is invalid.');
    if ($revisionNumber === (int)$session['result_revision'] && !hash_equals((string)$session['result_snapshot_json'], (string)$row['snapshot_json'])) throw new RuntimeException('Effective result revision does not match the session snapshot.');
    $answers = json_decode((string)$row['answers_json'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($answers)) throw new RuntimeException('Effective result answers are invalid.');
    $answers = array_map('strval', $answers);
    ksort($answers);
    return $answers;
}

function study_interest_complete(PDO $pdo, array $session): array
{
    if ((string)$session['status'] === 'completed') {
        $snapshot = json_decode((string)$session['result_snapshot_json'], true);
        if (!is_array($snapshot)) throw new RuntimeException('Completed result snapshot is unavailable.');
        return $snapshot;
    }
    if ((string)$session['status'] !== 'started') throw new DomainException('This assessment cannot be completed.');
    $configuration = study_interest_configuration_from_session($session);
    $answers = study_interest_answer_map($pdo, (int)$session['id']);
    $started = new DateTimeImmutable((string)$session['started_at_utc'], new DateTimeZone('UTC'));
    $duration = max(0, time() - $started->getTimestamp());
    $result = study_interest_score_versioned((string)$session['algorithm_version'], $configuration, $answers, $duration);
    $snapshot = [
        'schema' => 1,
        'session_public_id' => (string)$session['public_id'],
        'version_code' => (string)$session['version_code'],
        'algorithm_version' => (string)$session['algorithm_version'],
        'configuration_hash' => (string)$session['configuration_hash'],
        'completed_at_utc' => study_interest_now_utc(),
        'result_text' => $configuration['result_text'] ?? [],
    ] + $result;
    $snapshotJson = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

    study_interest_materialize_result($pdo, (int)$session['id'], $result);
    $now = (string)$snapshot['completed_at_utc'];
    $update = $pdo->prepare("UPDATE study_interest_sessions SET status='completed',completed_at_utc=?,last_activity_at_utc=?,result_snapshot_json=? WHERE id=? AND status='started'");
    $update->execute([$now, $now, $snapshotJson, (int)$session['id']]);
    if ($update->rowCount() !== 1) throw new RuntimeException('Assessment completion conflicted with another request.');
    return $snapshot;
}
