<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" data-inertia="description" content="{{ request()->routeIs('home') ? 'От замысла до занятия: собирайте последовательности асан, ведите практику с таймером, делайте личные разборы и печатайте готовые листы в RibkaYoga.' : 'Ваша библиотека асан и конструктор практик. Собирайте, сохраняйте и печатайте последовательности.' }}">
    @if(request()->routeIs('home'))
    <title data-inertia>Йога, собранная по-вашему · RibkaYoga</title>
    <meta property="og:title" data-inertia="og-title" content="RibkaYoga — йога, собранная по-вашему">
    <meta property="og:description" data-inertia="og-description" content="Ваше пространство для создания, проведения и осмысления практики.">
    <meta property="og:type" data-inertia="og-type" content="website">
    <meta property="og:image" data-inertia="og-image" content="{{ url('/images/brand/glass-garden-hero.webp') }}">
    @endif
    <meta name="theme-color" content="#397a28">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @inertiaHead
</head>
<body>@inertia</body>
</html>
