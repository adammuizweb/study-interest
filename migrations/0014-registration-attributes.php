<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $hasColumn = static function (string $column) use ($pdo): bool {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $statement->execute(['study_interest_sessions', $column]);
        return (int)$statement->fetchColumn() === 1;
    };
    if (!$hasColumn('registration_attributes_json')) {
        $pdo->exec('ALTER TABLE `study_interest_sessions` ADD COLUMN `registration_attributes_json` LONGTEXT NULL AFTER `contact_json`');
    }
    if (!$hasColumn('registration_attributes_hash')) {
        $pdo->exec('ALTER TABLE `study_interest_sessions` ADD COLUMN `registration_attributes_hash` CHAR(64) NULL AFTER `registration_attributes_json`');
    }
};
