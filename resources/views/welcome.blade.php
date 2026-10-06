<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#421d24">
    <title>Teami | مساحة العمل</title>
    @viteReactRefresh
    @vite('resources/js/app.jsx')
</head>
<body>
    <div id="teami-root"></div>
    <noscript>يرجى تفعيل JavaScript لفتح مساحة عمل Teami.</noscript>
</body>
</html>
