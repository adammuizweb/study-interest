<?php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) {
    http_response_code(403);
    exit;
}
adiwira_require_permission($pdo, 'plugin.study-interest.dashboard.access', false);
$manifest = plugin_manifest('study-interest');
$version = is_array($manifest) ? (string)($manifest['version'] ?? '') : '';
echo '<div class="wrap"><h1>Study Interest Explorer</h1><p>Foundation version ' . htmlspecialchars($version, ENT_QUOTES, 'UTF-8') . ' is installed.</p><p>Configuration and publishing are intentionally unavailable until an assessment version is reviewed and frozen.</p></div>';
