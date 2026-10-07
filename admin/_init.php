<?php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) {
    http_response_code(403);
    exit;
}
if (!isset($pdo) || !($pdo instanceof PDO)) throw new RuntimeException('Database connection is unavailable.');
$studyInterestUserId = function_exists('current_user_id') ? (int)current_user_id() : 0;
if ($studyInterestUserId < 1) {
    http_response_code(403);
    exit;
}

if (!function_exists('adiwira_redirect_with_flash') && defined('DASH_PATH')) {
    require_once rtrim((string)DASH_PATH, DIRECTORY_SEPARATOR) . '/admin/_notify.php';
}

$studyInterestBase = rtrim((string)ADMIN_BASE_PATH, '/') . '/?page=admin/tools/study-interest';
$studyInterestActionBase = rtrim((string)ADMIN_BASE_PATH, '/') . '/admin/tools/study-interest';

function study_interest_admin_url(string $route = '', array $query = []): string
{
    $page = 'admin/tools/study-interest' . ($route !== '' ? '/' . trim($route, '/') : '');
    return rtrim((string)ADMIN_BASE_PATH, '/') . '/?' . http_build_query(['page' => $page] + $query);
}

function study_interest_admin_action_url(string $route): string
{
    return rtrim((string)ADMIN_BASE_PATH, '/') . '/admin/tools/study-interest/' . trim($route, '/') . '.php';
}

function study_interest_admin_can(string $permission): bool
{
    global $pdo, $studyInterestUserId;
    return function_exists('user_can') && user_can($pdo, $studyInterestUserId, $permission);
}

function study_interest_admin_nav(string $active): void
{
    $items = [
        ['overview', '', __('Overview'), 'plugin.study-interest.dashboard.access'],
        ['assessments', 'assessments', __('Assessments'), 'plugin.study-interest.config.view'],
        ['structure', 'structure', __('Structure'), 'plugin.study-interest.config.view'],
        ['questions', 'questions', __('Question bank'), 'plugin.study-interest.config.view'],
        ['participants', 'participants', __('Participants'), 'plugin.study-interest.contacts.view'],
        ['sessions', 'sessions', __('Sessions'), 'plugin.study-interest.sessions.view'],
        ['result-page', 'result-page', __('Result page'), 'plugin.study-interest.presentation.view'],
        ['analytics', 'results', __('Analytics'), 'plugin.study-interest.results.view'],
        ['audit', 'audit', __('Activity log'), 'plugin.study-interest.audit.view'],
    ];
    echo '<nav class="sie-workspace-nav" aria-label="' . study_interest_h(__('Study Interest workspace')) . '">';
    foreach ($items as [$key, $route, $label, $permission]) {
        if (!study_interest_admin_can($permission)) continue;
        $current = $active === $key ? ' aria-current="page"' : '';
        echo '<a href="' . study_interest_h(study_interest_admin_url($route)) . '"' . $current . '>' . study_interest_h($label) . '</a>';
    }
    echo '</nav>';
}

function study_interest_admin_contact(?string $json): array
{
    if ($json === null || $json === '') return [];
    $contact = json_decode($json, true);
    return is_array($contact) ? $contact : [];
}

function study_interest_admin_page_url(string $route, array $query, int $page): string
{
    $query['p'] = $page;
    return study_interest_admin_url($route, $query);
}

function study_interest_admin_begin_snapshot(PDO $pdo): void
{
    if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->beginTransaction();
}

function study_interest_admin_redirect(string $type, string $message, ?string $location = null): never
{
    global $studyInterestBase;
    $target = $location ?? $studyInterestBase;
    if (function_exists('adiwira_redirect_with_flash')) adiwira_redirect_with_flash($target, $type, $message, 303);
    header('Location: ' . $target, true, 303);
    exit;
}

function study_interest_admin_datetime(?string $utc): string
{
    if ($utc === null || trim($utc) === '') return __('Not yet');
    if (function_exists('app_utc_mysql_to_site') && function_exists('app_display_datetime')) {
        $date = app_utc_mysql_to_site($utc);
        if ($date instanceof DateTimeInterface) return app_display_datetime($date);
    }
    return $utc;
}
