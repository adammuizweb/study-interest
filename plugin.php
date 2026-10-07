<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/configuration.php';
require_once __DIR__ . '/includes/scoring.php';
require_once __DIR__ . '/includes/assessment.php';
require_once __DIR__ . '/includes/export.php';

function study_interest_entrypoint(string $route): ?string
{
    $entrypoints = [
        '' => 'public/index.php',
        'index.php' => 'public/index.php',
        'api/start' => 'public/api/start.php',
        'api/start.php' => 'public/api/start.php',
        'api/answer' => 'public/api/answer.php',
        'api/answer.php' => 'public/api/answer.php',
        'api/complete' => 'public/api/complete.php',
        'api/complete.php' => 'public/api/complete.php',
    ];
    $relative = $entrypoints[trim($route, '/')] ?? null;
    if ($relative === null) return null;
    $root = realpath(__DIR__);
    $file = realpath(__DIR__ . '/' . $relative);
    return $root !== false && $file !== false && is_file($file)
        && str_starts_with($file, $root . DIRECTORY_SEPARATOR) ? $file : null;
}

function study_interest_is_public_request(): bool
{
    $path = rawurldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/'));
    return $path === '/study-interest' || str_starts_with($path, '/study-interest/');
}

function study_interest_is_admin_request(): bool
{
    $page = trim((string)($_GET['page'] ?? ''), '/');
    return $page === 'admin/tools/study-interest' || str_starts_with($page, 'admin/tools/study-interest/');
}

function study_interest_admin_assets(): void
{
    if (!study_interest_is_admin_request()) return;
    echo '<link rel="stylesheet" href="/static/plugins/study-interest/admin.css?v=0.9.0">' . PHP_EOL;
    echo '<script src="/static/plugins/study-interest/admin.js?v=0.9.0" defer></script>' . PHP_EOL;
}

if (function_exists('register_frontend_route')) {
    register_frontend_route('study-interest', function (PDO $pdo): void {
        $path = rawurldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/'));
        $route = $path === '/study-interest' ? '' : substr($path, strlen('/study-interest/'));
        $entrypoint = study_interest_entrypoint($route);
        if ($entrypoint === null) {
            http_response_code(404);
            echo 'Study Interest page not found';
            return;
        }
        require $entrypoint;
    });
}

if (function_exists('add_action')) {
    add_action('admin_head', 'study_interest_admin_assets');
    add_action('plugin_uninstall', function (string $name): void {
        if ($name !== 'study-interest') return;
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!($pdo instanceof PDO)) return;
        foreach ([
            'study_interest_audit_log', 'study_interest_rate_limits', 'study_interest_result_revisions', 'study_interest_result_flags',
            'study_interest_program_results', 'study_interest_dimension_results', 'study_interest_answers', 'study_interest_sessions',
            'study_interest_program_weights', 'study_interest_programs', 'study_interest_option_scores',
            'study_interest_options', 'study_interest_questions', 'study_interest_sections',
            'study_interest_dimensions', 'study_interest_test_versions', 'study_interest_tests',
        ] as $table) $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        $pdo->prepare("DELETE FROM settings WHERE `key` LIKE 'study_interest_%'")->execute();
    });
}
