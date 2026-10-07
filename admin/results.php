<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.results.view', false);
study_interest_admin_begin_snapshot($pdo);

$versions = $pdo->query("SELECT v.id,v.version_code,v.status,v.configuration_hash,v.configuration_json,v.published_at_utc,t.title AS test_title
    FROM study_interest_test_versions v JOIN study_interest_tests t ON t.id=v.test_id ORDER BY (v.status='published') DESC,v.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$requestedVersionId = filter_var($_GET['version_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$selectedVersion = null;
foreach ($versions as $candidate) {
    if ($requestedVersionId !== false && $requestedVersionId !== null && (int)$candidate['id'] === (int)$requestedVersionId) { $selectedVersion = $candidate; break; }
}
if ($selectedVersion === null && $versions !== []) $selectedVersion = $versions[0];
$versionId = $selectedVersion !== null ? (int)$selectedVersion['id'] : 0;

$summary = ['total' => 0, 'completed' => 0, 'in_progress' => 0];
$programs = $dimensions = $flags = $recent = [];
$programLabels = $dimensionLabels = [];
$selectedTitle = $selectedVersion !== null ? (string)$selectedVersion['test_title'] : __('No assessment version');
if ($versionId > 0) {
    $configuration = json_decode((string)$selectedVersion['configuration_json'], true);
    if (is_array($configuration)) {
        if (!hash_equals((string)$selectedVersion['configuration_hash'], hash('sha256', study_interest_configuration_json($configuration)))) throw new RuntimeException('Assessment configuration integrity check failed.');
        foreach ($configuration['programs'] ?? [] as $code => $program) $programLabels[(string)$code] = (string)($program['label'] ?? $code);
        foreach ($configuration['dimensions'] ?? [] as $code => $dimension) $dimensionLabels[(string)$code] = (string)($dimension['label'] ?? $code);
        if (trim((string)($configuration['title'] ?? '')) !== '') $selectedTitle = trim((string)$configuration['title']);
    }
    $summaryStatement = $pdo->prepare("SELECT COUNT(*) AS total,SUM(status='completed') AS completed,SUM(status='started') AS in_progress FROM study_interest_sessions WHERE version_id=?");
    $summaryStatement->execute([$versionId]);
    $summary = $summaryStatement->fetch(PDO::FETCH_ASSOC) ?: $summary;
    $programStatement = $pdo->prepare("SELECT p.program_code,COUNT(*) AS total,ROUND(AVG(p.score),2) AS average_score
        FROM study_interest_program_results p JOIN study_interest_sessions s ON s.id=p.session_id
        WHERE s.version_id=? AND p.recommendation_type='DIRECT_ENTRY'
        AND p.id=(SELECT p2.id FROM study_interest_program_results p2 WHERE p2.session_id=p.session_id AND p2.recommendation_type='DIRECT_ENTRY' ORDER BY p2.score DESC,p2.program_code ASC LIMIT 1)
        GROUP BY p.program_code ORDER BY total DESC,p.program_code ASC");
    $programStatement->execute([$versionId]);
    $programs = $programStatement->fetchAll(PDO::FETCH_ASSOC);
    $dimensionStatement = $pdo->prepare("SELECT d.dimension_code,ROUND(AVG(d.final_score),2) AS average_score
        FROM study_interest_dimension_results d JOIN study_interest_sessions s ON s.id=d.session_id
        WHERE s.version_id=? GROUP BY d.dimension_code ORDER BY average_score DESC,d.dimension_code ASC");
    $dimensionStatement->execute([$versionId]);
    $dimensions = $dimensionStatement->fetchAll(PDO::FETCH_ASSOC);
    $flagStatement = $pdo->prepare("SELECT f.flag_code,f.severity,COUNT(*) AS total FROM study_interest_result_flags f
        JOIN study_interest_sessions s ON s.id=f.session_id WHERE s.version_id=? GROUP BY f.flag_code,f.severity ORDER BY total DESC,f.flag_code ASC");
    $flagStatement->execute([$versionId]);
    $flags = $flagStatement->fetchAll(PDO::FETCH_ASSOC);
    $recentStatement = $pdo->prepare("SELECT s.public_id,s.completed_at_utc,
        (SELECT p.program_code FROM study_interest_program_results p WHERE p.session_id=s.id AND p.recommendation_type='DIRECT_ENTRY' ORDER BY p.score DESC,p.program_code ASC LIMIT 1) AS top_program,
        (SELECT p.score FROM study_interest_program_results p WHERE p.session_id=s.id AND p.recommendation_type='DIRECT_ENTRY' ORDER BY p.score DESC,p.program_code ASC LIMIT 1) AS top_score,
        (SELECT COUNT(*) FROM study_interest_result_flags f WHERE f.session_id=s.id) AS flag_count
        FROM study_interest_sessions s WHERE s.version_id=? AND s.status='completed' ORDER BY s.completed_at_utc DESC,s.id DESC LIMIT 10");
    $recentStatement->execute([$versionId]);
    $recent = $recentStatement->fetchAll(PDO::FETCH_ASSOC);
}

$total = (int)($summary['total'] ?? 0);
$completed = (int)($summary['completed'] ?? 0);
$inProgress = (int)($summary['in_progress'] ?? 0);
$completionRate = $total > 0 ? ($completed / $total) * 100 : 0.0;
$flagTotal = array_sum(array_map(static fn(array $row): int => (int)$row['total'], $flags));
$canExport = function_exists('user_can') && user_can($pdo, $studyInterestUserId, 'plugin.study-interest.results.export');
$flagLabels = ['STRAIGHTLINING' => __('Repeated answer pattern'), 'FAST_COMPLETION' => __('Fast completion'), 'LOW_RESPONSE_CONSISTENCY' => __('Low response consistency')];
$pdo->commit();
?>
<div class="sie-admin sie-analytics">
  <?php study_interest_admin_nav('analytics'); ?>
  <header class="sie-admin-head">
    <div>
      <a class="sie-back-link" href="<?= study_interest_h($studyInterestBase) ?>"><span aria-hidden="true">&larr;</span> <?= study_interest_h(__('Assessment workspace')) ?></a>
      <span class="sie-admin-eyebrow"><?= study_interest_h(__('Study Interest')) ?> &middot; <?= study_interest_h(__('Analytics')) ?></span>
      <h1 class="page-heading"><?= study_interest_h(__('Cohort analytics')) ?></h1>
      <p><?= study_interest_h(__('Understand participation and shared interest patterns across one immutable assessment version.')) ?></p>
    </div>
    <?php if ($canExport && $selectedVersion !== null): ?>
      <form method="post" action="<?= study_interest_h($studyInterestActionBase . '/export.php') ?>">
        <input type="hidden" name="csrf_token" value="<?= study_interest_h(csrf_token()) ?>"><input type="hidden" name="version_id" value="<?= $versionId ?>">
        <button class="adam-button secondary" type="submit"><?= study_interest_h(__('Export CSV')) ?> <span aria-hidden="true">&darr;</span></button>
      </form>
    <?php endif; ?>
  </header>

  <section class="sie-guidance-card"><strong><?= study_interest_h(__('How to use this report')) ?></strong><p><?= study_interest_h(__('Use cohort analytics to spot leading study directions, compare average interest dimensions, and identify response patterns that may need review. It summarizes groups and should not be used to diagnose or rank individual ability.')) ?></p></section>

  <section class="sie-filter-bar">
    <div><span><?= study_interest_h(__('Active report')) ?></span><strong><?= study_interest_h($selectedTitle) ?></strong></div>
    <form method="get" action="<?= study_interest_h(rtrim((string)ADMIN_BASE_PATH, '/') . '/') ?>">
      <input type="hidden" name="page" value="admin/tools/study-interest/results">
      <label><span><?= study_interest_h(__('Assessment version')) ?></span><select class="adam-input" name="version_id" data-sie-autosubmit><?php foreach ($versions as $version): ?><option value="<?= (int)$version['id'] ?>" <?= (int)$version['id'] === $versionId ? 'selected' : '' ?>><?= study_interest_h((string)$version['version_code']) ?> &middot; <?= study_interest_h(__(ucfirst((string)$version['status']))) ?></option><?php endforeach; ?></select></label>
    </form>
  </section>

  <section class="sie-metric-grid" aria-label="<?= study_interest_h(__('Participation summary')) ?>">
    <article><span><?= study_interest_h(__('Sessions')) ?></span><strong><?= $total ?></strong><small><?= study_interest_h(__('started on this version')) ?></small></article>
    <article><span><?= study_interest_h(__('Completed')) ?></span><strong><?= $completed ?></strong><small><?= study_interest_h(__('immutable result snapshots')) ?></small></article>
    <article><span><?= study_interest_h(__('Completion rate')) ?></span><strong><?= number_format($completionRate, 0) ?>%</strong><small><?= study_interest_h(__('of all sessions')) ?></small></article>
    <article><span><?= study_interest_h(__('Quality signals')) ?></span><strong><?= $flagTotal ?></strong><small><?= study_interest_h(__('review flags, not diagnoses')) ?></small></article>
  </section>

  <?php if ($selectedVersion === null): ?>
    <div class="sie-analytics-empty"><span aria-hidden="true">+</span><h2><?= study_interest_h(__('No version is available for reporting')) ?></h2><p><?= study_interest_h(__('Import and publish an assessment version before collecting results.')) ?></p></div>
  <?php else: ?>
    <div class="sie-analytics-grid">
      <section class="sie-analytics-panel">
        <div class="sie-panel-title"><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Recommendation pattern')) ?></span><h2><?= study_interest_h(__('Leading study directions')) ?></h2></div><strong><?= $completed ?></strong></div>
        <div class="sie-bar-list">
          <?php if ($programs === []): ?><p class="sie-panel-empty"><?= study_interest_h(__('No completed result has a leading recommendation yet.')) ?></p><?php endif; ?>
          <?php foreach ($programs as $index => $row): ?><?php $share = $completed > 0 ? min(100, ((int)$row['total'] / $completed) * 100) : 0; ?>
            <div class="sie-analytics-bar"><div><span><i><?= str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) ?></i><b><?= study_interest_h($programLabels[(string)$row['program_code']] ?? (string)$row['program_code']) ?></b></span><strong><?= (int)$row['total'] ?></strong></div><div class="sie-bar-track"><i style="width:<?= study_interest_h($share) ?>%"></i></div><small><?= number_format((float)$row['average_score'], 1) ?> <?= study_interest_h(__('average interest index')) ?></small></div>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="sie-analytics-panel">
        <div class="sie-panel-title"><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Dimension profile')) ?></span><h2><?= study_interest_h(__('Average interest signals')) ?></h2></div><strong>100</strong></div>
        <div class="sie-bar-list is-dimensions">
          <?php if ($dimensions === []): ?><p class="sie-panel-empty"><?= study_interest_h(__('No completed dimensions are available.')) ?></p><?php endif; ?>
          <?php foreach ($dimensions as $index => $row): ?><?php $score = max(0, min(100, (float)$row['average_score'])); ?>
            <div class="sie-analytics-bar"><div><span><i><?= str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) ?></i><b><?= study_interest_h($dimensionLabels[(string)$row['dimension_code']] ?? (string)$row['dimension_code']) ?></b></span><strong><?= number_format($score, 1) ?></strong></div><div class="sie-bar-track"><i style="width:<?= study_interest_h($score) ?>%"></i></div></div>
          <?php endforeach; ?>
        </div>
      </section>
    </div>

    <section class="sie-admin-section sie-quality-section">
      <div class="sie-admin-section__head"><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Response quality')) ?></span><h2><?= study_interest_h(__('Signals that may need review')) ?></h2></div><p><?= study_interest_h(__('Flags describe answer patterns or timing. They do not invalidate a participant or determine ability.')) ?></p></div>
      <div class="sie-quality-grid">
        <?php if ($flags === []): ?><div class="sie-quality-empty"><i aria-hidden="true"></i><strong><?= study_interest_h(__('No quality signals recorded')) ?></strong><span><?= study_interest_h(__('Completed responses currently have no review flags.')) ?></span></div><?php endif; ?>
        <?php foreach ($flags as $row): ?><article><span class="sie-severity is-<?= strtolower(study_interest_h((string)$row['severity'])) ?>"><?= study_interest_h((string)$row['severity']) ?></span><strong><?= (int)$row['total'] ?></strong><h3><?= study_interest_h($flagLabels[(string)$row['flag_code']] ?? (string)$row['flag_code']) ?></h3></article><?php endforeach; ?>
      </div>
    </section>

    <section class="sie-admin-section">
      <div class="sie-admin-section__head"><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Recent activity')) ?></span><h2><?= study_interest_h(__('Latest completed sessions')) ?></h2></div><p><?= study_interest_h(sprintf(__('%d session(s) are still in progress on this version.'), $inProgress)) ?></p></div>
      <div class="sie-admin-table">
        <table><thead><tr><th><?= study_interest_h(__('Session')) ?></th><th><?= study_interest_h(__('Completed')) ?></th><th><?= study_interest_h(__('Leading direction')) ?></th><th><?= study_interest_h(__('Interest index')) ?></th><th><?= study_interest_h(__('Flags')) ?></th><th><?= study_interest_h(__('Action')) ?></th></tr></thead><tbody>
          <?php if ($recent === []): ?><tr><td colspan="6"><div class="sie-table-empty"><strong><?= study_interest_h(__('No completed sessions yet')) ?></strong><p><?= study_interest_h(__('Completed results will appear here without exposing participant contact details.')) ?></p></div></td></tr><?php endif; ?>
          <?php foreach ($recent as $row): ?><tr><td data-label="<?= study_interest_h(__('Session')) ?>"><code><?= study_interest_h(substr((string)$row['public_id'], 0, 8)) ?>&hellip;</code></td><td data-label="<?= study_interest_h(__('Completed')) ?>"><?= study_interest_h(study_interest_admin_datetime((string)$row['completed_at_utc'])) ?></td><td data-label="<?= study_interest_h(__('Leading direction')) ?>"><?= study_interest_h($row['top_program'] !== null ? ($programLabels[(string)$row['top_program']] ?? (string)$row['top_program']) : __('Open profile')) ?></td><td data-label="<?= study_interest_h(__('Interest index')) ?>"><?= $row['top_score'] !== null ? number_format((float)$row['top_score'], 1) : '&mdash;' ?></td><td data-label="<?= study_interest_h(__('Flags')) ?>"><span class="sie-flag-count <?= (int)$row['flag_count'] > 0 ? 'has-flags' : '' ?>"><?= (int)$row['flag_count'] ?></span></td><td data-label="<?= study_interest_h(__('Action')) ?>"><?php if (study_interest_admin_can('plugin.study-interest.sessions.view')): ?><a class="sie-row-action" href="<?= study_interest_h(study_interest_admin_url('sessions/view', ['s' => (string)$row['public_id']])) ?>"><?= study_interest_h(__('Details')) ?> <span aria-hidden="true">&rarr;</span></a><?php else: ?>&mdash;<?php endif; ?></td></tr><?php endforeach; ?>
        </tbody></table>
      </div>
    </section>
  <?php endif; ?>
</div>
