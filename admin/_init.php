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
