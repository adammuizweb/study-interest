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
$studyInterestBase = ADMIN_BASE_PATH . '/?page=admin/tools/study-interest';

function study_interest_admin_redirect(string $notice): never
{
    $allowed = ['imported', 'published', 'invalid', 'failed'];
    if (!in_array($notice, $allowed, true)) $notice = 'failed';
    header('Location: ' . ADMIN_BASE_PATH . '/?page=admin/tools/study-interest&notice=' . rawurlencode($notice), true, 303);
    exit;
}
