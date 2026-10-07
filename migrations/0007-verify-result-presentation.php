<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $statement = $pdo->prepare('SELECT value,autoload FROM settings WHERE `key`=? LIMIT 1');
    $statement->execute(['study_interest_result_presentation']);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || (int)$row['autoload'] !== 1) throw new RuntimeException('Result presentation policy is unavailable.');
    $policy = json_decode((string)$row['value'], true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($policy) || (int)($policy['schema'] ?? 0) !== 1 || !in_array((string)($policy['mode'] ?? ''), ['full', 'masked', 'hidden'], true)) {
        throw new RuntimeException('Result presentation policy is invalid.');
    }
    $sections = is_array($policy['sections'] ?? null) ? $policy['sections'] : [];
    foreach (['hero', 'directions', 'dimensions', 'interpretation', 'pathways', 'next_steps', 'disclaimer'] as $section) {
        if (!in_array((string)($sections[$section] ?? ''), ['show', 'mask', 'hide'], true)) throw new RuntimeException("Result presentation section is invalid: {$section}");
    }
};
