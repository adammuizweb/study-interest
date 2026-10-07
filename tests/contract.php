<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $passed, string $message) use (&$failures): void {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$passed) $failures[] = $message;
};

$manifest = json_decode((string)file_get_contents($root . '/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
$check(($manifest['name'] ?? null) === 'study-interest', 'plugin slug is generic study-interest');
$check(($manifest['requires']['plugins']['quiz'] ?? null) === '>=1.4.12', 'Quiz dependency is explicit and versioned');
$check(($manifest['github_url'] ?? null) === 'https://github.com/adammuizweb/study-interest', 'repository URL is generic');
$check(is_file($root . '/schema.sql'), 'isolated schema exists');
$schema = (string)file_get_contents($root . '/schema.sql');
$check(str_contains($schema, 'study_interest_test_versions'), 'version table exists');
$check(str_contains($schema, 'result_snapshot_json'), 'result snapshot field exists');
$check(str_contains($schema, 'UNIQUE KEY `uq_study_interest_answer`'), 'answers are unique per session and question');
$check(!str_contains($schema, 'quiz_attempts'), 'schema does not reuse Quiz attempt tables');
$check(str_contains((string)file_get_contents($root . '/plugin.php'), "register_frontend_route('study-interest'"), 'public route is separate from Quiz');
$check(str_contains((string)file_get_contents($root . '/plugin.php'), 'quiz_extension_api_version'), 'dependency uses the public Quiz extension API');
$check(!str_contains((string)file_get_contents($root . '/plugin.php'), 'require_once __DIR__ . \'/../quiz'), 'plugin does not load Quiz internals by path');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " contract check(s) failed.\n");
    exit(1);
}
echo "Study Interest contract passed.\n";
