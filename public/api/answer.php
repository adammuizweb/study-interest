<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') study_interest_json(['ok' => false, 'error' => 'Method not allowed'], 405);
study_interest_require_csrf();
$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) study_interest_json(['ok' => false, 'error' => 'Invalid request'], 400);
$publicId = trim((string)($input['session'] ?? ''));
$questionId = filter_var($input['question_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$optionId = filter_var($input['option_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($questionId === false || $optionId === false) study_interest_json(['ok' => false, 'error' => 'Invalid answer'], 422);
try {
    $pdo->beginTransaction();
    $session = study_interest_session($pdo, $publicId, true);
    if ($session === null) throw new DomainException('Session unavailable.');
    study_interest_save_answer($pdo, $session, (int)$questionId, (int)$optionId);
    $pdo->commit();
    study_interest_json(['ok' => true]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (!$error instanceof DomainException) error_log('[study-interest] answer save failed: ' . $error->getMessage());
    study_interest_json(['ok' => false, 'error' => $error instanceof DomainException ? $error->getMessage() : 'Answer could not be saved'], $error instanceof DomainException ? 422 : 500);
}
