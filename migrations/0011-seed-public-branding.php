<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $json = json_encode(['schema' => 1, 'brand_title' => '', 'logo_image_url' => ''], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $pdo->prepare('INSERT IGNORE INTO settings (`key`,`value`,`autoload`) VALUES (?,?,1)')
        ->execute(['study_interest_public_branding', $json]);
};
