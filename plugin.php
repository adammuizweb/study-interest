<?php
declare(strict_types=1);

const STUDY_INTEREST_SCHEMA_REVISION = '20261007-foundation';

require_once __DIR__ . '/includes/helpers.php';

function study_interest_quiz_dependency_ready(): bool
{
    return function_exists('quiz_extension_api_version')
        && version_compare(quiz_extension_api_version(), '1.0.0', '>=');
}

function study_interest_schema_is_ready(PDO $pdo): bool
{
    $manifest = function_exists('plugin_manifest') ? plugin_manifest('study-interest') : null;
    $version = is_array($manifest) ? (string)($manifest['version'] ?? '') : '';
    return $version !== ''
        && function_exists('settings_get')
        && settings_get($pdo, 'study_interest_schema_version', '') === $version
        && settings_get($pdo, 'study_interest_schema_revision', '') === STUDY_INTEREST_SCHEMA_REVISION;
}

function study_interest_install_schema(PDO $pdo): void
{
    if (!function_exists('settings_get') || !function_exists('settings_set')) return;
    $manifest = function_exists('plugin_manifest') ? plugin_manifest('study-interest') : null;
    $version = is_array($manifest) ? (string)($manifest['version'] ?? '') : '';
    if ($version === '' || study_interest_schema_is_ready($pdo)) return;

    $sql = file_get_contents(__DIR__ . '/schema.sql');
    if (!is_string($sql) || trim($sql) === '') throw new RuntimeException('Study Interest schema is unavailable.');
    $pdo->exec($sql);
    if (!settings_set($pdo, 'study_interest_schema_revision', STUDY_INTEREST_SCHEMA_REVISION)
        || !settings_set($pdo, 'study_interest_schema_version', $version)) {
        throw new RuntimeException('Study Interest schema version could not be recorded.');
    }
}

function study_interest_entrypoint(string $route): ?string
{
    $entrypoints = [
        '' => 'public/index.php',
        'index.php' => 'public/index.php',
        'api/start' => 'public/api/start.php',
        'api/start.php' => 'public/api/start.php',
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

if (function_exists('register_frontend_route')) {
    register_frontend_route('study-interest', function (PDO $pdo): void {
        try {
            study_interest_install_schema($pdo);
        } catch (Throwable $error) {
            error_log('[study-interest] schema installation failed: ' . $error->getMessage());
            http_response_code(503);
            echo 'Study Interest is not ready.';
            return;
        }
        if (!study_interest_schema_is_ready($pdo)) {
            http_response_code(503);
            echo 'Study Interest is not ready.';
            return;
        }
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
    add_action('admin_menu', function (): void {
        if (!defined('ADMIN_BASE_PATH') || !function_exists('svg_ico')
            || !function_exists('plugin_resolve_route') || !function_exists('plugin_route_is_allowed')) return;
        $pdo = $GLOBALS['pdo'] ?? null;
        $uid = function_exists('current_user_id') ? (int)current_user_id() : 0;
        if (!($pdo instanceof PDO) || $uid <= 0) return;
        $route = plugin_resolve_route('admin/tools/study-interest');
        if (!is_array($route) || !plugin_route_is_allowed($pdo, $route, $uid)) return;
        $base = ADMIN_BASE_PATH;
        $active = trim((string)($_GET['page'] ?? ''), '/') === 'admin/tools/study-interest';
        echo '<li class="adam-nav-item"><a class="adam-nav-link' . ($active ? ' adam-nav-link--active' : '')
            . '" href="' . h($base . '/?page=admin/tools/study-interest') . '">'
            . '<span class="adam-nav-icon" aria-hidden="true">' . svg_ico('compass') . '</span>'
            . '<span class="adam-nav-text">Study Interest</span></a></li>';
    });

    add_action('plugin_uninstall', function (string $name): void {
        if ($name !== 'study-interest') return;
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!($pdo instanceof PDO)) return;
        foreach ([
            'study_interest_audit_log', 'study_interest_answers', 'study_interest_sessions',
            'study_interest_program_weights', 'study_interest_programs', 'study_interest_option_scores',
            'study_interest_options', 'study_interest_questions', 'study_interest_sections',
            'study_interest_dimensions', 'study_interest_test_versions', 'study_interest_tests',
        ] as $table) $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        $pdo->prepare("DELETE FROM settings WHERE `key` LIKE 'study_interest_%'")->execute();
    });
}
