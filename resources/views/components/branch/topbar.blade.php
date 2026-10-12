{{-- Branch admin top bar with branch name and profile link. --}}
@php
    $user = auth()->user();
@endphp

<header class="sa-topbar">
    <div class="sa-topbar__left">
        <button type="button" class="sa-menu-toggle" data-shell-mobile-toggle aria-label="Open menu">
            <span class="material-symbols-outlined icon icon--md">menu</span>
        </button>
        <div>
            <p class="sa-topbar__eyebrow text-label-caps">Branch</p>
            <p class="sa-topbar__title text-headline-sm">@yield('page-title', 'Dashboard')</p>
        </div>
    </div>

    <div class="sa-topbar__center hidden md:block">
        <span class="text-body-sm text-on-surface-variant">Branch:</span>
        <span class="text-body-sm font-medium ml-1">{{ $user?->branch?->name ?? '—' }}</span>
    </div>

    <div class="sa-topbar__right">
        <a href="{{ route('branch.profile.edit') }}" class="sa-topbar__user text-right">
            <div class="sa-topbar__user-name text-data-mono">{{ $user?->name }}</div>
            <div class="sa-topbar__user-role text-label-caps">{{ $user?->role?->label() }}</div>
        </a>
        <a href="{{ route('branch.profile.edit') }}" class="sa-topbar__avatar" title="My Profile">
            {{ strtoupper(substr($user?->name ?? 'U', 0, 1)) }}
        </a>
    </div>
</header>
