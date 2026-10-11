<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#3700B3">
    <title>TAIE | مساحة العمل</title>
    @viteReactRefresh
    @vite('resources/js/app.jsx')
    <link rel="icon" type="image/svg+xml" href="/brand/taie-icon.svg">
    <link rel="preload" href="/fonts/IBMPlexSansArabic-Regular.ttf" as="font" type="font/ttf" crossorigin>
    <link rel="preload" href="/fonts/IBMPlexSansArabic-SemiBold.ttf" as="font" type="font/ttf" crossorigin>
</head>
<body>
    <div id="teami-root"></div>
    <noscript>يرجى تفعيل JavaScript لفتح مساحة عمل Teami.</noscript>
</body>
</html>
