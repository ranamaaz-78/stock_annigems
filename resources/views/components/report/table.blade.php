{{--
    Shared report detail table (SCSS: .table-admin--dense).

    Renders any ReportResult: the column definitions carry the label, alignment
    and number format, and every cell is formatted through ReportFormatter so an
    on-screen figure and its exported counterpart are byte-identical.
--}}
@props([
    'title' => null,
    // list<array{key, label, align?, format?}>
    'columns',
    // iterable of associative row arrays
    'rows',
    // Optional footer row keyed by the same column keys.
    'totals' => [],
    'totalsLabel' => 'Total',
    'emptyTitle' => 'No data for this period',
    'emptyMessage' => 'Nothing matched the selected date range and filters.',
    'emptyIcon' => 'table_rows',
])

@php
    use App\Support\Reports\ReportFormatter;

    $isEmpty = $rows instanceof \Illuminate\Support\Collection
        ? $rows->isEmpty()
        : count($rows) === 0;

    /**
     * Cell classes for a column: numeric columns are right-aligned with tabular
     * figures; the first column carries the row label in body type.
     */
    $cellClass = function (array $column, int $index): string {
        $classes = [];

        if (($column['align'] ?? null) === 'right') {
            $classes[] = 'table-admin__num';
        }

        if (($column['format'] ?? null) === 'negative_money') {
            $classes[] = 'table-admin__num table-admin__num--negative';
        }

        if (($column['emphasis'] ?? null) === 'strong') {
            $classes[] = 'table-admin__num--strong';
        }

        if (($column['emphasis'] ?? null) === 'muted') {
            $classes[] = 'table-admin__num--muted';
        }

        if ($index === 0) {
            $classes[] = 'table-admin__label';
        }

        return implode(' ', array_unique($classes));
    };
@endphp

<div {{ $attributes->merge(['class' => 'panel report-section']) }}>
    @if($title || isset($actions))
        <div class="panel-header">
            @if($title)
                <h3 class="panel-header__title text-headline-sm">{{ $title }}</h3>
            @endif
            @isset($actions)
                <div class="page-header__actions">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    @if($isEmpty)
        <div class="p-4">
            <x-empty-state :title="$emptyTitle" :message="$emptyMessage" :icon="$emptyIcon" />
        </div>
    @else
        <div class="table-scroll">
            <table class="table-admin table-admin--dense">
                <thead>
                    <tr>
                        @foreach($columns as $column)
                            <th class="{{ ($column['align'] ?? null) === 'right' ? 'table-admin__num' : '' }}">
                                {{ $column['label'] }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr>
                            @foreach($columns as $index => $column)
                                <td class="{{ $cellClass($column, $index) }}">
                                    @if(($column['format'] ?? null) === 'branch')
                                        {{-- Three-letter mark plus name, per the approved
                                             comparison table. Escaped like any other cell. --}}
                                        <span class="branch-cell">
                                            <span class="branch-cell__mark">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr((string) ($row[$column['key']] ?? ''), 0, 3)) }}</span>
                                            <span class="branch-cell__name">{{ $row[$column['key']] }}</span>
                                        </span>
                                    @else
                                        {{ ReportFormatter::cell($row[$column['key']] ?? null, $column['format'] ?? null) }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
                @if(! empty($totals))
                    <tfoot>
                        <tr>
                            @foreach($columns as $index => $column)
                                <td class="{{ $cellClass($column, $index) }}">
                                    @if($index === 0)
                                        {{ $totals[$column['key']] ?? $totalsLabel }}
                                    @else
                                        {{ array_key_exists($column['key'], $totals)
                                            ? ReportFormatter::cell($totals[$column['key']], $column['format'] ?? null)
                                            : '' }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    @endif
</div>
