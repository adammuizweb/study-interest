<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/helpers.php';
require_once $root . '/includes/configuration.php';
require_once $root . '/includes/scoring.php';
require_once $root . '/includes/export.php';
$failures = [];
$check = static function (bool $passed, string $message) use (&$failures): void {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$passed) $failures[] = $message;
};

$manifest = json_decode((string)file_get_contents($root . '/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
$check(($manifest['name'] ?? null) === 'study-interest', 'plugin slug is generic study-interest');
$check(($manifest['version'] ?? null) === '0.7.0', 'plugin version is 0.7.0');
$check(($manifest['requires']['jyavani'] ?? null) === '>=2.3.174', 'Core requirement includes append-only plugin migrations');
$check(($manifest['requires']['plugins']['quiz'] ?? null) === '>=1.4.13', 'Quiz extension API dependency is explicit and versioned');
$check(($manifest['github_url'] ?? null) === 'https://github.com/adammuizweb/study-interest', 'repository URL is generic');
$check(in_array('pdo_mysql', $manifest['requires']['extensions'] ?? [], true), 'MySQL PDO requirement is explicit');
$check(in_array('zip', $manifest['requires']['extensions'] ?? [], true), 'ZIP requirement supports native Excel exports');

$migration = $root . '/migrations/0001-foundation.sql';
$schema = (string)file_get_contents($migration);
$check(is_file($migration) && is_file($root . '/migrations/0002-upgrade-foundation.php')
    && is_file($root . '/migrations/0003-verify-foundation.php')
    && is_file($root . '/migrations/0004-operational-workspace.php')
    && is_file($root . '/migrations/0005-verify-operational-workspace.php')
    && is_file($root . '/migrations/0006-seed-result-presentation.php')
    && is_file($root . '/migrations/0007-verify-result-presentation.php'), 'append-only foundation, workspace, and result-presentation migrations exist');
$check(!is_file($root . '/schema.sql'), 'request-time schema file was removed');
$check(str_contains($schema, 'study_interest_test_versions') && str_contains($schema, 'configuration_hash'), 'version table freezes configuration identity');
$check(str_contains($schema, 'result_snapshot_json'), 'result snapshot field exists');
$check(str_contains($schema, 'UNIQUE KEY `uq_study_interest_answer`'), 'answers are unique per session and question');
$check(str_contains($schema, 'study_interest_dimension_results') && str_contains($schema, 'study_interest_program_results') && str_contains($schema, 'study_interest_result_flags'), 'normalized result and flag tables exist');
$operationalMigration = (string)file_get_contents($root . '/migrations/0004-operational-workspace.php');
$check(str_contains($operationalMigration, 'study_interest_result_revisions') && str_contains($operationalMigration, 'result_revision')
    && str_contains($operationalMigration, 'UNIQUE KEY `uq_study_interest_result_revision`'), 'result corrections use numbered append-only revisions');
$check(str_contains($operationalMigration, 'information_schema.COLUMNS') && str_contains($operationalMigration, 'CREATE TABLE IF NOT EXISTS'), 'operational migration is retry-safe after partial DDL');
$presentationMigration = (string)file_get_contents($root . '/migrations/0006-seed-result-presentation.php');
$check(str_contains($presentationMigration, 'study_interest_result_presentation') && str_contains($presentationMigration, "'mode' => 'full'"), 'migration explicitly seeds the backward-compatible result-page policy');
$check(!str_contains($schema, 'quiz_attempts'), 'schema does not reuse Quiz attempt tables');
$check(!preg_match('/`(?:created|updated|started|completed|consent|published|retired|answered)_at`/', $schema), 'new instant columns use explicit UTC names');

$permissions = [];
foreach ($manifest['permissions'] ?? [] as $permission) $permissions[(string)$permission['key']] = $permission;
foreach (['dashboard.access', 'config.view', 'config.manage', 'config.publish', 'results.view', 'presentation.view', 'presentation.manage', 'contacts.view', 'contacts.manage', 'results.export', 'sessions.view', 'responses.view', 'results.correct', 'sessions.delete', 'workspace.export', 'audit.view'] as $suffix) {
    $check(isset($permissions['plugin.study-interest.' . $suffix]), 'permission is declared: ' . $suffix);
}
$check(($permissions['plugin.study-interest.config.publish']['delegable'] ?? null) === false, 'publishing permission is nondelegable');
$check(($permissions['plugin.study-interest.contacts.view']['delegable'] ?? null) === false, 'contact permission is separate and nondelegable');
$check(($permissions['plugin.study-interest.results.correct']['delegable'] ?? null) === false, 'result correction permission is separate and nondelegable');
$check(($permissions['plugin.study-interest.sessions.delete']['delegable'] ?? null) === false
    && ($permissions['plugin.study-interest.workspace.export']['delegable'] ?? null) === false, 'destructive and identifiable export permissions are nondelegable');
$check(($permissions['plugin.study-interest.presentation.manage']['delegable'] ?? null) === false, 'participant result-page management is nondelegable');
$routes = [];
foreach ($manifest['admin']['pages'] ?? [] as $route) $routes[(string)$route['route']] = $route;
$check(($routes['admin/tools/study-interest/import-baseline']['permission'] ?? null) === 'plugin.study-interest.config.manage', 'import route uses configuration permission');
$check(($routes['admin/tools/study-interest/publish']['permission'] ?? null) === 'plugin.study-interest.config.publish', 'publish route uses publishing permission');
$check(($routes['admin/tools/study-interest/results']['permission'] ?? null) === 'plugin.study-interest.results.view', 'analytics route uses result-view permission');
$check(($routes['admin/tools/study-interest/export']['permission'] ?? null) === 'plugin.study-interest.results.export', 'export route uses separate export permission');
$check(($routes['admin/tools/study-interest/participants']['permission'] ?? null) === 'plugin.study-interest.contacts.view', 'participant identity route uses contact permission');
$check(($routes['admin/tools/study-interest/sessions']['permission'] ?? null) === 'plugin.study-interest.sessions.view'
    && ($routes['admin/tools/study-interest/sessions/view']['permission'] ?? null) === 'plugin.study-interest.sessions.view', 'session list and detail use session-view permission');
$check(($routes['admin/tools/study-interest/sessions/save-correction']['permission'] ?? null) === 'plugin.study-interest.results.correct', 'result correction action uses dedicated permission');
$check(($routes['admin/tools/study-interest/audit']['permission'] ?? null) === 'plugin.study-interest.audit.view', 'audit route uses dedicated permission');
$check(($routes['admin/tools/study-interest/participants/edit']['permission'] ?? null) === 'plugin.study-interest.contacts.manage'
    && ($routes['admin/tools/study-interest/participants/save']['permission'] ?? null) === 'plugin.study-interest.contacts.manage', 'participant edits use dedicated contact-management permission');
$check(($routes['admin/tools/study-interest/sessions/delete']['permission'] ?? null) === 'plugin.study-interest.sessions.delete', 'session deletion uses dedicated permission');
$check(($routes['admin/tools/study-interest/workspace-export']['permission'] ?? null) === 'plugin.study-interest.workspace.export', 'operational exports use dedicated permission');
$check(($routes['admin/tools/study-interest/result-page']['permission'] ?? null) === 'plugin.study-interest.presentation.view'
    && ($routes['admin/tools/study-interest/result-page/save']['permission'] ?? null) === 'plugin.study-interest.presentation.manage', 'result-page policy separates viewing and management permissions');
$check(($routes['admin/tools/study-interest/assessments']['permission'] ?? null) === 'plugin.study-interest.config.view'
    && ($routes['admin/tools/study-interest/questions']['permission'] ?? null) === 'plugin.study-interest.config.view', 'hidden scoring routes use configuration-view permission');
$check(($manifest['admin']['nav'][0]['parent'] ?? null) === 'tools', 'manifest navigation uses the tools group');
foreach ($manifest['static']['copy'] ?? [] as $asset) {
    $check(is_file($root . '/' . (string)$asset['from']), 'static source exists: ' . (string)$asset['from']);
    $check(str_starts_with((string)$asset['to'], 'static/plugins/study-interest/'), 'static asset stays in plugin namespace');
}

$pluginSource = (string)file_get_contents($root . '/plugin.php');
$check(str_contains($pluginSource, "register_frontend_route('study-interest'"), 'public route is separate from Quiz');
$check(str_contains($pluginSource, 'quiz_extension_api_version'), 'dependency uses the public Quiz extension API');
$check(str_contains($pluginSource, "add_action('admin_head', 'study_interest_admin_assets')")
    && str_contains($pluginSource, '/static/plugins/study-interest/admin.css?v=0.7.0'), 'dashboard assets are scoped to Study Interest routes');
$check(!str_contains($pluginSource, 'study_interest_install_schema'), 'normal requests do not run schema installation');
$check(!str_contains($pluginSource, "'/../quiz") && !str_contains($pluginSource, 'quiz_attempts'), 'plugin does not load or query Quiz internals');
foreach (['api/answer' => 'public/api/answer.php', 'api/complete' => 'public/api/complete.php'] as $route => $file) {
    $check(str_contains($pluginSource, "'{$route}' => '{$file}'"), "explicit frontend entrypoint exists: {$route}");
}
$publishSource = (string)file_get_contents($root . '/admin/publish.php');
$check(str_contains($publishSource, "csrf_check") && str_contains($publishSource, 'authorization_lock_actor_permissions')
    && str_contains($publishSource, "plugin.study-interest.config.publish") && str_contains($publishSource, 'beginTransaction'), 'publish repeats CSRF and permission checks under transaction');
$exportSource = (string)file_get_contents($root . '/admin/export.php');
$check(str_contains($exportSource, 'csrf_check') && str_contains($exportSource, 'authorization_lock_actor_permissions')
    && str_contains($exportSource, 'results.exported') && !str_contains($exportSource, 'contact_json'), 'anonymized export is authorized, audited, and excludes contact data');
$workspaceExportSource = (string)file_get_contents($root . '/admin/workspace-export.php');
$check(str_contains($workspaceExportSource, 'authorization_lock_actor_permissions')
    && str_contains($workspaceExportSource, "'contacts.view'")
    && str_contains($workspaceExportSource, 'workspace.exported')
    && str_contains($workspaceExportSource, 'LIMIT 10001'), 'operational export is bounded, permission-locked, and audited');
$dashboardSource = (string)file_get_contents($root . '/admin/index.php');
$resultsSource = (string)file_get_contents($root . '/admin/results.php');
$check(str_contains($dashboardSource, '/import-baseline.php') && str_contains($dashboardSource, '/publish.php')
    && str_contains($resultsSource, '/export.php'), 'dashboard mutations use pre-layout direct action routes');
$check(!str_contains($dashboardSource, 'widefat') && !str_contains($resultsSource, 'widefat'), 'dashboard uses native scoped presentation instead of WordPress table classes');
$questionSaveSource = (string)file_get_contents($root . '/admin/questions/save.php');
$correctionSource = (string)file_get_contents($root . '/admin/sessions/save-correction.php');
$sessionViewSource = (string)file_get_contents($root . '/admin/sessions/view.php');
$check(str_contains($questionSaveSource, 'study_interest_replace_draft_configuration')
    && str_contains($questionSaveSource, 'authorization_lock_actor_permissions')
    && str_contains($questionSaveSource, "'draft'"), 'question authoring is transactional and draft-only');
$check(str_contains($correctionSource, 'study_interest_result_revisions')
    && str_contains($correctionSource, 'study_interest_score_versioned')
    && str_contains($correctionSource, 'expected_revision')
    && str_contains($correctionSource, 'plugin.study-interest.responses.view')
    && str_contains($correctionSource, 'result.corrected'), 'result correction recalculates and records an authorized audited optimistic revision');
$check(str_contains($sessionViewSource, 'plugin.study-interest.responses.view')
    && str_contains($sessionViewSource, 'plugin.study-interest.contacts.view'), 'session detail separates response and contact access');
$presentationSaveSource = (string)file_get_contents($root . '/admin/result-page/save.php');
$publicResultSource = (string)file_get_contents($root . '/public/index.php');
$check(str_contains($presentationSaveSource, 'csrf_check')
    && str_contains($presentationSaveSource, 'authorization_lock_actor_permissions')
    && str_contains($presentationSaveSource, 'expected_hash')
    && str_contains($presentationSaveSource, 'result_presentation.updated'), 'result-page policy save is transactional, optimistic, authorized, and audited');
$check(str_contains($publicResultSource, "['mode'] === 'hidden'")
    && str_contains($publicResultSource, "['mode'] === 'masked'")
    && str_contains($publicResultSource, "\$sectionState('directions')")
    && !str_contains($publicResultSource, 'filter: blur'), 'public result supports full, server-masked, and hidden output without cosmetic-only masking');
$check(str_contains($publicResultSource, 'direct_recommendation_limit')
    && str_contains($publicResultSource, "elseif (\$sectionState('pathways') === 'mask')")
    && str_contains($publicResultSource, "elseif (\$sectionState('interpretation') === 'mask')"), 'public result respects the immutable direction limit and masks optional sections without existence disclosure');
$participantEditSource = (string)file_get_contents($root . '/admin/participants/edit.php');
$participantSaveSource = (string)file_get_contents($root . '/admin/participants/save.php');
$check(str_contains($participantEditSource, 'expected_contact_hash')
    && str_contains($participantSaveSource, 'expected_contact_hash')
    && str_contains($participantSaveSource, 'hash_equals'), 'participant edits reject stale identity and consent snapshots');
$publicQuestionSource = (string)file_get_contents($root . '/includes/helpers.php');
$check(!str_contains($publicQuestionSource, 'option_scores s ON'), 'public question loader does not expose hidden scores');
$frontendSource = (string)file_get_contents($root . '/assets/frontend.js');
$check(str_contains($frontendSource, 'firstUnanswered < 0 ? data.questions.length - 1')
    && str_contains($frontendSource, 'else answers[String(question.id)] = previous;'), 'frontend resumes at the final answered question and reverts failed autosaves');
$startSource = (string)file_get_contents($root . '/public/api/start.php');
$check(str_contains($startSource, "'class_level' => \$classLevel") && str_contains($startSource, 'contact_consent_at_utc')
    && str_contains($startSource, 'Contact consent is required'), 'minimal identity and optional contact consent are validated and stored separately');

$presentation = study_interest_result_presentation_normalize([
    'mode' => 'masked',
    'sections' => ['hero' => 'hide', 'directions' => 'mask'],
    'masked_title' => 'Managed result',
]);
$check($presentation['mode'] === 'masked' && $presentation['sections']['hero'] === 'hide'
    && $presentation['sections']['directions'] === 'mask' && $presentation['sections']['dimensions'] === 'show', 'result-page policy normalizes global and per-section visibility safely');
$check(study_interest_result_presentation_hash($presentation) === study_interest_result_presentation_hash($presentation), 'result-page policy has a deterministic optimistic-lock fingerprint');
$invalidPresentation = study_interest_result_presentation_defaults();
$invalidPresentation['mode'] = 'unexpected';
$check(!study_interest_result_presentation_is_valid($invalidPresentation)
    && study_interest_result_presentation_fail_closed()['mode'] === 'hidden', 'invalid persisted result-page policy fails closed');

$configuration = study_interest_baseline_configuration();
$check(study_interest_configuration_errors($configuration) === [], 'packaged baseline passes full configuration validation');
$unsupportedConfiguration = $configuration;
$unsupportedConfiguration['algorithm_version'] = 'future-unsupported';
$check(study_interest_configuration_errors($unsupportedConfiguration) !== [], 'unsupported scoring algorithms cannot be published');
$check(count($configuration['questions']) === 45, 'packaged baseline contains 45 questions');
$check(count($configuration['dimensions']) === 8, 'packaged baseline contains eight dimensions');
$check(abs(array_sum(array_column($configuration['sections'], 'weight')) - 1.0) < 0.00001, 'section weights total 100%');
foreach ($configuration['programs'] as $code => $program) $check(abs(array_sum($program['weights']) - 1.0) < 0.00001, "program weights total 100%: {$code}");
$emptyConfiguration = $configuration;
$emptyConfiguration['questions'] = [];
foreach ($emptyConfiguration['sections'] as &$section) $section['question_count'] = 0;
unset($section);
$check(study_interest_configuration_errors($emptyConfiguration) !== [], 'zero-question configurations cannot be published');
$invalidLikertConfiguration = $configuration;
$invalidLikertConfiguration['questions'][0]['type'] = 'single_choice';
$invalidLikertConfiguration['questions'][0]['options'][0]['code'] = 'A';
$check(study_interest_configuration_errors($invalidLikertConfiguration) !== [], 'Section A requires the complete 1-5 Likert contract');
$check(study_interest_classification(84.995, $configuration) === 'Kuat'
    && study_interest_classification(54.995, $configuration) === 'Belum Dominan', 'classification has no decimal boundary gaps');
$_SERVER['HTTPS'] = '';
putenv('FORCE_HTTPS=0'); putenv('SESSION_ALLOW_INSECURE_COOKIES=0');
$secureByDefault = study_interest_cookie_secure();
putenv('SESSION_ALLOW_INSECURE_COOKIES=1');
$check($secureByDefault && !study_interest_cookie_secure(), 'session bearer cookie is secure by default and HTTP requires explicit opt-in');
putenv('SESSION_ALLOW_INSECURE_COOKIES'); putenv('FORCE_HTTPS');

$answers = [];
$highDimensions = ['SCI', 'HHC', 'ANA', 'PRA'];
foreach ($configuration['questions'] as $question) {
    if ($question['section'] !== 'A') continue;
    $high = in_array($question['dimension'], $highDimensions, true);
    $answers[$question['code']] = (string)($high ? (!empty($question['is_reverse']) ? 1 : 5) : (!empty($question['is_reverse']) ? 5 : 1));
}
$answers += [
    'Q25'=>'A','Q26'=>'A','Q27'=>'A','Q28'=>'B','Q29'=>'C','Q30'=>'A','Q31'=>'A','Q32'=>'A','Q33'=>'A','Q34'=>'B',
    'Q35'=>'5','Q36'=>'4','Q37'=>'2','Q38'=>'2','Q39'=>'1','Q40'=>'4',
    'Q41'=>'A','Q42'=>'A','Q43'=>'A','Q44'=>'A','Q45'=>'A',
];
$result = study_interest_score($configuration, $answers, 600);
$dimensionScores = array_column($result['dimensions'], 'score', 'code');
$recommendationScores = array_column($result['recommendations'], 'score', 'code');
$check(abs($dimensionScores['SCI'] - 100.0) < 0.001, 'golden profile computes Scientific Exploration exactly');
$check(abs($dimensionScores['ANA'] - 76.054) < 0.001 && abs($dimensionScores['PRA'] - 72.815) < 0.001, 'golden profile preserves weighted dimension scores');
$check(array_keys($recommendationScores) === ['biomedical_science', 'biotechnology'], 'golden profile returns only qualifying direct-entry recommendations');
$check(abs($recommendationScores['biomedical_science'] - 60.635) < 0.001, 'golden program score is reproducible');
$check($result['profile_clarity']['code'] === 'MULTIDISCIPLINARY' && abs($result['profile_clarity']['top_gap'] - 4.678) < 0.001, 'golden near-tie profile is multidisciplinary');
$check(count($result['interpretations']) === 1 && str_contains($result['interpretations'][0], 'Biomedical Science'), 'overlapping leading programs receive a specific interpretation');
$check($result['professional_pathways'] === [], 'professional pathway is excluded from direct ranking');
$check($result['flags'] === [], 'carefully answered golden profile has no quality flags');
$leadingDirections = study_interest_leading_directions($result['programs'], 3);
$check(count($leadingDirections) === 3 && (string)$leadingDirections[0]['code'] === 'biomedical_science', 'closest study directions are always derived from direct-entry scores');

$reverseAnswers = [];
foreach ($configuration['questions'] as $question) $reverseAnswers[$question['code']] = (string)$question['options'][0]['code'];
$reverseAnswers['Q1'] = '5'; $reverseAnswers['Q2'] = '5'; $reverseAnswers['Q3'] = '1';
$reverseResult = study_interest_score($configuration, $reverseAnswers, 100);
$science = null;
foreach ($reverseResult['dimensions'] as $dimension) if ($dimension['code'] === 'SCI') $science = $dimension;
$check(is_array($science) && abs($science['section_scores']['A'] - 100.0) < 0.001, 'reverse Likert scoring is normalized correctly');
$flagCodes = array_column($reverseResult['flags'], 'code');
$check(in_array('STRAIGHTLINING', $flagCodes, true) && in_array('FAST_COMPLETION', $flagCodes, true), 'response-quality analyzer flags straightlining and fast completion');
$openResult = $reverseResult;
$openResult['recommendations'] = [];
$check(study_interest_leading_directions($openResult['programs'], 1) !== [], 'an open profile still has a closest study direction');
$missingRejected = false;
try { $incomplete = $answers; unset($incomplete['Q45']); study_interest_score($configuration, $incomplete, 600); }
catch (DomainException) { $missingRejected = true; }
$check($missingRejected, 'missing required answers are rejected');

if (study_interest_xlsx_available()) {
    $xlsxPath = tempnam(sys_get_temp_dir(), 'sie-contract-');
    study_interest_xlsx_write($xlsxPath, 'Contract', ['Name', 'Score'], [["=Formula\x01 safe", 72.5]], [1], [24, 12]);
    $xlsx = new ZipArchive();
    $opened = $xlsx->open($xlsxPath) === true;
    $sheet = $opened ? (string)$xlsx->getFromName('xl/worksheets/sheet1.xml') : '';
    if ($opened) $xlsx->close();
    $check($opened && str_contains($sheet, 'inlineStr') && str_contains($sheet, '=Formula safe') && !str_contains($sheet, "\x01") && str_contains($sheet, '<autoFilter'), 'native Excel writer creates a styled, filterable workbook with formula-safe, XML-valid text cells');
    if (is_file($xlsxPath)) unlink($xlsxPath);
} else {
    $check(false, 'native Excel writer is available');
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$identityLeak = false;
$privateTokens = [chr(65) . chr(80) . chr(85), 'apu' . 'j.lan', 'apu' . '.ac.id', '/var/www/' . 'kantor'];
foreach ($iterator as $file) {
    $path = $file->getPathname();
    if (!$file->isFile() || $path === __FILE__ || str_contains($path, '/.git/') || str_contains($path, '/.notes/')) continue;
    if (preg_match('/\.(?:php|json|md|sql|js|css)$/', $path) !== 1) continue;
    $contents = strtolower((string)file_get_contents($path));
    foreach ($privateTokens as $token) {
        if (str_contains($contents, strtolower($token))) { $identityLeak = true; break 2; }
    }
}
$check(!$identityLeak, 'tracked product source contains no downstream identity');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " contract check(s) failed.\n");
    exit(1);
}
echo "Study Interest contract passed.\n";
