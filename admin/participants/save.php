<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.contacts.manage', false);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { adiwira_render_404(); return; }
$publicId = trim((string)($_POST['session_public_id'] ?? ''));
$returnUrl = study_interest_admin_url('participants/edit', ['s' => $publicId]);
if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) study_interest_admin_redirect('error', __('The security token expired. Please try again.'), $returnUrl);

try {
    if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $publicId) !== 1) throw new DomainException('Participant record is invalid.');
    $expectedContactHash = trim((string)($_POST['expected_contact_hash'] ?? ''));
    if (preg_match('/\A[a-f0-9]{64}\z/', $expectedContactHash) !== 1) throw new DomainException('Participant snapshot revision is invalid.');
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
    $school = mb_substr(trim((string)($_POST['school'] ?? '')), 0, 191);
    $classLevel = mb_substr(trim((string)($_POST['class_level'] ?? '')), 0, 40);
    $email = mb_substr(trim((string)($_POST['email'] ?? '')), 0, 191);
    $phone = mb_substr(trim((string)($_POST['phone'] ?? '')), 0, 40);
    $contactConsent = isset($_POST['contact_consent']);
    if (mb_strlen($name) < 2 || mb_strlen($school) < 2 || $classLevel === '') throw new DomainException('Name, school, and current stage are required.');
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new DomainException('Email is invalid.');
    if ($phone !== '' && preg_match('/\A[0-9+().\- ]{6,40}\z/', $phone) !== 1) throw new DomainException('Phone number is invalid.');
    if (($email !== '' || $phone !== '') && !$contactConsent) throw new DomainException('Contact consent is required when contact details are stored.');
    $contact = ['name' => $name, 'school' => $school, 'class_level' => $classLevel];
    if ($contactConsent) { $contact['email'] = $email; $contact['phone'] = $phone; }
    $contactJson = json_encode($contact, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->beginTransaction();
    if (!authorization_lock_actor_permissions($pdo, $studyInterestUserId) || !user_can($pdo, $studyInterestUserId, 'plugin.study-interest.contacts.manage')) throw new RuntimeException('Participant management permission changed.');
    $statement = $pdo->prepare('SELECT id,contact_json,contact_consent_at_utc FROM study_interest_sessions WHERE public_id=? LIMIT 1 FOR UPDATE');
    $statement->execute([$publicId]);
    $session = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($session)) throw new DomainException('Participant record was not found.');
    $currentContactHash = study_interest_participant_snapshot_hash((string)($session['contact_json'] ?? ''), $session['contact_consent_at_utc'] !== null ? (string)$session['contact_consent_at_utc'] : null);
    if (!hash_equals($currentContactHash, $expectedContactHash)) throw new DomainException('Participant information changed while this form was open. Review the latest record and try again.');
    $before = study_interest_admin_contact((string)($session['contact_json'] ?? ''));
    $changedFields = [];
    foreach (['name', 'school', 'class_level', 'email', 'phone'] as $field) if ((string)($before[$field] ?? '') !== (string)($contact[$field] ?? '')) $changedFields[] = $field;
    $beforeConsent = $session['contact_consent_at_utc'] !== null;
    if ($beforeConsent !== $contactConsent) $changedFields[] = 'contact_consent';
    if ($changedFields === []) throw new DomainException('No participant information changed.');
    $consentAt = $contactConsent ? ($session['contact_consent_at_utc'] ?: study_interest_now_utc()) : null;
    $pdo->prepare('UPDATE study_interest_sessions SET contact_json=?,contact_consent_at_utc=? WHERE id=?')->execute([$contactJson, $consentAt, (int)$session['id']]);
    study_interest_audit($pdo, $studyInterestUserId, 'participant.updated', 'session', $publicId,
        ['contact_hash' => hash('sha256', (string)$session['contact_json'])], ['contact_hash' => hash('sha256', $contactJson), 'changed_fields' => $changedFields]);
    $pdo->commit();
    study_interest_admin_redirect('success', __('Participant information saved.'), $returnUrl);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[study-interest] participant update failed: ' . $error->getMessage());
    study_interest_admin_redirect('error', $error instanceof DomainException ? $error->getMessage() : __('Participant information could not be saved.'), $returnUrl);
}
