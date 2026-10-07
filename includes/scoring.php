<?php
declare(strict_types=1);

function study_interest_classification(float $score, array $configuration): string
{
    $score = max(0.0, min(100.0, $score));
    foreach ($configuration['thresholds']['classifications'] ?? [] as $range) {
        if ($score >= (float)$range['minimum']) return (string)$range['label'];
    }
    return 'Unclassified';
}

function study_interest_score(array $configuration, array $answers, int $durationSeconds): array
{
    $errors = study_interest_configuration_errors($configuration);
    if ($errors !== []) throw new DomainException(implode(' ', $errors));
    $questions = [];
    foreach ($configuration['questions'] as $question) $questions[(string)$question['code']] = $question;
    $missing = array_values(array_diff(array_keys($questions), array_keys($answers)));
    if ($missing !== []) throw new DomainException('All required questions must be answered.');

    $actual = $minimum = $maximum = [];
    $rawA = [];
    foreach ($questions as $code => $question) {
        $selectedCode = (string)($answers[$code] ?? '');
        $selected = null;
        foreach ($question['options'] as $option) if ((string)$option['code'] === $selectedCode) { $selected = $option; break; }
        if (!is_array($selected)) throw new DomainException("Answer for {$code} is invalid.");
        $section = (string)$question['section'];
        if ($section === 'A') $rawA[] = ['dimension' => (string)$question['dimension'], 'reverse' => !empty($question['is_reverse']), 'value' => (int)$selectedCode];
        foreach ($configuration['dimensions'] as $dimension => $_dimension) {
            $values = array_map(static fn(array $option): float => (float)($option['scores'][$dimension] ?? 0), $question['options']);
            $actual[$section][$dimension] = ($actual[$section][$dimension] ?? 0.0) + (float)($selected['scores'][$dimension] ?? 0);
            $minimum[$section][$dimension] = ($minimum[$section][$dimension] ?? 0.0) + min($values);
            $maximum[$section][$dimension] = ($maximum[$section][$dimension] ?? 0.0) + max($values);
        }
    }

    $sectionScores = [];
    $dimensions = [];
    foreach ($configuration['dimensions'] as $dimension => $meta) {
        $final = 0.0;
        foreach ($configuration['sections'] as $section => $sectionMeta) {
            $range = ($maximum[$section][$dimension] ?? 0) - ($minimum[$section][$dimension] ?? 0);
            $score = $range > 0 ? ((($actual[$section][$dimension] ?? 0) - ($minimum[$section][$dimension] ?? 0)) / $range) * 100 : 0.0;
            $score = max(0.0, min(100.0, $score));
            $sectionScores[$section][$dimension] = round($score, 3);
            $final += $score * (float)$sectionMeta['weight'];
        }
        $dimensions[$dimension] = ['code' => $dimension, 'label' => (string)$meta['label'], 'section_scores' => array_column($sectionScores, $dimension), 'score' => round($final, 3)];
        $dimensions[$dimension]['section_scores'] = [];
        foreach (array_keys($configuration['sections']) as $section) $dimensions[$dimension]['section_scores'][$section] = $sectionScores[$section][$dimension];
    }
    uasort($dimensions, static fn(array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['code'], $b['code']));

    $programs = [];
    foreach ($configuration['programs'] as $code => $program) {
        $score = 0.0;
        foreach ($program['weights'] as $dimension => $weight) $score += $dimensions[$dimension]['score'] * (float)$weight;
        $programs[$code] = [
            'code' => $code, 'label' => (string)$program['label'], 'recommendation_type' => (string)$program['recommendation_type'],
            'score' => round($score, 3), 'classification' => study_interest_classification($score, $configuration),
            'rank' => null, 'recommended' => false,
        ];
    }
    uasort($programs, static fn(array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['code'], $b['code']));
    $minimumRecommendation = (float)$configuration['thresholds']['direct_recommendation_minimum'];
    $limit = (int)$configuration['thresholds']['direct_recommendation_limit'];
    $recommendations = [];
    foreach ($programs as $code => &$program) {
        if ($program['recommendation_type'] !== 'DIRECT_ENTRY' || $program['score'] < $minimumRecommendation || count($recommendations) >= $limit) continue;
        $program['rank'] = count($recommendations) + 1;
        $program['recommended'] = true;
        $recommendations[] = $program;
    }
    unset($program);
    $pathways = array_values(array_filter($programs, static fn(array $program): bool => $program['recommendation_type'] === 'PROFESSIONAL_PATHWAY'
        && $program['score'] >= (float)$configuration['thresholds']['professional_pathway_minimum']));

    $recommendedCodes = array_column($recommendations, 'code');
    $interpretations = [];
    if (in_array('biomedical_science', $recommendedCodes, true) && in_array('biotechnology', $recommendedCodes, true)) {
        $interpretations[] = (string)($configuration['result_text']['biomedical_biotechnology_overlap'] ?? '');
    }
    if (in_array('bachelor_midwifery', $recommendedCodes, true) && in_array('diploma_midwifery', $recommendedCodes, true)) {
        $interpretations[] = (string)($configuration['result_text']['midwifery_overlap'] ?? '');
    }
    $interpretations = array_values(array_filter($interpretations, static fn(string $text): bool => $text !== ''));

    $gap = count($recommendations) >= 2 ? $recommendations[0]['score'] - $recommendations[1]['score'] : null;
    $clarity = 'OPEN';
    if ($gap !== null) {
        $clarityThresholds = $configuration['thresholds']['profile_clarity'];
        if ($gap < (float)$clarityThresholds['practically_equal_below']) $clarity = 'PRACTICALLY_EQUAL';
        elseif ($gap < (float)$clarityThresholds['multidisciplinary_below']) $clarity = 'MULTIDISCIPLINARY';
        elseif ($gap >= (float)$clarityThresholds['very_clear_minimum']) $clarity = 'VERY_CLEAR';
        else $clarity = 'FAIRLY_CLEAR';
    } elseif (count($recommendations) === 1) $clarity = 'SINGLE_DOMINANT';

    $flags = [];
    $frequency = array_count_values(array_map(static fn(array $item): int => $item['value'], $rawA));
    $largestFrequency = $frequency === [] ? 0 : max($frequency);
    if ($largestFrequency >= (int)$configuration['thresholds']['response_quality']['straightlining_same_answers_minimum']) {
        $flags[] = ['code' => 'STRAIGHTLINING', 'severity' => 'REVIEW', 'context' => ['same_answer_count' => $largestFrequency]];
    }
    if ($durationSeconds < (int)$configuration['thresholds']['response_quality']['fast_completion_below_seconds']) {
        $flags[] = ['code' => 'FAST_COMPLETION', 'severity' => 'INFO', 'context' => ['duration_seconds' => $durationSeconds]];
    }
    $byDimension = [];
    foreach ($rawA as $item) $byDimension[$item['dimension']][$item['reverse'] ? 'reverse' : 'normal'][] = $item['value'];
    $inconsistent = [];
    foreach ($byDimension as $dimension => $sets) {
        if (empty($sets['normal']) || empty($sets['reverse'])) continue;
        $normal = array_sum($sets['normal']) / count($sets['normal']);
        $adjustedReverse = array_sum(array_map(static fn(int $value): int => 6 - $value, $sets['reverse'])) / count($sets['reverse']);
        if (abs($normal - $adjustedReverse) >= 3.0) $inconsistent[] = $dimension;
    }
    if (count($inconsistent) >= (int)$configuration['thresholds']['response_quality']['low_response_consistency_dimensions_minimum']) {
        $flags[] = ['code' => 'LOW_RESPONSE_CONSISTENCY', 'severity' => 'REVIEW', 'context' => ['dimensions' => $inconsistent]];
    }

    return [
        'dimensions' => array_values($dimensions), 'programs' => array_values($programs), 'recommendations' => $recommendations,
        'professional_pathways' => $pathways, 'interpretations' => $interpretations,
        'profile_clarity' => ['code' => $clarity, 'top_gap' => $gap !== null ? round($gap, 3) : null],
        'flags' => $flags, 'duration_seconds' => max(0, $durationSeconds),
    ];
}
