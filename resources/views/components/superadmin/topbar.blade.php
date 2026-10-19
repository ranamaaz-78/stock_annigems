{{-- SuperAdmin top bar (SCSS: .sa-topbar*). --}}
<header class="sa-topbar">
    <div class="sa-topbar__left">
        <button type="button" class="sa-menu-toggle" data-shell-mobile-toggle aria-label="{{ __('Open menu') }}">
            <span class="material-symbols-outlined icon icon--md">menu</span>
        </button>
        <div>
            <p class="sa-topbar__eyebrow text-label-caps">{{ __('System') }}</p>
            <p class="sa-topbar__title text-headline-sm">@yield('page-title', __('Overview'))</p>
        </div>
    </div>

    <div class="sa-topbar__right">
        <x-shell.language-switch />
        <div class="sa-topbar__user">
            <div class="sa-topbar__user-name text-data-mono">{{ auth()->user()?->name }}</div>
            <div class="sa-topbar__user-role text-label-caps">{{ __('SuperAdmin') }}</div>
        </div>
        <div class="sa-topbar__avatar">
            {{ strtoupper(substr(auth()->user()?->name ?? 'S', 0, 1)) }}
        </div>
    </div>
</header>
