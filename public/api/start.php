<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/helpers.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') study_interest_json(['ok' => false, 'error' => 'Method not allowed'], 405);
study_interest_require_csrf();
if (!study_interest_rate_limit($pdo, 'start', 3600, 20)) study_interest_json(['ok' => false, 'error' => 'Too many attempts. Please try again later.'], 429);
$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input) || empty($input['consent'])) study_interest_json(['ok' => false, 'error' => 'Consent is required'], 422);
$name = mb_substr(trim((string)($input['name'] ?? '')), 0, 120, 'UTF-8');
$school = mb_substr(trim((string)($input['school'] ?? '')), 0, 191, 'UTF-8');
$classLevel = mb_substr(trim((string)($input['class_level'] ?? '')), 0, 40, 'UTF-8');
$email = mb_substr(trim((string)($input['email'] ?? '')), 0, 191, 'UTF-8');
$phone = mb_substr(trim((string)($input['phone'] ?? '')), 0, 40, 'UTF-8');
$contactConsent = !empty($input['contact_consent']);
if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($school, 'UTF-8') < 2 || $classLevel === '') study_interest_json(['ok' => false, 'error' => 'Name, school, and current level are required'], 422);
if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) study_interest_json(['ok' => false, 'error' => 'Email is invalid'], 422);
if ($phone !== '' && preg_match('/\A[0-9+().\- ]{6,40}\z/', $phone) !== 1) study_interest_json(['ok' => false, 'error' => 'Phone number is invalid'], 422);
if (($email !== '' || $phone !== '') && !$contactConsent) study_interest_json(['ok' => false, 'error' => 'Contact consent is required when contact details are provided'], 422);
$version = study_interest_published_version($pdo);
if ($version === null) study_interest_json(['ok' => false, 'error' => 'No published assessment is available yet'], 503);
$requestedVersionId = filter_var($input['version_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$requestedHash = is_string($input['configuration_hash'] ?? null) ? trim($input['configuration_hash']) : '';
if ($requestedVersionId === false || $requestedVersionId === null || preg_match('/\A[a-f0-9]{64}\z/', $requestedHash) !== 1
    || (int)$version['id'] !== (int)$requestedVersionId || !hash_equals((string)$version['configuration_hash'], $requestedHash)) {
    study_interest_json(['ok' => false, 'error' => 'The assessment changed. Reload this page before starting.'], 409);
}
$publicId = study_interest_uuid();
$token = bin2hex(random_bytes(32));
$now = study_interest_now_utc();
$contact = ['name' => $name, 'school' => $school, 'class_level' => $classLevel];
if ($contactConsent) { $contact['email'] = $email; $contact['phone'] = $phone; }
$stmt = $pdo->prepare('INSERT INTO study_interest_sessions (public_id,test_id,version_id,token_hash,consent_at_utc,contact_consent_at_utc,started_at_utc,last_activity_at_utc,contact_json) VALUES (:public,:test,:version,:token,:consent,:contact_consent,:started,:activity,:contact)');
$stmt->execute([
    ':public' => $publicId,
    ':test' => (int)$version['test_id'],
    ':version' => (int)$version['id'],
    ':token' => hash('sha256', $token),
    ':consent' => $now,
    ':contact_consent' => $contactConsent ? $now : null,
    ':started' => $now,
    ':activity' => $now,
    ':contact' => json_encode($contact, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
]);
setcookie(study_interest_session_cookie_name($publicId), $token, ['expires' => time() + 86400 * 30, 'path' => '/study-interest', 'secure' => study_interest_cookie_secure(), 'httponly' => true, 'samesite' => 'Lax']);
study_interest_json(['ok' => true, 'session' => ['public_id' => $publicId, 'version_code' => (string)$version['version_code']]]);
