<?php

namespace App\Support\Reports;

/**
 * ReportCatalog
 *
 * The one authoritative list of every report in the system.
 *
 * Routes, tabs, the reports landing page, the export endpoint, and the filter
 * controls a report offers are all derived from this catalog. That matters for
 * two reasons:
 *
 * 1. A report slug arriving in a URL is checked against this list before
 *    anything else happens, so an unknown or crafted slug 404s rather than
 *    reaching a service.
 * 2. The SuperAdmin catalog is a genuinely separate list. It contains only the
 *    aggregate reports, and it cannot be widened by passing a flag — matching
 *    the separate-services rule in spec 7.4.
 */
class ReportCatalog
{
    /**
     * Report families in display order, for the Admin (branch) surface.
     *
     * Each family holds its reports; each report declares:
     * - label:   tab and page title
     * - summary: one line explaining what the report answers
     * - filters: which secondary filter controls the filter bar should render
     * - chart:   whether this report carries a chart (max one per screen)
     *
     * @return array<string, array{label: string, icon: string, reports: array<string, array{label: string, summary: string, filters: list<string>, chart: bool}>}>
     */
    public static function adminFamilies(): array
    {
        return [
            'sales' => [
                'label' => 'Sales',
                'icon' => 'payments',
                'reports' => [
                    'sales-summary' => [
                        'label' => 'Sales Summary',
                        'summary' => 'Invoice count, gross sales, discount, tax and net revenue for the period.',
                        'filters' => ['grouping', 'payment_method', 'staff'],
                        'chart' => true,
                    ],
                    'sales-by-payment-method' => [
                        'label' => 'By Payment Method',
                        'summary' => 'How customers paid, with each method’s share of revenue.',
                        'filters' => [],
                        'chart' => true,
                    ],
                    'sales-by-category' => [
                        'label' => 'By Category',
                        'summary' => 'Which jewellery categories sell, by revenue and piece count.',
                        'filters' => ['category'],
                        'chart' => true,
                    ],
                    'sales-by-staff' => [
                        'label' => 'By Staff',
                        'summary' => 'Invoice count and revenue per seller, with the role held at the time.',
                        'filters' => [],
                        'chart' => true,
                    ],
                    'gold-weight-sold' => [
                        'label' => 'Gold Weight Sold',
                        'summary' => 'Net grams of metal sold, broken down by purity.',
                        'filters' => ['purity'],
                        'chart' => true,
                    ],
                    'discount-report' => [
                        'label' => 'Discounts',
                        'summary' => 'Discount given per invoice and per staff member, as a share of gross.',
                        'filters' => ['staff'],
                        'chart' => false,
                    ],
                    'tax-collected' => [
                        'label' => 'Tax Collected',
                        'summary' => 'Tax collected per period, for filing.',
                        'filters' => ['grouping'],
                        'chart' => true,
                    ],
                    'voided-invoices' => [
                        'label' => 'Voided Invoices',
                        'summary' => 'Reversed sales with reason and actor. Excluded from every revenue figure.',
                        'filters' => [],
                        'chart' => false,
                    ],
                    'loose-stone-sales' => [
                        'label' => 'Loose Stone Sales',
                        'summary' => 'Stones sold loose rather than set into a piece, by quantity and carat.',
                        'filters' => [],
                        'chart' => false,
                    ],
                ],
            ],
            'quotations' => [
                'label' => 'Quotations',
                'icon' => 'request_quote',
                'reports' => [
                    'quotation-funnel' => [
                        'label' => 'Funnel',
                        'summary' => 'Draft, converted and cancelled counts and values, with the conversion rate.',
                        'filters' => [],
                        'chart' => true,
                    ],
                    'pending-quotations' => [
                        'label' => 'Pending',
                        'summary' => 'Drafts still open, with age in days — old drafts carry stale gold rates.',
                        'filters' => [],
                        'chart' => false,
                    ],
                    'time-to-convert' => [
                        'label' => 'Time to Convert',
                        'summary' => 'How long customers take to decide, from quotation to invoice.',
                        'filters' => [],
                        'chart' => false,
                    ],
                    'cancelled-quotations' => [
                        'label' => 'Cancelled',
                        'summary' => 'Cancelled quotations with value and who cancelled them.',
                        'filters' => [],
                        'chart' => false,
                    ],
                ],
            ],
            'inventory' => [
                'label' => 'Inventory',
                'icon' => 'inventory_2',
                'reports' => [
                    'stock-summary' => [
                        'label' => 'Stock Summary',
                        'summary' => 'Piece count and net weight by lifecycle status.',
                        'filters' => [],
                        'chart' => true,
                    ],
                    'gold-weight-in-stock' => [
                        'label' => 'Gold Weight in Stock',
                        'summary' => 'Gross and net grams held per purity, for available pieces only.',
                        'filters' => [],
                        'chart' => true,
                    ],
                    'category-stock' => [
                        'label' => 'Category Stock',
                        'summary' => 'Piece count and net weight per category and sub-category.',
                        'filters' => ['category'],
                        'chart' => false,
                    ],
                    'aging-stock' => [
                        'label' => 'Aging Stock',
                        'summary' => 'How long available pieces have been held, in configurable buckets.',
                        'filters' => [],
                        'chart' => true,
                    ],
                    'no-movement-items' => [
                        'label' => 'No Movement',
                        'summary' => 'Pieces untouched since creation, oldest first.',
                        'filters' => [],
                        'chart' => false,
                    ],
                ],
            ],
            'stones' => [
                'label' => 'Stones',
                'icon' => 'diamond',
                'reports' => [
                    'stone-stock-summary' => [
                        'label' => 'Stone Stock',
                        'summary' => 'Quantity and carat in hand for every registered stone.',
                        'filters' => [],
                        'chart' => true,
                    ],
                    'stone-low-stock' => [
                        'label' => 'Zero & Low Stock',
                        'summary' => 'Stones out of stock or below the reorder threshold.',
                        'filters' => [],
                        'chart' => false,
                    ],
                    'stone-intake-history' => [
                        'label' => 'Intake History',
                        'summary' => 'Every intake batch with quantity, carat and who performed it.',
                        'filters' => [],
                        'chart' => false,
                    ],
                    'stone-consumption' => [
                        'label' => 'Consumption',
                        'summary' => 'Which stones actually move: used in items, sold loose, or returned.',
                        'filters' => ['stone'],
                        'chart' => false,
                    ],
                    'stone-movement-ledger' => [
                        'label' => 'Movement Ledger',
                        'summary' => 'Full movement trail with quantity before and after each change.',
                        'filters' => ['stone', 'movement_type', 'staff'],
                        'chart' => false,
                    ],
                ],
            ],
            'customers' => [
                'label' => 'Customers',
                'icon' => 'group',
                'reports' => [
                    'top-customers' => [
                        'label' => 'Top Customers',
                        'summary' => 'Highest spending and most frequent customers, across all branches.',
                        'filters' => [],
                        'chart' => true,
                    ],
                    'cross-branch-customers' => [
                        'label' => 'Cross-Branch',
                        'summary' => 'Customers who have purchased at more than one branch.',
                        'filters' => [],
                        'chart' => false,
                    ],
                    'new-customers' => [
                        'label' => 'New Customers',
                        'summary' => 'Customers first registered in the period, with their first purchase.',
                        'filters' => [],
                        'chart' => false,
                    ],
                    'repeat-customers' => [
                        'label' => 'Repeat Customers',
                        'summary' => 'Customers with more than one completed invoice, and the repeat rate.',
                        'filters' => [],
                        'chart' => false,
                    ],
                    'customer-purchase-history' => [
                        'label' => 'Purchase History',
                        'summary' => 'One customer’s purchases across every branch, branch named on each row.',
                        'filters' => ['customer'],
                        'chart' => false,
                    ],
                ],
            ],
            'audit' => [
                'label' => 'Audit',
                'icon' => 'history',
                'reports' => [
                    'item-movement-log' => [
                        'label' => 'Item Movements',
                        'summary' => 'Chain of custody for every jewellery piece on the branch.',
                        'filters' => ['item', 'movement_type', 'staff'],
                        'chart' => false,
                    ],
                    'stone-movement-log' => [
                        'label' => 'Stone Movements',
                        'summary' => 'Stone quantity changes with before and after values.',
                        'filters' => ['stone', 'movement_type', 'staff'],
                        'chart' => false,
                    ],
                    'user-activity' => [
                        'label' => 'User Activity',
                        'summary' => 'Who created, edited or deleted what, with the role held at the time.',
                        'filters' => ['staff'],
                        'chart' => false,
                    ],
                    'gold-rate-history' => [
                        'label' => 'Gold Rate History',
                        'summary' => 'Every rate change with purity, rate, effective date and who set it.',
                        'filters' => ['purity'],
                        'chart' => false,
                    ],
                    'document-audit' => [
                        'label' => 'Invoice & Quotation Audit',
                        'summary' => 'Every creation, conversion, cancellation and void, with actor and role.',
                        'filters' => [],
                        'chart' => false,
                    ],
                ],
            ],
        ];
    }

    /**
     * Report families for the SuperAdmin surface.
     *
     * A deliberately shorter list. Every entry is an aggregate; nothing here
     * names or reaches an individual invoice, quotation, item, stone or
     * customer purchase. Customer Purchase History, Pending Quotations, the
     * per-invoice discount list, the no-movement item list, and the movement
     * ledgers are all absent for that reason — they are record listings.
     *
     * @return array<string, array{label: string, icon: string, reports: array<string, array{label: string, summary: string, filters: list<string>, chart: bool}>}>
     */
    public static function superAdminFamilies(): array
    {
        $admin = self::adminFamilies();

        $allowed = [
            'sales' => [
                'sales-summary',
                'sales-by-payment-method',
                'sales-by-category',
                'sales-by-staff',
                'gold-weight-sold',
                'discount-report',
                'tax-collected',
                'voided-invoices',
            ],
            'quotations' => [
                'quotation-funnel',
                'cancelled-quotations',
            ],
            'inventory' => [
                'stock-summary',
                'gold-weight-in-stock',
                'category-stock',
                'aging-stock',
            ],
            'stones' => [
                'stone-stock-summary',
                'stone-low-stock',
                'stone-intake-history',
                'stone-consumption',
            ],
            'customers' => [
                'top-customers',
                'cross-branch-customers',
                'new-customers',
                'repeat-customers',
            ],
            'audit' => [
                'user-activity',
                'gold-rate-history',
            ],
        ];

        $families = [];

        foreach ($allowed as $familyKey => $reportKeys) {
            $family = $admin[$familyKey];
            $reports = [];

            foreach ($reportKeys as $reportKey) {
                $report = $family['reports'][$reportKey];
                // SuperAdmin filters by branch, date and grouping only; the
                // record-reference filters are stripped, not merely unused.
                $report['filters'] = array_values(array_intersect($report['filters'], ['grouping', 'payment_method']));
                $reports[$reportKey] = $report;
            }

            $families[$familyKey] = [
                'label' => $family['label'],
                'icon' => $family['icon'],
                'reports' => $reports,
            ];
        }

        // The two reports that only make sense above a single branch.
        $families['comparison'] = [
            'label' => 'Cross-Branch',
            'icon' => 'compare_arrows',
            'reports' => [
                'branch-comparison' => [
                    'label' => 'Branch Comparison',
                    'summary' => 'Every branch side by side on revenue, invoices, pieces sold and stock.',
                    'filters' => [],
                    'chart' => true,
                ],
                'stock-distribution' => [
                    'label' => 'Stock Distribution',
                    'summary' => 'How pieces, gold weight and stone quantity are spread across branches.',
                    'filters' => [],
                    'chart' => true,
                ],
            ],
        ];

        return $families;
    }

    /**
     * Definition for one Admin report, or null when the slug is unknown.
     *
     * @return ?array{key: string, family: string, family_label: string, label: string, summary: string, filters: list<string>, chart: bool}
     */
    public static function findAdminReport(string $key): ?array
    {
        return self::find(self::adminFamilies(), $key);
    }

    /**
     * Definition for one SuperAdmin report, or null when the slug is unknown
     * *or* is an Admin-only record listing.
     *
     * @return ?array{key: string, family: string, family_label: string, label: string, summary: string, filters: list<string>, chart: bool}
     */
    public static function findSuperAdminReport(string $key): ?array
    {
        return self::find(self::superAdminFamilies(), $key);
    }

    /**
     * Look a report up inside a family map.
     *
     * @param  array<string, array{label: string, icon: string, reports: array<string, array<string, mixed>>}>  $families
     * @return ?array<string, mixed>
     */
    private static function find(array $families, string $key): ?array
    {
        foreach ($families as $familyKey => $family) {
            if (! isset($family['reports'][$key])) {
                continue;
            }

            return array_merge($family['reports'][$key], [
                'key' => $key,
                'family' => $familyKey,
                'family_label' => $family['label'],
            ]);
        }

        return null;
    }
}
