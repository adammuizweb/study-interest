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

function study_interest_correction_preview(array $configuration, string $algorithmVersion, array $savedAnswers, array $draftAnswers, int $durationSeconds, string $targetProgram, ?array $savedResult = null): array
{
    $configurationErrors = study_interest_configuration_errors($configuration);
    if ($configurationErrors !== []) throw new DomainException(implode(' ', $configurationErrors));
    $questions = array_values($configuration['questions'] ?? []);
    $optionCount = array_sum(array_map(static fn(array $question): int => count($question['options'] ?? []), $questions));
    $scorePasses = max(2, 2 + $optionCount - count($questions));
    $dimensionCount = max(1, count($configuration['dimensions'] ?? []));
    $scoreCost = max(1, $optionCount * $dimensionCount
        + count($configuration['sections'] ?? []) * $dimensionCount
        + count($configuration['programs'] ?? []) * $dimensionCount);
    if ($optionCount > 1000 || $scorePasses * $scoreCost > 2000000) {
        throw new DomainException('This assessment is too large for live correction guidance.');
    }
    $program = $configuration['programs'][$targetProgram] ?? null;
    if (!is_array($program) || (string)($program['recommendation_type'] ?? '') !== 'DIRECT_ENTRY'
        || isset($program['is_active']) && empty($program['is_active'])) {
        throw new DomainException('Select a valid target direction.');
    }

    $programMap = static function (array $result): array {
        $programs = array_values(array_filter($result['programs'] ?? [], static fn(mixed $item): bool => is_array($item)
            && (string)($item['recommendation_type'] ?? '') === 'DIRECT_ENTRY'));
        usort($programs, static fn(array $left, array $right): int => (float)($right['score'] ?? 0) <=> (float)($left['score'] ?? 0)
            ?: strcmp((string)($left['code'] ?? ''), (string)($right['code'] ?? '')));
        $mapped = [];
        foreach ($programs as $index => $item) {
            $item['guidance_position'] = $index + 1;
            $mapped[(string)$item['code']] = $item;
        }
        return $mapped;
    };
    $summary = static function (array $item): array {
        return [
            'code' => (string)$item['code'],
            'label' => (string)$item['label'],
            'score' => round((float)$item['score'], 3),
            'position' => (int)$item['guidance_position'],
            'result_rank' => $item['rank'] === null ? null : (int)$item['rank'],
            'classification' => (string)$item['classification'],
            'recommended' => !empty($item['recommended']),
        ];
    };

    $score = static fn(array $answers): array => study_interest_score_versioned(
        $algorithmVersion,
        $configuration,
        $answers,
        $durationSeconds,
        false
    );
    $savedResult ??= $score($savedAnswers);
    $draftResult = $score($draftAnswers);
    $savedPrograms = $programMap($savedResult);
    $draftPrograms = $programMap($draftResult);
    if (!isset($savedPrograms[$targetProgram], $draftPrograms[$targetProgram])) throw new RuntimeException('Target direction result is unavailable.');

    $programs = [];
    foreach ($draftPrograms as $code => $item) {
        $entry = $summary($item);
        $entry['saved_score'] = round((float)($savedPrograms[$code]['score'] ?? 0), 3);
        $entry['delta'] = round($entry['score'] - $entry['saved_score'], 3);
        $programs[] = $entry;
    }

    $choices = [];
    $suggestions = [];
    foreach ($questions as $questionIndex => $question) {
        $questionCode = (string)($question['code'] ?? '');
        if ($questionCode === '' || !array_key_exists($questionCode, $draftAnswers)) throw new DomainException('Every answer is required for guidance preview.');
        $selectedCode = (string)$draftAnswers[$questionCode];
        foreach ($question['options'] ?? [] as $option) {
            $optionCode = (string)($option['code'] ?? '');
            if ($optionCode === '') continue;
            if ($optionCode === $selectedCode) {
                $candidatePrograms = $draftPrograms;
            } else {
                $candidateAnswers = $draftAnswers;
                $candidateAnswers[$questionCode] = $optionCode;
                $candidatePrograms = $programMap($score($candidateAnswers));
            }
            $candidate = $candidatePrograms[$targetProgram] ?? null;
            if (!is_array($candidate)) throw new RuntimeException('Target direction preview is unavailable.');
            $choice = $summary($candidate);
            $choice['delta'] = round((float)$candidate['score'] - (float)$draftPrograms[$targetProgram]['score'], 3);
            $choice['selected'] = $optionCode === $selectedCode;
            $choices[$questionCode][$optionCode] = $choice;
            if (!$choice['selected'] && $choice['delta'] > 0) {
                $suggestions[] = [
                    'question_code' => $questionCode,
                    'question_number' => $questionIndex + 1,
                    'option_code' => $optionCode,
                    'option_label' => (string)($option['label'] ?? $optionCode),
                ] + $choice;
            }
        }
    }
    usort($suggestions, static fn(array $left, array $right): int => (float)$right['delta'] <=> (float)$left['delta']
        ?: (int)$left['question_number'] <=> (int)$right['question_number']
        ?: strcmp((string)$left['option_code'], (string)$right['option_code']));

    $target = $summary($draftPrograms[$targetProgram]);
    $target['saved_score'] = round((float)$savedPrograms[$targetProgram]['score'], 3);
    $target['delta'] = round($target['score'] - $target['saved_score'], 3);
    return [
        'target' => $target,
        'programs' => $programs,
        'choices' => $choices,
        'suggestions' => array_slice($suggestions, 0, 12),
        'changed_count' => count(array_filter($draftAnswers, static fn(string $answer, string $code): bool => $answer !== (string)($savedAnswers[$code] ?? ''), ARRAY_FILTER_USE_BOTH)),
    ];
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
