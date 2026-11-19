<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $hasColumn = static function (string $column) use ($pdo): bool {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $statement->execute(['study_interest_program_results', $column]);
        return (int)$statement->fetchColumn() === 1;
    };
    $columns = [
        'pre_eligibility_rank' => 'INT UNSIGNED NULL AFTER `rank_position`',
        'eligibility_status' => "VARCHAR(20) NOT NULL DEFAULT 'ELIGIBLE' AFTER `pre_eligibility_rank`",
        'intake_availability' => "VARCHAR(20) NOT NULL DEFAULT 'ACCEPTING' AFTER `eligibility_status`",
        'policy_codes_json' => 'TEXT NULL AFTER `intake_availability`',
        'policy_hashes_json' => 'TEXT NULL AFTER `policy_codes_json`',
        'reason_codes_json' => 'TEXT NULL AFTER `policy_hashes_json`',
    ];
    foreach ($columns as $column => $definition) {
        if (!$hasColumn($column)) $pdo->exec("ALTER TABLE `study_interest_program_results` ADD COLUMN `{$column}` {$definition}");
    }
};
