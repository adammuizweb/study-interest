<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.dashboard.access', false);

$manifest = plugin_manifest('study-interest');
$pluginVersion = is_array($manifest) ? (string)($manifest['version'] ?? '') : '';
$versions = $pdo->query("SELECT v.id,v.version_code,v.status,v.algorithm_version,v.expected_question_count,v.configuration_hash,
    v.created_at_utc,v.published_at_utc,v.retired_at_utc,t.code AS test_code,t.title AS test_title,
    (SELECT COUNT(*) FROM study_interest_sessions s WHERE s.version_id=v.id) AS session_count,
    (SELECT COUNT(*) FROM study_interest_sessions s WHERE s.version_id=v.id AND s.status='completed') AS completed_count
    FROM study_interest_test_versions v JOIN study_interest_tests t ON t.id=v.test_id ORDER BY v.id DESC")->fetchAll(PDO::FETCH_ASSOC);

$canManage = function_exists('user_can') && user_can($pdo, $studyInterestUserId, 'plugin.study-interest.config.manage');
$canPublish = function_exists('user_can') && user_can($pdo, $studyInterestUserId, 'plugin.study-interest.config.publish');
$canViewResults = function_exists('user_can') && user_can($pdo, $studyInterestUserId, 'plugin.study-interest.results.view');
$packagedConfiguration = study_interest_baseline_configuration();
$packagedVersion = (string)($packagedConfiguration['version'] ?? '');
$packagedHash = hash('sha256', study_interest_configuration_json($packagedConfiguration));
$packagedImported = false;
$publishedVersion = null;
$totalSessions = 0;
$totalCompleted = 0;
$validationById = [];
foreach ($versions as $row) {
    if ((string)$row['version_code'] === $packagedVersion) $packagedImported = true;
    if ((string)$row['status'] === 'published' && $publishedVersion === null) $publishedVersion = $row;
    $totalSessions += (int)$row['session_count'];
    $totalCompleted += (int)$row['completed_count'];
    if ((string)$row['status'] === 'draft' && $canPublish) $validationById[(int)$row['id']] = study_interest_publish_errors($pdo, (int)$row['id']);
}
$completionRate = $totalSessions > 0 ? ($totalCompleted / $totalSessions) * 100 : 0.0;
$publishedCompletionRate = $publishedVersion !== null && (int)$publishedVersion['session_count'] > 0
    ? ((int)$publishedVersion['completed_count'] / (int)$publishedVersion['session_count']) * 100 : 0.0;
$programCount = count($packagedConfiguration['programs'] ?? []);
?>
<div class="sie-admin">
  <header class="sie-admin-head">
    <div>
      <span class="sie-admin-eyebrow"><?= study_interest_h(__('Study Interest')) ?> &middot; v<?= study_interest_h($pluginVersion) ?></span>
      <h1 class="page-heading"><?= study_interest_h(__('Assessment workspace')) ?></h1>
      <p><?= study_interest_h(__('Manage immutable assessment versions, monitor participation, and review result quality from one place.')) ?></p>
    </div>
    <div class="sie-admin-actions">
      <?php if ($canViewResults): ?><a class="adam-button secondary" href="<?= study_interest_h($studyInterestBase . '/results') ?>"><?= study_interest_h(__('View analytics')) ?></a><?php endif; ?>
      <a class="adam-button" href="/study-interest/" target="_blank" rel="noopener"><?= study_interest_h(__('Open public assessment')) ?> <span aria-hidden="true">&nearr;</span></a>
    </div>
  </header>

  <section class="sie-admin-overview" aria-label="<?= study_interest_h(__('Assessment status')) ?>">
    <article class="sie-live-card <?= $publishedVersion === null ? 'is-empty' : '' ?>">
      <div class="sie-live-card__top">
        <span class="sie-live-indicator"><i></i><?= study_interest_h($publishedVersion === null ? __('No live version') : __('Live assessment')) ?></span>
        <?php if ($publishedVersion !== null): ?><code><?= study_interest_h(substr((string)$publishedVersion['configuration_hash'], 0, 12)) ?></code><?php endif; ?>
      </div>
      <?php if ($publishedVersion === null): ?>
        <div class="sie-live-empty"><span aria-hidden="true">+</span><h2><?= study_interest_h(__('Publish a version to begin')) ?></h2><p><?= study_interest_h(__('Participants cannot start until a reviewed draft has been published.')) ?></p></div>
      <?php else: ?>
        <div class="sie-live-title"><div><span><?= study_interest_h((string)$publishedVersion['version_code']) ?></span><h2><?= study_interest_h((string)$publishedVersion['test_title']) ?></h2></div><strong><?= (int)$publishedVersion['expected_question_count'] ?><small><?= study_interest_h(__('questions')) ?></small></strong></div>
        <div class="sie-live-meta"><span><?= study_interest_h(__('Algorithm')) ?><b><?= study_interest_h((string)$publishedVersion['algorithm_version']) ?></b></span><span><?= study_interest_h(__('Published')) ?><b><?= study_interest_h(study_interest_admin_datetime((string)$publishedVersion['published_at_utc'])) ?></b></span></div>
        <div class="sie-live-stats"><div><strong><?= (int)$publishedVersion['session_count'] ?></strong><span><?= study_interest_h(__('Sessions')) ?></span></div><div><strong><?= (int)$publishedVersion['completed_count'] ?></strong><span><?= study_interest_h(__('Completed')) ?></span></div><div><strong><?= number_format($publishedCompletionRate, 0) ?>%</strong><span><?= study_interest_h(__('Completion')) ?></span></div></div>
      <?php endif; ?>
    </article>

    <aside class="sie-package-card">
      <div class="sie-package-card__head"><span class="sie-package-icon" aria-hidden="true">P</span><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Packaged configuration')) ?></span><h2><?= study_interest_h($packagedVersion) ?></h2></div><span class="sie-state-pill <?= $packagedImported ? 'is-ready' : 'is-pending' ?>"><?= study_interest_h($packagedImported ? __('Imported') : __('Ready to import')) ?></span></div>
      <p><?= study_interest_h(__('A reviewed starter configuration bundled with this plugin release. Importing creates an immutable draft.')) ?></p>
      <dl class="sie-package-facts"><div><dt><?= study_interest_h(__('Dimensions')) ?></dt><dd><?= count($packagedConfiguration['dimensions'] ?? []) ?></dd></div><div><dt><?= study_interest_h(__('Sections')) ?></dt><dd><?= count($packagedConfiguration['sections'] ?? []) ?></dd></div><div><dt><?= study_interest_h(__('Questions')) ?></dt><dd><?= count($packagedConfiguration['questions'] ?? []) ?></dd></div><div><dt><?= study_interest_h(__('Programs')) ?></dt><dd><?= $programCount ?></dd></div></dl>
      <div class="sie-package-hash"><span><?= study_interest_h(__('Package fingerprint')) ?></span><code title="<?= study_interest_h($packagedHash) ?>"><?= study_interest_h(substr($packagedHash, 0, 20)) ?>&hellip;</code></div>
      <?php if (!$packagedImported && $canManage): ?>
        <form method="post" action="<?= study_interest_h($studyInterestActionBase . '/import-baseline.php') ?>" data-sie-confirm data-sie-confirm-title="<?= study_interest_h(__('Import packaged assessment?')) ?>" data-sie-confirm-message="<?= study_interest_h(__('This creates a new draft. It will not become public until separately reviewed and published.')) ?>" data-sie-confirm-text="<?= study_interest_h(__('Import draft')) ?>">
          <input type="hidden" name="csrf_token" value="<?= study_interest_h(csrf_token()) ?>">
          <button class="adam-button" type="submit"><?= study_interest_h(__('Import as draft')) ?></button>
        </form>
      <?php elseif ($packagedImported): ?>
        <div class="sie-package-ready"><i aria-hidden="true"></i><?= study_interest_h(__('This packaged version is already in the version history.')) ?></div>
      <?php endif; ?>
    </aside>
  </section>

  <section class="sie-admin-summary" aria-label="<?= study_interest_h(__('All-version summary')) ?>">
    <div><span><?= study_interest_h(__('Versions')) ?></span><strong><?= count($versions) ?></strong><small><?= study_interest_h(__('immutable records')) ?></small></div>
    <div><span><?= study_interest_h(__('Sessions')) ?></span><strong><?= $totalSessions ?></strong><small><?= study_interest_h(__('all versions')) ?></small></div>
    <div><span><?= study_interest_h(__('Completed')) ?></span><strong><?= $totalCompleted ?></strong><small><?= study_interest_h(__('result snapshots')) ?></small></div>
    <div><span><?= study_interest_h(__('Completion rate')) ?></span><strong><?= number_format($completionRate, 0) ?>%</strong><small><?= study_interest_h(__('started sessions')) ?></small></div>
  </section>

  <section class="sie-admin-section">
    <div class="sie-admin-section__head"><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Version history')) ?></span><h2><?= study_interest_h(__('Assessment releases')) ?></h2></div><p><?= study_interest_h(__('Published versions never change. Publish a new draft when the assessment configuration needs to evolve.')) ?></p></div>
    <div class="sie-admin-table">
      <table>
        <thead><tr><th><?= study_interest_h(__('Version')) ?></th><th><?= study_interest_h(__('Status')) ?></th><th><?= study_interest_h(__('Published')) ?></th><th><?= study_interest_h(__('Questions')) ?></th><th><?= study_interest_h(__('Sessions')) ?></th><th><?= study_interest_h(__('Completed')) ?></th><th><?= study_interest_h(__('Action')) ?></th></tr></thead>
        <tbody>
          <?php if ($versions === []): ?>
            <tr><td colspan="7"><div class="sie-table-empty"><span aria-hidden="true">+</span><strong><?= study_interest_h(__('No assessment versions yet')) ?></strong><p><?= study_interest_h(__('Import the packaged configuration to create the first draft.')) ?></p></div></td></tr>
          <?php endif; ?>
          <?php foreach ($versions as $row): ?>
            <?php $status = (string)$row['status']; $errors = $validationById[(int)$row['id']] ?? []; ?>
            <tr>
              <td data-label="<?= study_interest_h(__('Version')) ?>"><strong><?= study_interest_h((string)$row['version_code']) ?></strong><small><?= study_interest_h((string)$row['algorithm_version']) ?> &middot; <code><?= study_interest_h(substr((string)$row['configuration_hash'], 0, 10)) ?>&hellip;</code></small></td>
              <td data-label="<?= study_interest_h(__('Status')) ?>"><span class="sie-status is-<?= study_interest_h($status) ?>"><i></i><?= study_interest_h(__(ucfirst($status))) ?></span></td>
              <td data-label="<?= study_interest_h(__('Published')) ?>"><?= $status === 'published' || $status === 'retired' ? study_interest_h(study_interest_admin_datetime((string)$row['published_at_utc'])) : '<span class="sie-muted">' . study_interest_h(__('Not yet')) . '</span>' ?></td>
              <td data-label="<?= study_interest_h(__('Questions')) ?>"><?= (int)$row['expected_question_count'] ?></td>
              <td data-label="<?= study_interest_h(__('Sessions')) ?>"><?= (int)$row['session_count'] ?></td>
              <td data-label="<?= study_interest_h(__('Completed')) ?>"><?= (int)$row['completed_count'] ?></td>
              <td data-label="<?= study_interest_h(__('Action')) ?>">
                <?php if ($status === 'draft' && $canPublish && $errors === []): ?>
                  <form method="post" action="<?= study_interest_h($studyInterestActionBase . '/publish.php') ?>" data-sie-confirm data-sie-confirm-title="<?= study_interest_h(__('Publish this assessment version?')) ?>" data-sie-confirm-message="<?= study_interest_h($publishedVersion === null ? __('This version will become available to new participants.') : sprintf(__('This version will go live and %s will be retired for new sessions.'), (string)$publishedVersion['version_code'])) ?>" data-sie-confirm-text="<?= study_interest_h(__('Publish version')) ?>">
                    <input type="hidden" name="csrf_token" value="<?= study_interest_h(csrf_token()) ?>"><input type="hidden" name="version_id" value="<?= (int)$row['id'] ?>">
                    <button class="sie-row-action" type="submit"><?= study_interest_h(__('Publish')) ?> <span aria-hidden="true">&rarr;</span></button>
                  </form>
                <?php elseif ($status === 'draft' && $canPublish && $errors !== []): ?>
                  <span class="sie-validation" title="<?= study_interest_h(implode(' ', $errors)) ?>"><?= count($errors) ?> <?= study_interest_h(__('validation issue(s)')) ?></span>
                <?php else: ?><span class="sie-muted">&mdash;</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>
