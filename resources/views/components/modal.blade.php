{{-- Modal dialog wrapper. Slot is the body; use Alpine-free show via CSS class toggle. --}}
@props([
    'id',
    'title',
])

<div
    id="{{ $id }}"
    class="fixed inset-0 z-[100] hidden"
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $id }}-title"
>
    <div class="absolute inset-0 bg-[#141d23]/40 backdrop-blur-sm" data-modal-overlay></div>
    <div class="relative z-10 mx-auto mt-16 w-full max-w-lg px-4">
        <div class="bg-surface-container-lowest border border-outline-variant rounded-xl shadow-[0_4px_12px_rgba(0,0,0,0.08)]">
            <div class="flex items-center justify-between px-4 py-3 border-b border-outline-variant">
                <h2 id="{{ $id }}-title" class="font-headline-sm text-headline-sm text-on-surface">{{ $title }}</h2>
                <button type="button" class="text-on-surface-variant hover:text-on-surface" data-modal-close aria-label="Close">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="p-4">
                {{ $slot }}
            </div>
            @isset($footer)
                <div class="px-4 py-3 border-t border-outline-variant flex justify-end gap-2">
                    {{ $footer }}
                </div>
            @endisset
        </div>
    </div>
</div>
