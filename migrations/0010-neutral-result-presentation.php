<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $statement = $pdo->prepare('SELECT value FROM settings WHERE `key`=? LIMIT 1');
    $statement->execute(['study_interest_result_presentation']);
    $raw = $statement->fetchColumn();
    if (!is_string($raw) || $raw === '') return;

    $policy = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($policy)) return;
    $replacements = [
        'masked_title' => ['Hasilmu sedang ditinjau', 'Your result is being reviewed'],
        'masked_message' => ['Admin akan menghubungimu dan memberitahu hasil test kamu.', 'An authorized administrator will provide information about this result.'],
        'hidden_title' => ['Hasil belum dapat ditampilkan', 'This result is not available yet'],
        'hidden_message' => ['Pengelola belum membuka halaman hasil untuk peserta.', 'The result page has not been made available to participants.'],
        'section_mask_message' => ['Admin akan menghubungimu dan memberitahu hasil test kamu.', 'An authorized administrator will provide information about this part of the result.'],
    ];
    $changed = false;
    foreach ($replacements as $key => [$previous, $replacement]) {
        if (($policy[$key] ?? null) !== $previous) continue;
        $policy[$key] = $replacement;
        $changed = true;
    }
    if (!$changed) return;

    $json = json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare('UPDATE settings SET value=? WHERE `key`=?')->execute([$json, 'study_interest_result_presentation']);
};
