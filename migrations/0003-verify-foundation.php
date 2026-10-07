<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $required = [
        'study_interest_tests', 'study_interest_test_versions', 'study_interest_dimensions',
        'study_interest_sections', 'study_interest_questions', 'study_interest_options',
        'study_interest_option_scores', 'study_interest_programs', 'study_interest_program_weights',
        'study_interest_sessions', 'study_interest_answers', 'study_interest_dimension_results',
        'study_interest_program_results', 'study_interest_result_flags', 'study_interest_rate_limits',
        'study_interest_audit_log',
    ];
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    foreach ($required as $table) {
        $statement->execute([$table]);
        if ((int)$statement->fetchColumn() !== 1) {
            throw new RuntimeException('Study Interest foundation table is missing: ' . $table);
        }
    }
};
