<?php

namespace App\Services;

use App\Enums\BranchStatus;
use App\Enums\ReportDatePreset;
use App\Enums\ReportGrouping;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Branch;
use App\Models\User;
use App\Repositories\Reports\CreditReportRepository;
use App\Repositories\Reports\InventoryReportRepository;
use App\Repositories\Reports\QuotationReportRepository;
use App\Repositories\Reports\SalesReportRepository;
use App\Repositories\Reports\StoneReportRepository;
use App\Support\Reports\ReportFilter;
use App\Support\Reports\ReportFormatter;
use App\Support\Reports\ReportResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * DashboardStatsService
 *
 * The single source for every dashboard figure in the system.
 *
 * It reads through the same report repositories the report screens use, which
 * is the whole point: a "This Month's Revenue" card and the Sales Summary report
 * for the same month execute the same SQL, so they cannot disagree. Any figure
 * added to a dashboard must come from a repository, never from an ad-hoc query
 * written next to the card.
 *
 * Caching
 * -------
 * Dashboard results are cached for a couple of minutes on the already-configured
 * database cache store. This is the first use of the cache in the application,
 * so the reasoning is worth stating: a dashboard is hit on every page load by
 * every user, and its figures are summaries where being sixty seconds stale is
 * harmless. Detailed reports are deliberately *not* cached, because they are
 * filtered on demand and a stale filtered report would be misleading.
 *
 * The key includes the branch, the role, the acting user and today's date, so a
 * new day, a different branch, or a different role never reads another's
 * numbers. The cache is flushed for a branch whenever an invoice is created or
 * voided, which are the two events that move a revenue figure.
 */
class DashboardStatsService
{
    public function __construct(
        private SalesReportRepository $sales,
        private InventoryReportRepository $inventory,
        private StoneReportRepository $stones,
        private QuotationReportRepository $quotations,
        private CreditReportRepository $credit,
    ) {}

    /**
     * Cache key prefix, exposed so the invalidation helper and the tests agree.
     */
    public const CACHE_PREFIX = 'dashboard-stats';

    /**
     * Discard cached dashboard figures for one branch.
     *
     * Called after an invoice is created or voided — the only two events that
     * change a revenue figure. Cache tags are unavailable on the database store,
     * so a monotonically increasing version number per branch is bumped instead
     * and folded into every key, which retires the old entries immediately.
     */
    public static function forgetBranch(?int $branchId): void
    {
        // The branch's own dashboards, plus the SuperAdmin consolidated view,
        // which also became stale the moment this branch's revenue changed.
        $namespaces = array_unique([$branchId === null ? 'all' : (string) $branchId, 'all']);

        foreach ($namespaces as $namespace) {
            $key = self::CACHE_PREFIX.':version:'.$namespace;

            Cache::forever($key, (int) Cache::get($key, 0) + 1);
        }
    }

    /**
     * Cards, lists and charts for the dashboard of a branch user.
     *
     * Card sets follow spec 8.2 to 8.5 exactly. Every card is decided here,
     * server-side, from the actor's role and permissions — nothing is emitted
     * and then hidden with CSS, because a hidden card is still a leaked figure.
     *
     * @return array{
     *     branch_name: string,
     *     role_label: string,
     *     cards: list<array<string, mixed>>,
     *     secondary_cards: list<array<string, mixed>>,
     *     chart: ?array<string, mixed>,
     *     recent_invoices: \Illuminate\Support\Collection<int, array<string, mixed>>,
     *     pending_quotations: \Illuminate\Support\Collection<int, array<string, mixed>>,
     *     aging_items: \Illuminate\Support\Collection<int, array<string, mixed>>,
     *     low_stones: \Illuminate\Support\Collection<int, array<string, mixed>>,
     *     recent_intakes: \Illuminate\Support\Collection<int, array<string, mixed>>,
     *     quotation_funnel: ?array<string, mixed>,
     *     can_view_reports: bool
     * }
     */
    public function forBranchUser(User $user): array
    {
        return $this->remember($user, fn (): array => $this->buildBranchDashboard($user));
    }

    /**
     * Cards, comparison table and chart for the SuperAdmin dashboard (spec 8.1).
     *
     * @param  ?int  $branchId Null for the default All Branches mode
     * @return array{
     *     mode: string,
     *     branch_name: string,
     *     cards: list<array<string, mixed>>,
     *     chart: ?array<string, mixed>,
     *     comparison: \Illuminate\Support\Collection<int, array<string, mixed>>
     * }
     */
    public function forSuperAdmin(User $user, ?int $branchId = null): array
    {
        $key = self::CACHE_PREFIX.':superadmin:'.($branchId ?? 'all').':'.$this->today();

        return Cache::remember(
            $this->versioned($key, $branchId),
            $this->ttl(),
            fn (): array => $this->buildSuperAdminDashboard($branchId)
        );
    }

    /**
     * Build the branch dashboard for the actor's role.
     *
     * @return array<string, mixed>
     */
    private function buildBranchDashboard(User $user): array
    {
        $branchId = $user->branch_id;
        $role = $user->role instanceof UserRole ? $user->role : UserRole::from((string) $user->role);

        $dashboard = [
            'branch_name' => $user->branch?->name ?? __('Branch'),
            'role_label' => $role->label(),
            'cards' => [],
            'secondary_cards' => [],
            'chart' => null,
            'recent_invoices' => collect(),
            'pending_quotations' => collect(),
            'aging_items' => collect(),
            'low_stones' => collect(),
            'recent_intakes' => collect(),
            'quotation_funnel' => null,
            // Spec 8.6: clicking a card navigates to the matching report where
            // the user has access and does nothing where they do not. A Manager
            // therefore gets the same cards as an Admin but no links (spec 8.3).
            'can_view_reports' => $user->can('viewAdminReports'),
        ];

        return match ($role) {
            UserRole::CASHIER => $this->cashierDashboard($dashboard, $user, $branchId),
            UserRole::INVENTORY_STAFF => $this->inventoryStaffDashboard($dashboard, $user, $branchId),
            // Admin and Manager share the same card set; the Manager simply has
            // no route into the Reports section (spec 8.3).
            default => $this->adminDashboard($dashboard, $user, $branchId),
        };
    }

    /**
     * Admin / Manager dashboard (spec 8.2 and 8.3).
     *
     * @param  array<string, mixed>  $dashboard
     * @return array<string, mixed>
     */
    private function adminDashboard(array $dashboard, User $user, ?int $branchId): array
    {
        $today = $this->filter($branchId, ReportDatePreset::TODAY);
        $month = $this->filter($branchId, ReportDatePreset::THIS_MONTH);

        // Sales figures require sales visibility. An Admin or Manager always
        // has it; the check stays because permissions are editable.
        if ($user->can('viewSalesKpis')) {
            $todayTotals = $this->sales->totals($today);
            $monthTotals = $this->sales->totals($month);
            $previousMonth = $this->sales->totals($month->previousPeriod());

            $dashboard['cards'][] = $this->card(
                'todays_revenue',
                "Today's Revenue",
                ReportFormatter::money($todayTotals['net_revenue']),
                icon: 'payments',
                hint: __(':count invoices today', ['count' => ReportFormatter::count($todayTotals['invoice_count'])]),
            );

            $dashboard['cards'][] = $this->card(
                'month_revenue',
                "This Month's Revenue",
                ReportFormatter::money($monthTotals['net_revenue']),
                icon: 'trending_up',
                trend: ReportFormatter::trend($monthTotals['net_revenue'], $previousMonth['net_revenue']),
            );

            $dashboard['cards'][] = $this->card(
                'pieces_sold',
                'Pieces Sold This Month',
                ReportFormatter::count($this->sales->piecesSold($month)),
                icon: 'shopping_bag',
            );

            // Replaces the design's "Net Profit" card. Profit cannot be
            // computed without purchase cost, and gold weight sold is the
            // genuinely useful jewellery figure revenue alone hides.
            $dashboard['cards'][] = $this->card(
                'gold_weight_sold',
                'Gold Weight Sold This Month',
                ReportFormatter::grams($this->sales->goldWeightSold($month)),
                icon: 'scale',
                hint: __('Net metal weight, revenue not profit'),
            );

            $dashboard['chart'] = $this->revenueTrendChart($branchId);
        }

        if ($user->can('viewInventoryKpis')) {
            $dashboard['secondary_cards'][] = $this->card(
                'available_pieces',
                'Available Pieces',
                ReportFormatter::count($this->inventory->availablePieceCount($branchId)),
                icon: 'inventory_2',
            );

            // Replaces the design's "Inventory Value" card for the same reason:
            // no cost data exists, so no valuation can honestly be shown.
            $dashboard['secondary_cards'][] = $this->card(
                'gold_weight_stock',
                'Gold Weight in Stock',
                ReportFormatter::grams($this->inventory->goldWeightInStock($branchId)),
                icon: 'diamond',
                hint: __('Available pieces only'),
            );

            $dashboard['aging_items'] = $this->inventory->agingItems($branchId, 5, $this->oldestAgingEdge());
        }

        if ($user->hasPermission('quotations.view')) {
            $pending = $this->quotations->pendingTotals($branchId);

            $dashboard['secondary_cards'][] = $this->card(
                'pending_quotations',
                'Pending Quotations',
                ReportFormatter::count($pending['count']),
                icon: 'request_quote',
                hint: __(':value quoted', ['value' => ReportFormatter::money($pending['value'])]),
            );

            $dashboard['pending_quotations'] = $this->quotations->pendingDrafts($branchId, 5);
            $dashboard['quotation_funnel'] = $this->quotations->funnelTotals($month);
        }

        if ($user->hasPermission('stones.view')) {
            $dashboard['secondary_cards'][] = $this->card(
                'total_stones',
                'Stones in Stock',
                ReportFormatter::count($this->stones->totalStonesInStock($branchId)),
                icon: 'diamond',
                hint: __(':grams by weight, :carats', ['grams' => ReportFormatter::grams($this->stones->totalStockGrams($branchId)), 'carats' => ReportFormatter::carats($this->stones->totalCaratInStock($branchId))]),
            );

            // Stone worth is purchase cost, so it is shown only to users who may see cost.
            if ($user->hasPermission('items.view_cost')) {
                $dashboard['secondary_cards'][] = $this->card(
                    'stone_value',
                    'Stone Stock Value',
                    ReportFormatter::money($this->stones->stockValue($branchId)),
                    icon: 'payments',
                    hint: __('At purchase rate'),
                );
            }

            $dashboard['secondary_cards'][] = $this->card(
                'zero_stock_stones',
                'Stones at Zero Stock',
                ReportFormatter::count($this->stones->zeroStockCount($branchId)),
                icon: 'warning',
                hint: __('Reorder needed'),
            );

            $dashboard['low_stones'] = $this->stones->zeroAndLowStock($branchId)->take(5);
        }

        // Goods that left the shelf and are not sold yet: performas and delivery notes.
        $performas = $user->hasPermission('performas.view');
        $notes = $user->hasPermission('delivery_notes.view');
        if ($performas || $notes) {
            $onCredit = $performas ? (float) $this->credit->outstandingByCreditor($branchId)->sum('outstanding') : 0.0;
            $uninvoiced = $notes ? $this->credit->uninvoicedNotes($branchId, 1000) : collect();

            $dashboard['secondary_cards'][] = $this->card(
                'out_with_clients',
                'Out With Clients',
                ReportFormatter::money($onCredit + (float) $uninvoiced->sum('grand_total')),
                icon: 'local_shipping',
                hint: __(':value on performa, :count delivery notes to invoice', ['value' => ReportFormatter::money($onCredit), 'count' => ReportFormatter::count($uninvoiced->count())]),
            );
        }

        if ($user->hasPermission('invoices.view')) {
            $dashboard['recent_invoices'] = $this->sales->recentInvoices($branchId, 5);

            $awaiting = $this->sales->awaitingPayment($branchId);
            $dashboard['secondary_cards'][] = $this->card(
                'awaiting_payment',
                'Awaiting Payment',
                ReportFormatter::money($awaiting['invoice_due'] + $awaiting['note_due']),
                icon: 'hourglass_top',
                hint: __(':invoices invoices, :notes delivery notes', ['invoices' => ReportFormatter::count($awaiting['invoices']), 'notes' => ReportFormatter::count($awaiting['notes'])]),
            );
        }

        return $dashboard;
    }

    /**
     * Cashier dashboard (spec 8.4).
     *
     * Deliberately shows the cashier's own figures beside the branch total and
     * no colleague comparison: seeing your own numbers is motivating, being
     * ranked against the person next to you is not what this is for.
     *
     * @param  array<string, mixed>  $dashboard
     * @return array<string, mixed>
     */
    private function cashierDashboard(array $dashboard, User $user, ?int $branchId): array
    {
        $today = $this->filter($branchId, ReportDatePreset::TODAY);

        if ($user->can('viewSalesKpis')) {
            $branchToday = $this->sales->totals($today);
            $mine = $this->sales->totalsForStaff($today, (int) $user->id);

            $dashboard['cards'][] = $this->card(
                'todays_revenue',
                "Today's Revenue (Branch)",
                ReportFormatter::money($branchToday['net_revenue']),
                icon: 'payments',
            );

            $dashboard['cards'][] = $this->card(
                'my_invoices_today',
                'My Invoices Today',
                ReportFormatter::count($mine['count']),
                icon: 'receipt_long',
                hint: __(':value billed by me', ['value' => ReportFormatter::money($mine['revenue'])]),
            );

            $dashboard['cards'][] = $this->card(
                'todays_invoice_count',
                "Today's Invoice Count (Branch)",
                ReportFormatter::count($branchToday['invoice_count']),
                icon: 'point_of_sale',
            );
        }

        if ($user->hasPermission('quotations.view')) {
            $pending = $this->quotations->pendingTotals($branchId, (int) $user->id);

            $dashboard['cards'][] = $this->card(
                'my_pending_quotations',
                'My Pending Quotations',
                ReportFormatter::count($pending['count']),
                icon: 'request_quote',
                hint: __(':value quoted', ['value' => ReportFormatter::money($pending['value'])]),
            );
        }

        if ($user->hasPermission('invoices.view')) {
            $dashboard['recent_invoices'] = $this->sales->recentInvoices($branchId, 8);
        }

        return $dashboard;
    }

    /**
     * Inventory Staff dashboard (spec 8.5).
     *
     * No revenue figure appears anywhere: this role has no sales visibility,
     * which is why the sales repository is not touched at all below.
     *
     * @param  array<string, mixed>  $dashboard
     * @return array<string, mixed>
     */
    private function inventoryStaffDashboard(array $dashboard, User $user, ?int $branchId): array
    {
        if ($user->can('viewInventoryKpis')) {
            $dashboard['cards'][] = $this->card(
                'available_pieces',
                'Available Pieces',
                ReportFormatter::count($this->inventory->availablePieceCount($branchId)),
                icon: 'inventory_2',
            );

            $dashboard['cards'][] = $this->card(
                'gold_weight_stock',
                'Gold Weight in Stock',
                ReportFormatter::grams($this->inventory->goldWeightInStock($branchId)),
                icon: 'scale',
                hint: __('Available pieces only'),
            );

            $dashboard['aging_items'] = $this->inventory->agingItems($branchId, 8, $this->oldestAgingEdge());
        }

        if ($user->hasPermission('stones.view')) {
            $dashboard['cards'][] = $this->card(
                'total_stones',
                'Stones in Stock',
                ReportFormatter::count($this->stones->totalStonesInStock($branchId)),
                icon: 'diamond',
                hint: __(':carats held, :grams by weight', ['carats' => ReportFormatter::carats($this->stones->totalCaratInStock($branchId)), 'grams' => ReportFormatter::grams($this->stones->totalStockGrams($branchId))]),
            );

            if ($user->hasPermission('items.view_cost')) {
                $dashboard['cards'][] = $this->card(
                    'stone_value',
                    'Stone Stock Value',
                    ReportFormatter::money($this->stones->stockValue($branchId)),
                    icon: 'payments',
                    hint: __('At purchase rate'),
                );
            }

            $dashboard['cards'][] = $this->card(
                'zero_stock_stones',
                'Stones at Zero Stock',
                ReportFormatter::count($this->stones->zeroStockCount($branchId)),
                icon: 'warning',
                hint: __('Reorder needed'),
            );

            $dashboard['low_stones'] = $this->stones->zeroAndLowStock($branchId)->take(8);
        }

        if ($user->hasPermission('stones.intake_view')) {
            $dashboard['recent_intakes'] = $this->stones->recentIntakes($branchId, 5);
        }

        return $dashboard;
    }

    /**
     * SuperAdmin dashboard content for the selected mode (spec 8.1).
     *
     * @return array<string, mixed>
     */
    private function buildSuperAdminDashboard(?int $branchId): array
    {
        $month = $this->filter($branchId, ReportDatePreset::THIS_MONTH);

        $dashboard = [
            'mode' => $branchId === null ? 'all' : 'single',
            'branch_name' => $branchId === null
                ? __('All Branches')
                : (string) (Branch::query()->whereKey($branchId)->value('name') ?? __('Branch')),
            'cards' => [],
            'chart' => $this->revenueTrendChart($branchId),
            'comparison' => collect(),
        ];

        if ($branchId === null) {
            $dashboard['cards'] = [
                $this->card('total_branches', __('Total Branches'), ReportFormatter::count(Branch::query()->count()), icon: 'store'),
                $this->card('active_branches', __('Active Branches'), ReportFormatter::count(Branch::query()->where('status', BranchStatus::ACTIVE)->count()), icon: 'check_circle'),
                $this->card('total_users', __('Total Users'), ReportFormatter::count(User::query()->withTrashed()->count()), icon: 'group', hint: __(':count active', ['count' => ReportFormatter::count(User::query()->where('status', UserStatus::ACTIVE)->count())])),
                $this->card('total_revenue', __('Revenue This Month'), ReportFormatter::money($this->sales->totals($month)['net_revenue']), icon: 'payments', hint: __('Completed invoices only')),
            ];

            $dashboard['comparison'] = $this->branchComparison($month);

            return $dashboard;
        }

        $dashboard['cards'] = [
            $this->card('branch_revenue', __('Revenue This Month'), ReportFormatter::money($this->sales->totals($month)['net_revenue']), icon: 'payments'),
            $this->card('pieces_sold', __('Pieces Sold This Month'), ReportFormatter::count($this->sales->piecesSold($month)), icon: 'shopping_bag'),
            $this->card('available_pieces', __('Available Pieces'), ReportFormatter::count($this->inventory->availablePieceCount($branchId)), icon: 'inventory_2'),
            $this->card('gold_weight_stock', __('Gold Weight in Stock'), ReportFormatter::grams($this->inventory->goldWeightInStock($branchId)), icon: 'scale'),
        ];

        return $dashboard;
    }

    /**
     * Branch-by-branch comparison for All Branches mode.
     *
     * Deliberately contains no action column and no record links: the design's
     * per-row visibility icon is removed because spec 7.4 forbids SuperAdmin
     * drilling through to a record.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function branchComparison(ReportFilter $month): \Illuminate\Support\Collection
    {
        return Branch::query()
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'status'])
            ->map(function (Branch $branch) use ($month): array {
                $scoped = $month->forBranch((int) $branch->id);
                $totals = $this->sales->totals($scoped);

                return [
                    'branch' => $branch->name,
                    'code' => $branch->code,
                    'status' => $branch->status,
                    'revenue' => $totals['net_revenue'],
                    'invoices' => $totals['invoice_count'],
                    'pieces_sold' => $this->sales->piecesSold($scoped),
                    'pieces_in_stock' => $this->inventory->availablePieceCount((int) $branch->id),
                    'gold_weight_stock' => $this->inventory->goldWeightInStock((int) $branch->id),
                ];
            })
            ->sortByDesc('revenue')
            ->values();
    }

    /**
     * Daily revenue for the trailing 30 days, as a vertical column chart.
     *
     * @return array<string, mixed>
     */
    private function revenueTrendChart(?int $branchId): array
    {
        $now = CarbonImmutable::now(config('annigems.timezone'));

        $filter = new ReportFilter(
            from: $now->subDays(29)->startOfDay(),
            to: $now->endOfDay(),
            preset: ReportDatePreset::CUSTOM,
            grouping: ReportGrouping::DAY,
            branchId: $branchId,
        );

        $points = $this->sales->revenueTrend($filter)
            ->map(fn (array $row): array => [
                // Bucket is YYYY-MM-DD; only the day-month is readable on an axis.
                'label' => CarbonImmutable::parse($row['bucket'])->format('d M'),
                'value' => $row['net_revenue'],
                'display' => ReportFormatter::moneyCompact($row['net_revenue']),
            ])
            ->all();

        return ReportResult::chart(__('Revenue — last 30 days'), 'revenue', $points, 'vertical');
    }

    /**
     * A KPI card in the shape x-report.kpi expects.
     *
     * @param  ?array{direction: string, label: string}  $trend
     * @return array<string, mixed>
     */
    private function card(
        string $key,
        string $label,
        string $value,
        ?string $icon = null,
        ?string $hint = null,
        ?array $trend = null,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'icon' => $icon,
            'hint' => $hint,
            'trend' => $trend,
        ];
    }

    /**
     * Filter for a preset range against one branch.
     */
    private function filter(?int $branchId, ReportDatePreset $preset): ReportFilter
    {
        [$from, $to] = $preset->range();

        return new ReportFilter($from, $to, $preset, ReportGrouping::DAY, $branchId);
    }

    /**
     * The last configured aging edge — items older than this need attention.
     */
    private function oldestAgingEdge(): int
    {
        /** @var list<int> $edges */
        $edges = config('annigems.reports.aging_buckets', [30, 60, 90, 180]);

        return $edges === [] ? 180 : (int) max($edges);
    }

    /**
     * Cache a branch dashboard against branch, role, user and date.
     *
     * The user id is part of the key because the Cashier cards are personal
     * ("My Invoices Today"); without it one cashier would read another's count.
     *
     * @param  callable(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function remember(User $user, callable $callback): array
    {
        $role = $user->role instanceof UserRole ? $user->role->value : (string) $user->role;

        $key = implode(':', [
            self::CACHE_PREFIX,
            'branch',
            $user->branch_id ?? 'none',
            $role,
            $user->id,
            $this->today(),
        ]);

        return Cache::remember($this->versioned($key, $user->branch_id), $this->ttl(), $callback);
    }

    /**
     * Fold the branch's cache version into a key so a bump retires it.
     */
    private function versioned(string $key, ?int $branchId): string
    {
        $versionKey = self::CACHE_PREFIX.':version:'.($branchId ?? 'all');

        return $key.':v'.(int) Cache::get($versionKey, 0);
    }

    /**
     * Cache lifetime in seconds, from config.
     */
    private function ttl(): int
    {
        return (int) config('annigems.reports.dashboard_cache_seconds', 120);
    }

    /**
     * Today's date in the display timezone — part of every cache key so the
     * figures roll over at the operator's midnight, not the server's.
     */
    private function today(): string
    {
        return CarbonImmutable::now(config('annigems.timezone'))->toDateString();
    }
}
