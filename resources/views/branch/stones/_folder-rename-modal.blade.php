{{--
    Rename the open folder.

    Only the folder's label changes. Lots inside keep their own names, and the
    folder still collects lots filed under its old spelling.
--}}
<x-dialog
    id="rename-folder-modal"
    :title="__('Rename folder')"
    :subtitle="__('Lots inside keep their own names. Only the folder label changes.')"
    icon="edit"
>
    <form method="POST" action="{{ route('branch.stone-folders.update', $category) }}" data-loading-form>
        @csrf
        @method('PUT')
        <div class="dialog__body">
            <x-form.input name="name" :label="__('Folder name')" :value="$category->name" required maxlength="120" />
        </div>
        <div class="dialog__footer">
            <x-btn type="button" variant="secondary" data-modal-close>{{ __('Cancel') }}</x-btn>
            <x-btn type="submit" :loading-text="__('Saving...')">{{ __('Save') }}</x-btn>
        </div>
    </form>
</x-dialog>
