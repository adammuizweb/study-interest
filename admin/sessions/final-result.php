<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_init.php';
adiwira_require_permission($pdo, 'plugin.study-interest.results.view', false);
$publicId = trim((string)($_GET['s'] ?? ''));
if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $publicId) !== 1) {
    http_response_code(404);
    echo study_interest_h(__('Session not found.'));
    return;
}
$canViewContacts = study_interest_admin_can('plugin.study-interest.contacts.view');
$contactColumn = $canViewContacts ? 's.contact_json' : 'NULL AS contact_json';
$statement = $pdo->prepare("SELECT s.id,s.public_id,s.status,s.result_snapshot_json,{$contactColumn},v.version_code,v.configuration_hash,v.configuration_json
    FROM study_interest_sessions s JOIN study_interest_test_versions v ON v.id=s.version_id WHERE s.public_id=? LIMIT 1");
$statement->execute([$publicId]);
$session = $statement->fetch(PDO::FETCH_ASSOC);
if (!is_array($session) || (string)$session['status'] !== 'completed') {
    http_response_code(404);
    echo study_interest_h(__('Completed session not found.'));
    return;
}
$configuration = study_interest_configuration_from_session($session);
$snapshot = json_decode((string)($session['result_snapshot_json'] ?? ''), true);
if (!is_array($snapshot)) {
    http_response_code(500);
    echo study_interest_h(__('The final result is unavailable.'));
    return;
}
$dimensionStatement = $pdo->prepare('SELECT * FROM study_interest_dimension_results WHERE session_id=? ORDER BY final_score DESC,dimension_code');
$dimensionStatement->execute([(int)$session['id']]);
$dimensions = $dimensionStatement->fetchAll(PDO::FETCH_ASSOC);
$programStatement = $pdo->prepare("SELECT * FROM study_interest_program_results WHERE session_id=? ORDER BY is_recommended DESC,CASE eligibility_status WHEN 'ELIGIBLE' THEN 0 WHEN 'NEEDS_REVIEW' THEN 1 ELSE 2 END,CASE recommendation_type WHEN 'DIRECT_ENTRY' THEN 0 ELSE 1 END,rank_position IS NULL,rank_position,pre_eligibility_rank,score DESC");
$programStatement->execute([(int)$session['id']]);
$programs = $programStatement->fetchAll(PDO::FETCH_ASSOC);
$dimensionLabels = [];
foreach ($configuration['dimensions'] ?? [] as $code => $meta) $dimensionLabels[(string)$code] = (string)($meta['label'] ?? $code);
$programLabels = [];
foreach ($configuration['programs'] ?? [] as $code => $meta) $programLabels[(string)$code] = (string)($meta['label'] ?? $code);
$schemaVersion = (int)($configuration['schema_version'] ?? 1);
$participantRecommendations = $schemaVersion >= 3
    ? (is_array($snapshot['recommendations'] ?? null) ? $snapshot['recommendations'] : [])
    : study_interest_historical_leading_directions(
        is_array($snapshot['programs'] ?? null) ? $snapshot['programs'] : [],
        max(1, min(50, (int)($configuration['thresholds']['direct_recommendation_limit'] ?? 3)))
    );
$directRecommendationMinimum = max(0.0, min(100.0, (float)($configuration['thresholds']['direct_recommendation_minimum'] ?? 0)));
$participantRecommendations = array_values(array_filter(
    $participantRecommendations,
    static fn(mixed $program): bool => is_array($program) && is_numeric($program['score'] ?? null)
        && (float)$program['score'] >= $directRecommendationMinimum
));
$participantDimensions = array_slice(is_array($snapshot['dimensions'] ?? null) ? $snapshot['dimensions'] : [], 0, 3);
$participantPathways = is_array($snapshot['professional_pathways'] ?? null) ? $snapshot['professional_pathways'] : [];
$resultText = is_array($snapshot['result_text'] ?? null) ? $snapshot['result_text'] : [];
$programRankLabels = [];
if (function_exists('apply_filters')) {
    $filteredRankLabels = apply_filters('study_interest_admin_program_rank_labels', [], [
        'surface' => 'session_final_result',
        'version_code' => (string)$session['version_code'],
        'schema_version' => $schemaVersion,
    ], $pdo);
    if (is_array($filteredRankLabels)) {
        foreach ($filteredRankLabels as $rank => $label) {
            $rank = filter_var($rank, FILTER_VALIDATE_INT);
            $label = is_string($label) ? trim($label) : '';
            if ($rank !== false && $rank >= 1 && $rank <= 50 && $label !== '' && mb_strlen($label) <= 80) $programRankLabels[(int)$rank] = $label;
        }
    }
}
$contact = $canViewContacts ? study_interest_admin_contact((string)($session['contact_json'] ?? '')) : [];
?>
<div class="sie-admin sie-workspace-page sie-final-result-page">
  <?php study_interest_admin_nav('sessions'); ?>
  <a class="sie-back-link" href="<?= study_interest_h(study_interest_admin_url('sessions/view', ['s' => $publicId])) ?>"><span aria-hidden="true">&larr;</span> <?= study_interest_h(__('Session detail')) ?></a>
  <header class="sie-admin-head"><div><span class="sie-admin-eyebrow"><?= study_interest_h((string)$session['version_code']) ?></span><h1 class="page-heading"><?= study_interest_h(__('Final Result')) ?></h1><p><?= study_interest_h($canViewContacts ? (string)($contact['name'] ?? __('Participant session')) : __('Participant session')) ?> · <code><?= study_interest_h($publicId) ?></code></p></div></header>
  <section class="sie-final-result">
    <section class="sie-participant-result" aria-labelledby="sie-final-result-title">
      <div class="sie-participant-result__head"><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Participant-facing result')) ?></span><h2 id="sie-final-result-title"><?= study_interest_h((string)($resultText['hero_title'] ?? __('Interest exploration result'))) ?></h2><p><?= study_interest_h((string)($resultText['directions_body'] ?? __('Only eligible directions that meet the published recommendation rules are shown here.'))) ?></p></div><strong><?= study_interest_h(__('Final result')) ?></strong></div>
      <?php if ($participantDimensions !== []): ?><div class="sie-participant-signals" aria-label="<?= study_interest_h(__('Leading interest dimensions')) ?>"><?php foreach ($participantDimensions as $index => $dimension): ?><article><span>0<?= (int)$index + 1 ?></span><div><small><?= study_interest_h(__('Interest dimension')) ?></small><b><?= study_interest_h((string)($dimension['label'] ?? '')) ?></b></div><strong><?= number_format((float)($dimension['score'] ?? 0), 1) ?></strong></article><?php endforeach; ?></div><?php endif; ?>
      <?php if ($participantRecommendations === []): ?><article class="sie-participant-open"><span aria-hidden="true">+</span><div><small><?= study_interest_h(__('Open profile')) ?></small><h3><?= study_interest_h((string)($resultText['no_dominant_title'] ?? __('The interest profile remains open'))) ?></h3><p><?= study_interest_h((string)($resultText['no_dominant_body'] ?? __('Use the interest dimensions to guide further exploration.'))) ?></p></div></article><?php else: ?><div class="sie-participant-programs"><?php foreach ($participantRecommendations as $index => $program): ?><article><span>0<?= (int)($program['rank'] ?? $index + 1) ?></span><h3><?= study_interest_h((string)($program['label'] ?? '')) ?></h3><div><strong><?= number_format((float)($program['score'] ?? 0), 1) ?></strong><small>/100</small></div><p><?= study_interest_h((string)($program['classification'] ?? '')) ?></p></article><?php endforeach; ?></div><?php endif; ?>
      <?php if ($participantPathways !== []): ?><div class="sie-participant-pathways"><?php foreach ($participantPathways as $pathway): ?><article><small><?= study_interest_h((string)($resultText['professional_pathway'] ?? __('Further pathway'))) ?></small><b><?= study_interest_h((string)($pathway['label'] ?? '')) ?></b><p><?= study_interest_h((string)($resultText['professional_pathway_body'] ?? '')) ?></p></article><?php endforeach; ?></div><?php endif; ?>
      <footer><p><?= study_interest_h((string)($resultText['disclaimer'] ?? __('This result is an interest map, not an admission decision or measure of academic ability.'))) ?></p></footer>
    </section>
    <section class="sie-config-panel sie-dimension-evidence"><div class="sie-panel-title"><div><span class="sie-admin-eyebrow"><?= study_interest_h(__('Result profile')) ?></span><h2><?= study_interest_h(__('Dimension scores')) ?></h2></div><p><?= study_interest_h(__('These dimensions support the participant conversation even when no program crosses the recommendation threshold.')) ?></p></div><div class="sie-bar-list is-dimensions"><?php foreach ($dimensions as $index => $row): ?><div class="sie-analytics-bar"><div><span><i><?= str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) ?></i><b><?= study_interest_h($dimensionLabels[(string)$row['dimension_code']] ?? (string)$row['dimension_code']) ?></b></span><strong><?= number_format((float)$row['final_score'], 1) ?></strong></div><div class="sie-bar-track"><i style="width:<?= study_interest_h(max(0, min(100, (float)$row['final_score']))) ?>%"></i></div></div><?php endforeach; ?></div></section>
    <details class="sie-internal-evidence"><summary><div><h2><?= study_interest_h(__('All raw study-direction scores')) ?></h2><p><?= study_interest_h(__('Complete study-direction scores, including directions excluded by eligibility or recommendation thresholds.')) ?></p></div><span><b><?= study_interest_h(__('Show all scores')) ?></b><i><?= study_interest_h(__('Hide all scores')) ?></i></span></summary><div class="sie-program-results"><?php foreach ($programs as $index => $program): ?><?php $displayRank = $index + 1; $eligibilityStatus = (string)$program['eligibility_status']; $isRecommended = (int)$program['is_recommended'] === 1; $rankLabel = $programRankLabels[$displayRank] ?? (string)$program['classification']; $evidenceLabel = $isRecommended ? __('Shown to participant') : ($eligibilityStatus === 'INELIGIBLE' ? __('Not shown: ineligible') : ($eligibilityStatus === 'NEEDS_REVIEW' ? __('Not shown: eligibility review required') : ((string)$program['recommendation_type'] === 'PROFESSIONAL_PATHWAY' ? __('Not shown: pathway rule not met') : __('Not shown: below recommendation threshold')))); ?><div class="<?= $isRecommended ? 'is-recommended' : ($eligibilityStatus !== 'ELIGIBLE' ? 'is-excluded' : '') ?>"><span><?= $displayRank ?></span><p><b><?= study_interest_h($programLabels[(string)$program['program_code']] ?? (string)$program['program_code']) ?></b><small class="sie-program-rank-label"><?= study_interest_h($rankLabel) ?></small><small><?= study_interest_h((string)$program['classification']) ?> &middot; <?= study_interest_h($evidenceLabel) ?></small></p><strong><?= number_format((float)$program['score'], 1) ?></strong></div><?php endforeach; ?></div></details>
  </section>
</div>
