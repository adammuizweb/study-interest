<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $table = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $table->execute(['study_interest_result_revisions']);
    if ((int)$table->fetchColumn() !== 1) throw new RuntimeException('Result revision table is unavailable.');
    $columns = $pdo->query('SHOW COLUMNS FROM study_interest_sessions')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['result_revision', 'result_updated_by', 'result_updated_at_utc'] as $column) {
        if (!in_array($column, $columns, true)) throw new RuntimeException("Session result revision column is unavailable: {$column}");
    }
    $revisionColumns = $pdo->query('SHOW COLUMNS FROM study_interest_result_revisions')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['session_id', 'revision_number', 'answers_json', 'snapshot_json', 'revision_hash', 'reason', 'created_by', 'created_at_utc'] as $column) {
        if (!in_array($column, $revisionColumns, true)) throw new RuntimeException("Result revision column is unavailable: {$column}");
    }
    $index = $pdo->query("SHOW INDEX FROM study_interest_result_revisions WHERE Key_name='uq_study_interest_result_revision'")->fetchAll(PDO::FETCH_ASSOC);
    if (count($index) !== 2) throw new RuntimeException('Result revision uniqueness is unavailable.');
};
