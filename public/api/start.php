<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/helpers.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') study_interest_json(['ok' => false, 'error' => 'Method not allowed'], 405);
study_interest_require_csrf();
$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input) || empty($input['consent'])) study_interest_json(['ok' => false, 'error' => 'Consent is required'], 422);
$version = study_interest_published_version($pdo);
if ($version === null) study_interest_json(['ok' => false, 'error' => 'No published assessment is available yet'], 503);
$publicId = study_interest_uuid();
$token = bin2hex(random_bytes(32));
$stmt = $pdo->prepare('INSERT INTO study_interest_sessions (public_id, test_id, version_id, token_hash, consent_at) VALUES (:public, :test, :version, :token, NOW())');
$stmt->execute([
    ':public' => $publicId,
    ':test' => (int)$version['test_id'],
    ':version' => (int)$version['id'],
    ':token' => hash('sha256', $token),
]);
setcookie('study_interest_token', $token, ['expires' => time() + 86400 * 30, 'path' => '/study-interest', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
study_interest_json(['ok' => true, 'session' => ['public_id' => $publicId, 'version_code' => (string)$version['version_code']]]);
