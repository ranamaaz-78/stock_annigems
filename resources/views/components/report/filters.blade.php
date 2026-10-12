{{--
    Report filter bar controls.

    Date preset, custom range and grouping are always present. The secondary
    controls render only when the report declared them in ReportCatalog, so a
    sales summary does not ship a stone picker.

    Everything here is a plain GET form field: a report is a URL, which makes it
    bookmarkable and means the export button can carry the same query string.

    Native <select class="input-field"> controls are upgraded to Select2 search
    globally (see resources/js/modules/select-search.js) so customer / item /
    stone lists can be typed into.
--}}
@props([
    // list<string> — filter keys this report declared
    'available' => [],
    // Option collections supplied by the controller, keyed by list name
    'options' => [],
    'filter',
])

@php
    use App\Enums\PaymentMethod;
    use App\Enums\ReportDatePreset;
    use App\Enums\ReportGrouping;

    $has = fn (string $key): bool => in_array($key, $available, true);
    $current = request()->query();
@endphp

<div>
    <label for="preset" class="form-label">Period</label>
    <select id="preset" name="preset" class="input-field">
        @foreach(ReportDatePreset::cases() as $case)
            <option value="{{ $case->value }}" @selected($filter->preset === $case)>{{ $case->label() }}</option>
        @endforeach
    </select>
</div>

<div>
    <label for="from" class="form-label">From</label>
    <input type="date" id="from" name="from" class="input-field" value="{{ $filter->from->toDateString() }}">
</div>

<div>
    <label for="to" class="form-label">To</label>
    <input type="date" id="to" name="to" class="input-field" value="{{ $filter->to->toDateString() }}">
</div>

@if($has('grouping'))
    <div>
        <label for="grouping" class="form-label">Group by</label>
        <select id="grouping" name="grouping" class="input-field">
            @foreach(ReportGrouping::cases() as $case)
                <option value="{{ $case->value }}" @selected($filter->grouping === $case)>{{ $case->label() }}</option>
            @endforeach
        </select>
    </div>
@endif

@if($has('payment_method'))
    <div>
        <label for="payment_method" class="form-label">Payment method</label>
        <select id="payment_method" name="payment_method" class="input-field">
            <option value="">All methods</option>
            @foreach(PaymentMethod::cases() as $case)
                <option value="{{ $case->value }}" @selected(($current['payment_method'] ?? '') === $case->value)>{{ $case->label() }}</option>
            @endforeach
        </select>
    </div>
@endif

{{-- The stone ledger and the item ledger are different enums; the controller
     picks the right one so neither report offers the other's movements. --}}
@if($has('movement_type') && isset($options['movement_types']))
    <div>
        <label for="movement_type" class="form-label">Movement type</label>
        <select id="movement_type" name="movement_type" class="input-field">
            <option value="">All movements</option>
            @foreach($options['movement_types'] as $case)
                <option value="{{ $case->value }}" @selected(($current['movement_type'] ?? '') === $case->value)>{{ $case->label() }}</option>
            @endforeach
        </select>
    </div>
@endif

{{-- Record references are submitted as uuids and resolved to ids during
     validation, so no primary key is ever exposed in a report URL. --}}
@if($has('category') && isset($options['categories']))
    <div>
        <label for="category" class="form-label">Category</label>
        <select id="category" name="category" class="input-field">
            <option value="">All categories</option>
            @foreach($options['categories'] as $option)
                <option value="{{ $option->uuid }}" @selected(($current['category'] ?? '') === $option->uuid)>{{ $option->name }}</option>
            @endforeach
        </select>
    </div>
@endif

@if($has('purity') && isset($options['purities']))
    <div>
        <label for="purity" class="form-label">Purity</label>
        <select id="purity" name="purity" class="input-field">
            <option value="">All purities</option>
            @foreach($options['purities'] as $option)
                <option value="{{ $option->uuid }}" @selected(($current['purity'] ?? '') === $option->uuid)>{{ $option->name }}</option>
            @endforeach
        </select>
    </div>
@endif

@if($has('staff') && isset($options['staff']))
    <div>
        <label for="staff" class="form-label">Staff member</label>
        <select id="staff" name="staff" class="input-field">
            <option value="">All staff</option>
            @foreach($options['staff'] as $option)
                <option value="{{ $option->uuid }}" @selected(($current['staff'] ?? '') === $option->uuid)>{{ $option->name }}</option>
            @endforeach
        </select>
    </div>
@endif

@if($has('customer') && isset($options['customers']))
    <div class="filter-bar__wide">
        <label for="customer" class="form-label">Customer</label>
        <select id="customer" name="customer" class="input-field">
            <option value="">Choose a customer</option>
            @foreach($options['customers'] as $option)
                <option value="{{ $option->uuid }}" @selected(($current['customer'] ?? '') === $option->uuid)>{{ $option->name }} — {{ $option->phone }}</option>
            @endforeach
        </select>
    </div>
@endif

@if($has('stone') && isset($options['stones']))
    <div class="filter-bar__wide">
        <label for="stone" class="form-label">Stone</label>
        <select id="stone" name="stone" class="input-field">
            <option value="">All stones</option>
            @foreach($options['stones'] as $option)
                <option value="{{ $option->uuid }}" @selected(($current['stone'] ?? '') === $option->uuid)>{{ $option->stone_code }} — {{ $option->name }}</option>
            @endforeach
        </select>
    </div>
@endif

@if($has('item') && isset($options['items']))
    <div class="filter-bar__wide">
        <label for="item" class="form-label">Item</label>
        <select id="item" name="item" class="input-field">
            <option value="">All items</option>
            @foreach($options['items'] as $option)
                <option value="{{ $option->uuid }}" @selected(($current['item'] ?? '') === $option->uuid)>{{ $option->item_code }} — {{ $option->name }}</option>
            @endforeach
        </select>
    </div>
@endif

<div class="filter-bar__actions">
    <x-btn type="submit" loading-text="Running...">Apply</x-btn>
</div>
