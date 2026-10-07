<?php
declare(strict_types=1);

function study_interest_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function study_interest_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function study_interest_now_utc(): string
{
    return function_exists('app_now_utc_mysql')
        ? app_now_utc_mysql()
        : (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
}

function study_interest_result_presentation_setting_key(): string
{
    return 'study_interest_result_presentation';
}

function study_interest_result_presentation_defaults(): array
{
    return [
        'schema' => 1,
        'mode' => 'full',
        'sections' => [
            'hero' => 'show',
            'directions' => 'show',
            'dimensions' => 'show',
            'interpretation' => 'show',
            'pathways' => 'show',
            'next_steps' => 'show',
            'disclaimer' => 'show',
        ],
        'masked_title' => 'Hasilmu sedang ditinjau',
        'masked_message' => 'Ringkasan hasil belum dibuka untuk peserta. Silakan hubungi pengelola atau konselor untuk informasi lebih lanjut.',
        'hidden_title' => 'Hasil belum dapat ditampilkan',
        'hidden_message' => 'Pengelola belum membuka halaman hasil untuk peserta.',
        'section_mask_message' => 'Bagian ini hanya tersedia melalui pendampingan pengelola atau konselor.',
    ];
}

function study_interest_result_presentation_text(mixed $value, string $fallback, int $maximum): string
{
    $text = trim((string)$value);
    if ($text === '' || preg_match('//u', $text) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) === 1) return $fallback;
    return mb_substr($text, 0, $maximum);
}

function study_interest_result_presentation_normalize(mixed $value): array
{
    $defaults = study_interest_result_presentation_defaults();
    if (!is_array($value)) return $defaults;
    $mode = (string)($value['mode'] ?? '');
    if (!in_array($mode, ['full', 'masked', 'hidden'], true)) $mode = $defaults['mode'];
    $sections = [];
    $postedSections = is_array($value['sections'] ?? null) ? $value['sections'] : [];
    foreach ($defaults['sections'] as $key => $default) {
        $state = (string)($postedSections[$key] ?? '');
        $sections[$key] = in_array($state, ['show', 'mask', 'hide'], true) ? $state : $default;
    }
    return [
        'schema' => 1,
        'mode' => $mode,
        'sections' => $sections,
        'masked_title' => study_interest_result_presentation_text($value['masked_title'] ?? '', $defaults['masked_title'], 120),
        'masked_message' => study_interest_result_presentation_text($value['masked_message'] ?? '', $defaults['masked_message'], 500),
        'hidden_title' => study_interest_result_presentation_text($value['hidden_title'] ?? '', $defaults['hidden_title'], 120),
        'hidden_message' => study_interest_result_presentation_text($value['hidden_message'] ?? '', $defaults['hidden_message'], 500),
        'section_mask_message' => study_interest_result_presentation_text($value['section_mask_message'] ?? '', $defaults['section_mask_message'], 500),
    ];
}

function study_interest_result_presentation_fail_closed(): array
{
    $policy = study_interest_result_presentation_defaults();
    $policy['mode'] = 'hidden';
    $policy['hidden_title'] = 'Hasil sementara tidak tersedia';
    $policy['hidden_message'] = 'Pengaturan akses hasil tidak dapat diverifikasi. Silakan hubungi pengelola layanan.';
    return $policy;
}

function study_interest_result_presentation_is_valid(mixed $value): bool
{
    if (!is_array($value) || (int)($value['schema'] ?? 0) !== 1 || !in_array((string)($value['mode'] ?? ''), ['full', 'masked', 'hidden'], true)) return false;
    if (!is_array($value['sections'] ?? null)) return false;
    foreach (study_interest_result_presentation_defaults()['sections'] as $key => $_default) {
        if (!in_array((string)($value['sections'][$key] ?? ''), ['show', 'mask', 'hide'], true)) return false;
    }
    foreach (['masked_title' => 120, 'masked_message' => 500, 'hidden_title' => 120, 'hidden_message' => 500, 'section_mask_message' => 500] as $key => $maximum) {
        $text = $value[$key] ?? null;
        if (!is_string($text) || trim($text) === '' || mb_strlen($text) > $maximum || preg_match('//u', $text) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) === 1) return false;
    }
    return true;
}

function study_interest_result_presentation(PDO $pdo): array
{
    try {
        $statement = $pdo->prepare('SELECT value FROM settings WHERE `key`=? LIMIT 1');
        $statement->execute([study_interest_result_presentation_setting_key()]);
        $raw = $statement->fetchColumn();
        if ($raw === false) return study_interest_result_presentation_fail_closed();
        if (!is_string($raw) || $raw === '') return study_interest_result_presentation_fail_closed();
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!study_interest_result_presentation_is_valid($decoded)) return study_interest_result_presentation_fail_closed();
        return study_interest_result_presentation_normalize($decoded);
    } catch (Throwable $error) {
        error_log('[study-interest] result presentation policy unavailable: ' . $error->getMessage());
        return study_interest_result_presentation_fail_closed();
    }
}

function study_interest_result_presentation_hash(array $policy): string
{
    return hash('sha256', json_encode(study_interest_result_presentation_normalize($policy), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function study_interest_participant_snapshot_hash(?string $contactJson, ?string $contactConsentAtUtc): string
{
    return hash('sha256', (string)$contactJson . "\n" . (string)$contactConsentAtUtc);
}

function study_interest_audit(PDO $pdo, int $actorId, string $action, string $entityType, ?string $entityId, ?array $before, ?array $after): void
{
    $statement = $pdo->prepare('INSERT INTO study_interest_audit_log (actor_id,action,entity_type,entity_id,before_json,after_json,request_id,created_at_utc) VALUES (?,?,?,?,?,?,?,?)');
    $statement->execute([
        $actorId ?: null, mb_substr($action, 0, 80), mb_substr($entityType, 0, 80), $entityId !== null ? mb_substr($entityId, 0, 80) : null,
        $before !== null ? json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        $after !== null ? json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        mb_substr((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''), 0, 80) ?: null, study_interest_now_utc(),
    ]);
}

function study_interest_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function study_interest_require_csrf(): void
{
    $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '');
    if (!function_exists('stateless_csrf_check') || !stateless_csrf_check($token, 7200)) {
        study_interest_json(['ok' => false, 'error' => 'CSRF token mismatch'], 419);
    }
}

function study_interest_published_version(PDO $pdo): ?array
{
    $stmt = $pdo->query("SELECT v.*, t.code AS test_code, t.title AS test_title
        FROM study_interest_test_versions v
        JOIN study_interest_tests t ON t.id = v.test_id
        WHERE v.status = 'published' ORDER BY v.published_at_utc DESC, v.id DESC LIMIT 1");
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    return is_array($row) ? $row : null;
}

function study_interest_session_cookie_name(string $publicId): string
{
    return 'study_interest_' . substr(hash('sha256', strtolower($publicId)), 0, 16);
}

function study_interest_session(PDO $pdo, string $publicId, bool $lock = false): ?array
{
    if (preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/i', $publicId) !== 1) return null;
    $statement = $pdo->prepare('SELECT s.*,v.version_code,v.algorithm_version,v.configuration_hash,v.configuration_json,t.title AS test_title '
        . 'FROM study_interest_sessions s JOIN study_interest_test_versions v ON v.id=s.version_id JOIN study_interest_tests t ON t.id=s.test_id '
        . 'WHERE s.public_id=? LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
    $statement->execute([$publicId]);
    $session = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($session)) return null;
    $token = (string)($_COOKIE[study_interest_session_cookie_name($publicId)] ?? '');
    if ($token === '' || !hash_equals((string)$session['token_hash'], hash('sha256', $token))) return null;
    return $session;
}

function study_interest_configuration_from_session(array $session): array
{
    $configuration = json_decode((string)($session['configuration_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($configuration)) throw new RuntimeException('Assessment configuration is unavailable.');
    $json = study_interest_configuration_json($configuration);
    if (!hash_equals((string)$session['configuration_hash'], hash('sha256', $json))) throw new RuntimeException('Assessment configuration integrity check failed.');
    return $configuration;
}

function study_interest_rate_limit(PDO $pdo, string $action, int $windowSeconds, int $maximum): bool
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) return false;
    $secret = function_exists('app_secret') ? (string)app_secret() : (string)getenv('SESSION_SECRET');
    if ($secret === '') return false;
    $bucket = intdiv(time(), max(1, $windowSeconds));
    $hash = hash_hmac('sha256', $ip, $secret);
    $statement = $pdo->prepare('INSERT INTO study_interest_rate_limits (ip_hash,action,bucket,request_count) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE request_count=request_count+1');
    $statement->execute([$hash, mb_substr($action, 0, 40), $bucket]);
    if (random_int(1, 100) === 1) $pdo->prepare('DELETE FROM study_interest_rate_limits WHERE bucket<?')->execute([$bucket - 48]);
    $count = $pdo->prepare('SELECT request_count FROM study_interest_rate_limits WHERE ip_hash=? AND action=? AND bucket=?');
    $count->execute([$hash, mb_substr($action, 0, 40), $bucket]);
    return (int)$count->fetchColumn() <= $maximum;
}

function study_interest_cookie_secure(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    $allowInsecure = in_array(strtolower(trim((string)getenv('SESSION_ALLOW_INSECURE_COOKIES'))), ['1', 'true', 'yes', 'on'], true);
    $forceHttps = in_array(strtolower(trim((string)getenv('FORCE_HTTPS'))), ['1', 'true', 'yes', 'on'], true);
    return $forceHttps || !$allowInsecure;
}

function study_interest_public_questions(PDO $pdo, int $versionId): array
{
    $stmt = $pdo->prepare("SELECT q.id, q.question_code, q.title, q.prompt, q.question_type, q.required,
        s.code AS section_code, s.label AS section_label, s.display_order AS section_order
        FROM study_interest_questions q
        JOIN study_interest_sections s ON s.id = q.section_id
        WHERE q.version_id = :version AND q.required = 1
        ORDER BY s.display_order, q.display_order, q.id");
    $stmt->execute([':version' => $versionId]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($questions as &$question) {
        $options = $pdo->prepare('SELECT id, option_code, label, display_order FROM study_interest_options WHERE question_id = :question ORDER BY display_order, id');
        $options->execute([':question' => (int)$question['id']]);
        $question['options'] = $options->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($question);
    return $questions;
}
