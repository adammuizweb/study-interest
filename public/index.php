<?php
declare(strict_types=1);

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");
$page_title = 'Study Interest Explorer';
if (!function_exists('stateless_csrf_token')) {
    http_response_code(503);
    $content_html = '<main class="sie-shell"><div class="sie-card"><h1>Service unavailable</h1><p>The required security service is unavailable.</p></div></main>';
    require __DIR__ . '/layout.php';
    return;
}
$csrf = stateless_csrf_token();
$publicId = trim((string)($_GET['session'] ?? ''));
$session = $publicId !== '' ? study_interest_session($pdo, $publicId) : null;

if ($publicId !== '' && $session === null) {
    http_response_code(404);
    $content_html = '<main class="sie-shell"><div class="sie-card"><h1>Session unavailable</h1><p>This private session is unavailable in the current browser.</p><a class="sie-button" href="/study-interest/">Start a new exploration</a></div></main>';
} elseif ($session !== null && (string)$session['status'] === 'completed') {
    $snapshot = json_decode((string)$session['result_snapshot_json'], true);
    if (!is_array($snapshot)) throw new RuntimeException('Result snapshot is unavailable.');
    $recommendations = '';
    foreach ($snapshot['recommendations'] ?? [] as $program) {
        $recommendations .= '<article class="sie-result-card"><span>#' . (int)$program['rank'] . '</span><h3>' . study_interest_h($program['label']) . '</h3>'
            . '<strong>' . number_format((float)$program['score'], 2) . '/100</strong><p>' . study_interest_h($program['classification']) . '</p></article>';
    }
    if ($recommendations === '') $recommendations = '<div class="sie-card"><h2>' . study_interest_h($snapshot['result_text']['no_dominant_title'] ?? 'Your interests remain open') . '</h2><p>' . study_interest_h($snapshot['result_text']['no_dominant_body'] ?? '') . '</p></div>';
    $bars = '';
    foreach ($snapshot['dimensions'] ?? [] as $dimension) {
        $score = max(0, min(100, (float)$dimension['score']));
        $bars .= '<div class="sie-bar"><div><span>' . study_interest_h($dimension['label']) . '</span><strong>' . number_format($score, 1) . '</strong></div><i><b style="width:' . $score . '%"></b></i></div>';
    }
    $pathways = '';
    foreach ($snapshot['professional_pathways'] ?? [] as $pathway) $pathways .= '<div class="sie-card sie-pathway"><p class="sie-eyebrow">' . study_interest_h($snapshot['result_text']['professional_pathway'] ?? 'Professional pathway') . '</p><h2>' . study_interest_h($pathway['label']) . '</h2><p>' . study_interest_h($snapshot['result_text']['professional_pathway_body'] ?? '') . '</p></div>';
    $clarity = (string)($snapshot['profile_clarity']['code'] ?? 'OPEN');
    $clarityText = match ($clarity) {
        'PRACTICALLY_EQUAL', 'MULTIDISCIPLINARY' => (string)($snapshot['result_text']['multidisciplinary'] ?? 'Several fields are worth exploring.'),
        'VERY_CLEAR' => 'Your leading interest direction is relatively clear.',
        'FAIRLY_CLEAR', 'SINGLE_DOMINANT' => 'You have a useful starting direction for further exploration.',
        default => 'Your interest profile remains open to several directions.',
    };
    $dominantLabels = array_map(static fn(array $dimension): string => (string)$dimension['label'], array_slice($snapshot['dimensions'] ?? [], 0, 3));
    $interpretations = '';
    foreach ($snapshot['interpretations'] ?? [] as $interpretation) $interpretations .= '<p>' . study_interest_h($interpretation) . '</p>';
    if ($interpretations !== '') $interpretations = '<div class="sie-card"><h2>What the close results mean</h2>' . $interpretations . '</div>';
    $content_html = '<main class="sie-shell sie-results"><p class="sie-eyebrow">YOUR STUDY INTEREST PROFILE</p><h1>' . study_interest_h(implode(' · ', $dominantLabels)) . '</h1><p class="sie-lead">' . study_interest_h($clarityText) . '</p>'
        . '<section class="sie-results-grid">' . $recommendations . '</section><section class="sie-card"><h2>Your interest dimensions</h2>' . $bars . '</section>'
        . $interpretations . $pathways . '<div class="sie-card"><h2>Use this as a conversation starter</h2><p>Explore the recommended fields further and discuss the result with a parent, teacher, mentor, or education counsellor.</p></div>'
        . '<div class="sie-disclaimer">' . study_interest_h($snapshot['result_text']['disclaimer'] ?? '') . '</div></main>';
} elseif ($session !== null) {
    $questions = study_interest_public_questions($pdo, (int)$session['version_id']);
    $answers = study_interest_answer_map($pdo, (int)$session['id']);
    $clientQuestions = [];
    foreach ($questions as $question) {
        $clientQuestions[] = [
            'id' => (int)$question['id'], 'code' => (string)$question['question_code'], 'title' => (string)($question['title'] ?? ''),
            'prompt' => (string)$question['prompt'], 'section' => (string)$question['section_code'], 'section_label' => (string)$question['section_label'],
            'options' => array_map(static fn(array $option): array => ['id' => (int)$option['id'], 'code' => (string)$option['option_code'], 'label' => (string)$option['label']], $question['options']),
        ];
    }
    $clientAnswers = [];
    foreach ($clientQuestions as $question) {
        $selectedCode = $answers[$question['code']] ?? null;
        foreach ($question['options'] as $option) if ($option['code'] === $selectedCode) $clientAnswers[(string)$question['id']] = $option['id'];
    }
    $content_html = '<main class="sie-assessment"><header><a href="/study-interest/" class="sie-brand">Study Interest Explorer</a><div><span id="sie-progress-label"></span><progress id="sie-progress" max="' . count($clientQuestions) . '" value="1"></progress></div></header>'
        . '<section id="sie-question" class="sie-question" aria-live="polite"></section><footer><button id="sie-back" class="sie-button secondary" type="button">Back</button><span id="sie-save-state" role="status">Saved</span><button id="sie-next" class="sie-button" type="button">Next</button></footer></main>';
    $page_data = ['mode' => 'assessment', 'csrf' => $csrf, 'session' => $publicId, 'questions' => $clientQuestions, 'answers' => $clientAnswers,
        'answerUrl' => '/study-interest/api/answer', 'completeUrl' => '/study-interest/api/complete'];
} else {
    $content_html = '<main class="sie-shell"><p class="sie-eyebrow">EXPLORATION TOOL</p><h1>Find the fields that feel like you.</h1>'
        . '<p class="sie-lead">Explore your study interests and get a thoughtful starting point for discussion. There are no right or wrong answers, no timer, and no proctoring.</p>'
        . '<div class="sie-card"><h2>Before you begin</h2><p>Set aside around 10 to 15 minutes. Your answers are saved automatically in this browser.</p>'
        . '<div class="sie-fields"><label>Full name<input id="sie-name" type="text" minlength="2" maxlength="120" autocomplete="name" required></label>'
        . '<label>School or organization<input id="sie-school" type="text" minlength="2" maxlength="191" autocomplete="organization" required></label>'
        . '<label>Class or current level<input id="sie-class" type="text" maxlength="40" placeholder="Example: Grade 12" required></label>'
        . '<label>Email (optional)<input id="sie-email" type="email" maxlength="191" autocomplete="email"></label>'
        . '<label>Phone or WhatsApp (optional)<input id="sie-phone" type="tel" maxlength="40" autocomplete="tel"></label></div>'
        . '<label class="sie-consent"><input id="sie-consent" type="checkbox"> I understand that this is an exploration tool, not a diagnosis, aptitude test, or assessment of academic ability.</label>'
        . '<label class="sie-consent"><input id="sie-contact-consent" type="checkbox"> I agree that the optional contact details above may be used to follow up about this result.</label>'
        . '<button id="sie-start" class="sie-button" type="button" disabled>Start exploration</button><p id="sie-message" role="status"></p></div></main>';
    $page_data = ['mode' => 'landing', 'csrf' => $csrf, 'startUrl' => '/study-interest/api/start'];
}
require __DIR__ . '/layout.php';
