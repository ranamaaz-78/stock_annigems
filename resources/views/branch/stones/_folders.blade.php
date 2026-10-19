{{--
    Material folders.

    One tile per material name, opening into that material's lots. The
    box/list switch at the bottom right is remembered per browser by
    resources/js/modules/stone-folders.js.
--}}
<div class="panel" data-stone-folders>
    @if($categories->isEmpty())
        <div class="panel__body">
            <x-empty-state title="No stones yet" message="Register a lot by hand, or upload the inventory sheet with Upload Excel." icon="folder">
                <x-slot:actions>
                    @can('create', \App\Models\Stone::class)
                        <x-btn href="{{ route('branch.stones.create') }}">Register Stone</x-btn>
                    @endcan
                </x-slot:actions>
            </x-empty-state>
        </div>
    @else
        <div class="panel__body">
            <div class="folder-grid" data-folder-list>
                @foreach($categories as $category)
                    @php($manageable = auth()->user()->can('manage', $category))
                    {{-- The tile is a box with a stretched link over it, so the
                         rename field and delete button are never nested inside
                         the link. --}}
                    <div class="folder-tile" data-folder-tile @if($manageable) data-manageable @endif>
                        <a href="{{ route('branch.stones.index', ['category' => $category->uuid]) }}"
                           class="folder-tile__link" aria-label="Open {{ $category->name }}"></a>
                        <span class="material-symbols-outlined icon icon--lg folder-tile__icon">folder</span>
                        @if($manageable)
                            {{-- Admin: hover shows a pencil, click turns the name into a
                                 field (Enter saves, Escape cancels). Saving submits the
                                 form below, which returns to this list. --}}
                            <button type="button" class="folder-tile__name folder-tile__name--editable" data-folder-rename
                                    title="Click to rename" aria-label="Rename {{ $category->name }}">
                                <span data-folder-name-text>{{ $category->name }}</span>
                                <span class="material-symbols-outlined icon icon--sm folder-tile__pencil" aria-hidden="true">edit</span>
                            </button>
                        @else
                            <span class="folder-tile__name">{{ $category->name }}</span>
                        @endif
                        {{-- Lot count is what the operator scans for; the line
                             under it is the stock, in each lot's own unit. --}}
                        <span class="folder-tile__meta">
                            {{ $category->lot_count }} {{ Str::plural('lot', $category->lot_count) }}
                        </span>
                        <span class="folder-tile__meta folder-tile__stock">{{ $category->stockSummary() }}</span>

                        @if($manageable)
                            <form method="POST" action="{{ route('branch.stone-folders.update', $category) }}" data-folder-rename-form hidden>
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="return" value="folders">
                                <input type="hidden" name="name" value="{{ $category->name }}">
                            </form>
                            <form method="POST" action="{{ route('branch.stone-folders.destroy', $category) }}" class="folder-tile__actions"
                                  data-loading-form data-confirm-delete-folder
                                  data-folder-name="{{ $category->name }}" data-lot-count="{{ $category->lot_count }}">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="return" value="folders">
                                <button type="submit" class="folder-tile__action" data-icon-only title="Delete folder" aria-label="Delete folder {{ $category->name }}">
                                    <span class="material-symbols-outlined icon icon--sm" aria-hidden="true">delete</span>
                                </button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        <div class="panel__toolbar folder-toolbar">
            @if($categories->total() > 0)
                <div class="folder-toolbar__pages">{{ $categories->withQueryString()->links() }}</div>
            @endif
            <div class="view-switch" role="group" aria-label="Folder view">
                <button type="button" class="view-switch__btn" data-folder-view="box" aria-label="Box view" title="Box view">
                    <span class="material-symbols-outlined icon icon--sm">grid_view</span>
                </button>
                <button type="button" class="view-switch__btn" data-folder-view="list" aria-label="List view" title="List view">
                    <span class="material-symbols-outlined icon icon--sm">view_list</span>
                </button>
            </div>
        </div>
    @endif
</div>
