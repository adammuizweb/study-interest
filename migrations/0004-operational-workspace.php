<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $hasColumn = static function (string $table, string $column) use ($pdo): bool {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $statement->execute([$table, $column]);
        return (int)$statement->fetchColumn() === 1;
    };
    if (!$hasColumn('study_interest_sessions', 'result_revision')) {
        $pdo->exec('ALTER TABLE `study_interest_sessions` ADD COLUMN `result_revision` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `result_snapshot_json`');
    }
    if (!$hasColumn('study_interest_sessions', 'result_updated_by')) {
        $pdo->exec('ALTER TABLE `study_interest_sessions` ADD COLUMN `result_updated_by` INT UNSIGNED NULL AFTER `result_revision`');
    }
    if (!$hasColumn('study_interest_sessions', 'result_updated_at_utc')) {
        $pdo->exec('ALTER TABLE `study_interest_sessions` ADD COLUMN `result_updated_at_utc` DATETIME(6) NULL AFTER `result_updated_by`');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS `study_interest_result_revisions` (
      `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `session_id` INT UNSIGNED NOT NULL,
      `revision_number` INT UNSIGNED NOT NULL,
      `answers_json` LONGTEXT NOT NULL,
      `snapshot_json` LONGTEXT NOT NULL,
      `revision_hash` CHAR(64) NOT NULL,
      `reason` VARCHAR(500) NOT NULL,
      `created_by` INT UNSIGNED NULL,
      `created_at_utc` DATETIME(6) NOT NULL,
      UNIQUE KEY `uq_study_interest_result_revision` (`session_id`, `revision_number`),
      KEY `idx_study_interest_result_revision_created` (`created_at_utc`),
      FOREIGN KEY (`session_id`) REFERENCES `study_interest_sessions` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
