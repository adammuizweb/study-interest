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
        'masked_title' => ['Your result is being reviewed', 'An administrator will share your result'],
        'masked_message' => ['An authorized administrator will provide information about this result.', 'An authorized administrator will provide your result directly.'],
        'hidden_title' => ['This result is not available yet', 'Thank you for participating'],
        'hidden_message' => ['The result page has not been made available to participants.', 'Your responses have been submitted.'],
        'section_mask_message' => ['An authorized administrator will provide information about this part of the result.', 'An authorized administrator will provide this part of your result directly.'],
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
