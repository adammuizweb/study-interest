<?php
declare(strict_types=1);

function study_interest_canonical_value(mixed $value): mixed
{
    if (!is_array($value)) return $value;
    if (array_is_list($value)) return array_map('study_interest_canonical_value', $value);
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = study_interest_canonical_value($item);
    return $value;
}

function study_interest_canonical_json(array $value): string
{
    return json_encode(study_interest_canonical_value($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

function study_interest_registration_attribute_definitions(array $configuration): array
{
    return (int)($configuration['schema_version'] ?? 1) >= 3 && is_array($configuration['registration_attributes'] ?? null)
        ? $configuration['registration_attributes']
        : [];
}

function study_interest_has_eligibility_policies(array $configuration): bool
{
    return (int)($configuration['schema_version'] ?? 1) >= 3
        && is_array($configuration['eligibility_policies'] ?? null)
        && $configuration['eligibility_policies'] !== [];
}

function study_interest_registration_attributes_from_input(array $input, array $configuration): array
{
    $values = [];
    foreach (study_interest_registration_attribute_definitions($configuration) as $code => $definition) {
        if (!is_array($definition)) continue;
        $present = array_key_exists($code, $input);
        if ($present && !is_scalar($input[$code])) throw new DomainException((string)($definition['label'] ?? $code) . ' is invalid.');
        $value = $present ? trim((string)$input[$code]) : '';
        if ($value === '') {
            if (($definition['required'] ?? false) === true) throw new DomainException('This field is required.');
            continue;
        }
        $valid = false;
        foreach (is_array($definition['options'] ?? null) ? $definition['options'] : [] as $option) {
            if (is_array($option) && hash_equals((string)($option['code'] ?? ''), $value)) { $valid = true; break; }
        }
        if (!$valid) throw new DomainException((string)($definition['label'] ?? $code) . ' is invalid.');
        $values[(string)$code] = $value;
    }
    ksort($values, SORT_STRING);
    return $values;
}

function study_interest_registration_attributes_json(array $attributes): string
{
    $attributes = array_map('strval', $attributes);
    ksort($attributes, SORT_STRING);
    return json_encode((object)$attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

function study_interest_registration_attributes_hash(array $attributes): string
{
    return hash('sha256', study_interest_registration_attributes_json($attributes));
}

function study_interest_registration_attributes_from_session(array $session, array $configuration): array
{
    if ((int)($configuration['schema_version'] ?? 1) < 3) return [];
    $json = (string)($session['registration_attributes_json'] ?? '');
    $hash = (string)($session['registration_attributes_hash'] ?? '');
    if ($json === '' || preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1) throw new RuntimeException('Session registration attributes are unavailable.');
    $attributes = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    if (!str_starts_with($json, '{') || !is_array($attributes)) throw new RuntimeException('Session registration attributes are invalid.');
    foreach ($attributes as $value) if (!is_string($value)) throw new RuntimeException('Session registration attributes are invalid.');
    ksort($attributes, SORT_STRING);
    $canonicalJson = study_interest_registration_attributes_json($attributes);
    if (!hash_equals($json, $canonicalJson) || !hash_equals($hash, hash('sha256', $canonicalJson))) throw new RuntimeException('Session registration attribute integrity check failed.');
    return $attributes;
}

function study_interest_policy_hash(array $policy): string
{
    unset($policy['policy_hash']);
    return hash('sha256', study_interest_canonical_json($policy));
}

function study_interest_policy_metadata(array $policy): array
{
    $metadata = study_interest_canonical_value($policy);
    $metadata['policy_hash'] = study_interest_policy_hash($policy);
    return $metadata;
}

function study_interest_application_surfaces(): array
{
    return [
        'DIRECT_RECOMMENDATION' => 'DIRECT_ENTRY',
        'PROFESSIONAL_PATHWAY' => 'PROFESSIONAL_PATHWAY',
    ];
}

function study_interest_policy_is_current(array $policy, array $configuration, string $asOf): bool
{
    if ((string)($policy['status'] ?? '') !== 'published'
        || trim((string)($policy['approved_by'] ?? '')) === ''
        || !study_interest_utc_timestamp_is_valid((string)($policy['approved_at'] ?? ''))
        || (string)($policy['target_intake'] ?? '') !== (string)($configuration['target_intake'] ?? '')) return false;
    $asOfIsDate = study_interest_date_is_valid($asOf);
    $asOfIsTimestamp = study_interest_utc_timestamp_is_valid($asOf);
    $asOfIsDatabaseTimestamp = preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\z/', $asOf) === 1;
    if (!$asOfIsDate && !$asOfIsTimestamp && !$asOfIsDatabaseTimestamp) return false;
    $asOfDate = substr($asOf, 0, 10);
    if (!study_interest_date_is_valid($asOfDate)) return false;
    $approvedAt = (string)$policy['approved_at'];
    if ($asOfIsTimestamp) {
        if ($approvedAt > $asOf) return false;
    } elseif ($asOfIsDatabaseTimestamp) {
        $asOfInstant = new DateTimeImmutable($asOf, new DateTimeZone('UTC'));
        $approvedInstant = new DateTimeImmutable($approvedAt, new DateTimeZone('UTC'));
        if ($approvedInstant > $asOfInstant) return false;
    } elseif (substr($approvedAt, 0, 10) > $asOfDate) {
        return false;
    }
    $from = (string)($policy['effective_from'] ?? '');
    $until = (string)($policy['effective_until'] ?? '');
    return study_interest_date_is_valid($from) && study_interest_date_is_valid($until)
        && $asOfDate >= $from && $asOfDate <= $until;
}

function study_interest_registration_value_is_disclosed(array $configuration, string $attribute, string $value): bool
{
    $definition = study_interest_registration_attribute_definitions($configuration)[$attribute] ?? null;
    if (!is_array($definition)) return false;
    foreach (is_array($definition['options'] ?? null) ? $definition['options'] : [] as $option) {
        if (is_array($option) && (string)($option['code'] ?? '') === $value) return empty($option['is_undisclosed']);
    }
    return false;
}

function study_interest_apply_eligibility(array $configuration, array $programs, array $registrationAttributes, ?string $asOf = null): array
{
    $asOf ??= gmdate('Y-m-d\TH:i:s\Z');
    $asOfDate = substr($asOf, 0, 10);
    if (!study_interest_date_is_valid($asOfDate)
        || (!study_interest_date_is_valid($asOf) && !study_interest_utc_timestamp_is_valid($asOf)
            && preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\z/', $asOf) !== 1)) {
        $asOf = gmdate('Y-m-d\TH:i:s\Z');
        $asOfDate = substr($asOf, 0, 10);
    }
    $policies = array_values(is_array($configuration['eligibility_policies'] ?? null) ? $configuration['eligibility_policies'] : []);
    $surfaces = study_interest_application_surfaces();
    $policyEvidence = [];
    foreach ($policies as $policy) if (is_array($policy)) $policyEvidence[] = study_interest_policy_metadata($policy);

    $preRanks = [];
    foreach (['DIRECT_ENTRY', 'PROFESSIONAL_PATHWAY'] as $type) {
        $rank = 0;
        foreach ($programs as $code => $program) if ((string)($program['recommendation_type'] ?? '') === $type) $preRanks[$code] = ++$rank;
    }
    foreach ($programs as $code => &$program) {
        $configuredProgram = is_array($configuration['programs'][$code] ?? null) ? $configuration['programs'][$code] : [];
        $program['pre_eligibility_rank'] = $preRanks[$code] ?? null;
        $program['intake_availability'] = (string)($configuredProgram['intake_availability'] ?? 'ACCEPTING');
        $program['eligibility_status'] = 'ELIGIBLE';
        $program['policy_codes'] = [];
        $program['policy_hashes'] = [];
        $program['reason_codes'] = [];
        $program['rank'] = null;
        $program['recommended'] = false;
        $surfaceRequiresReview = false;
        foreach ($policies as $policy) {
            if (!is_array($policy) || !in_array((string)$code, array_map('strval', is_array($policy['affected_program_codes'] ?? null) ? $policy['affected_program_codes'] : []), true)) continue;
            $policyCode = (string)($policy['code'] ?? 'UNAVAILABLE');
            $program['policy_codes'][] = $policyCode;
            $program['policy_hashes'][] = study_interest_policy_hash($policy);
            $reasonCodes = is_array($policy['reason_codes'] ?? null) ? $policy['reason_codes'] : [];
            $status = 'NEEDS_REVIEW';
            $surfaceType = $surfaces[(string)($policy['application_surface'] ?? '')] ?? null;
            if ($surfaceType !== (string)($configuredProgram['recommendation_type'] ?? '')) $surfaceRequiresReview = true;
            if ($surfaceType === (string)($configuredProgram['recommendation_type'] ?? '')
                && study_interest_policy_is_current($policy, $configuration, $asOf)) {
                $attribute = (string)($policy['attribute'] ?? '');
                $value = (string)($registrationAttributes[$attribute] ?? '');
                if ($value !== '' && study_interest_registration_value_is_disclosed($configuration, $attribute, $value)) {
                    if (is_array($policy['decision_map'] ?? null) && isset($policy['decision_map'][$value])) {
                        $status = (string)$policy['decision_map'][$value];
                    } elseif (is_array($policy['allowed_values'] ?? null)) {
                        $status = in_array($value, array_map('strval', $policy['allowed_values']), true) ? 'ELIGIBLE' : 'INELIGIBLE';
                    }
                }
            }
            if (!in_array($status, ['ELIGIBLE', 'INELIGIBLE', 'NEEDS_REVIEW'], true)) $status = 'NEEDS_REVIEW';
            $program['reason_codes'][] = (string)($reasonCodes[$status] ?? 'POLICY_REVIEW_REQUIRED');
            if ($status === 'INELIGIBLE' || ($status === 'NEEDS_REVIEW' && $program['eligibility_status'] === 'ELIGIBLE')) $program['eligibility_status'] = $status;
        }
        if ($surfaceRequiresReview) $program['eligibility_status'] = 'NEEDS_REVIEW';
        $program['policy_codes'] = array_values(array_unique($program['policy_codes']));
        $program['policy_hashes'] = array_values(array_unique($program['policy_hashes']));
        $program['reason_codes'] = array_values(array_unique($program['reason_codes']));
    }
    unset($program);

    $minimum = (float)($configuration['thresholds']['direct_recommendation_minimum'] ?? 0);
    $limit = max(1, (int)($configuration['thresholds']['direct_recommendation_limit'] ?? 3));
    $recommendations = [];
    foreach ($programs as &$program) {
        if ((string)$program['recommendation_type'] !== 'DIRECT_ENTRY' || (string)$program['eligibility_status'] !== 'ELIGIBLE'
            || (string)$program['intake_availability'] !== 'ACCEPTING' || (float)($program['_full_score'] ?? $program['score']) < $minimum || count($recommendations) >= $limit) continue;
        $program['rank'] = count($recommendations) + 1;
        $program['recommended'] = true;
        $recommendations[] = $program;
    }
    unset($program);
    $pathways = array_values(array_filter($programs, static fn(array $program): bool => (string)$program['recommendation_type'] === 'PROFESSIONAL_PATHWAY'
        && (string)$program['eligibility_status'] === 'ELIGIBLE' && (string)$program['intake_availability'] === 'ACCEPTING'
        && (float)($program['_full_score'] ?? $program['score']) >= (float)($configuration['thresholds']['professional_pathway_minimum'] ?? 0)));
    $recommendedCodes = array_column($recommendations, 'code');
    $interpretations = [];
    foreach ($configuration['interpretation_rules'] ?? [] as $rule) {
        $required = array_map('strval', is_array($rule['when_all_directions'] ?? null) ? $rule['when_all_directions'] : []);
        if ($required !== [] && array_diff($required, $recommendedCodes) === []) $interpretations[] = (string)($rule['message'] ?? '');
    }
    $interpretations = array_values(array_filter($interpretations, static fn(string $text): bool => $text !== ''));
    $gap = count($recommendations) >= 2 ? (float)($recommendations[0]['_full_score'] ?? $recommendations[0]['score']) - (float)($recommendations[1]['_full_score'] ?? $recommendations[1]['score']) : null;
    $clarity = 'OPEN';
    if ($gap !== null) {
        $thresholds = $configuration['thresholds']['profile_clarity'] ?? [];
        if ($gap < (float)($thresholds['practically_equal_below'] ?? 2)) $clarity = 'PRACTICALLY_EQUAL';
        elseif ($gap < (float)($thresholds['multidisciplinary_below'] ?? 7)) $clarity = 'MULTIDISCIPLINARY';
        elseif ($gap >= (float)($thresholds['very_clear_minimum'] ?? 15)) $clarity = 'VERY_CLEAR';
        elseif ($gap >= (float)($thresholds['fairly_clear_minimum'] ?? 7)) $clarity = 'FAIRLY_CLEAR';
    } elseif (count($recommendations) === 1) $clarity = 'SINGLE_DOMINANT';
    foreach ($programs as &$program) unset($program['_full_score']);
    unset($program);
    foreach ($recommendations as &$program) unset($program['_full_score']);
    unset($program);
    foreach ($pathways as &$program) unset($program['_full_score']);
    unset($program);
    return [
        'programs' => array_values($programs),
        'recommendations' => $recommendations,
        'professional_pathways' => $pathways,
        'interpretations' => $interpretations,
        'profile_clarity' => ['code' => $clarity, 'top_gap' => $gap !== null ? round($gap, 2) : null],
        'eligibility_evidence' => ['schema' => 1, 'target_intake' => (string)($configuration['target_intake'] ?? ''), 'evaluated_on' => $asOfDate, 'policies' => $policyEvidence],
    ];
}
