{{-- Sidebar chrome: POS-style fullscreen (density lives on the edge arrow). --}}
<div class="sa-sidebar__tools">
    {{-- The POS has no top bar, so its language button lives here. --}}
    @if(request()->routeIs('branch.pos.*'))
        <x-shell.language-switch tone="dark" class="sa-sidebar__lang" />
    @endif
    <button
        type="button"
        class="sa-sidebar__tool"
        data-shell-fullscreen
        aria-pressed="false"
        title="{{ __('Full screen') }}"
    >
        <span class="material-symbols-outlined icon icon--md" data-shell-fullscreen-icon>fullscreen</span>
        <span class="sa-sidebar__label" data-shell-fullscreen-label>{{ __('Full screen') }}</span>
    </button>
</div>
