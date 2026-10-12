{{-- SuperAdmin fixed dark sidebar with module-grouped navigation. --}}
@php
    $nav = \App\Support\Navigation\SidebarNav::tree(config('annigems.superadmin_nav', []), auth()->user());
@endphp

<div class="sa-sidebar-backdrop" data-shell-backdrop></div>
<nav class="sa-sidebar" aria-label="SuperAdmin" data-shell-sidebar>
    <div class="sa-sidebar__brand">
        <span class="material-symbols-outlined icon icon--lg icon--primary-fixed">diamond</span>
        <div class="sa-sidebar__brand-text">
            <p class="sa-sidebar__brand-title">{{ setting('company_name', 'Annigems') }}</p>
            <p class="sa-sidebar__brand-sub text-label-caps">SuperAdmin Suite</p>
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
        <form method="POST" action="{{ route('logout') }}" data-loading-form>
            @csrf
            <button type="submit" class="sa-sidebar__logout" data-loading-text="Signing out..." title="Logout">
                <span class="material-symbols-outlined icon icon--md">logout</span>
                <span class="sa-sidebar__label">Logout</span>
            </button>
        </form>
    </div>
</nav>
