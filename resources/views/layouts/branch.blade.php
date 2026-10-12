{{-- Branch admin shell: dark sidebar + topbar (mirrors SuperAdmin layout). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <x-shell.boot />
    <title>@yield('title', 'Branch') — {{ setting('company_name', config('app.name', 'Annigems')) }}</title>
    <x-brand.favicon />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;0,700;1,500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0&display=swap" rel="stylesheet">
    @vite(['resources/css/tailwind.css', 'resources/css/app.scss', 'resources/js/app.js'])
    @stack('head')
</head>
<body
    @if(session('success')) data-flash-success="{{ session('success') }}" @endif
    @if(session('error')) data-flash-error="{{ session('error') }}" @endif
    @if(session('generated_credentials'))
        data-cred-email="{{ session('generated_credentials.email') }}"
        data-cred-password="{{ session('generated_credentials.password') }}"
        data-cred-name="{{ session('generated_credentials.name') }}"
    @endif
>
    <x-branch.sidebar />
    <x-branch.topbar />

    <main class="sa-main">
        <div class="sa-main__inner">
            @yield('content')
        </div>
    </main>

    @stack('scripts')
</body>
</html>
