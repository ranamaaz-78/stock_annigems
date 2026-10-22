{{--
    Fullscreen POS shell.

    The branch sidebar stays on the left for navigation; there is no topbar. The
    ticket fills the rest of the screen.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" translate="no" class="pos-html">
<head>
    <meta charset="utf-8">
    <meta name="google" content="notranslate">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <x-shell.boot />
    <x-shell.i18n />
    <title>@yield('title', 'POS') — {{ setting('company_name', config('app.name', 'Aani Gemas')) }}</title>
    <x-brand.favicon />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0&display=swap" rel="stylesheet">
    @vite(['resources/css/tailwind.css', 'resources/css/app.scss', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="pos-body">
    <x-branch.sidebar />
    <div class="pos-shell">
        @yield('content')
    </div>
    {{-- Hidden host for the saved document; print() runs here so POS never leaves. --}}
    <iframe id="pos-print-frame" class="pos-print-frame" title="{{ __('Print document') }}" aria-hidden="true"></iframe>
    @stack('scripts')
</body>
</html>
