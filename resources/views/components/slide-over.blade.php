{{-- Right slide-over panel used for Add/Edit Branch. --}}
@props([
    'id',
    'title',
])

<div id="{{ $id }}" class="fixed inset-0 z-[100] hidden" aria-hidden="true">
    <div class="absolute inset-0 bg-[#141d23]/40 backdrop-blur-sm" data-slide-overlay></div>
    <aside class="absolute right-0 top-0 h-full w-full max-w-md bg-surface-container-lowest border-l border-outline-variant shadow-[0_4px_12px_rgba(0,0,0,0.08)] flex flex-col">
        <div class="flex items-center justify-between px-4 py-3 border-b border-outline-variant">
            <h2 class="font-headline-sm text-headline-sm text-on-surface">{{ $title }}</h2>
            <button type="button" class="text-on-surface-variant hover:text-on-surface" data-slide-close aria-label="{{ __('Close') }}">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="flex-1 overflow-y-auto p-4">
            {{ $slot }}
        </div>
        @isset($footer)
            <div class="px-4 py-3 border-t border-outline-variant flex justify-end gap-2">
                {{ $footer }}
            </div>
        @endisset
    </aside>
</div>
