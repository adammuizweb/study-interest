<?php
declare(strict_types=1);
$documentTitle = trim((string)($page_title ?? 'Study Interest Explorer')) ?: 'Study Interest Explorer';
$metaTitle = trim((string)($publicBranding['brand_title'] ?? '')) ?: $documentTitle;
$metaDescription = trim((string)($page_description ?? 'Explore study interests through a private guided assessment.'));
$faviconUrl = isset($pdo) && $pdo instanceof PDO ? study_interest_site_favicon_url($pdo) : '/static/img/favicon/apple-touch-icon.png';
$canonicalUrl = isset($pdo) && $pdo instanceof PDO ? study_interest_absolute_public_url($pdo, '/study-interest/') : '/study-interest/';
$shareImageUrl = isset($pdo) && $pdo instanceof PDO ? study_interest_absolute_public_url($pdo, $faviconUrl) : $faviconUrl;
?>
<!doctype html>
<html lang="<?= study_interest_h($page_language ?? 'en') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#10162f">
    <title><?= study_interest_h($documentTitle) ?></title>
    <meta name="description" content="<?= study_interest_h($metaDescription) ?>">
    <link rel="canonical" href="<?= study_interest_h($canonicalUrl) ?>">
    <link rel="icon" href="<?= study_interest_h($faviconUrl) ?>">
    <link rel="apple-touch-icon" href="<?= study_interest_h($faviconUrl) ?>">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= study_interest_h($metaTitle) ?>">
    <meta property="og:description" content="<?= study_interest_h($metaDescription) ?>">
    <meta property="og:url" content="<?= study_interest_h($canonicalUrl) ?>">
    <meta property="og:image" content="<?= study_interest_h($shareImageUrl) ?>">
    <meta property="og:image:alt" content="<?= study_interest_h($metaTitle) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= study_interest_h($metaTitle) ?>">
    <meta name="twitter:description" content="<?= study_interest_h($metaDescription) ?>">
    <meta name="twitter:image" content="<?= study_interest_h($shareImageUrl) ?>">
    <link rel="stylesheet" href="/static/plugins/study-interest/frontend.css?v=0.9.9">
</head>
<body class="<?= study_interest_h($page_class ?? '') ?>">
<?= $content_html ?? '' ?>
<?php if (isset($page_data)): ?>
<script>window.StudyInterestData=<?= json_encode($page_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="/static/plugins/study-interest/frontend.js?v=0.9.9" defer></script>
<?php endif; ?>
</body>
</html>
