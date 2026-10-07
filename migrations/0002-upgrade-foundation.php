<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $columnExists = static function (string $table, string $column) use ($pdo): bool {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $statement->execute([$table, $column]);
        return (int)$statement->fetchColumn() === 1;
    };
    $legacy = $columnExists('study_interest_test_versions', 'created_at')
        || $columnExists('study_interest_sessions', 'started_at')
        || $columnExists('study_interest_answers', 'answered_at');
    if (!$legacy) return;

    foreach (['study_interest_test_versions', 'study_interest_sessions', 'study_interest_answers'] as $table) {
        $count = $pdo->query("SELECT COUNT(*) FROM `{$table}`");
        if ($count !== false && (int)$count->fetchColumn() > 0) {
            throw new RuntimeException('Legacy Study Interest runtime data requires an explicit reviewed migration before this version can be activated.');
        }
    }

    $changes = [
        ['study_interest_tests', 'created_at', 'created_at_utc', 'DATETIME(6) NOT NULL'],
        ['study_interest_tests', 'updated_at', 'updated_at_utc', 'DATETIME(6) NOT NULL'],
        ['study_interest_test_versions', 'published_at', 'published_at_utc', 'DATETIME(6) NULL'],
        ['study_interest_test_versions', 'retired_at', 'retired_at_utc', 'DATETIME(6) NULL'],
        ['study_interest_test_versions', 'created_at', 'created_at_utc', 'DATETIME(6) NOT NULL'],
        ['study_interest_test_versions', 'updated_at', 'updated_at_utc', 'DATETIME(6) NOT NULL'],
        ['study_interest_sessions', 'consent_at', 'consent_at_utc', 'DATETIME(6) NULL'],
        ['study_interest_sessions', 'contact_consent_at', 'contact_consent_at_utc', 'DATETIME(6) NULL'],
        ['study_interest_sessions', 'started_at', 'started_at_utc', 'DATETIME(6) NOT NULL'],
        ['study_interest_sessions', 'completed_at', 'completed_at_utc', 'DATETIME(6) NULL'],
        ['study_interest_sessions', 'last_activity_at', 'last_activity_at_utc', 'DATETIME(6) NOT NULL'],
        ['study_interest_answers', 'answered_at', 'answered_at_utc', 'DATETIME(6) NOT NULL'],
        ['study_interest_answers', 'updated_at', 'updated_at_utc', 'DATETIME(6) NOT NULL'],
        ['study_interest_audit_log', 'created_at', 'created_at_utc', 'DATETIME(6) NOT NULL'],
    ];
    foreach ($changes as [$table, $old, $new, $definition]) {
        if ($columnExists($table, $old) && !$columnExists($table, $new)) {
            $pdo->exec("ALTER TABLE `{$table}` CHANGE COLUMN `{$old}` `{$new}` {$definition}");
        }
    }
    if (!$columnExists('study_interest_test_versions', 'expected_question_count')) {
        $pdo->exec('ALTER TABLE study_interest_test_versions ADD COLUMN expected_question_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER algorithm_version');
    }
    if (!$columnExists('study_interest_test_versions', 'configuration_hash')) {
        $pdo->exec("ALTER TABLE study_interest_test_versions ADD COLUMN configuration_hash CHAR(64) NOT NULL AFTER expected_question_count");
    }
    if (!$columnExists('study_interest_test_versions', 'configuration_json')) {
        $pdo->exec('ALTER TABLE study_interest_test_versions ADD COLUMN configuration_json LONGTEXT NOT NULL AFTER configuration_hash');
    }
    if (!$columnExists('study_interest_questions', 'title')) {
        $pdo->exec('ALTER TABLE study_interest_questions ADD COLUMN title VARCHAR(255) NULL AFTER question_code');
    }
};
