<?php

/**
 * Annigems application configuration.
 *
 * Central place for timezone/currency defaults and password policy display
 * values so views and services never hardcode them.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Display timezone / currency
    |--------------------------------------------------------------------------
    | Used in views and reports. Override via .env when deploying.
    */
    // Display timezone / currency: runtime values live in the settings
    // catalog (timezone, currency_code, currency_symbol). These remain as
    // bootstrap fallbacks before the settings table exists.
    'timezone' => env('ANNIGEMS_TIMEZONE', 'Asia/Karachi'),
    'currency' => env('ANNIGEMS_CURRENCY', 'PKR'),
    'currency_symbol' => env('ANNIGEMS_CURRENCY_SYMBOL', 'Rs'),

    /*
    |--------------------------------------------------------------------------
    | Printed document identity (quotations / invoices)
    |--------------------------------------------------------------------------
    | Rendered on the document sheet header and footer. Branch name and address
    | come from the branch record; everything company-wide lives here so the
    | Blade views never hardcode brand copy or terms.
    */
    'documents' => [
        // brand_name moved to setting('company_name'). Kept as a fallback
        // for print views if settings have not been seeded yet.
        'brand_name' => env('ANNIGEMS_BRAND_NAME', 'Aani Gemas'),
        'brand_tagline' => env('ANNIGEMS_BRAND_TAGLINE', 'Luxury Inventory'),
        // Single letter shown in the header mark tile.
        'brand_mark' => env('ANNIGEMS_BRAND_MARK', 'A'),
        // How long a quotation stays valid; drives the "Valid until" meta row.
        'quotation_validity_days' => (int) env('ANNIGEMS_QUOTATION_VALIDITY_DAYS', 7),
        'quotation_terms' => env(
            'ANNIGEMS_QUOTATION_TERMS',
            'This is a price estimate and not a tax invoice. Gold and stone rates are subject to change; the final amount is confirmed at the time of purchase.'
        ),
        'invoice_terms' => env(
            'ANNIGEMS_INVOICE_TERMS',
            'All sales are final. Exchanges are accepted within 7 days against this invoice, provided the item is unworn and in its original condition.'
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Password policy
    |--------------------------------------------------------------------------
    | Fixed shop-wide rules. Not SuperAdmin-editable.
    */
    'password' => [
        'min_length' => 8,
        'require_letters' => true,
        'require_numbers' => true,
        'require_mixed_case' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | SuperAdmin seed credentials (read by SuperAdminSeeder)
    |--------------------------------------------------------------------------
    */
    'superadmin' => [
        'name' => env('SUPERADMIN_NAME', 'Super Admin'),
        'email' => env('SUPERADMIN_EMAIL'),
        'password' => env('SUPERADMIN_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Login rate limiting
    |--------------------------------------------------------------------------
    | Fixed shop-wide rules. Not SuperAdmin-editable.
    | Decay is the lockout window in seconds (15 minutes).
    */
    'login_max_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 5),
    'login_decay_seconds' => (int) env('LOGIN_DECAY_SECONDS', 900),

    /*
    |--------------------------------------------------------------------------
    | Branch sidebar navigation (Module 2+)
    |--------------------------------------------------------------------------
    | Grouped module-wise. A node with `children` is an accordion parent
    | (click to expand); a node with `route` is a leaf. Permission, gate and
    | policy keys are unchanged — a parent is shown when any child is visible.
    | enabled=false shows a disabled stub for future modules.
    */
    'branch_nav' => [
        [
            'id' => 'dashboard',
            'label' => 'Dashboard',
            'route' => 'branch.dashboard',
            'icon' => 'dashboard',
            'enabled' => true,
        ],
        [
            'id' => 'pos',
            'label' => 'POS',
            'route' => 'branch.pos.show',
            'icon' => 'point_of_sale',
            'permission' => 'quotations.create',
            'enabled' => true,
        ],
        [
            'id' => 'inventory',
            'label' => 'Inventory',
            'icon' => 'inventory_2',
            'children' => [
                [
                    'label' => 'Jewellery',
                    'route' => 'branch.jewellery-items.index',
                    'icon' => 'watch',
                    'permission' => 'jewellery.view',
                    'enabled' => true,
                ],
                [
                    'label' => 'Categories',
                    'route' => 'branch.categories.index',
                    'icon' => 'category',
                    'permission' => 'categories.view',
                    'enabled' => true,
                ],
                [
                    'label' => 'Metals',
                    'route' => 'branch.metals.index',
                    'icon' => 'brightness_high',
                    'permission' => 'categories.view',
                    'enabled' => true,
                ],
            ],
        ],
        [
            'id' => 'stones',
            'label' => 'Stones',
            'icon' => 'diamond',
            'children' => [
                [
                    'label' => 'Packets',
                    'route' => 'branch.stones.index',
                    'icon' => 'diamond',
                    'permission' => 'stones.view',
                    'enabled' => true,
                ],
                [
                    'label' => 'Intake',
                    'route' => 'branch.stone-intakes.scan',
                    'icon' => 'barcode_scanner',
                    'permission' => 'stones.intake',
                    'enabled' => true,
                ],
                [
                    'label' => 'Import History',
                    'route' => 'branch.stone-imports.index',
                    'icon' => 'history',
                    'permission' => 'stones.create',
                    'enabled' => true,
                ],
                [
                    'label' => 'Images',
                    'route' => 'branch.stone-images.index',
                    'icon' => 'folder',
                    'permission' => 'stones.images',
                    'enabled' => true,
                ],
            ],
        ],
        [
            'id' => 'sales',
            'label' => 'Sales',
            'icon' => 'point_of_sale',
            'children' => [
                [
                    'label' => 'Quotations',
                    'route' => 'branch.quotations.index',
                    'icon' => 'request_quote',
                    'permission' => 'quotations.view',
                    'enabled' => true,
                ],
                [
                    'label' => 'Invoices',
                    'route' => 'branch.invoices.index',
                    'icon' => 'receipt_long',
                    'permission' => 'invoices.view',
                    'enabled' => true,
                ],
                [
                    'label' => 'Delivery Notes',
                    'route' => 'branch.delivery-notes.index',
                    'icon' => 'local_shipping',
                    'permission' => 'delivery_notes.view',
                    'enabled' => true,
                ],
                [
                    'label' => 'Customers',
                    'route' => 'branch.customers.index',
                    'icon' => 'group',
                    'permission' => 'customers.view',
                    'enabled' => true,
                ],
            ],
        ],
        [
            'id' => 'credit',
            'label' => 'Performas',
            'icon' => 'assignment',
            'children' => [
                [
                    'label' => 'Performas',
                    'route' => 'branch.performas.index',
                    'icon' => 'assignment',
                    'permission' => 'performas.view',
                    'enabled' => true,
                ],
                [
                    'label' => 'Creditors',
                    'route' => 'branch.creditors.index',
                    'icon' => 'handshake',
                    'permission' => 'creditors.view',
                    'enabled' => true,
                ],
            ],
        ],
        [
            'id' => 'staff',
            'label' => 'Staff',
            'icon' => 'badge',
            'children' => [
                [
                    'label' => 'Users',
                    'route' => 'branch.users.index',
                    'icon' => 'group',
                    'permission' => 'users.view',
                    'enabled' => true,
                ],
                [
                    'label' => 'Roles',
                    'route' => 'branch.roles.index',
                    'icon' => 'admin_panel_settings',
                    'policy' => 'viewRoleReference',
                    'enabled' => true,
                ],
            ],
        ],
        [
            'id' => 'gold-rates',
            'label' => 'Gold Rates',
            'route' => 'branch.gold-rates.index',
            'icon' => 'brightness_high',
            'permission' => 'gold_rates.view',
            'enabled' => true,
        ],
        [
            'id' => 'reports',
            'label' => 'Reports',
            'route' => 'branch.reports.index',
            'icon' => 'analytics',
            // Gated by the model-less report ability rather than the bare
            // permission: SuperAdmin holds every permission by design and must
            // not reach a branch's detailed reports.
            'gate' => 'viewAdminReports',
            'enabled' => true,
        ],
        [
            'id' => 'settings',
            'label' => 'Settings',
            'icon' => 'settings',
            'children' => [
                [
                    'label' => 'Branch',
                    'route' => 'branch.settings.index',
                    'icon' => 'settings',
                    'gate' => 'viewBranchSettings',
                    'enabled' => true,
                ],
                [
                    'label' => 'Company',
                    'route' => 'branch.company-settings.show',
                    'icon' => 'apartment',
                    'gate' => 'viewGlobalSettings',
                    'enabled' => true,
                ],
            ],
        ],
        [
            'id' => 'trash',
            'label' => 'Trash',
            'route' => 'branch.trash.index',
            'icon' => 'delete',
            'permission' => 'trash.view',
            'enabled' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SuperAdmin sidebar navigation
    |--------------------------------------------------------------------------
    | Same tree shape as branch_nav. SuperAdmin routes are already gated by
    | the superadmin middleware, so these nodes do not repeat permission keys.
    */
    'superadmin_nav' => [
        [
            'id' => 'dashboard',
            'label' => 'Dashboard',
            'route' => 'superadmin.dashboard',
            'icon' => 'dashboard',
            'enabled' => true,
        ],
        [
            'id' => 'organisation',
            'label' => 'Organisation',
            'icon' => 'apartment',
            'children' => [
                [
                    'label' => 'Branches',
                    'route' => 'superadmin.branches.index',
                    'icon' => 'store',
                    'enabled' => true,
                ],
                [
                    'label' => 'Users',
                    'route' => 'superadmin.users.index',
                    'icon' => 'group',
                    'enabled' => true,
                ],
            ],
        ],
        [
            'id' => 'gold-rates',
            'label' => 'Gold Rates',
            'route' => 'superadmin.gold-rates.index',
            'icon' => 'brightness_high',
            'enabled' => true,
        ],
        [
            'id' => 'tax-rates',
            'label' => 'Tax Rates',
            'route' => 'superadmin.tax-rates.index',
            'icon' => 'percent',
            'enabled' => true,
        ],
        [
            'id' => 'reports',
            'label' => 'Reports',
            'route' => 'superadmin.reports.index',
            'icon' => 'monitoring',
            'enabled' => true,
        ],
        [
            'id' => 'settings',
            'label' => 'Global Settings',
            'route' => 'superadmin.settings.index',
            'icon' => 'settings',
            'enabled' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | USB barcode scanner (keyboard wedge)
    |--------------------------------------------------------------------------
    | Timing and strip rules are fixed shop-wide. Not SuperAdmin-editable.
    */
    'scanner' => [
        'inter_keystroke_ms' => (int) env('ANNIGEMS_SCANNER_INTER_KEY_MS', 80),
        'idle_refocus_ms' => (int) env('ANNIGEMS_SCANNER_IDLE_REFOCUS_MS', 2500),
        'prefix' => env('ANNIGEMS_SCANNER_PREFIX', ''),
        'suffix' => env('ANNIGEMS_SCANNER_SUFFIX', ''),
        'terminator' => env('ANNIGEMS_SCANNER_TERMINATOR', 'both'),
        'terminators' => ['Enter', 'Tab'],
        'beep_enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | List page size
    |--------------------------------------------------------------------------
    | The "Show 10 / 20 / 50 / 100 / All" choice under a list. `all_max` caps
    | "All" so a very large table cannot be asked for in one page.
    */
    'pagination' => [
        'default' => (int) env('ANNIGEMS_PER_PAGE', 20),
        'options' => [10, 20, 50, 100],
        'all_max' => (int) env('ANNIGEMS_PER_PAGE_ALL_MAX', 5000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stone inventory bounds
    |--------------------------------------------------------------------------
    */
    'stones' => [
        'carat_max' => (float) env('ANNIGEMS_STONE_CARAT_MAX', 9999999),
        'intake_quantity_max' => (int) env('ANNIGEMS_STONE_INTAKE_QTY_MAX', 10000),
        'draft_debounce_ms' => (int) env('ANNIGEMS_STONE_DRAFT_DEBOUNCE_MS', 800),
        'lookup_throttle_per_minute' => (int) env('ANNIGEMS_STONE_LOOKUP_THROTTLE', 180),
        'image_max_kilobytes' => (int) env('ANNIGEMS_STONE_IMAGE_KB', 5120),
        'import_max_kilobytes' => (int) env('ANNIGEMS_STONE_IMPORT_KB', 10240),
        // Rows sent per request while inserting, so the progress bar can move.
        'import_chunk_size' => (int) env('ANNIGEMS_STONE_IMPORT_CHUNK', 100),
        'import_chunk_max' => (int) env('ANNIGEMS_STONE_IMPORT_CHUNK_MAX', 500),
        // How long an uploaded workbook stays on disk while the operator works
        // through the mapping and preview steps before it is pruned.
        'import_retention_hours' => (int) env('ANNIGEMS_STONE_IMPORT_RETENTION_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Jewellery pieces (one row per physical item)
    |--------------------------------------------------------------------------
    */
    'jewellery' => [
        'gross_weight_max' => (float) env('ANNIGEMS_JEWELLERY_GROSS_MAX', 5000),
        'image_max_kilobytes' => (int) env('ANNIGEMS_JEWELLERY_IMAGE_KB', 2048),
        'image_max_count' => (int) env('ANNIGEMS_JEWELLERY_IMAGE_MAX', 8),
        'public_throttle_per_minute' => (int) env('ANNIGEMS_PUBLIC_ITEM_THROTTLE', 30),
        'allocation_quantity_max' => (int) env('ANNIGEMS_JEWELLERY_STONE_QTY_MAX', 10000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gold rates (Module 5)
    |--------------------------------------------------------------------------
    */
    'gold_rates' => [
        'deviation_warn_percent' => (int) env('ANNIGEMS_GOLD_RATE_DEVIATION_WARN', 20),
        'rate_max' => (float) env('ANNIGEMS_GOLD_RATE_MAX', 999999.99),
    ],

    /*
    |--------------------------------------------------------------------------
    | Quotations (Module 5)
    |--------------------------------------------------------------------------
    */
    'quotations' => [
        'draft_debounce_ms' => (int) env('ANNIGEMS_QUOTATION_DRAFT_DEBOUNCE_MS', 800),
        'lookup_throttle_per_minute' => (int) env('ANNIGEMS_QUOTATION_LOOKUP_THROTTLE', 180),
    ],

    /*
    |--------------------------------------------------------------------------
    | Invoices (Module 5)
    |--------------------------------------------------------------------------
    */
    'invoices' => [
        'verify_throttle_per_minute' => (int) env('ANNIGEMS_INVOICE_VERIFY_THROTTLE', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reports & dashboard statistics (Module 6)
    |--------------------------------------------------------------------------
    | Reports derive everything from Modules 1-5 data and capture nothing new.
    | Thresholds and bucket edges live here so a business rule change never
    | requires editing a service or a Blade view.
    */
    'reports' => [
        // Widest date range a single report may span. Anything wider is
        // rejected with a message asking for a narrower range, because an
        // unbounded range is how a report screen takes the database down.
        'max_range_days' => (int) env('ANNIGEMS_REPORT_MAX_RANGE_DAYS', 731),

        // Rows returned per page on detailed report tables.
        'per_page' => (int) env('ANNIGEMS_REPORT_PER_PAGE', 50),

        // Dashboard statistics cache lifetime, in seconds. Deliberately short:
        // a dashboard that is a minute stale is acceptable, one that contradicts
        // the invoice list is not.
        'dashboard_cache_seconds' => (int) env('ANNIGEMS_DASHBOARD_CACHE_SECONDS', 120),

        // Stock aging bucket upper edges in days. The final bucket is open-ended
        // and is generated from the last edge. Not SuperAdmin-editable.
        'aging_buckets' => [30, 60, 90, 180],

        'low_stone_threshold' => (int) env('ANNIGEMS_LOW_STONE_THRESHOLD', 5),

        // Same idea for a lot that is weighed in grams rather than counted in pieces.
        'low_stone_weight_threshold' => (float) env('ANNIGEMS_LOW_STONE_WEIGHT_THRESHOLD', 10),

        // Items shown in "top N" style dashboard and report lists.
        'top_list_limit' => (int) env('ANNIGEMS_REPORT_TOP_LIMIT', 10),

        // Bars shown on a chart before older periods are grouped away.
        'chart_max_points' => (int) env('ANNIGEMS_REPORT_CHART_POINTS', 30),

        'export' => [
            // Above this row count a CSV export is handed to a queued job
            // instead of streamed inline, so the browser never waits on it.
            'queue_row_threshold' => (int) env('ANNIGEMS_EXPORT_QUEUE_THRESHOLD', 5000),
            // Exports are expensive; limit how many a user may trigger.
            'throttle_per_minute' => (int) env('ANNIGEMS_EXPORT_THROTTLE', 6),
            // Disk holding generated files for queued exports.
            'disk' => env('ANNIGEMS_EXPORT_DISK', 'local'),
        ],
    ],
];
