<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $logoUrlIsSafe = static function (string $url): bool {
        if ($url === '') return true;
        if (strlen($url) > 2048 || preg_match('//u', $url) !== 1
            || preg_match('/[\x00-\x20\x7F]/', $url) === 1 || str_contains($url, '\\')) return false;
        $decoded = rawurldecode($url);
        if (preg_match('/[\x00-\x20\x7F]/', $decoded) === 1 || str_contains($decoded, '\\')) return false;
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) return false;
        foreach (explode('/', rawurldecode((string)($parts['path'] ?? ''))) as $segment) {
            if ($segment === '.' || $segment === '..') return false;
        }
        if (str_starts_with($url, '/')) {
            $path = strtolower((string)($parts['path'] ?? ''));
            return !str_starts_with($url, '//') && $path !== '' && $path[0] === '/'
                && $path !== '/private' && !str_starts_with($path, '/private/');
        }
        return strtolower((string)($parts['scheme'] ?? '')) === 'https' && trim((string)($parts['host'] ?? '')) !== '';
    };
    $statement = $pdo->prepare('SELECT value,autoload FROM settings WHERE `key`=? LIMIT 1');
    $statement->execute(['study_interest_public_branding']);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['autoload'] !== 1) throw new RuntimeException('Study Interest public branding setting is unavailable.');
    $value = json_decode((string)$row['value'], true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($value) || array_keys($value) !== ['schema', 'brand_title', 'logo_image_url']
        || (int)$value['schema'] !== 1 || !is_string($value['brand_title']) || !is_string($value['logo_image_url'])
        || mb_strlen($value['brand_title']) > 120 || preg_match('/[\x00-\x1F\x7F]/', $value['brand_title']) === 1
        || !$logoUrlIsSafe($value['logo_image_url'])) {
        throw new RuntimeException('Study Interest public branding setting is invalid.');
    }
};
