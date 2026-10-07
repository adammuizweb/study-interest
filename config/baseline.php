<?php
declare(strict_types=1);

$dimensions = [
    'CON' => ['label' => 'Conceptual Reasoning', 'description' => 'Abstract questions, ideas, ethics, and frameworks.', 'display_order' => 1],
    'QUA' => ['label' => 'Quantitative Inquiry', 'description' => 'Mathematics, measurement, evidence, and formal models.', 'display_order' => 2],
    'LAN' => ['label' => 'Language & Interpretation', 'description' => 'Texts, meaning, argument, and cultural expression.', 'display_order' => 3],
    'CMP' => ['label' => 'Computational Systems', 'description' => 'Algorithms, software, data, and interconnected systems.', 'display_order' => 4],
    'SOC' => ['label' => 'Social & Economic Analysis', 'description' => 'People, institutions, incentives, and public choices.', 'display_order' => 5],
    'DES' => ['label' => 'Creative Design', 'description' => 'Visual thinking, making, experimentation, and communication.', 'display_order' => 6],
];

$sections = [
    'INTERESTS' => ['label' => 'Topics and activities', 'weight' => 0.65, 'question_count' => 12, 'display_order' => 1],
    'WORKSTYLE' => ['label' => 'Ways of working', 'weight' => 0.35, 'question_count' => 6, 'display_order' => 2],
];

$scale = [
    '1' => ['label' => 'Not at all like me', 'score' => 0],
    '2' => ['label' => 'A little like me', 'score' => 25],
    '3' => ['label' => 'Somewhat like me', 'score' => 50],
    '4' => ['label' => 'Mostly like me', 'score' => 75],
    '5' => ['label' => 'Very much like me', 'score' => 100],
];
$makeQuestion = static function (string $code, string $section, string $dimension, string $prompt) use ($scale): array {
    $options = [];
    foreach ($scale as $optionCode => $option) {
        $options[] = ['code' => $optionCode, 'label' => $option['label'], 'scores' => [$dimension => $option['score']]];
    }
    return ['code' => $code, 'section' => $section, 'prompt' => $prompt, 'type' => 'likert', 'dimension' => $dimension, 'required' => true, 'options' => $options];
};

$questionPrompts = [
    ['Q01', 'INTERESTS', 'CON', 'I enjoy examining difficult questions even when there is no single correct answer.'],
    ['Q02', 'INTERESTS', 'CON', 'I like comparing different explanations, principles, or ethical positions.'],
    ['Q03', 'INTERESTS', 'QUA', 'I enjoy using numbers or measurements to understand how something works.'],
    ['Q04', 'INTERESTS', 'QUA', 'I am curious about patterns that can be tested with evidence or mathematical models.'],
    ['Q05', 'INTERESTS', 'LAN', 'I enjoy interpreting stories, arguments, language, or historical texts.'],
    ['Q06', 'INTERESTS', 'LAN', 'I like expressing complex ideas clearly through writing or discussion.'],
    ['Q07', 'INTERESTS', 'CMP', 'I enjoy understanding how software, algorithms, or digital systems work.'],
    ['Q08', 'INTERESTS', 'CMP', 'I like breaking a complicated process into logical steps.'],
    ['Q09', 'INTERESTS', 'SOC', 'I am interested in how communities, institutions, and economies make decisions.'],
    ['Q10', 'INTERESTS', 'SOC', 'I enjoy analysing why people respond differently to rules, incentives, or social change.'],
    ['Q11', 'INTERESTS', 'DES', 'I enjoy turning an idea into a visual, physical, or interactive concept.'],
    ['Q12', 'INTERESTS', 'DES', 'I like experimenting with form, layout, materials, or user experience.'],
    ['Q13', 'WORKSTYLE', 'CON', 'I am comfortable spending time refining the assumptions behind a problem.'],
    ['Q14', 'WORKSTYLE', 'QUA', 'I prefer decisions that can be supported by calculations or measurable evidence.'],
    ['Q15', 'WORKSTYLE', 'LAN', 'I notice how wording and context can change the meaning of an idea.'],
    ['Q16', 'WORKSTYLE', 'CMP', 'I like building repeatable systems that make a task more reliable.'],
    ['Q17', 'WORKSTYLE', 'SOC', 'I consider how a decision affects different groups of people.'],
    ['Q18', 'WORKSTYLE', 'DES', 'I improve ideas by making prototypes and responding to feedback.'],
];
$questions = array_map(static fn(array $item): array => $makeQuestion(...$item), $questionPrompts);

return [
    'schema_version' => 2,
    'code' => 'neutral-study-interest',
    'title' => 'Study Interest Explorer',
    'description' => 'An editable exploration of academic interests and preferred ways of working.',
    'locale' => 'en',
    'version' => 'neutral-en-1.0',
    'algorithm_version' => 'weighted-choice-2.0',
    'dimensions' => $dimensions,
    'sections' => $sections,
    'questions' => $questions,
    'programs' => [
        'philosophy' => ['label' => 'Philosophy', 'recommendation_type' => 'DIRECT_ENTRY', 'is_active' => true, 'display_order' => 1, 'weights' => ['CON' => .30, 'QUA' => .05, 'LAN' => .25, 'CMP' => .05, 'SOC' => .25, 'DES' => .10]],
        'physics' => ['label' => 'Physics', 'recommendation_type' => 'DIRECT_ENTRY', 'is_active' => true, 'display_order' => 2, 'weights' => ['CON' => .35, 'QUA' => .35, 'LAN' => .02, 'CMP' => .15, 'SOC' => .03, 'DES' => .10]],
        'literature' => ['label' => 'Literature', 'recommendation_type' => 'DIRECT_ENTRY', 'is_active' => true, 'display_order' => 3, 'weights' => ['CON' => .15, 'QUA' => .02, 'LAN' => .40, 'CMP' => .03, 'SOC' => .20, 'DES' => .20]],
        'computing' => ['label' => 'Computing', 'recommendation_type' => 'DIRECT_ENTRY', 'is_active' => true, 'display_order' => 4, 'weights' => ['CON' => .15, 'QUA' => .25, 'LAN' => .02, 'CMP' => .40, 'SOC' => .03, 'DES' => .15]],
        'economics' => ['label' => 'Economics', 'recommendation_type' => 'DIRECT_ENTRY', 'is_active' => true, 'display_order' => 5, 'weights' => ['CON' => .15, 'QUA' => .30, 'LAN' => .10, 'CMP' => .10, 'SOC' => .30, 'DES' => .05]],
        'design' => ['label' => 'Design', 'recommendation_type' => 'DIRECT_ENTRY', 'is_active' => true, 'display_order' => 6, 'weights' => ['CON' => .15, 'QUA' => .10, 'LAN' => .10, 'CMP' => .10, 'SOC' => .15, 'DES' => .40]],
    ],
    'thresholds' => [
        'classifications' => [
            ['minimum' => 80, 'maximum' => 100, 'label' => 'Strong alignment'],
            ['minimum' => 65, 'maximum' => 79.99, 'label' => 'Clear alignment'],
            ['minimum' => 50, 'maximum' => 64.99, 'label' => 'Worth exploring'],
            ['minimum' => 0, 'maximum' => 49.99, 'label' => 'Emerging interest'],
        ],
        'direct_recommendation_minimum' => 50,
        'direct_recommendation_limit' => 3,
        'professional_pathway_minimum' => 70,
        'profile_clarity' => ['practically_equal_below' => 2, 'multidisciplinary_below' => 7, 'fairly_clear_minimum' => 7, 'very_clear_minimum' => 15],
        'response_quality' => ['fast_completion_below_seconds' => 120],
    ],
    'interpretation_rules' => [
        ['code' => 'ideas-and-language', 'when_all_directions' => ['philosophy', 'literature'], 'message' => 'Your responses connect conceptual inquiry with language and interpretation.'],
        ['code' => 'models-and-systems', 'when_all_directions' => ['physics', 'computing'], 'message' => 'Your responses connect quantitative models with computational systems.'],
    ],
    'result_text' => [
        'score_name' => 'Interest alignment index',
        'no_dominant_title' => 'Your interests remain open',
        'no_dominant_body' => 'No single direction stands out yet. Use the complete pattern to choose several areas for further exploration.',
        'multidisciplinary' => 'Several directions are close together and may be worth exploring side by side.',
        'very_clear' => 'Your leading direction is clearly differentiated in this response pattern.',
        'fairly_clear' => 'Your responses provide a useful starting direction for further exploration.',
        'open_profile' => 'Your profile remains open across several directions.',
        'professional_pathway' => 'Further pathways',
        'professional_pathway_body' => 'These pathways may require prior study or other entry requirements.',
        'disclaimer' => 'This result is an exploratory map of interests and preferred activities. It is not a diagnosis, admission prediction, or measure of academic ability.',
        'hero_kicker' => 'YOUR EXPLORATION RESULT',
        'hero_title' => 'See the interests that stand out in your responses.',
        'directions_kicker' => 'AREAS TO EXPLORE',
        'directions_title' => 'Closest study directions',
        'directions_body' => 'Use these directions to investigate subjects, activities, and possible learning pathways.',
        'dimensions_kicker' => 'COMPLETE MAP',
        'dimensions_title' => 'Interest dimensions',
        'dimensions_body' => 'Longer lines indicate dimensions that appeared more consistently in your choices.',
        'interpretation_kicker' => 'READING THE PATTERN',
        'interpretation_title' => 'When leading directions connect',
        'next_steps_kicker' => 'CONTINUE THE CONVERSATION',
        'next_steps_title' => 'Turn this result into further exploration.',
        'next_steps_body' => 'Compare course content, learning activities, and real experiences related to the directions that interest you.',
        'start_over_label' => 'Start a new exploration',
    ],
    'public_copy' => [
        'landing_kicker' => 'EXPLORE, DO NOT LABEL',
        'question_count_suffix' => 'questions',
        'duration_prefix' => 'about',
        'duration_suffix' => 'minutes',
        'feature_one_title' => 'No timer',
        'feature_one_body' => 'Answer at your own pace.',
        'feature_two_title' => 'Saved automatically',
        'feature_two_body' => 'Continue in the same browser.',
        'feature_three_title' => 'No right answer',
        'feature_three_body' => 'Choose what describes you most closely.',
        'progress_label' => 'Exploration progress',
        'back_label' => 'Back',
        'next_label' => 'Next',
        'complete_label' => 'View result',
        'ready_label' => 'Ready',
        'saving_label' => 'Saving...',
        'saved_label' => 'Saved',
        'save_error' => 'The answer could not be saved. Check your connection and try again.',
        'preparing_label' => 'Preparing your exploration...',
        'start_label' => 'Start exploration',
    ],
    'intake' => [
        'heading' => 'Before you begin',
        'introduction' => 'Choose what information you want to provide for this assessment.',
        'privacy_body' => 'Your information is stored with your assessment session and is available only to authorized administrators.',
        'privacy_url' => '',
        'assessment_consent_label' => 'I understand the purpose of this assessment and agree to submit my responses.',
        'contact_heading' => 'Optional follow-up contact',
        'contact_introduction' => 'Provide contact details only if you agree to be contacted about this result.',
        'contact_consent_label' => 'I agree that these contact details may be used for follow-up about this assessment.',
        'fields' => [
            'name' => ['enabled' => true, 'required' => true, 'label' => 'Name', 'placeholder' => 'Name used for this assessment', 'help' => ''],
            'school' => ['enabled' => true, 'required' => false, 'label' => 'Institution', 'placeholder' => 'School, university, company, or community', 'help' => ''],
            'class_level' => ['enabled' => true, 'required' => false, 'label' => 'Current stage', 'placeholder' => 'For example: Year 12 or career transition', 'help' => ''],
            'email' => ['enabled' => true, 'required' => false, 'label' => 'Email', 'placeholder' => 'name@example.com', 'help' => ''],
            'phone' => ['enabled' => false, 'required' => false, 'label' => 'Phone', 'placeholder' => 'A number that can be contacted', 'help' => ''],
        ],
    ],
];
