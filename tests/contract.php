<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/helpers.php';
require_once $root . '/includes/configuration.php';
require_once $root . '/includes/eligibility.php';
require_once $root . '/includes/scoring.php';
require_once $root . '/includes/assessment.php';
require_once $root . '/includes/export.php';
$failures = [];
$check = static function (bool $passed, string $message) use (&$failures): void {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$passed) $failures[] = $message;
};

$manifest = json_decode((string)file_get_contents($root . '/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
$check(($manifest['name'] ?? null) === 'study-interest', 'plugin slug is generic study-interest');
$check(($manifest['version'] ?? null) === '0.9.12', 'plugin version is 0.9.12');
$check(($manifest['requires']['jyavani'] ?? null) === '>=2.3.174', 'Core requirement includes append-only plugin migrations');
$check(($manifest['requires']['plugins'] ?? null) === [], 'plugin is standalone and declares no plugin dependency');
$check(($manifest['github_url'] ?? null) === 'https://github.com/adammuizweb/study-interest', 'repository URL is generic');
$check(($manifest['plugin_uri'] ?? null) === 'https://jyavani.com/plugin/study-interest/'
    && ($manifest['store'] ?? null) === ['url' => 'https://jyavani.com/plugin-store', 'slug' => 'study-interest'], 'manifest declares canonical Store identity and detail URL');
$check(($manifest['icon'] ?? null) === 'icon.svg' && is_file($root . '/icon.svg'), 'package declares a root-level bundled Store icon');
$check(in_array('pdo_mysql', $manifest['requires']['extensions'] ?? [], true), 'MySQL PDO requirement is explicit');
$check(in_array('zip', $manifest['requires']['extensions'] ?? [], true), 'ZIP requirement supports native Excel exports');

$migration = $root . '/migrations/0001-foundation.sql';
$schema = (string)file_get_contents($migration);
$check(is_file($migration) && is_file($root . '/migrations/0002-upgrade-foundation.php')
    && is_file($root . '/migrations/0003-verify-foundation.php')
    && is_file($root . '/migrations/0004-operational-workspace.php')
    && is_file($root . '/migrations/0005-verify-operational-workspace.php')
    && is_file($root . '/migrations/0006-seed-result-presentation.php')
    && is_file($root . '/migrations/0007-verify-result-presentation.php')
    && is_file($root . '/migrations/0008-refine-result-masking.php')
    && is_file($root . '/migrations/0009-enforce-single-live-assessment.php')
    && is_file($root . '/migrations/0010-neutral-result-presentation.php')
    && is_file($root . '/migrations/0011-seed-public-branding.php')
    && is_file($root . '/migrations/0012-verify-public-branding.php')
    && is_file($root . '/migrations/0013-result-follow-up-copy.php')
    && is_file($root . '/migrations/0014-registration-attributes.php')
    && is_file($root . '/migrations/0015-materialized-eligibility.php'), 'append-only foundation, workspace, presentation, registration, and eligibility migrations exist');
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
$followUpMigration = (string)file_get_contents($root . '/migrations/0013-result-follow-up-copy.php');
$check(str_contains($followUpMigration, "['Your result is being reviewed', 'An administrator will share your result']")
    && str_contains($followUpMigration, "['This result is not available yet', 'Thank you for participating']")
    && str_contains($followUpMigration, "!== \$previous"), 'result follow-up migration updates only exact historical defaults');
$singleLiveMigration = (string)file_get_contents($root . '/migrations/0009-enforce-single-live-assessment.php');
$check(str_contains($singleLiveMigration, 'GENERATED ALWAYS AS')
    && str_contains($singleLiveMigration, 'uq_study_interest_public_slot')
    && str_contains($singleLiveMigration, 'study_interest_publication_lock'), 'database enforces one globally published assessment and serializes publication');
$registrationMigration = (string)file_get_contents($root . '/migrations/0014-registration-attributes.php');
$eligibilityMigration = (string)file_get_contents($root . '/migrations/0015-materialized-eligibility.php');
$check(str_contains($registrationMigration, 'information_schema.COLUMNS')
    && str_contains($registrationMigration, 'registration_attributes_json')
    && str_contains($registrationMigration, 'registration_attributes_hash'), 'migration 0014 retry-safely adds immutable registration evidence');
$check(str_contains($eligibilityMigration, 'information_schema.COLUMNS')
    && str_contains($eligibilityMigration, "DEFAULT 'ELIGIBLE'")
    && str_contains($eligibilityMigration, "DEFAULT 'ACCEPTING'")
    && str_contains($eligibilityMigration, 'pre_eligibility_rank'), 'migration 0015 retry-safely materializes backward-compatible eligibility evidence');
$check(!str_contains($schema, 'quiz_attempts'), 'schema does not reuse Quiz attempt tables');
$check(!preg_match('/`(?:created|updated|started|completed|consent|published|retired|answered)_at`/', $schema), 'new instant columns use explicit UTC names');

$permissions = [];
foreach ($manifest['permissions'] ?? [] as $permission) $permissions[(string)$permission['key']] = $permission;
foreach (['dashboard.access', 'config.view', 'config.manage', 'config.publish', 'results.view', 'presentation.view', 'presentation.manage', 'contacts.view', 'contacts.manage', 'results.export', 'sessions.view', 'responses.view', 'results.correct', 'sessions.delete', 'workspace.export', 'audit.view'] as $suffix) {
    $check(isset($permissions['plugin.study-interest.' . $suffix]), 'permission is declared: ' . $suffix);
}
$check(count(array_filter($permissions, static fn(array $permission): bool => ($permission['delegable'] ?? null) === true)) === count($permissions),
    'all separate Study Interest permissions can be assigned to custom operational roles');
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
$check(($routes['admin/tools/study-interest/sessions/preview-correction']['permission'] ?? null) === 'plugin.study-interest.results.correct', 'result correction preview uses dedicated permission');
$check(($routes['admin/tools/study-interest/audit']['permission'] ?? null) === 'plugin.study-interest.audit.view', 'audit route uses dedicated permission');
$check(($routes['admin/tools/study-interest/participants/edit']['permission'] ?? null) === 'plugin.study-interest.contacts.manage'
    && ($routes['admin/tools/study-interest/participants/save']['permission'] ?? null) === 'plugin.study-interest.contacts.manage', 'participant edits use dedicated contact-management permission');
$check(($routes['admin/tools/study-interest/sessions/delete']['permission'] ?? null) === 'plugin.study-interest.sessions.delete', 'session deletion uses dedicated permission');
$check(($routes['admin/tools/study-interest/workspace-export']['permission'] ?? null) === 'plugin.study-interest.workspace.export', 'operational exports use dedicated permission');
$check(($routes['admin/tools/study-interest/result-page']['permission'] ?? null) === 'plugin.study-interest.presentation.view'
    && ($routes['admin/tools/study-interest/result-page/save']['permission'] ?? null) === 'plugin.study-interest.presentation.manage', 'result-page policy separates viewing and management permissions');
$check(($routes['admin/tools/study-interest/branding']['permission'] ?? null) === 'plugin.study-interest.presentation.view'
    && ($routes['admin/tools/study-interest/branding/save']['permission'] ?? null) === 'plugin.study-interest.presentation.manage', 'global branding reuses public-presentation permissions');
$check(($routes['admin/tools/study-interest/assessments']['permission'] ?? null) === 'plugin.study-interest.config.view'
    && ($routes['admin/tools/study-interest/questions']['permission'] ?? null) === 'plugin.study-interest.config.view', 'hidden scoring routes use configuration-view permission');
$check(($routes['admin/tools/study-interest/structure']['permission'] ?? null) === 'plugin.study-interest.config.view'
    && ($routes['admin/tools/study-interest/structure/save']['permission'] ?? null) === 'plugin.study-interest.config.manage', 'generic structure builder uses configuration-management permission');
$check(($routes['admin/tools/study-interest/assessments/create']['permission'] ?? null) === 'plugin.study-interest.config.manage'
    && ($routes['admin/tools/study-interest/assessments/create-save']['permission'] ?? null) === 'plugin.study-interest.config.manage'
    && ($routes['admin/tools/study-interest/assessments/delete']['permission'] ?? null) === 'plugin.study-interest.config.manage', 'assessment create and delete routes use configuration-management permission');
$check(($manifest['admin']['nav'][0]['parent'] ?? null) === 'tools', 'manifest navigation uses the tools group');
$check(($manifest['admin']['nav'][0]['icon_asset'] ?? null) === 'static/plugins/study-interest/icon-sidebar.svg', 'dashboard aside uses the published Study Interest icon');
foreach ($manifest['static']['copy'] ?? [] as $asset) {
    $check(is_file($root . '/' . (string)$asset['from']), 'static source exists: ' . (string)$asset['from']);
    $check(str_starts_with((string)$asset['to'], 'static/plugins/study-interest/'), 'static asset stays in plugin namespace');
}

$pluginSource = (string)file_get_contents($root . '/plugin.php');
$publicLayoutSource = (string)file_get_contents($root . '/public/layout.php');
$check(str_contains($pluginSource, "register_frontend_route('study-interest'"), 'plugin owns its public route');
$check(!str_contains(strtolower($pluginSource), 'quiz'), 'bootstrap loads without Quiz functions or gates');
$check(str_contains($pluginSource, "add_action('admin_head', 'study_interest_admin_assets')")
    && str_contains($pluginSource, '/static/plugins/study-interest/admin.css?v=0.9.12')
    && str_contains($pluginSource, '/static/plugins/study-interest/admin.js?v=0.9.12')
    && str_contains($publicLayoutSource, '/static/plugins/study-interest/frontend.css?v=0.9.12')
    && str_contains($publicLayoutSource, '/static/plugins/study-interest/frontend.js?v=0.9.12'), 'plugin assets use the release cache key');
$check(str_contains($publicLayoutSource, 'study_interest_site_favicon_url($pdo)')
    && str_contains($publicLayoutSource, 'property="og:image"')
    && str_contains($publicLayoutSource, 'name="twitter:image"')
    && str_contains($publicLayoutSource, 'property="og:url"')
    && str_contains($publicLayoutSource, 'rel="canonical"'), 'public links expose Core favicon, canonical, Open Graph, and Twitter metadata');
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
$questionEditSource = (string)file_get_contents($root . '/admin/questions/edit.php');
$assessmentCreateSource = (string)file_get_contents($root . '/admin/assessments/create-save.php');
$assessmentDeleteSource = (string)file_get_contents($root . '/admin/assessments/delete.php');
$assessmentSaveSource = (string)file_get_contents($root . '/admin/assessments/save.php');
$structureSaveSource = (string)file_get_contents($root . '/admin/structure/save.php');
$correctionSource = (string)file_get_contents($root . '/admin/sessions/save-correction.php');
$correctionPreviewSource = (string)file_get_contents($root . '/admin/sessions/preview-correction.php');
$correctionPageSource = (string)file_get_contents($root . '/admin/sessions/correct.php');
$adminSource = (string)file_get_contents($root . '/assets/admin.js');
$sessionListSource = (string)file_get_contents($root . '/admin/sessions/index.php');
$sessionViewSource = (string)file_get_contents($root . '/admin/sessions/view.php');
$adminInitSource = (string)file_get_contents($root . '/admin/_init.php');
$check(str_contains($questionSaveSource, 'study_interest_replace_draft_configuration')
    && str_contains($questionSaveSource, 'authorization_lock_actor_permissions')
    && str_contains($questionSaveSource, "'draft'")
    && str_contains($questionSaveSource, '$scores[$dimensionCode] = $score'), 'question authoring is transactional, draft-only, and preserves explicit zero scores');
$check(str_contains($questionEditSource, 'data-sie-add-option')
    && str_contains($questionEditSource, 'data-sie-remove-option')
    && str_contains($questionEditSource, "questions/delete"), 'question editor exposes create, update, and delete controls for questions and answers');
$check(str_contains($assessmentCreateSource, 'study_interest_import_configuration')
    && str_contains($assessmentCreateSource, 'test.created')
    && str_contains($assessmentDeleteSource, "status'] !== 'draft'")
    && str_contains($assessmentDeleteSource, 'configuration.deleted'), 'assessment CRUD creates complete drafts and deletes only audited unpublished drafts');
$check(str_contains($assessmentSaveSource, "result_text")
    && str_contains($assessmentSaveSource, 'test.updated')
    && str_contains($assessmentSaveSource, 'duration_estimate')
    && str_contains($assessmentSaveSource, 'result_actions')
    && str_contains($assessmentSaveSource, 'study_interest_public_action_url_error'), 'assessment editor manages canonical result copy and safe optional schema-v3 public actions');
$check(str_contains($assessmentSaveSource, "foreach (\$configuration['programs'] as \$code => &\$program)")
    && str_contains($structureSaveSource, "\$configuration['programs'][\$code] = \$existing +"), 'admin configuration and structure saves mutate existing program records without discarding extension fields');
$check(str_contains($correctionSource, 'study_interest_result_revisions')
    && str_contains($correctionSource, 'study_interest_score_versioned')
    && str_contains($correctionSource, 'expected_revision')
    && str_contains($correctionSource, 'plugin.study-interest.responses.view')
    && str_contains($correctionSource, 'result.corrected')
    && str_contains($correctionSource, 'changed_questions')
    && str_contains($sessionListSource, 'Edit submitted answers'), 'submitted-answer editing recalculates and records an authorized audited optimistic revision');
$check(str_contains($correctionPreviewSource, 'study_interest_correction_preview')
    && str_contains($correctionPreviewSource, 'study_interest_score_versioned')
    && str_contains($correctionPreviewSource, 'study_interest_snapshot_matches_result')
    && str_contains($correctionPreviewSource, "adiwira_require_permission(\$pdo, 'plugin.study-interest.config.view', true)")
    && str_contains($correctionPreviewSource, 'expected_revision')
    && str_contains($correctionPreviewSource, 'SELECT status,result_revision')
    && !preg_match('/\b(?:INSERT|UPDATE|DELETE)\b/i', $correctionPreviewSource), 'correction guidance is read-only, version-scoped, score-reproducible, and separately authorized');
$check(str_contains($correctionPreviewSource, 'study_interest_has_eligibility_policies')
    && str_contains($correctionPreviewSource, "adiwira_require_permission(\$pdo, 'plugin.study-interest.contacts.view', true)")
    && str_contains($correctionSource, "user_can(\$pdo, \$studyInterestUserId, 'plugin.study-interest.contacts.view')")
    && strpos($correctionSource, 'beginTransaction') < strpos($correctionSource, "plugin.study-interest.contacts.view")
    && str_contains($correctionPageSource, 'study_interest_has_eligibility_policies')
    && str_contains($correctionPageSource, "adiwira_require_permission(\$pdo, 'plugin.study-interest.contacts.view', false)"), 'eligibility-aware correction requires contact access and reauthorizes it inside the save transaction');
$check(!str_contains($correctionPreviewSource, 's.registration_attributes_json')
    && !str_contains($correctionSource, 's.registration_attributes_json')
    && str_contains($correctionPreviewSource, 'SELECT registration_attributes_json FROM study_interest_sessions WHERE id=?')
    && str_contains($correctionSource, 'SELECT registration_attributes_json FROM study_interest_sessions WHERE id=?'), 'correction loads raw registration attributes only after the frozen configuration proves an eligibility policy exists');
$check(str_contains($correctionPageSource, 'data-sie-correction-guidance')
    && str_contains($correctionPageSource, 'data-sie-guidance-target')
    && str_contains($correctionPageSource, 'sessions/preview-correction')
    && str_contains($correctionPageSource, "['action' => 'preview']")
    && str_contains($adminSource, 'data-sie-guidance-suggestions')
    && str_contains($adminSource, "includes('application/json')"), 'correction workspace exposes target-direction guidance and answer-level impact controls');
$check(str_contains($sessionViewSource, 'plugin.study-interest.responses.view')
    && str_contains($sessionViewSource, 'plugin.study-interest.contacts.view'), 'session detail separates response and contact access');
$presentationSaveSource = (string)file_get_contents($root . '/admin/result-page/save.php');
$brandingSaveSource = (string)file_get_contents($root . '/admin/branding/save.php');
$publicResultSource = (string)file_get_contents($root . '/public/index.php');
$publicResultStyles = (string)file_get_contents($root . '/assets/frontend.css');
$baselineSource = (string)file_get_contents($root . '/config/baseline.php');
$configurationSource = (string)file_get_contents($root . '/includes/configuration.php');
$assessmentEditSource = (string)file_get_contents($root . '/admin/assessments/edit.php');
$frontendSource = (string)file_get_contents($root . '/assets/frontend.js');
$brandingPageSource = (string)file_get_contents($root . '/admin/branding.php');
$check(str_contains($presentationSaveSource, 'csrf_check')
    && str_contains($presentationSaveSource, 'authorization_lock_actor_permissions')
    && str_contains($presentationSaveSource, 'expected_hash')
    && str_contains($presentationSaveSource, 'result_presentation.updated'), 'result-page policy save is transactional, optimistic, authorized, and audited');
$check(str_contains($publicResultSource, "['mode'] === 'hidden'")
    && str_contains($publicResultSource, "['mode'] === 'masked'")
    && str_contains($publicResultSource, "\$sectionState('directions')")
    && str_contains($publicResultSource, 'sie-result-mask-preview')
    && str_contains($publicResultStyles, 'filter: blur(10px)'), 'public result supports full, safe blurred-mask, and hidden output');
$check(str_contains($publicResultSource, "\$snapshot['recommendations']")
    && str_contains($publicResultSource, 'study_interest_historical_leading_directions')
    && str_contains($publicResultSource, '$schemaVersion >= 3')
    && str_contains($publicResultSource, "elseif (\$sectionState('pathways') === 'mask')")
    && str_contains($publicResultSource, "elseif (\$sectionState('interpretation') === 'mask')"), 'public result respects the immutable direction limit and masks optional sections without existence disclosure');
$check(str_contains($publicResultSource, 'sie-result-description')
    && str_contains($publicResultSource, "study_interest_h((string)\$program['description'])")
    && str_contains($publicResultSource, "study_interest_h((string)\$pathway['description'])"), 'schema-v3 program descriptions are escaped on recommendation and pathway cards');
$check(str_contains($publicResultSource, '$assessmentTitle')
    && str_contains($publicResultSource, '$assessmentDescription'), 'public assessment identity comes from dashboard-managed configuration');
$check(!str_contains($publicResultSource, '$estimatedMinutes')
    && !str_contains($publicResultSource, "['duration_prefix']")
    && !str_contains($publicResultSource, "['duration_suffix']")
    && !str_contains($publicResultSource, 'feature_one_')
    && !str_contains($publicResultSource, 'No timer')
    && !str_contains($publicResultSource, 'Tanpa timer')
    && str_contains($publicResultSource, '$durationEstimate')
    && str_contains($publicResultSource, "['duration_estimate']"), 'schema-v3 landing badge supports only explicit optional duration-estimate copy');
$check(str_contains($publicResultSource, "'SINGLE_DOMINANT' =>")
    && str_contains($publicResultSource, "['single_dominant']")
    && str_contains($publicResultSource, '$resultActions')
    && str_contains($publicResultSource, 'sie-result-actions')
    && str_contains($publicResultSource, 'study_interest_public_action_url_error')
    && str_contains($configurationSource, 'study_interest_public_action_url_error'), 'schema-v3 result renders distinct single-direction copy and validated configurable actions');
$assessmentSource = (string)file_get_contents($root . '/includes/assessment.php');
$check(str_contains($assessmentSource, "'result_text' => \$configuration['result_text'] ?? []")
    && str_contains($correctionSource, "'result_text' => \$configuration['result_text'] ?? []"), 'completion and correction freeze schema-v3 single-direction copy into immutable result snapshots');
$check(!str_contains($baselineSource, 'feature_one_')
    && !str_contains($configurationSource, 'feature_one_')
    && !str_contains($assessmentEditSource, 'feature_one_')
    && !str_contains($assessmentSaveSource, 'feature_one_'), 'new assessment configuration no longer exposes the retired no-timer feature');
$check(str_contains($publicResultSource, '<p class="sie-kicker">THANK YOU</p>')
    && str_contains($publicResultSource, '<p class="sie-kicker">FOLLOW-UP</p>')
    && str_contains($publicResultSource, "['masked', 'hidden'], true)) \$page_language = 'en'")
    && substr_count($publicResultSource, '>Back <span aria-hidden="true">&rarr;</span>') === 2
    && !str_contains($publicResultSource, "\$legacyConfiguration ? 'Kembali' : 'Back'")
    && !str_contains($publicResultSource, 'TERIMA KASIH')
    && !str_contains($publicResultSource, 'TINDAK LANJUT')
    && !str_contains($publicResultSource, 'HASIL DISAMARKAN')
    && !str_contains($publicResultSource, 'AKSES HASIL'), 'limited result pages use follow-up and thank-you language');
$check(substr_count($publicResultSource, '$renderPublicBrand($assessmentTitle)') === 3
    && str_contains($publicResultSource, 'study_interest_public_branding($pdo)')
    && str_contains($publicResultStyles, '.sie-logo-image'), 'global branding renders in every public assessment header without replacing assessment identity');
$check(str_contains($brandingSaveSource, 'csrf_check')
    && str_contains($brandingSaveSource, 'authorization_lock_actor_permissions')
    && str_contains($brandingSaveSource, 'FOR UPDATE')
    && str_contains($brandingSaveSource, 'expected_hash')
    && str_contains($brandingSaveSource, 'settings_set')
    && str_contains($brandingSaveSource, 'public_branding.updated')
    && !str_contains($brandingSaveSource, 'study_interest_test_versions'), 'branding updates are optimistic, authorized, audited, and separate from immutable versions');
$check(str_contains($brandingPageSource, 'window.openMediaSelector')
    && str_contains($brandingPageSource, '/static/js/add/modal-helpers.js')
    && str_contains($brandingPageSource, '/static/js/add/media-selector.js')
    && str_contains($brandingPageSource, 'dataset.sieBrandingReady')
    && str_contains($brandingPageSource, 'ADMIN_BASE_PATH')
    && str_contains($brandingPageSource, '/admin/modal_img/index.php?embedded=1&visibility=public')
    && !str_contains($brandingPageSource, 'list_modal.php'), 'branding initializes the complete Core Media Library selector after its page markup');
$check(str_contains($publicResultSource, "'EXPLORE, DO NOT LABEL'")
    && str_contains($publicResultSource, "'Question'")
    && str_contains($publicResultSource, "'YOUR EXPLORATION RESULT'")
    && str_contains($publicResultSource, "\$legacyConfiguration ? 'id' : 'en'")
    && str_contains($frontendSource, 'copy.progress_item_label'), 'historical schema-v1 assessments use English plugin-owned interface fallbacks');
$indonesianUiFragments = [
    'Hasil privat', 'HASIL EKSPLORASIMU', 'Tiga dimensi minat teratas', 'Ringkasan profil minat',
    'ARAH BERIKUTNYA', 'Bidang untuk dieksplorasi', 'MASIH TERBUKA', 'PETA LENGKAP', 'JALUR PROFESI',
    'Langkah berikutnya', 'Catatan hasil', 'Progres eksplorasi', 'Pertanyaan', 'Kembali', 'Lanjut',
    'Menyimpan...', 'Tersimpan', 'Jawaban tidak dapat disimpan', 'SEBELUM MULAI', 'Kenalkan dirimu',
    'Nomor telepon', 'JavaScript diperlukan',
];
$check(array_filter($indonesianUiFragments, static fn(string $fragment): bool => str_contains($publicResultSource, $fragment)) === [], 'participant source contains no Indonesian plugin-owned interface fallback');
$participantEditSource = (string)file_get_contents($root . '/admin/participants/edit.php');
$participantSaveSource = (string)file_get_contents($root . '/admin/participants/save.php');
$check(str_contains($participantEditSource, 'expected_contact_hash')
    && str_contains($participantSaveSource, 'expected_contact_hash')
    && str_contains($participantSaveSource, 'hash_equals'), 'participant edits reject stale identity and consent snapshots');
$publicQuestionSource = (string)file_get_contents($root . '/includes/helpers.php');
$check(!str_contains($publicQuestionSource, 'option_scores s ON'), 'public question loader does not expose hidden scores');
$check(str_contains($frontendSource, 'firstUnanswered < 0 ? data.questions.length - 1')
    && str_contains($frontendSource, 'else answers[String(question.id)] = previous;'), 'frontend resumes at the final answered question and reverts failed autosaves');
$startSource = (string)file_get_contents($root . '/public/api/start.php');
$check(str_contains($startSource, "'class_level' => \$classLevel") && str_contains($startSource, 'contact_consent_at_utc')
    && str_contains($startSource, 'Contact consent is required'), 'minimal identity and optional contact consent are validated and stored separately');
$check(str_contains($startSource, 'configuration_hash')
    && str_contains($startSource, 'The assessment changed. Reload this page before starting.')
    && str_contains($publicResultSource, "'versionId'")
    && str_contains($publicResultSource, '$questionCount'), 'public start is pinned to the displayed version and question count is dynamic');
$check(str_contains($startSource, 'registration_attributes_json') && str_contains($startSource, 'registration_attributes_hash')
    && str_contains($publicResultSource, 'data-sie-registration') && str_contains($frontendSource, 'registration_attributes')
    && !str_contains($publicResultSource, 'eligibility_policies') && !str_contains($frontendSource, 'eligibility_policies'), 'public registration submits configured selects without exposing policy configuration');
$check(str_contains($publicResultSource, '<fieldset class="sie-field sie-field-wide sie-registration-radio">')
    && str_contains($publicResultSource, '<legend><?= study_interest_h((string)$attribute[\'label\']) ?>')
    && str_contains($publicResultSource, 'name="sie-registration-<?= study_interest_h((string)$code) ?>"')
    && str_contains($publicResultSource, "(\$attribute['control'] ?? 'select') === 'radio'")
    && str_contains($publicResultSource, "(\$attribute['required'] ?? false) === true ? ' required' : ''")
    && substr_count($publicResultSource, '<option value=""></option>') === 1,
    'radio registration uses an escaped fieldset and shared safe name, browser-required inputs, and no blank radio choice while select stays unchanged');
$check(str_contains($frontendSource, "field.type !== 'radio' || field.checked")
    && str_contains($frontendSource, 'registrationFields.some((field) => !field.validity.valid)')
    && !str_contains($frontendSource, 'option_scores') && !str_contains($frontendSource, 'scores:'),
    'landing validity covers required radio groups and submission serializes checked radio values only without scoring data');
$check(!str_contains($exportSource, 'registration_attributes_json')
    && str_contains($workspaceExportSource, "'contacts.view'") && str_contains($workspaceExportSource, 'registration_attributes_json'), 'raw registration values are excluded from anonymous export and gated by contact-view workspace export');
$check(str_contains($workspaceExportSource, 'OR s.registration_attributes_json LIKE ?')
    && substr_count($workspaceExportSource, '$needle') >= 7, 'workspace export search includes registration attributes consistently with contact-authorized list search');
$check(str_contains($adminInitSource, 'study_interest_admin_top_direction_condition')
    && str_contains($adminInitSource, 'historical_peer.score>')
    && str_contains($adminInitSource, 'rank_position=1')
    && str_contains($resultsSource, 'study_interest_admin_top_direction_condition')
    && str_contains($exportSource, 'study_interest_admin_top_direction_condition')
    && str_contains($workspaceExportSource, 'study_interest_admin_top_direction_condition')
    && str_contains($sessionListSource, 'study_interest_admin_top_direction_condition'), 'all report, list, and export top-direction queries split historical raw-highest from schema-v3 effective recommendations');
$check(str_contains($sessionListSource, 'configuration_hash')
    && str_contains($sessionListSource, 'study_interest_configuration_from_session')
    && str_contains($resultsSource, 'study_interest_configuration_from_session')
    && str_contains($exportSource, 'study_interest_configuration_from_session')
    && str_contains($workspaceExportSource, 'study_interest_configuration_from_session'), 'top-direction schema identity comes from hash-verified immutable version configuration');
$check(str_contains($sessionViewSource, 'NULL AS registration_attributes_json,s.registration_attributes_hash')
    && str_contains($sessionViewSource, 'study_interest_snapshot_registration_hash_matches'), 'revision history verifies schema-2 registration hashes even when raw registration data is hidden');
$check(str_contains($sessionViewSource, '!study_interest_has_eligibility_policies($configuration) || $canViewContacts'), 'session detail offers correction only when eligibility evidence access is authorized');

$presentation = study_interest_result_presentation_normalize([
    'mode' => 'masked',
    'sections' => ['hero' => 'hide', 'directions' => 'mask'],
    'masked_title' => 'Managed result',
]);
$check($presentation['mode'] === 'masked' && $presentation['sections']['hero'] === 'hide'
    && $presentation['sections']['directions'] === 'mask' && $presentation['sections']['dimensions'] === 'show', 'result-page policy normalizes global and per-section visibility safely');
$presentationDefaults = study_interest_result_presentation_defaults();
$check($presentationDefaults['masked_title'] === 'An administrator will share your result'
    && $presentationDefaults['hidden_title'] === 'Thank you for participating'
    && $presentationDefaults['hidden_message'] === 'Your responses have been submitted.', 'limited result defaults describe administrator follow-up and submission confirmation');
$check(study_interest_result_presentation_hash($presentation) === study_interest_result_presentation_hash($presentation), 'result-page policy has a deterministic optimistic-lock fingerprint');
$invalidPresentation = study_interest_result_presentation_defaults();
$invalidPresentation['mode'] = 'unexpected';
$check(!study_interest_result_presentation_is_valid($invalidPresentation)
    && study_interest_result_presentation_fail_closed()['mode'] === 'hidden', 'invalid persisted result-page policy fails closed');
$branding = study_interest_public_branding_normalize(['brand_title' => 'Learning Compass', 'logo_image_url' => '/static/img/brand.webp']);
$check($branding === ['schema' => 1, 'brand_title' => 'Learning Compass', 'logo_image_url' => '/static/img/brand.webp']
    && study_interest_public_branding_hash($branding) === study_interest_public_branding_hash($branding), 'public branding normalizes with a deterministic optimistic-lock fingerprint');
$check(study_interest_public_branding_url_error('') === null
    && study_interest_public_branding_url_error('/static/img/brand.webp?v=2') === null
    && study_interest_public_branding_url_error('https://cdn.example.test/brand.svg') === null, 'public branding accepts empty, public root-relative, and HTTPS logo URLs');
$invalidBrandUrls = ['http://example.test/logo.png', '//example.test/logo.png', 'data:image/svg+xml,x', 'https://user:pass@example.test/logo.png', 'https://example.test/logo.png#x', '/private', '/private/logo.png', '/static/../private/logo.png', '/static/%2e%2e/private/logo.png', '/static\\logo.png'];
$check(array_filter($invalidBrandUrls, static fn(string $url): bool => study_interest_public_branding_url_error($url) === null) === [], 'public branding rejects unsafe logo URL forms');
$invalidBranding = study_interest_public_branding_defaults();
$invalidBranding['brand_title'] = str_repeat('x', 121);
$check(!study_interest_public_branding_is_valid($invalidBranding)
    && study_interest_public_branding_normalize($invalidBranding) === study_interest_public_branding_defaults(), 'invalid public branding fails safely to defaults');
$invalidBranding['brand_title'] = "Two\nlines";
$check(!study_interest_public_branding_is_valid($invalidBranding), 'public brand title is bounded single-line text');
$metadataPdo = new class extends PDO { public function __construct() {} };
$previousHttps = $_SERVER['HTTPS'] ?? null;
$previousHost = $_SERVER['HTTP_HOST'] ?? null;
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'example.test';
$check(study_interest_site_origin($metadataPdo) === 'https://example.test'
    && study_interest_site_favicon_url($metadataPdo) === '/static/img/favicon/apple-touch-icon.png'
    && study_interest_absolute_public_url($metadataPdo, '/static/img/share.png') === 'https://example.test/static/img/share.png', 'public metadata has safe request-origin and Core image fallbacks');
if ($previousHttps === null) unset($_SERVER['HTTPS']); else $_SERVER['HTTPS'] = $previousHttps;
if ($previousHost === null) unset($_SERVER['HTTP_HOST']); else $_SERVER['HTTP_HOST'] = $previousHost;

$configuration = study_interest_baseline_configuration();
$check(study_interest_configuration_errors($configuration) === [], 'packaged baseline passes full configuration validation');
$check(($configuration['result_text']['single_dominant'] ?? '') !== ''
    && ($configuration['public_copy']['duration_estimate'] ?? null) === ''
    && ($configuration['result_actions'] ?? null) === [], 'schema-v3 neutral baseline carries distinct single-direction copy and keeps optional public enhancements empty');
$historicalDurationConfiguration = $configuration;
$historicalDurationConfiguration['public_copy']['duration_prefix'] = 'about';
$historicalDurationConfiguration['public_copy']['duration_suffix'] = 'minutes';
$check(study_interest_configuration_errors($historicalDurationConfiguration) === [], 'historical published duration copy remains accepted but unused');
$historicalNoTimerConfiguration = $configuration;
$historicalNoTimerConfiguration['public_copy']['feature_one_title'] = 'No timer';
$historicalNoTimerConfiguration['public_copy']['feature_one_body'] = 'Answer at your own pace.';
$check(study_interest_configuration_errors($historicalNoTimerConfiguration) === [], 'historical no-timer copy remains accepted but unused');
$localizedConfiguration = $configuration;
$localizedConfiguration['title'] = 'Eksplorasi Minat';
$localizedConfiguration['questions'][0]['prompt'] = 'Saya suka mempelajari pertanyaan yang rumit.';
$localizedConfiguration['public_copy']['back_label'] = 'Kembali';
$check(study_interest_configuration_errors($localizedConfiguration) === [], 'creator-authored assessment content may use any language');
$unsupportedConfiguration = $configuration;
$unsupportedConfiguration['algorithm_version'] = 'future-unsupported';
$check(study_interest_configuration_errors($unsupportedConfiguration) !== [], 'unsupported scoring algorithms cannot be published');
$invalidResultText = $configuration;
$invalidResultText['result_text']['disclaimer'] = '';
$check(study_interest_configuration_errors($invalidResultText) !== [], 'participant result messages are required configuration');
$missingSingleDominant = $configuration;
unset($missingSingleDominant['result_text']['single_dominant']);
$historicalResultCopy = $missingSingleDominant;
$historicalResultCopy['schema_version'] = 2;
$historicalResultCopy['algorithm_version'] = 'weighted-choice-2.0';
unset($historicalResultCopy['target_intake'], $historicalResultCopy['registration_attributes'], $historicalResultCopy['eligibility_policies']);
foreach ($historicalResultCopy['programs'] as &$program) unset($program['intake_availability']);
unset($program);
$check(study_interest_configuration_draft_errors($missingSingleDominant) !== []
    && study_interest_configuration_errors($historicalResultCopy) === [], 'single-direction result copy is required only for schema v3');
$publicEnhancements = $configuration;
$publicEnhancements['public_copy']['duration_estimate'] = 'About 8 minutes';
$publicEnhancements['result_actions'] = [
    ['label' => 'Explore courses', 'url' => '/courses/'],
    ['label' => 'Talk with an adviser', 'url' => 'https://example.test/advice'],
];
$historicalOptionalEnhancements = $configuration;
unset($historicalOptionalEnhancements['public_copy']['duration_estimate'], $historicalOptionalEnhancements['result_actions']);
$unsafeResultAction = $publicEnhancements;
$unsafeResultAction['result_actions'][0]['url'] = 'javascript:alert(1)';
$longDurationEstimate = $publicEnhancements;
$longDurationEstimate['public_copy']['duration_estimate'] = str_repeat('x', 101);
$check(study_interest_configuration_errors($publicEnhancements) === []
    && study_interest_configuration_errors($historicalOptionalEnhancements) === []
    && study_interest_configuration_draft_errors($unsafeResultAction) !== []
    && study_interest_configuration_draft_errors($longDurationEstimate) !== [], 'optional schema-v3 duration and result actions accept bounded safe copy and reject unsafe values');
$describedConfiguration = $configuration;
$describedConfiguration['programs']['physics']['description'] = 'Explore matter, energy, and quantitative models.';
$invalidProgramDescription = $describedConfiguration;
$invalidProgramDescription['programs']['physics']['description'] = str_repeat('x', 2001);
$controlProgramDescription = $describedConfiguration;
$controlProgramDescription['programs']['physics']['description'] = "Unsafe\x07text";
$programProjection = array_column(study_interest_configuration_normalized_projection($describedConfiguration)['programs'], null, 'code');
$check(study_interest_configuration_errors($describedConfiguration) === []
    && study_interest_configuration_draft_errors($invalidProgramDescription) !== []
    && study_interest_configuration_draft_errors($controlProgramDescription) !== [], 'schema-v3 program descriptions accept optional bounded valid text');
$check(!array_key_exists('description', $programProjection['physics'])
    && str_contains((string)file_get_contents($root . '/README.md'), 'normalized program table does not own that field')
    && str_contains((string)file_get_contents($root . '/README.md'), '`weighted-choice-2.1` freezes it into result snapshots'), 'program descriptions remain configuration-owned because the normalized database schema has no matching field');
$check(($configuration['schema_version'] ?? null) === 3 && ($configuration['algorithm_version'] ?? null) === 'weighted-choice-2.1'
    && ($configuration['version'] ?? null) === 'neutral-en-1.1', 'packaged starter uses generic schema v3 scoring');
$check(count($configuration['questions']) === 18, 'packaged neutral starter contains 18 editable questions');
$check(count($configuration['dimensions']) === 6, 'packaged neutral starter contains six generic dimensions');
$check(abs(array_sum(array_column($configuration['sections'], 'weight')) - 1.0) < 0.00001, 'section weights total 100%');
foreach ($configuration['programs'] as $code => $program) $check(abs(array_sum($program['weights']) - 1.0) < 0.00001, "program weights total 100%: {$code}");
$blankConfiguration = study_interest_blank_configuration();
$check(study_interest_configuration_draft_errors($blankConfiguration) === []
    && study_interest_configuration_errors($blankConfiguration) !== [], 'blank assessments are valid drafts but cannot be published before authoring');
$emptyConfiguration = $configuration;
$emptyConfiguration['questions'] = [];
foreach ($emptyConfiguration['sections'] as &$section) $section['question_count'] = 0;
unset($section);
$check(study_interest_configuration_errors($emptyConfiguration) !== [], 'zero-question configurations cannot be published');
$unmeasuredConfiguration = $configuration;
foreach ($unmeasuredConfiguration['questions'] as &$question) foreach ($question['options'] as &$option) if (isset($option['scores']['CON'])) $option['scores']['CON'] = 0;
unset($question, $option);
$check(study_interest_configuration_errors($unmeasuredConfiguration) !== [], 'every published dimension must vary in at least one question');
$missingSectionRange = $configuration;
foreach ($missingSectionRange['questions'] as &$question) {
    if (($question['section'] ?? null) !== 'WORKSTYLE') continue;
    foreach ($question['options'] as &$option) $option['scores']['CON'] = 0;
    unset($option);
}
unset($question);
$zeroWeightRange = $missingSectionRange;
$zeroWeightRange['sections']['INTERESTS']['weight'] = 1.0;
$zeroWeightRange['sections']['WORKSTYLE']['weight'] = 0.0;
$schemaV2MissingRange = $missingSectionRange;
$schemaV2MissingRange['schema_version'] = 2;
$schemaV2MissingRange['algorithm_version'] = 'weighted-choice-2.0';
unset($schemaV2MissingRange['target_intake'], $schemaV2MissingRange['registration_attributes'], $schemaV2MissingRange['eligibility_policies']);
foreach ($schemaV2MissingRange['programs'] as &$program) unset($program['intake_availability']);
unset($program);
$check(study_interest_configuration_errors($missingSectionRange) !== []
    && study_interest_configuration_errors($zeroWeightRange) === []
    && study_interest_configuration_errors($schemaV2MissingRange) === [], 'schema-v3 publication requires every active dimension to vary in every nonzero-weight section without changing schema-v2 validation');
$invalidNormalLikert = $configuration;
$invalidNormalLikert['questions'][0]['options'][0]['scores']['CON'] = 100;
$invalidReverseLikert = $configuration;
$invalidReverseLikert['questions'][0]['is_reverse'] = true;
$validReverseLikert = $invalidReverseLikert;
foreach ($validReverseLikert['questions'][0]['options'] as &$option) $option['scores']['CON'] = 100 - (float)$option['scores']['CON'];
unset($option);
$check(study_interest_configuration_errors($invalidNormalLikert) !== []
    && study_interest_configuration_errors($invalidReverseLikert) !== []
    && study_interest_configuration_errors($validReverseLikert) === [], 'schema-v3 ordered Likert scoring follows normal ascending and reverse descending vectors');
$invalidQualityThresholds = $configuration;
$invalidQualityThresholds['thresholds']['response_quality']['straightlining_same_answers_minimum'] = 19;
$invalidFastThreshold = $configuration;
$invalidFastThreshold['thresholds']['response_quality']['fast_completion_below_seconds'] = 86401;
$invalidConsistencyThreshold = $configuration;
$invalidConsistencyThreshold['thresholds']['response_quality']['low_response_consistency_dimensions_minimum'] = 1;
$check(study_interest_configuration_draft_errors($invalidQualityThresholds) !== []
    && study_interest_configuration_draft_errors($invalidFastThreshold) !== []
    && study_interest_configuration_draft_errors($invalidConsistencyThreshold) !== [], 'schema-v3 response-quality thresholds are bounded by configured Likert and comparable dimension metadata');
$check(study_interest_classification(79.995, $configuration) === 'Strong alignment'
    && study_interest_classification(64.995, $configuration) === 'Clear alignment', 'classification has no decimal boundary gaps');
$contactConfiguration = $configuration;
$contactConfiguration['intake']['fields']['school']['enabled'] = false;
$contact = study_interest_contact_from_input(['name' => 'Ada', 'school' => 'Ignored', 'email' => 'ada@example.com'], $contactConfiguration, true);
$check($contact === ['name' => 'Ada', 'email' => 'ada@example.com'], 'configured intake stores only enabled participant fields');
$contactConsentRejected = false;
try { study_interest_contact_from_input(['name' => 'Ada', 'email' => 'ada@example.com'], $contactConfiguration, false); }
catch (DomainException) { $contactConsentRejected = true; }
$check($contactConsentRejected, 'configured contact details still require separate consent');

$eligibilityConfiguration = $configuration;
$eligibilityConfiguration['target_intake'] = 'intake-2027';
$eligibilityConfiguration['registration_attributes'] = [
    'route' => [
        'label' => 'Application route', 'help' => 'Choose one if known.', 'privacy' => 'Used only to evaluate configured availability rules.',
        'options' => [
            ['code' => 'allow', 'label' => 'Route A'],
            ['code' => 'block', 'label' => 'Route B'],
            ['code' => 'undisclosed', 'label' => 'Prefer not to say', 'is_undisclosed' => true],
        ],
    ],
    'region' => [
        'label' => 'Region', 'help' => '', 'privacy' => 'Stored with the session for configured eligibility checks.',
        'options' => [['code' => 'north', 'label' => 'North'], ['code' => 'south', 'label' => 'South']],
    ],
];
$policy = [
    'code' => 'route-policy',
    'official_version' => '2027.1',
    'target_intake' => 'intake-2027',
    'affected_program_codes' => ['physics'],
    'application_surface' => 'DIRECT_RECOMMENDATION',
    'attribute' => 'route',
    'decision_map' => ['allow' => 'ELIGIBLE', 'block' => 'INELIGIBLE', 'undisclosed' => 'INELIGIBLE'],
    'reason_codes' => ['ELIGIBLE' => 'POLICY_MATCH', 'INELIGIBLE' => 'POLICY_NOT_MET', 'NEEDS_REVIEW' => 'POLICY_REVIEW'],
    'status' => 'published',
    'effective_from' => '2000-01-01',
    'effective_until' => '2099-12-31',
    'source_document_id' => 'document-2027-1',
    'approved_by' => 'approval-office',
    'approved_at' => '2026-11-01T00:00:00Z',
];
$eligibilityConfiguration['eligibility_policies'] = [$policy];
$check(!study_interest_has_eligibility_policies($configuration)
    && study_interest_has_eligibility_policies($eligibilityConfiguration), 'correction sensitivity is derived only from non-empty schema-v3 eligibility policy configuration');
$check(study_interest_configuration_errors($eligibilityConfiguration) === [], 'schema-v3 registration and published eligibility policy pass publication validation');
$radioConfiguration = $eligibilityConfiguration;
$radioConfiguration['registration_attributes']['route'] = [
    'label' => 'Application route', 'help' => 'Choose one.', 'privacy' => 'Used only for configured eligibility checks.',
    'control' => 'radio', 'required' => true,
    'options' => [['code' => 'allow', 'label' => 'Route A'], ['code' => 'block', 'label' => 'Route B']],
];
$radioConfiguration['eligibility_policies'][0]['decision_map'] = ['allow' => 'ELIGIBLE', 'block' => 'INELIGIBLE'];
$invalidRegistrationControl = $radioConfiguration;
$invalidRegistrationControl['registration_attributes']['route']['control'] = 'buttons';
$invalidRegistrationRequired = $radioConfiguration;
$invalidRegistrationRequired['registration_attributes']['route']['required'] = 1;
$check(study_interest_configuration_errors($radioConfiguration) === []
    && count($radioConfiguration['registration_attributes']['route']['options']) === 2
    && study_interest_configuration_draft_errors($invalidRegistrationControl) !== []
    && study_interest_configuration_draft_errors($invalidRegistrationRequired) !== [],
    'schema-v3 accepts an exactly two-option required radio and rejects non-exact control and boolean values');
$legacyAttributeConfiguration = $eligibilityConfiguration;
$legacyAttribute = $legacyAttributeConfiguration['registration_attributes']['route'];
$check(!array_key_exists('control', $legacyAttribute) && !array_key_exists('required', $legacyAttribute)
    && study_interest_configuration_errors($legacyAttributeConfiguration) === []
    && ($legacyAttribute['control'] ?? 'select') === 'select'
    && ($legacyAttribute['required'] ?? false) === false,
    'existing schema-v3 registration definitions remain optional selects by default');
$unknownSurfaceConfiguration = $eligibilityConfiguration;
$unknownSurfaceConfiguration['eligibility_policies'][0]['application_surface'] = 'UNKNOWN_SURFACE';
$mismatchedSurfaceConfiguration = $eligibilityConfiguration;
$mismatchedSurfaceConfiguration['eligibility_policies'][0]['application_surface'] = 'PROFESSIONAL_PATHWAY';
$check(study_interest_configuration_errors($unknownSurfaceConfiguration) !== []
    && study_interest_configuration_errors($mismatchedSurfaceConfiguration) !== [], 'publication rejects unknown and recommendation-type-mismatched application surfaces');
$draftPolicyConfiguration = $eligibilityConfiguration;
$draftPolicyConfiguration['eligibility_policies'][0]['status'] = 'draft';
$draftPolicyConfiguration['eligibility_policies'][0]['approved_by'] = '';
$draftPolicyConfiguration['eligibility_policies'][0]['approved_at'] = '';
$check(study_interest_configuration_draft_errors($draftPolicyConfiguration) === []
    && study_interest_configuration_errors($draftPolicyConfiguration) !== [], 'draft policy metadata imports but cannot publish before approval');
$incompleteDraftPolicyConfiguration = $eligibilityConfiguration;
$incompleteDraftPolicyConfiguration['target_intake'] = '';
$incompleteDraftPolicyConfiguration['eligibility_policies'][0]['status'] = 'draft';
foreach (['official_version', 'target_intake', 'effective_from', 'effective_until', 'source_document_id', 'approved_by', 'approved_at'] as $key) {
    $incompleteDraftPolicyConfiguration['eligibility_policies'][0][$key] = '';
}
$check(study_interest_configuration_draft_errors($incompleteDraftPolicyConfiguration) === []
    && study_interest_runtime_configuration_errors($incompleteDraftPolicyConfiguration) === []
    && study_interest_configuration_errors($incompleteDraftPolicyConfiguration) !== [], 'an incomplete draft policy passes import and runtime validation but fails full publication validation');
$incompletePublishedPolicyConfiguration = $incompleteDraftPolicyConfiguration;
$incompletePublishedPolicyConfiguration['eligibility_policies'][0]['status'] = 'published';
$incompleteRetiredPolicyConfiguration = $incompleteDraftPolicyConfiguration;
$incompleteRetiredPolicyConfiguration['eligibility_policies'][0]['status'] = 'retired';
$check(study_interest_configuration_draft_errors($incompletePublishedPolicyConfiguration) !== []
    && study_interest_configuration_draft_errors($incompleteRetiredPolicyConfiguration) !== []
    && study_interest_runtime_configuration_errors($incompletePublishedPolicyConfiguration) === [], 'published and retired authoring states require complete official metadata while runtime can fail safe');
$invalidDraftPolicyMetadata = $incompleteDraftPolicyConfiguration;
$invalidDraftPolicyMetadata['eligibility_policies'][0]['official_version'] = 'not valid!';
$partialDraftPolicyDates = $incompleteDraftPolicyConfiguration;
$partialDraftPolicyDates['eligibility_policies'][0]['effective_from'] = '2027-01-01';
$mismatchedDraftPolicyIntake = $incompleteDraftPolicyConfiguration;
$mismatchedDraftPolicyIntake['eligibility_policies'][0]['target_intake'] = 'intake-2027';
$incompleteDraftReasonCodes = $incompleteDraftPolicyConfiguration;
unset($incompleteDraftReasonCodes['eligibility_policies'][0]['reason_codes']['NEEDS_REVIEW']);
$check(study_interest_configuration_draft_errors($invalidDraftPolicyMetadata) !== []
    && study_interest_configuration_draft_errors($partialDraftPolicyDates) !== []
    && study_interest_configuration_draft_errors($mismatchedDraftPolicyIntake) !== []
    && study_interest_configuration_draft_errors($incompleteDraftReasonCodes) !== [], 'supplied draft metadata must be valid and consistent and all neutral reason codes remain required');
$expiredPolicyConfiguration = $eligibilityConfiguration;
$expiredPolicyConfiguration['eligibility_policies'][0]['effective_from'] = '2000-01-01';
$expiredPolicyConfiguration['eligibility_policies'][0]['effective_until'] = '2001-01-01';
$check(study_interest_configuration_errors($expiredPolicyConfiguration) !== [], 'publication rejects an out-of-period configured policy');
$futureApprovalConfiguration = $eligibilityConfiguration;
$futureApprovalConfiguration['eligibility_policies'][0]['approved_at'] = '2099-01-01T00:00:00Z';
$check(study_interest_configuration_errors($futureApprovalConfiguration) !== [], 'publication rejects a policy approved after the publication eligibility instant');
$invalidPolicyDate = $eligibilityConfiguration;
$invalidPolicyDate['eligibility_policies'][0]['effective_until'] = '2099-02-30';
$invalidApprovalDate = $eligibilityConfiguration;
$invalidApprovalDate['eligibility_policies'][0]['approved_at'] = '2026-13-01T00:00:00Z';
$check(study_interest_configuration_draft_errors($invalidPolicyDate) !== []
    && study_interest_configuration_draft_errors($invalidApprovalDate) !== [], 'policy metadata rejects impossible calendar dates and approval timestamps');
$registration = study_interest_registration_attributes_from_input([
    'region' => 'north', 'route' => 'allow', 'not_configured' => ['invalid'],
], $eligibilityConfiguration);
$check($registration === ['region' => 'north', 'route' => 'allow']
    && study_interest_registration_attributes_json($registration) === '{"region":"north","route":"allow"}'
    && study_interest_registration_attributes_hash($registration) === hash('sha256', '{"region":"north","route":"allow"}'), 'optional registration mapping ignores unconfigured input and freezes canonical JSON with SHA-256');
$emptyRegistrationJson = study_interest_registration_attributes_json([]);
$check($emptyRegistrationJson === '{}'
    && study_interest_registration_attributes_from_session([
        'registration_attributes_json' => $emptyRegistrationJson,
        'registration_attributes_hash' => hash('sha256', $emptyRegistrationJson),
    ], $configuration) === [], 'schema-v3 sessions preserve an empty registration object without blocking completion');
$invalidRegistrationRejected = false;
try { study_interest_registration_attributes_from_input(['route' => 'unknown'], $eligibilityConfiguration); }
catch (DomainException) { $invalidRegistrationRejected = true; }
$check($invalidRegistrationRejected, 'configured registration selects reject unknown option codes');
$nonScalarRegistrationRejected = false;
try { study_interest_registration_attributes_from_input(['region' => ['north']], $eligibilityConfiguration); }
catch (DomainException) { $nonScalarRegistrationRejected = true; }
$requiredRegistrationRejected = false;
$requiredRegistrationMessage = '';
try { study_interest_registration_attributes_from_input([], $radioConfiguration); }
catch (DomainException $error) { $requiredRegistrationRejected = true; $requiredRegistrationMessage = $error->getMessage(); }
$radioRegistration = study_interest_registration_attributes_from_input(['route' => 'allow'], $radioConfiguration);
$check($nonScalarRegistrationRejected && $requiredRegistrationRejected && $requiredRegistrationMessage === 'This field is required.'
    && $radioRegistration === ['route' => 'allow']
    && study_interest_registration_attributes_json($radioRegistration) === '{"route":"allow"}',
    'registration rejects non-scalar and missing required values with a neutral error and freezes the selected radio value canonically');
$_SERVER['HTTPS'] = '';
putenv('FORCE_HTTPS=0'); putenv('SESSION_ALLOW_INSECURE_COOKIES=0');
$secureByDefault = study_interest_cookie_secure();
putenv('SESSION_ALLOW_INSECURE_COOKIES=1');
$check($secureByDefault && !study_interest_cookie_secure(), 'session bearer cookie is secure by default and HTTP requires explicit opt-in');
putenv('SESSION_ALLOW_INSECURE_COOKIES'); putenv('FORCE_HTTPS');

$answers = [];
$answersByDimension = ['CON' => '5', 'QUA' => '4', 'LAN' => '3', 'CMP' => '2', 'SOC' => '1', 'DES' => '5'];
foreach ($configuration['questions'] as $question) $answers[$question['code']] = $answersByDimension[(string)$question['dimension']];
$result = study_interest_score($configuration, $answers, 600);
$describedResult = study_interest_score($describedConfiguration, $answers, 600);
$describedPrograms = array_column($describedResult['programs'], null, 'code');
$describedRecommendations = array_column($describedResult['recommendations'], null, 'code');
$dimensionScores = array_column($result['dimensions'], 'score', 'code');
$recommendationScores = array_column($result['recommendations'], 'score', 'code');
$check(abs($dimensionScores['CON'] - 100.0) < 0.001 && abs($dimensionScores['QUA'] - 75.0) < 0.001
    && abs($dimensionScores['SOC']) < 0.001, 'generic scoring normalizes configured dimensions exactly');
$check(array_keys($recommendationScores) === ['physics', 'design', 'computing'], 'generic profile ranks only qualifying primary directions');
$check(abs($recommendationScores['physics'] - 76.0) < 0.001 && abs($recommendationScores['design'] - 70.0) < 0.001, 'generic direction scores are reproducible');
$check($result['profile_clarity']['code'] === 'MULTIDISCIPLINARY' && abs($result['profile_clarity']['top_gap'] - 6.0) < 0.001, 'generic near-tie profile is multidisciplinary');
$check(count($result['interpretations']) === 1 && str_contains($result['interpretations'][0], 'quantitative models'), 'configured direction combinations produce interpretation copy');
$check($result['professional_pathways'] === [], 'professional pathway is excluded from direct ranking');
$check($result['flags'] === [], 'carefully answered golden profile has no quality flags');
$check(($describedPrograms['physics']['description'] ?? null) === 'Explore matter, energy, and quantitative models.'
    && ($describedRecommendations['physics']['description'] ?? null) === 'Explore matter, energy, and quantitative models.'
    && str_contains(study_interest_configuration_json($describedResult), 'Explore matter, energy, and quantitative models.'), 'weighted-choice-2.1 freezes descriptions into program and recommendation result snapshots');
$incompleteDraftRuntimeResult = study_interest_score($incompleteDraftPolicyConfiguration, $answers, 600, ['route' => 'allow'], '2026-11-19');
$check(array_column($incompleteDraftRuntimeResult['programs'], null, 'code')['physics']['eligibility_status'] === 'NEEDS_REVIEW', 'an incomplete draft policy fails safe to review at runtime');
$eligibleResult = study_interest_score($eligibilityConfiguration, $answers, 600, ['route' => 'allow', 'region' => 'north'], '2026-11-19');
$ineligibleResult = study_interest_score($eligibilityConfiguration, $answers, 600, ['route' => 'block'], '2026-11-19');
$reviewResult = study_interest_score($eligibilityConfiguration, $answers, 600, [], '2026-11-19');
$undisclosedResult = study_interest_score($eligibilityConfiguration, $answers, 600, ['route' => 'undisclosed'], '2026-11-19');
$eligiblePrograms = array_column($eligibleResult['programs'], null, 'code');
$ineligiblePrograms = array_column($ineligibleResult['programs'], null, 'code');
$reviewPrograms = array_column($reviewResult['programs'], null, 'code');
$check($eligiblePrograms['physics']['eligibility_status'] === 'ELIGIBLE'
    && $ineligiblePrograms['physics']['eligibility_status'] === 'INELIGIBLE'
    && $reviewPrograms['physics']['eligibility_status'] === 'NEEDS_REVIEW'
    && array_column($undisclosedResult['programs'], null, 'code')['physics']['eligibility_status'] === 'NEEDS_REVIEW', 'eligibility produces all three statuses and treats missing or undisclosed attributes as review');
$check($ineligiblePrograms['design']['eligibility_status'] === 'ELIGIBLE'
    && abs((float)$ineligiblePrograms['physics']['score'] - (float)$eligiblePrograms['physics']['score']) < 0.000001
    && $ineligiblePrograms['physics']['classification'] === $eligiblePrograms['physics']['classification'], 'eligibility leaves unaffected programs eligible and never changes raw scores or classifications');
$ineligibleRecommendationCodes = array_column($ineligibleResult['recommendations'], 'code');
$check(!in_array('physics', $ineligibleRecommendationCodes, true)
    && count($ineligibleRecommendationCodes) === 3
    && array_column($ineligibleResult['recommendations'], 'rank') === [1, 2, 3]
    && array_column($ineligibleResult['recommendations'], 'pre_eligibility_rank')[0] > 1, 'effective recommendations filter, backfill, and rerank contiguously after scoring');
$check($ineligibleResult['interpretations'] === []
    && $ineligibleResult['profile_clarity'] !== $eligibleResult['profile_clarity'], 'interpretations and clarity use effective recommendations after eligibility');
$expectedEvidence = study_interest_policy_metadata($policy);
$check($ineligibleResult['eligibility_evidence']['policies'] === [$expectedEvidence]
    && $ineligiblePrograms['physics']['policy_codes'] === ['route-policy']
    && $ineligiblePrograms['physics']['policy_hashes'] === [study_interest_policy_hash($policy)]
    && $ineligiblePrograms['physics']['reason_codes'] === ['POLICY_NOT_MET'], 'result evidence freezes exact policy metadata, deterministic hash, and neutral reason codes');
$reorderedPolicy = array_reverse($policy, true);
$check(study_interest_policy_hash($policy) === study_interest_policy_hash($reorderedPolicy), 'policy hash is canonical and independent of object key order');
$unapprovedRuntime = $eligibilityConfiguration;
$unapprovedRuntime['eligibility_policies'][0]['approved_by'] = '';
$unapprovedRuntime['eligibility_policies'][0]['approved_at'] = '';
$unapprovedResult = study_interest_score_versioned('weighted-choice-2.1', $unapprovedRuntime, $answers, 600, true, ['route' => 'allow'], '2026-11-19');
$outOfPeriodResult = study_interest_score_versioned('weighted-choice-2.1', $eligibilityConfiguration, $answers, 600, true, ['route' => 'allow'], '2100-01-01');
$unknownSurfaceResult = study_interest_score_versioned('weighted-choice-2.1', $unknownSurfaceConfiguration, $answers, 600, false, ['route' => 'allow'], '2026-11-19T00:00:00Z');
$mismatchedSurfaceResult = study_interest_score_versioned('weighted-choice-2.1', $mismatchedSurfaceConfiguration, $answers, 600, false, ['route' => 'allow'], '2026-11-19T00:00:00Z');
$mixedSurfaceConfiguration = $eligibilityConfiguration;
$mixedSurfacePolicy = $mismatchedSurfaceConfiguration['eligibility_policies'][0];
$mixedSurfacePolicy['code'] = 'cross-surface-policy';
$mixedSurfaceConfiguration['eligibility_policies'][] = $mixedSurfacePolicy;
$mixedSurfaceResult = study_interest_score_versioned('weighted-choice-2.1', $mixedSurfaceConfiguration, $answers, 600, false, ['route' => 'block'], '2026-11-19T00:00:00Z');
$approvalAfterStartConfiguration = $eligibilityConfiguration;
$approvalAfterStartConfiguration['eligibility_policies'][0]['approved_at'] = '2026-11-19T12:00:00Z';
$approvalAfterStartResult = study_interest_score_versioned('weighted-choice-2.1', $approvalAfterStartConfiguration, $answers, 600, false, ['route' => 'allow'], '2026-11-19T11:59:59Z');
$check(array_column($unapprovedResult['programs'], null, 'code')['physics']['eligibility_status'] === 'NEEDS_REVIEW'
    && array_column($outOfPeriodResult['programs'], null, 'code')['physics']['eligibility_status'] === 'NEEDS_REVIEW'
    && array_column($unknownSurfaceResult['programs'], null, 'code')['physics']['eligibility_status'] === 'NEEDS_REVIEW'
    && array_column($mismatchedSurfaceResult['programs'], null, 'code')['physics']['eligibility_status'] === 'NEEDS_REVIEW'
    && array_column($mixedSurfaceResult['programs'], null, 'code')['physics']['eligibility_status'] === 'NEEDS_REVIEW'
    && array_column($approvalAfterStartResult['programs'], null, 'code')['physics']['eligibility_status'] === 'NEEDS_REVIEW', 'unapproved, out-of-period, unknown-surface, cross-surface, and approval-after-session policies fail safe to review at runtime');
$closedConfiguration = $eligibilityConfiguration;
$closedConfiguration['programs']['physics']['intake_availability'] = 'CLOSED';
$closedResult = study_interest_score($closedConfiguration, $answers, 600, ['route' => 'allow'], '2026-11-19');
$check(array_column($closedResult['programs'], null, 'code')['physics']['eligibility_status'] === 'ELIGIBLE'
    && !in_array('physics', array_column($closedResult['recommendations'], 'code'), true), 'non-accepting intake availability is audited separately and filtered from recommendations');
$allIneligibleConfiguration = $eligibilityConfiguration;
$allIneligibleConfiguration['eligibility_policies'][0]['affected_program_codes'] = array_keys($allIneligibleConfiguration['programs']);
$allIneligible = study_interest_score($allIneligibleConfiguration, $answers, 600, ['route' => 'block'], '2026-11-19');
$check($allIneligible['recommendations'] === [] && $allIneligible['professional_pathways'] === []
    && $allIneligible['interpretations'] === [] && $allIneligible['profile_clarity']['code'] === 'OPEN', 'all-ineligible result has neutral no-result state without pathways or interpretations');
$pathwayConfiguration = $eligibilityConfiguration;
$pathwayConfiguration['programs']['advanced-pathway'] = $pathwayConfiguration['programs']['physics'];
$pathwayConfiguration['programs']['advanced-pathway']['label'] = 'Advanced pathway';
$pathwayConfiguration['programs']['advanced-pathway']['description'] = 'A frozen participant-facing pathway description.';
$pathwayConfiguration['programs']['advanced-pathway']['recommendation_type'] = 'PROFESSIONAL_PATHWAY';
$pathwayPolicy = $policy;
$pathwayPolicy['code'] = 'pathway-policy';
$pathwayPolicy['application_surface'] = 'PROFESSIONAL_PATHWAY';
$pathwayPolicy['affected_program_codes'] = ['advanced-pathway'];
$pathwayConfiguration['eligibility_policies'][] = $pathwayPolicy;
$pathwayEligible = study_interest_score($pathwayConfiguration, $answers, 600, ['route' => 'allow'], '2026-11-19');
$pathwayBlocked = study_interest_score($pathwayConfiguration, $answers, 600, ['route' => 'block'], '2026-11-19');
$check(array_column($pathwayEligible['professional_pathways'], 'code') === ['advanced-pathway']
    && ($pathwayEligible['professional_pathways'][0]['description'] ?? null) === 'A frozen participant-facing pathway description.'
    && $pathwayBlocked['professional_pathways'] === [], 'professional pathways freeze descriptions and use the same eligibility and availability filter');
$snapshotContract = ['schema' => 2, 'registration_attributes_hash' => study_interest_registration_attributes_hash(['route' => 'block'])] + $ineligibleResult;
$snapshotJson = study_interest_configuration_json($snapshotContract);
$check(!array_key_exists('registration_attributes', $snapshotContract)
    && !array_key_exists('registration_attributes_json', $snapshotContract)
    && str_contains($snapshotJson, 'registration_attributes_hash') && str_contains($snapshotJson, 'eligibility_evidence'), 'schema-2 result snapshot carries registration hash and eligibility evidence but no raw registration map');
$check(study_interest_snapshot_matches_result($snapshotContract, $ineligibleResult, study_interest_registration_attributes_hash(['route' => 'block']))
    && !study_interest_snapshot_matches_result($snapshotContract, $ineligibleResult, study_interest_registration_attributes_hash(['route' => 'allow']))
    && !study_interest_snapshot_matches_result($snapshotContract, $ineligibleResult), 'schema-2 snapshot matching accepts only the verified session registration hash');
$correctionSource = file_get_contents(__DIR__ . '/../admin/sessions/save-correction.php');
$check(str_contains($correctionSource, "\$schemaVersion >= 3 ? 2"), 'schema-v3 correction revisions remain schema-2 snapshots');

$historicalConfiguration = $configuration;
$historicalConfiguration['schema_version'] = 2;
$historicalConfiguration['algorithm_version'] = 'weighted-choice-2.0';
unset($historicalConfiguration['target_intake'], $historicalConfiguration['registration_attributes'], $historicalConfiguration['eligibility_policies']);
foreach ($historicalConfiguration['programs'] as &$program) {
    unset($program['intake_availability']);
    $program['description'] = 'Schema-v2 output must ignore this field.';
}
unset($program);
$historicalResult = study_interest_score($historicalConfiguration, $answers, 600);
$check(array_column($historicalResult['recommendations'], 'code') === ['physics', 'design', 'computing']
    && !array_key_exists('eligibility_evidence', $historicalResult)
    && !array_key_exists('eligibility_status', $historicalResult['programs'][0])
    && !array_key_exists('description', $historicalResult['programs'][0])
    && !array_key_exists('description', $historicalResult['recommendations'][0]), 'schema-v2 weighted-choice-2.0 historical result shape and ranking remain compatible');
$historicalPrograms = $historicalResult['programs'];
foreach ($historicalPrograms as &$historicalProgram) $historicalProgram['recommended'] = false;
unset($historicalProgram);
$check(array_column(study_interest_historical_leading_directions($historicalPrograms, 3), 'code') === ['physics', 'design', 'computing']
    && array_column(study_interest_historical_leading_directions($historicalPrograms, 1), 'code') === ['physics'], 'historical closest directions come from raw direct scores when frozen recommendations are zero or fewer than the configured limit');

$precisionConfiguration = [
    'schema_version' => 3, 'algorithm_version' => 'weighted-choice-2.1',
    'dimensions' => ['D1' => ['label' => 'One'], 'D2' => ['label' => 'Two']],
    'sections' => ['S' => ['weight' => 1.0]],
    'questions' => [[
        'code' => 'Q', 'section' => 'S', 'options' => [
            ['code' => 'A', 'scores' => ['D1' => 0, 'D2' => 0]],
            ['code' => 'B', 'scores' => ['D1' => 61.564935064935, 'D2' => 61.564936]],
            ['code' => 'C', 'scores' => ['D1' => 100, 'D2' => 100]],
        ],
    ]],
    'programs' => [
        'alpha' => ['label' => 'Alpha', 'recommendation_type' => 'DIRECT_ENTRY', 'intake_availability' => 'ACCEPTING', 'weights' => ['D1' => 1, 'D2' => 0]],
        'zeta' => ['label' => 'Zeta', 'recommendation_type' => 'DIRECT_ENTRY', 'intake_availability' => 'ACCEPTING', 'weights' => ['D1' => 0, 'D2' => 1]],
    ],
    'thresholds' => [
        'classifications' => [
            ['minimum' => 0, 'maximum' => 61.5649355, 'label' => 'LOWER'],
            ['minimum' => 61.5649355, 'maximum' => 100, 'label' => 'UPPER'],
        ],
        'direct_recommendation_minimum' => 0, 'direct_recommendation_limit' => 2, 'professional_pathway_minimum' => 0,
        'profile_clarity' => ['practically_equal_below' => .0000005, 'multidisciplinary_below' => 1, 'fairly_clear_minimum' => 1, 'very_clear_minimum' => 2],
        'response_quality' => [],
    ],
    'target_intake' => '', 'registration_attributes' => [], 'eligibility_policies' => [], 'interpretation_rules' => [],
];
$precision21 = study_interest_score_weighted_choice_2_1($precisionConfiguration, ['Q' => 'B'], 600, false);
$precisionDimensions = array_column($precision21['dimensions'], 'score', 'code');
$precisionPrograms = array_column($precision21['programs'], null, 'code');
$check($precisionDimensions === ['D2' => 61.56, 'D1' => 61.56]
    && array_column($precision21['programs'], 'code') === ['zeta', 'alpha']
    && $precisionPrograms['zeta']['score'] === 61.56 && $precisionPrograms['alpha']['score'] === 61.56
    && $precisionPrograms['zeta']['classification'] === 'UPPER' && $precisionPrograms['alpha']['classification'] === 'LOWER'
    && array_column($precision21['recommendations'], 'code') === ['zeta', 'alpha']
    && $precision21['profile_clarity'] === ['code' => 'MULTIDISCIPLINARY', 'top_gap' => 0.0],
    'weighted-choice-2.1 rounds PRA-style outputs directly to two decimals after full-precision ranking, classification, and clarity');
$thresholdPrecisionConfiguration = $precisionConfiguration;
$thresholdPrecisionConfiguration['thresholds']['direct_recommendation_minimum'] = 61.5649355;
$thresholdPrecision = study_interest_score_weighted_choice_2_1($thresholdPrecisionConfiguration, ['Q' => 'B'], 600, false);
$check(array_column($thresholdPrecision['recommendations'], 'code') === ['zeta'],
    'weighted-choice-2.1 applies recommendation thresholds before final score rounding');
$fullWeightConfiguration = $precisionConfiguration;
$fullWeightConfiguration['sections'] = ['MEASURED' => ['weight' => .25], 'MISSING' => ['weight' => .75]];
$fullWeightConfiguration['questions'][0]['section'] = 'MEASURED';
$fullWeightResult = study_interest_score_weighted_choice_2_1($fullWeightConfiguration, ['Q' => 'C'], 600, false);
$fullWeightScores = array_column($fullWeightResult['dimensions'], 'score', 'code');
$check($fullWeightScores === ['D1' => 25.00, 'D2' => 25.00], 'weighted-choice-2.1 keeps the full configured section weights instead of renormalizing missing ranges');
$preview = study_interest_correction_preview($configuration, 'weighted-choice-2.1', $answers, $answers, 600, 'physics');
$previewJson = json_encode($preview, JSON_THROW_ON_ERROR);
$check(($preview['target']['code'] ?? null) === 'physics' && ($preview['changed_count'] ?? null) === 0
    && count($preview['choices'] ?? []) === count($configuration['questions'])
    && count($preview['choices']['Q01'] ?? []) === count($configuration['questions'][0]['options'])
    && !str_contains($previewJson, '"scores"'), 'correction guidance derives complete answer impacts without exposing hidden scoring vectors');
$changedAnswers = $answers;
$changedAnswers['Q01'] = $answers['Q01'] === '1' ? '2' : '1';
$changedPreview = study_interest_correction_preview($configuration, 'weighted-choice-2.1', $answers, $changedAnswers, 600, 'physics');
$check(($changedPreview['changed_count'] ?? null) === 1
    && isset($changedPreview['target']['saved_score'], $changedPreview['target']['delta'])
    && is_array($changedPreview['suggestions'] ?? null), 'correction guidance compares a draft against the saved result and ranks alternatives');
$expiredCorrectionConfiguration = $eligibilityConfiguration;
$expiredCorrectionConfiguration['eligibility_policies'][0]['effective_from'] = '2020-01-01';
$expiredCorrectionConfiguration['eligibility_policies'][0]['effective_until'] = '2020-12-31';
$expiredCorrectionConfiguration['eligibility_policies'][0]['approved_at'] = '2019-12-31T23:59:59Z';
$expiredCorrectionPreview = study_interest_correction_preview($expiredCorrectionConfiguration, 'weighted-choice-2.1', $answers, $answers, 600, 'physics', null, ['route' => 'allow'], '2020-06-01T09:00:00Z');
$check(($expiredCorrectionPreview['target']['code'] ?? null) === 'physics', 'correction preview uses runtime validation and remains reproducible after a policy expires for an in-period session');
$largePreviewConfiguration = $configuration;
for ($index = count($largePreviewConfiguration['questions'][0]['options']); $index < 1000; $index++) {
    $largePreviewConfiguration['questions'][0]['options'][] = [
        'code' => 'EXTRA_' . $index,
        'label' => 'Extra option ' . $index,
        'scores' => array_fill_keys(array_keys($largePreviewConfiguration['dimensions']), 0),
    ];
}
try {
    study_interest_correction_preview($largePreviewConfiguration, 'weighted-choice-2.1', $answers, $answers, 600, 'physics');
    $largePreviewRejected = false;
} catch (DomainException $error) {
    $largePreviewRejected = $error->getMessage() === 'This assessment is too large for live correction guidance.';
}
$check($largePreviewRejected, 'correction guidance rejects scoring workloads that exceed its live-preview bound');
$leadingDirections = study_interest_leading_directions($result['programs'], 3);
$check(count($leadingDirections) === 3 && (string)$leadingDirections[0]['code'] === 'physics', 'closest study directions are always derived from primary direction scores');
$fastResult = study_interest_score($configuration, $answers, 100);
$check(array_column($fastResult['flags'], 'code') === ['FAST_COMPLETION'], 'generic response-quality analyzer flags fast completion');
$straightliningConfiguration = $configuration;
$straightliningConfiguration['thresholds']['response_quality']['straightlining_same_answers_minimum'] = 18;
$straightliningAnswers = array_fill_keys(array_column($straightliningConfiguration['questions'], 'code'), '5');
$straightliningResult = study_interest_score($straightliningConfiguration, $straightliningAnswers, 600);
$check(array_column($straightliningResult['flags'], 'code') === ['STRAIGHTLINING']
    && ($straightliningResult['flags'][0]['context']['same_answer_count'] ?? null) === 18, 'schema-v3 straightlining uses configured dimension-tagged Likert responses');
$consistencyConfiguration = $configuration;
$consistencyConfiguration['questions'][1]['is_reverse'] = true;
foreach ($consistencyConfiguration['questions'][1]['options'] as &$option) $option['scores']['CON'] = 100 - (float)$option['scores']['CON'];
unset($option);
$consistencyConfiguration['thresholds']['response_quality']['low_response_consistency_dimensions_minimum'] = 1;
$consistencyAnswers = $answers;
$consistencyAnswers['Q01'] = '5';
$consistencyAnswers['Q02'] = '5';
$consistencyResult = study_interest_score($consistencyConfiguration, $consistencyAnswers, 600);
$check(array_column($consistencyResult['flags'], 'code') === ['LOW_RESPONSE_CONSISTENCY']
    && ($consistencyResult['flags'][0]['context']['dimensions'] ?? null) === ['CON'], 'schema-v3 consistency compares configured normal and reverse Likert items by valid dimension metadata');
$openResult = $result;
$openResult['recommendations'] = [];
foreach ($openResult['programs'] as &$program) $program['eligibility_status'] = 'INELIGIBLE';
unset($program);
$check(study_interest_leading_directions($openResult['programs'], 1) === [], 'an open profile cannot bypass eligibility when deriving a closest direction');
$missingRejected = false;
try { $incomplete = $answers; unset($incomplete['Q18']); study_interest_score($configuration, $incomplete, 600); }
catch (DomainException) { $missingRejected = true; }
$check($missingRejected, 'missing required answers are rejected');
$check(in_array('baseline-1.0', study_interest_supported_algorithm_versions(), true), 'historical baseline scoring remains registered');
$check(in_array('weighted-choice-2.0', study_interest_supported_algorithm_versions(), true)
    && in_array('weighted-choice-2.1', study_interest_supported_algorithm_versions(), true), 'both frozen generic scoring algorithms remain registered');

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
$deploymentCoupling = false;
$localPatterns = ['#/var/www/#i', '#\b(?:https?://)?[a-z0-9.-]+\.lan\b#i'];
foreach ($iterator as $file) {
    $path = $file->getPathname();
    if (!$file->isFile() || $path === __FILE__ || str_contains($path, '/.git/') || str_contains($path, '/.notes/')) continue;
    if (preg_match('/\.(?:php|json|md|sql|js|css|svg)$/', $path) !== 1) continue;
    $contents = (string)file_get_contents($path);
    foreach ($localPatterns as $pattern) {
        if (preg_match($pattern, $contents) === 1) { $deploymentCoupling = true; break 2; }
    }
}
$check(!$deploymentCoupling, 'tracked product source contains no local deployment coupling');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " contract check(s) failed.\n");
    exit(1);
}
echo "Study Interest contract passed.\n";
