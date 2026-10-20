{{--
    Stone lot table.

    Shown when a material folder is open, or when a filter narrows individual
    lots rather than folders. Selecting rows here feeds the bulk label print.
--}}
<div class="panel">
    @if($stones->isEmpty())
        <div class="panel__body">
            <x-empty-state :title="__('No stones match')" :message="__('Clear the filters, or upload the inventory sheet with Upload Excel.')" icon="diamond">
                <x-slot:actions>
                    <a href="{{ route('branch.stones.index') }}" class="btn btn--secondary">{{ __('Back to folders') }}</a>
                </x-slot:actions>
            </x-empty-state>
        </div>
    @else
        <form method="GET" action="{{ route('branch.stones.labels.bulk') }}" target="_blank" id="bulk-label-form">
            <div class="panel__scroll">
                <table class="table-admin table-admin--lots">
                    <thead>
                        <tr>
                            <th class="lots-sticky lots-sticky--check">
                                {{-- Ticks every lot on this page for "Print selected labels". --}}
                                <input type="checkbox" class="form-check" data-select-all="uuids[]" aria-label="{{ __('Select all lots on this page') }}" title="{{ __('Select all') }}">
                            </th>
                            <th class="lots-sticky lots-sticky--stone">{{ __('Stone') }}</th>
                            <th>{{ __('Shape · size') }}</th>
                            <th class="table-admin__num">{{ __('On hand') }}</th>
                            <th class="table-admin__num">{{ __('Rate') }}</th>
                            <th class="table-admin__num">{{ __('Total cost') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th class="table-admin__actions-col lots-sticky lots-sticky--actions">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($stones as $stone)
                            @php
                                $shapeSize = collect([$stone->shape, $stone->dimension])
                                    ->filter(fn ($part) => filled($part) && ! in_array($part, ['N/A', '0x0', '0X0'], true))
                                    ->implode(' · ');
                                // Carat (per-carat lots) and tray sit under the shape instead of taking a column each.
                                $facts = array_filter([
                                    $stone->carat !== null ? $stone->caratLabel().' ct' : null,
                                    filled($stone->tray_no) ? __('Tray').' '.$stone->tray_no : null,
                                ]);
                                // One status: an inactive lot reads as inactive, otherwise the lot's own state.
                                $isPending = $stone->status === \App\Enums\StoneStatus::PENDING;
                            @endphp
                            <tr class="{{ $stone->isOutOfStock() ? 'table-admin__row--warn' : '' }}">
                                <td class="lots-sticky lots-sticky--check">
                                    @if($stone->hasPacketBarcode() || auth()->user()->can('delete', $stone))
                                        <input type="checkbox" name="uuids[]" value="{{ $stone->uuid }}" class="form-check">
                                    @endif
                                </td>
                                <td class="lots-sticky lots-sticky--stone">
                                    <x-table.identity
                                        :href="route('branch.stones.show', $stone)"
                                        :title="$stone->name"
                                        :meta="$stone->stone_code"
                                        icon="diamond"
                                        :image-url="$stone->image && auth()->user()->can('view', $stone->image) ? $stone->image->url() : null"
                                        :image-alt="$stone->image?->name ?? ''"
                                    >
                                        @if($stone->image_code)
                                            {{-- Muted when no picture with that name is in the image folder yet. --}}
                                            <span class="table-meta" @if(! $stone->image) title="{{ __('No image named :name yet', ['name' => $stone->image_code]) }}" @endif>
                                                <span class="material-symbols-outlined icon icon--xs">image</span>
                                                {{ $stone->image_code }}
                                            </span>
                                        @endif
                                    </x-table.identity>
                                </td>
                                <td>
                                    <div>{{ $shapeSize !== '' ? $shapeSize : '—' }}</div>
                                    @if($facts)
                                        <span class="table-meta">{{ implode(' · ', $facts) }}</span>
                                    @endif
                                </td>
                                {{-- Gram lots report weight; everything else reports pieces. --}}
                                <td class="table-admin__num {{ $stone->isOutOfStock() ? 'table-admin__num--negative' : 'table-admin__num--strong' }}">
                                    {{ $stone->onHandLabel() }}
                                </td>
                                {{-- The rate (its unit says how it is priced), and under it the price before its latest change. --}}
                                <td class="table-admin__num">
                                    <div>{{ $stone->rateLabel() }}</div>
                                    @if($stone->last_rate !== null)
                                        <span class="table-meta" @if($stone->rate_changed_at) title="{{ __('Changed :date', ['date' => setting_datetime($stone->rate_changed_at)]) }}" @endif>{{ __('was :rate', ['rate' => $stone->lastRateLabel()]) }}</span>
                                    @endif
                                </td>
                                {{-- The stored formula explains the figure on hover. --}}
                                <td class="table-admin__num" title="{{ $stone->cost_formula }}">{{ setting_money($stone->total_cost) }}</td>
                                <td>
                                    @if(! $stone->is_active)
                                        <x-status-badge status="inactive" />
                                    @elseif($stone->status === \App\Enums\StoneStatus::OUT_OF_STOCK)
                                        <x-status-badge status="out_of_stock" :label="__('Out of stock')" />
                                    @elseif($isPending)
                                        <x-status-badge status="reserved" :label="__('Pending')" />
                                    @else
                                        <x-status-badge status="available" :label="__('In stock')" />
                                    @endif
                                </td>
                                <td class="table-admin__actions-col lots-sticky lots-sticky--actions">
                                    <x-table.actions>
                                        <x-table.action :href="route('branch.stones.show', $stone)" />
                                        @can('update', $stone)
                                            <x-table.action :href="route('branch.stones.edit', $stone)" icon="edit" :label="__('Edit')" />
                                        @endcan
                                        @can('printLabel', $stone)
                                            {{-- Opens the print dialog over this page; the link is the no-script fallback. --}}
                                            <x-table.action :href="route('branch.stones.label', $stone)" icon="print" :label="__('Print label')" data-label-open="{{ $stone->uuid }}" />
                                        @endcan
                                        @can('delete', $stone)
                                            {{-- Submits the form below the table (a form cannot sit inside the label form). --}}
                                            <button type="submit" form="delete-stone-{{ $stone->uuid }}" class="table-admin__action table-admin__action--danger" aria-label="{{ __('Delete') }}" title="{{ __('Delete') }}">
                                                <span class="material-symbols-outlined icon icon--sm">delete</span>
                                            </button>
                                        @endcan
                                    </x-table.actions>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if(auth()->user()->hasPermission('stones.barcode') || auth()->user()->hasPermission('stones.delete'))
                <div class="panel__toolbar">
                    @if(auth()->user()->hasPermission('stones.barcode'))
                        <x-btn type="button" variant="secondary" data-label-selected>
                            <span class="material-symbols-outlined icon icon--sm">print</span>
                            {{ __('Print selected labels') }}
                        </x-btn>
                    @endif
                    @if(auth()->user()->hasPermission('stones.delete'))
                        <x-btn type="button" variant="danger" data-delete-selected :disabled="true">
                            <span class="material-symbols-outlined icon icon--sm">delete</span>
                            {{ __('Delete selected') }}
                        </x-btn>
                    @endif
                </div>
            @endif
        </form>
        @if(auth()->user()->hasPermission('stones.delete'))
            <form id="bulk-delete-form" method="POST" action="{{ route('branch.stones.bulk-destroy') }}" class="hidden" data-loading-form>
                @csrf
                @method('DELETE')
            </form>
        @endif
        @foreach($stones as $stone)
            @can('delete', $stone)
                <form id="delete-stone-{{ $stone->uuid }}" method="POST" action="{{ route('branch.stones.destroy', $stone) }}" data-loading-form data-confirm-delete-stone
                      data-remaining="{{ $stone->isWeighed() ? rtrim(rtrim(number_format((float) $stone->weight_grams, 3, '.', ''), '0'), '.').' g' : ((int) $stone->quantity).' pcs' }}" class="hidden">
                    @csrf
                    @method('DELETE')
                </form>
            @endcan
        @endforeach
        @if($stones->total() > 0)
            <div class="panel__footer">
                {{ $stones->withQueryString()->links() }}
            </div>
        @endif
    @endif
</div>
