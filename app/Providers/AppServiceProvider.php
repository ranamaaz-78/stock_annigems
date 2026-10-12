<?php

namespace App\Providers;

use App\Models\Creditor;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JewelleryItem;
use App\Models\Performa;
use App\Models\Quotation;
use App\Models\Scopes\BranchScope;
use App\Models\Stone;
use App\Models\StoneImage;
use App\Models\StoneImport;
use App\Models\StoneIntake;
use App\Models\User;
use App\Policies\DashboardPolicy;
use App\Policies\ReportPolicy;
use App\Policies\SettingPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
         if (config('app.env') === 'production') {
        URL::forceScheme('https');
    }

        // Page links use the Annigems look, not Laravel's stock Tailwind colours.
        Paginator::defaultView('vendor.pagination.annigems');
        Paginator::defaultSimpleView('vendor.pagination.annigems-simple');

        Gate::define('permission', fn (User $user, string $name): bool => $user->hasPermission($name));

        RateLimiter::for('stone-lookup', function (Request $request) {
            return Limit::perMinute((int) config('annigems.stones.lookup_throttle_per_minute', 180))
                ->by('stone-lookup:'.($request->user()?->id ?? $request->ip()));
        });

        RateLimiter::for('public-item', function (Request $request) {
            return Limit::perMinute((int) config('annigems.jewellery.public_throttle_per_minute', 30))
                ->by('public-item:'.$request->ip());
        });

        RateLimiter::for('quotation-lookup', function (Request $request) {
            return Limit::perMinute((int) config('annigems.quotations.lookup_throttle_per_minute', 180))
                ->by('quotation-lookup:'.($request->user()?->id ?? $request->ip()));
        });

        RateLimiter::for('invoice-verify', function (Request $request) {
            return Limit::perMinute((int) config('annigems.invoices.verify_throttle_per_minute', 30))
                ->by('invoice-verify:'.$request->ip());
        });

        /**
         * Report export limiter (spec 10.4).
         *
         * Generating an export re-runs every aggregate behind the report, so a
         * held-down download button is a genuine load event. Keyed on the user
         * rather than the IP because a branch shares one office connection and
         * one cashier must not lock out the rest.
         */
        RateLimiter::for('report-export', function (Request $request) {
            return Limit::perMinute((int) config('annigems.reports.export.throttle_per_minute', 6))
                ->by('report-export:'.($request->user()?->id ?? $request->ip()));
        });

        $dashboardPolicy = new DashboardPolicy;
        Gate::define('viewBranch', fn (User $user): bool => $dashboardPolicy->viewBranch($user));
        Gate::define('viewSalesKpis', fn (User $user): bool => $dashboardPolicy->viewSalesKpis($user));
        Gate::define('viewInventoryKpis', fn (User $user): bool => $dashboardPolicy->viewInventoryKpis($user));

        /**
         * Module 6 report gates. ReportPolicy has no backing model, so its
         * abilities are registered explicitly rather than auto-discovered.
         */
        $reportPolicy = new ReportPolicy;
        Gate::define('viewAdminReports', fn (User $user): bool => $reportPolicy->viewAdminReports($user));
        Gate::define('exportAdminReports', fn (User $user): bool => $reportPolicy->exportAdminReports($user));
        Gate::define('viewSuperAdminReports', fn (User $user): bool => $reportPolicy->viewSuperAdminReports($user));
        Gate::define('exportSuperAdminReports', fn (User $user): bool => $reportPolicy->exportSuperAdminReports($user));
        Gate::define('viewDashboard', fn (User $user): bool => $reportPolicy->viewDashboard($user));

        /**
         * Module 7 settings gates. SettingPolicy has no backing model.
         * SuperAdmin holds every permission, so each ability also asserts role.
         */
        $settingPolicy = new SettingPolicy;
        Gate::define('viewGlobalSettings', fn (User $user): bool => $settingPolicy->viewGlobalSettings($user));
        Gate::define('manageGlobalSettings', fn (User $user): bool => $settingPolicy->manageGlobalSettings($user));
        Gate::define('viewSettingsHistory', fn (User $user): bool => $settingPolicy->viewSettingsHistory($user));
        Gate::define('viewBranchSettings', fn (User $user): bool => $settingPolicy->viewBranchSettings($user));
        Gate::define('manageBranchSettings', fn (User $user): bool => $settingPolicy->manageBranchSettings($user));

        /**
         * Custom user route binding: resolve by uuid without branch scope,
         * then return 403 (not 404) when a branch user targets another branch.
         */
        Route::bind('user', function (string $value): User {
            $model = User::query()
                ->withoutGlobalScope(BranchScope::class)
                ->withTrashed()
                ->where('uuid', $value)
                ->first();

            if (! $model) {
                abort(404);
            }

            $auth = auth()->user();

            if ($auth && ! $auth->isSuperAdmin() && $auth->branch_id !== null) {
                if ($model->branch_id !== $auth->branch_id) {
                    abort(403);
                }
            }

            return $model;
        });

        $this->bindBranchScoped('stone', Stone::class);
        $this->bindBranchScoped('stoneImage', StoneImage::class);
        $this->bindBranchScoped('stoneIntake', StoneIntake::class);
        $this->bindBranchScoped('stoneImport', StoneImport::class);
        $this->bindBranchScoped('jewelleryItem', JewelleryItem::class);
        $this->bindBranchScoped('quotation', Quotation::class);
        $this->bindBranchScoped('invoice', Invoice::class);
        $this->bindBranchScoped('performa', Performa::class);
        $this->bindBranchScoped('creditor', Creditor::class);

        /**
         * Customers are global — bind by uuid without branch check.
         */
        Route::bind('customer', function (string $value): Customer {
            $model = Customer::query()->where('uuid', $value)->first();

            if (! $model) {
                abort(404);
            }

            return $model;
        });
    }

    /**
     * Resolve a branch-owned model by uuid. Missing → 404; other branch → 403.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $class
     */
    private function bindBranchScoped(string $parameter, string $class): void
    {
        Route::bind($parameter, function (string $value) use ($class) {
            $model = $class::query()
                ->withoutGlobalScope(BranchScope::class)
                ->where('uuid', $value)
                ->first();

            if (! $model) {
                abort(404);
            }

            $auth = auth()->user();

            if ($auth && ! $auth->isSuperAdmin() && $auth->branch_id !== null) {
                if ((int) $model->branch_id !== (int) $auth->branch_id) {
                    abort(403);
                }
            }

            return $model;
        });
    }
}
