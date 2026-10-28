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
$check(($manifest['version'] ?? null) === '0.9.10', 'plugin version is 0.9.10');
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
    && is_file($root . '/migrations/0013-result-follow-up-copy.php'), 'append-only foundation, workspace, result-presentation, and branding migrations exist');
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
    && str_contains($pluginSource, '/static/plugins/study-interest/admin.css?v=0.9.10')
    && str_contains($pluginSource, '/static/plugins/study-interest/admin.js?v=0.9.10')
    && str_contains($publicLayoutSource, '/static/plugins/study-interest/frontend.css?v=0.9.10')
    && str_contains($publicLayoutSource, '/static/plugins/study-interest/frontend.js?v=0.9.10'), 'plugin assets use the release cache key');
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
$correctionSource = (string)file_get_contents($root . '/admin/sessions/save-correction.php');
$sessionListSource = (string)file_get_contents($root . '/admin/sessions/index.php');
$sessionViewSource = (string)file_get_contents($root . '/admin/sessions/view.php');
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
    && str_contains($assessmentSaveSource, 'test.updated'), 'assessment editor manages canonical test metadata and participant result messages');
$check(str_contains($correctionSource, 'study_interest_result_revisions')
    && str_contains($correctionSource, 'study_interest_score_versioned')
    && str_contains($correctionSource, 'expected_revision')
    && str_contains($correctionSource, 'plugin.study-interest.responses.view')
    && str_contains($correctionSource, 'result.corrected')
    && str_contains($correctionSource, 'changed_questions')
    && str_contains($sessionListSource, 'Edit submitted answers'), 'submitted-answer editing recalculates and records an authorized audited optimistic revision');
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
$adminSource = (string)file_get_contents($root . '/assets/admin.js');
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
$check(str_contains($publicResultSource, 'direct_recommendation_limit')
    && str_contains($publicResultSource, "elseif (\$sectionState('pathways') === 'mask')")
    && str_contains($publicResultSource, "elseif (\$sectionState('interpretation') === 'mask')"), 'public result respects the immutable direction limit and masks optional sections without existence disclosure');
$check(str_contains($publicResultSource, '$assessmentTitle')
    && str_contains($publicResultSource, '$assessmentDescription'), 'public assessment identity comes from dashboard-managed configuration');
$check(!str_contains($publicResultSource, '$estimatedMinutes')
    && !str_contains($publicResultSource, "['duration_prefix']")
    && !str_contains($publicResultSource, "['duration_suffix']")
    && !str_contains($publicResultSource, 'feature_one_')
    && !str_contains($publicResultSource, 'No timer')
    && !str_contains($publicResultSource, 'Tanpa timer')
    && str_contains($publicResultSource, '<span class="sie-time-badge"><?= $questionCount ?>'), 'landing badge shows only the question count without timing copy');
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
$check(($configuration['schema_version'] ?? null) === 2 && ($configuration['algorithm_version'] ?? null) === 'weighted-choice-2.0', 'packaged starter uses generic schema v2 scoring');
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
$leadingDirections = study_interest_leading_directions($result['programs'], 3);
$check(count($leadingDirections) === 3 && (string)$leadingDirections[0]['code'] === 'physics', 'closest study directions are always derived from primary direction scores');
$fastResult = study_interest_score($configuration, $answers, 100);
$check(array_column($fastResult['flags'], 'code') === ['FAST_COMPLETION'], 'generic response-quality analyzer flags fast completion');
$openResult = $result;
$openResult['recommendations'] = [];
$check(study_interest_leading_directions($openResult['programs'], 1) !== [], 'an open profile still has a closest study direction');
$missingRejected = false;
try { $incomplete = $answers; unset($incomplete['Q18']); study_interest_score($configuration, $incomplete, 600); }
catch (DomainException) { $missingRejected = true; }
$check($missingRejected, 'missing required answers are rejected');
$check(in_array('baseline-1.0', study_interest_supported_algorithm_versions(), true), 'historical baseline scoring remains registered');

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
