{{-- Branch admin fixed dark sidebar with module-grouped, permission-filtered navigation. --}}
@php
    $user = auth()->user();
    $nav = \App\Support\Navigation\SidebarNav::tree(config('annigems.branch_nav', []), $user);
@endphp

<div class="sa-sidebar-backdrop" data-shell-backdrop></div>
<nav class="sa-sidebar" aria-label="{{ __('Branch admin') }}" data-shell-sidebar>
    <div class="sa-sidebar__brand">
        <img class="sa-sidebar__logo" src="{{ brand_logo_url() }}" alt="{{ setting('company_name') }}">
        <div class="sa-sidebar__brand-text">
            <p class="sa-sidebar__brand-title">{{ setting('company_name', 'Aani Gemas') }}</p>
            <p class="sa-sidebar__brand-sub text-label-caps">{{ __('Luxury Inventory') }}</p>
        </div>
        <x-shell.collapse />
    </div>

    <div class="sa-sidebar__nav">
        @foreach($nav as $item)
            <x-shell.nav-node :item="$item" />
        @endforeach
    </div>

    <div class="sa-sidebar__footer">
        <x-shell.tools />
        <a href="{{ route('branch.profile.edit') }}" class="sa-sidebar__link" title="{{ __('My Profile') }}">
            <span class="material-symbols-outlined icon icon--md">person</span>
            <span class="sa-sidebar__label">{{ __('My Profile') }}</span>
        </a>
        <form method="POST" action="{{ route('logout') }}" data-loading-form>
            @csrf
            <button type="submit" class="sa-sidebar__logout" data-loading-text="{{ __('Signing out...') }}" title="{{ __('Logout') }}">
                <span class="material-symbols-outlined icon icon--md">logout</span>
                <span class="sa-sidebar__label">{{ __('Logout') }}</span>
            </button>
        </form>
    </div>
</nav>
