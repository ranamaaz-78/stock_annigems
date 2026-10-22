{{-- Delete (move to Trash) button with a confirm dialog. Records can be restored from Trash, never purged. --}}
@props(['type', 'record', 'text' => null, 'icon' => false])

@php($formId = 'delete-record-'.$record->uuid)

@if($icon)
    <button type="button" class="table-admin__action" aria-label="{{ __('Delete') }}" title="{{ __('Delete') }}" data-delete-form="{{ $formId }}" data-delete-text="{{ $text ?? __('This moves it to Trash. You can restore it from the Trash page.') }}">
        <span class="material-symbols-outlined icon icon--sm">delete</span>
    </button>
@else
    <x-btn type="button" variant="danger" data-delete-form="{{ $formId }}" data-delete-text="{{ $text ?? __('This moves it to Trash. You can restore it from the Trash page.') }}">{{ __('Delete') }}</x-btn>
@endif

<form method="POST" action="{{ route('branch.records.destroy', ['type' => $type, 'uuid' => $record->uuid]) }}" id="{{ $formId }}" class="hidden">
    @csrf
    @method('DELETE')
</form>

@once
    @push('scripts')
    <script>
        document.addEventListener('click', async (event) => {
            const btn = event.target.closest('[data-delete-form]');
            if (!btn) return;
            const ok = await window.AnnigemsAlert.confirmAction({
                title: @js(__('Delete this record?')),
                text: btn.dataset.deleteText,
                confirmButtonText: @js(__('Delete')),
            });
            if (ok) document.getElementById(btn.dataset.deleteForm).submit();
        });
    </script>
    @endpush
@endonce
