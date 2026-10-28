<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.results.correct', true);
adiwira_require_permission($pdo, 'plugin.study-interest.responses.view', true);
adiwira_require_permission($pdo, 'plugin.study-interest.results.view', true);
adiwira_require_permission($pdo, 'plugin.study-interest.config.view', true);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { study_interest_json(['ok' => false, 'error' => __('Method not allowed.')], 405); }
if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) {
    study_interest_json(['ok' => false, 'error' => __('The security token expired. Please try again.')], 419);
}

try {
    $publicId = trim((string)($_POST['session_public_id'] ?? ''));
    if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $publicId) !== 1) {
        throw new DomainException('Session is invalid.');
    }
    $expectedRevision = filter_var($_POST['expected_revision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($expectedRevision === false || $expectedRevision === null) throw new DomainException('Result revision is invalid.');
    $targetProgram = trim((string)($_POST['target_program'] ?? ''));
    $rawAnswers = is_array($_POST['answers'] ?? null) ? $_POST['answers'] : [];

    $statement = $pdo->prepare('SELECT s.id,s.public_id,s.status,s.result_revision,s.result_snapshot_json,v.algorithm_version,v.configuration_hash,v.configuration_json FROM study_interest_sessions s JOIN study_interest_test_versions v ON v.id=s.version_id WHERE s.public_id=? LIMIT 1');
    $statement->execute([$publicId]);
    $session = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($session) || (string)$session['status'] !== 'completed') throw new DomainException('Completed session was not found.');
    if ((int)$session['result_revision'] !== (int)$expectedRevision) throw new DomainException('This result changed while the correction form was open. Reload the latest revision.');

    $configuration = study_interest_configuration_from_session($session);
    $savedAnswers = study_interest_effective_answer_map($pdo, (int)$session['id'], (int)$session['result_revision']);
    $draftAnswers = [];
    foreach ($configuration['questions'] ?? [] as $question) {
        $code = (string)($question['code'] ?? '');
        $selected = is_scalar($rawAnswers[$code] ?? null) ? (string)$rawAnswers[$code] : '';
        $valid = false;
        foreach ($question['options'] ?? [] as $option) {
            if ((string)($option['code'] ?? '') === $selected) { $valid = true; break; }
        }
        if (!$valid) throw new DomainException("Select a valid answer for {$code}.");
        $draftAnswers[$code] = $selected;
    }
    ksort($savedAnswers);
    ksort($draftAnswers);
    $snapshot = json_decode((string)$session['result_snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($snapshot)) throw new RuntimeException('Current result snapshot is invalid.');
    $duration = max(0, (int)($snapshot['duration_seconds'] ?? 0));
    $savedResult = study_interest_score_versioned((string)$session['algorithm_version'], $configuration, $savedAnswers, $duration);
    if (!study_interest_snapshot_matches_result($snapshot, $savedResult)) throw new RuntimeException('Current answers do not reproduce the stored result.');

    $preview = study_interest_correction_preview(
        $configuration,
        (string)$session['algorithm_version'],
        $savedAnswers,
        $draftAnswers,
        $duration,
        $targetProgram,
        $savedResult
    );
    $revisionCheck = $pdo->prepare('SELECT status,result_revision FROM study_interest_sessions WHERE id=? LIMIT 1');
    $revisionCheck->execute([(int)$session['id']]);
    $currentSession = $revisionCheck->fetch(PDO::FETCH_ASSOC);
    if (!is_array($currentSession) || (string)$currentSession['status'] !== 'completed'
        || (int)$currentSession['result_revision'] !== (int)$expectedRevision) {
        throw new DomainException('This result changed while guidance was calculated. Reload the latest revision.');
    }
    study_interest_json(['ok' => true, 'revision' => (int)$expectedRevision, 'preview' => $preview]);
} catch (Throwable $error) {
    error_log('[study-interest] correction preview failed: ' . $error->getMessage());
    study_interest_json(['ok' => false, 'error' => $error instanceof DomainException ? $error->getMessage() : __('The correction preview could not be calculated.')], $error instanceof DomainException ? 422 : 500);
}
