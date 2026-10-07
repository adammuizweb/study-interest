<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="<?= study_interest_h($page_language ?? 'en') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#10162f">
    <title><?= study_interest_h($page_title ?? 'Study Interest Explorer') ?></title>
    <link rel="stylesheet" href="/static/plugins/study-interest/frontend.css?v=0.9.0">
</head>
<body class="<?= study_interest_h($page_class ?? '') ?>">
<?= $content_html ?? '' ?>
<?php if (isset($page_data)): ?>
<script>window.StudyInterestData=<?= json_encode($page_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="/static/plugins/study-interest/frontend.js?v=0.9.0" defer></script>
<?php endif; ?>
</body>
</html>
