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
    if (!function_exists('stateless_csrf_check') || !stateless_csrf_check($token, 604800)) {
        study_interest_json(['ok' => false, 'error' => 'CSRF token mismatch'], 419);
    }
}

function study_interest_published_version(PDO $pdo): ?array
{
    $stmt = $pdo->query("SELECT v.*, t.code AS test_code, t.title AS test_title
        FROM study_interest_test_versions v
        JOIN study_interest_tests t ON t.id = v.test_id
        WHERE v.status = 'published' ORDER BY v.published_at DESC, v.id DESC LIMIT 1");
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    return is_array($row) ? $row : null;
}

function study_interest_public_questions(PDO $pdo, int $versionId): array
{
    $stmt = $pdo->prepare("SELECT q.id, q.question_code, q.prompt, q.question_type, q.required,
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
