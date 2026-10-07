<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $published = $pdo->query("SELECT id FROM study_interest_test_versions WHERE status='published' ORDER BY published_at_utc DESC,id DESC")->fetchAll(PDO::FETCH_COLUMN);
    if ($published !== []) {
        $liveId = (int)$published[0];
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $retire = $pdo->prepare("UPDATE study_interest_test_versions SET status='retired',retired_at_utc=COALESCE(retired_at_utc,?),updated_at_utc=? WHERE status='published' AND id<>?");
        $retire->execute([$now, $now, $liveId]);
    }

    $hasColumn = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $hasColumn->execute(['study_interest_test_versions', 'public_slot']);
    if ((int)$hasColumn->fetchColumn() !== 1) {
        $pdo->exec("ALTER TABLE `study_interest_test_versions` ADD COLUMN `public_slot` TINYINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN `status`='published' THEN 1 ELSE NULL END) STORED AFTER `status`");
    } else {
        $column = $pdo->query("SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='study_interest_test_versions' AND COLUMN_NAME='public_slot' LIMIT 1")->fetchColumn();
        if (!is_string($column) || !str_contains(strtolower($column), 'generated')) {
            $pdo->exec("ALTER TABLE `study_interest_test_versions` MODIFY COLUMN `public_slot` TINYINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN `status`='published' THEN 1 ELSE NULL END) STORED");
        }
    }

    $hasIndex = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
    $hasIndex->execute(['study_interest_test_versions', 'uq_study_interest_public_slot']);
    if ((int)$hasIndex->fetchColumn() !== 1) {
        $pdo->exec('ALTER TABLE `study_interest_test_versions` ADD UNIQUE KEY `uq_study_interest_public_slot` (`public_slot`)');
    }
    $pdo->prepare('INSERT IGNORE INTO settings (`key`,`value`,`autoload`) VALUES (?,?,0)')->execute(['study_interest_publication_lock', '1']);
};
