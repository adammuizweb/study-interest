<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $statement = $pdo->prepare('SELECT value FROM settings WHERE `key`=? LIMIT 1');
    $statement->execute(['study_interest_result_presentation']);
    $raw = $statement->fetchColumn();
    if (!is_string($raw) || $raw === '') return;

    $policy = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($policy)) return;

    $message = 'Admin akan menghubungimu dan memberitahu hasil test kamu.';
    $changed = false;
    if (($policy['masked_message'] ?? null) === 'Ringkasan hasil belum dibuka untuk peserta. Silakan hubungi pengelola atau konselor untuk informasi lebih lanjut.') {
        $policy['masked_message'] = $message;
        $changed = true;
    }
    if (($policy['section_mask_message'] ?? null) === 'Bagian ini hanya tersedia melalui pendampingan pengelola atau konselor.') {
        $policy['section_mask_message'] = $message;
        $changed = true;
    }
    if (!$changed) return;

    $json = json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare('UPDATE settings SET value=? WHERE `key`=?')->execute([$json, 'study_interest_result_presentation']);
};
