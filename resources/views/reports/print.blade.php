{{--
    Print-optimised report page — the project's "PDF export".

    Follows the convention already established by the invoice and quotation
    print sheets: a standalone page loading app.scss only, opened with the
    browser's own print dialog. That keeps the export dependency-free and,
    because the table is a real thead/tbody, the browser repeats column headers
    across pages natively — which a generated-image PDF would not do.

    Shared by both surfaces: an aggregate SuperAdmin report and a detailed
    branch report print through this same file.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $result->title }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    @vite(['resources/css/app.scss'])
</head>
<body class="report-print">
    <div class="report-print__page">
        <header class="report-print__header">
            <div>
                <p class="text-label-caps">{{ $result->meta('branch_label', 'All Branches') }}</p>
                <h1 class="text-headline-sm">{{ $result->title }}</h1>
            </div>
            {{-- Hidden on paper: a printed page needs no print button. --}}
            <div class="report-print-hide">
                <button type="button" class="btn btn--primary" onclick="window.print()">Print</button>
            </div>
        </header>

        {{-- Self-describing header, identical to the screen and the CSV. --}}
        <div class="report-meta">
            @foreach($result->meta('header', []) as $item)
                <div class="report-meta__item">
                    <span class="report-meta__label">{{ $item['label'] }}</span>
                    <span class="report-meta__value">{{ $item['value'] }}</span>
                </div>
            @endforeach
        </div>

        @if($result->meta('money', false))
            <p class="report-notice">
                <span>
                    These are <strong>revenue</strong> figures, not profit. Purchase cost is not recorded by the
                    system, so margin cannot be calculated. Voided invoices are excluded from every total below.
                </span>
            </p>
        @endif

        @if(! empty($result->summary))
            <div class="kpi-grid {{ count($result->summary) === 3 ? 'kpi-grid--3' : '' }}">
                @foreach($result->summary as $card)
                    <x-report.kpi
                        :label="$card['label']"
                        :value="$card['value']"
                        :hint="$card['hint'] ?? null"
                        :trend="$card['trend'] ?? null"
                    />
                @endforeach
            </div>
        @endif

        @if(! empty($result->columns))
            <x-report.table
                :columns="$result->columns"
                :rows="$result->rows"
                :totals="$result->totals"
                :title="$result->meta('table_title', 'Breakdown')"
            />
        @endif

        @foreach($result->meta('extra_tables', []) as $table)
            <x-report.table
                :columns="$table['columns']"
                :rows="$table['rows']"
                :totals="$table['totals'] ?? []"
                :title="$table['title']"
            />
        @endforeach

        <footer class="report-print__footer">
            <span>{{ $result->title }} — {{ $result->meta('range_label', '') }}</span>
            <span>Generated {{ $result->meta('generated_at', '') }}</span>
        </footer>
    </div>
</body>
</html>
