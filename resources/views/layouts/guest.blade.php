{{-- Guest layout: login, password change, password reset, error pages without admin chrome. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <x-shell.i18n />
    <title>@yield('title', setting('company_name', config('app.name', 'Aanigemas')))</title>
    <x-brand.favicon />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0&display=swap" rel="stylesheet">
    @vite(['resources/css/tailwind.css', 'resources/css/app.scss', 'resources/js/app.js'])
    @stack('head')
</head>
<body
    class="min-h-screen bg-background text-on-surface antialiased"
    @if(session('success')) data-flash-success="{{ session('success') }}" @endif
    @if(session('error')) data-flash-error="{{ session('error') }}" @endif
>
    @yield('content')
    @stack('scripts')
</body>
</html>
