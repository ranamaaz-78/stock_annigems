{{-- Public product page chrome: logo header only, no admin sidebar. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', setting('company_name', config('app.name', 'Annigems')))</title>
    <x-brand.favicon />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0&display=swap" rel="stylesheet">
    @vite(['resources/css/tailwind.css', 'resources/css/app.scss'])
</head>
<body class="min-h-screen bg-background text-on-surface antialiased flex flex-col">
    <header class="bg-surface-container-lowest border-b border-outline-variant flex items-center justify-center px-6 py-4">
        @if(brand_asset_url('company_logo'))
            <img src="{{ brand_asset_url('company_logo') }}" alt="{{ setting('company_name') }}" class="h-8 w-auto mr-3">
        @endif
        <p class="text-headline-sm text-primary">{{ setting('company_name', config('app.name', 'Annigems')) }}</p>
    </header>
    <main class="flex-1 w-full max-w-md mx-auto bg-surface-container-lowest">
        @yield('content')
    </main>
</body>
</html>
