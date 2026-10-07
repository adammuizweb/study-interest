<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') study_interest_json(['ok' => false, 'error' => 'Method not allowed'], 405);
study_interest_require_csrf();
$input = json_decode((string)file_get_contents('php://input'), true);
$publicId = is_array($input) ? trim((string)($input['session'] ?? '')) : '';
try {
    $pdo->beginTransaction();
    $session = study_interest_session($pdo, $publicId, true);
    if ($session === null) throw new DomainException('Session unavailable.');
    study_interest_complete($pdo, $session);
    $pdo->commit();
    study_interest_json(['ok' => true, 'result_url' => '/study-interest/?session=' . rawurlencode($publicId)]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (!$error instanceof DomainException) error_log('[study-interest] completion failed: ' . $error->getMessage());
    study_interest_json(['ok' => false, 'error' => $error instanceof DomainException ? $error->getMessage() : 'Assessment could not be completed'], $error instanceof DomainException ? 422 : 500);
}
