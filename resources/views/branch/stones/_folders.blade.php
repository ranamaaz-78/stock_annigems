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
                    <a href="{{ route('branch.stones.index', ['category' => $category->uuid]) }}" class="folder-tile">
                        <span class="material-symbols-outlined icon icon--lg folder-tile__icon">folder</span>
                        <span class="folder-tile__name">{{ $category->name }}</span>
                        {{-- Lot count is what the operator scans for; the line
                             under it is the stock, in each lot's own unit. --}}
                        <span class="folder-tile__meta">
                            {{ $category->lot_count }} {{ Str::plural('lot', $category->lot_count) }}
                        </span>
                        <span class="folder-tile__meta folder-tile__stock">{{ $category->stockSummary() }}</span>
                    </a>
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
