<?php

use App\Http\Controllers\Branch\BranchDashboardController;
use App\Http\Controllers\Branch\BranchUserController;
use App\Http\Controllers\Branch\CategoryController;
use App\Http\Controllers\Branch\CreditorController;
use App\Http\Controllers\Branch\CustomerController;
use App\Http\Controllers\Branch\GoldRateController;
use App\Http\Controllers\Branch\InvoiceController;
use App\Http\Controllers\Branch\JewelleryImageController;
use App\Http\Controllers\Branch\JewelleryItemController;
use App\Http\Controllers\Branch\JewelleryLabelController;
use App\Http\Controllers\Branch\MetalTypeController;
use App\Http\Controllers\Branch\ProfileController;
use App\Http\Controllers\Branch\PerformaController;
use App\Http\Controllers\Branch\PosController;
use App\Http\Controllers\Branch\QuotationController;
use App\Http\Controllers\Branch\ReportController;
use App\Http\Controllers\Branch\SettingController;
use App\Http\Controllers\Branch\RoleReferenceController;
use App\Http\Controllers\Branch\DeliveryNoteController;
use App\Http\Controllers\Branch\StoneController;
use App\Http\Controllers\Branch\TrashController;
use App\Http\Controllers\Branch\StoneFolderController;
use App\Http\Controllers\Branch\StoneImportController;
use App\Http\Controllers\Branch\StoneImportHistoryController;
use App\Http\Controllers\Branch\StoneImageController;
use App\Http\Controllers\Branch\StoneIntakeController;
use App\Http\Controllers\Branch\StoneLabelController;
use Illuminate\Support\Facades\Route;

/**
 * Branch-scoped routes (Module 2).
 *
 * All routes require auth, password changed, branch context, and branch membership.
 * SuperAdmin cannot access these routes (EnsureBranchMember).
 */
Route::middleware(['auth', 'password.changed', 'branch.context', 'branch.member'])
    ->prefix('branch')
    ->name('branch.')
    ->group(function (): void {
        Route::get('/dashboard', [BranchDashboardController::class, 'index'])->name('dashboard');
        Route::get('/pos', [PosController::class, 'show'])->name('pos.show');
        Route::get('/pos/numbers', [PosController::class, 'numbers'])->name('pos.numbers');

        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');

        Route::get('/roles', [RoleReferenceController::class, 'index'])->name('roles.index');

        Route::get('/users', [BranchUserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [BranchUserController::class, 'create'])->name('users.create');
        Route::post('/users', [BranchUserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}', [BranchUserController::class, 'show'])->name('users.show');
        Route::get('/users/{user}/edit', [BranchUserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [BranchUserController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/deactivate', [BranchUserController::class, 'deactivate'])->name('users.deactivate');
        Route::post('/users/{user}/activate', [BranchUserController::class, 'activate'])->withTrashed()->name('users.activate');
        Route::post('/users/{user}/reset-password', [BranchUserController::class, 'resetPassword'])->withTrashed()->name('users.reset-password');

        // Stone inventory (quantity-based packets)
        Route::get('/stones', [StoneController::class, 'index'])->name('stones.index');
        Route::get('/stones/create', [StoneController::class, 'create'])->name('stones.create');
        Route::post('/stones', [StoneController::class, 'store'])->name('stones.store');
        Route::get('/stones/search', [StoneController::class, 'search'])->name('stones.search');
        Route::post('/stones/export', [\App\Http\Controllers\Branch\StoneExportController::class, 'download'])->name('stones.export');
        Route::get('/stones/labels/bulk', [StoneLabelController::class, 'bulk'])->name('stones.labels.bulk');
        Route::post('/stones/labels/data', [StoneLabelController::class, 'data'])->name('stones.labels.data');
        // Excel import wizard: read the sheet, confirm columns, then insert.
        Route::get('/stones/import/template', [StoneImportController::class, 'template'])->name('stones.import.template');
        Route::post('/stones/import/stage', [StoneImportController::class, 'stage'])->name('stones.import.stage');
        Route::post('/stones/import/preview', [StoneImportController::class, 'preview'])->name('stones.import.preview');
        Route::post('/stones/import/recheck', [StoneImportController::class, 'recheck'])->name('stones.import.recheck');
        Route::post('/stones/import/begin', [StoneImportController::class, 'begin'])->name('stones.import.begin');
        Route::post('/stones/import/chunk', [StoneImportController::class, 'chunk'])->name('stones.import.chunk');
        Route::post('/stones/import/finish', [StoneImportController::class, 'finish'])->name('stones.import.finish');
        Route::delete('/stones/bulk-delete', [StoneFolderController::class, 'destroyLots'])->name('stones.bulk-destroy');
        Route::delete('/stone-folders/bulk-delete', [StoneFolderController::class, 'destroyMany'])->name('stone-folders.bulk-destroy');
        Route::put('/stone-folders/{stoneCategory}', [StoneFolderController::class, 'update'])->name('stone-folders.update');
        Route::delete('/stone-folders/{stoneCategory}', [StoneFolderController::class, 'destroy'])->name('stone-folders.destroy');
        Route::get('/stones/{stone}', [StoneController::class, 'show'])->name('stones.show');
        Route::get('/stones/{stone}/edit', [StoneController::class, 'edit'])->name('stones.edit');
        Route::put('/stones/{stone}', [StoneController::class, 'update'])->name('stones.update');
        Route::delete('/stones/{stone}', [StoneController::class, 'destroy'])->name('stones.destroy');
        Route::post('/stones/{stone}/deactivate', [StoneController::class, 'deactivate'])->name('stones.deactivate');
        Route::post('/stones/{stone}/activate', [StoneController::class, 'activate'])->name('stones.activate');
        Route::get('/stones/{stone}/label', [StoneLabelController::class, 'show'])->name('stones.label');

        Route::get('/stone-imports', [StoneImportHistoryController::class, 'index'])->name('stone-imports.index');
        Route::get('/stone-imports/{stoneImport}/rejects', [StoneImportHistoryController::class, 'rejects'])->name('stone-imports.rejects');

        Route::get('/stone-images', [StoneImageController::class, 'index'])->name('stone-images.index');
        Route::post('/stone-images/bulk-delete', [StoneImageController::class, 'bulkDestroy'])->name('stone-images.bulk-destroy');
        Route::post('/stone-images/check', [StoneImageController::class, 'check'])->name('stone-images.check');
        Route::post('/stone-images', [StoneImageController::class, 'store'])->name('stone-images.store');
        Route::get('/stone-images/{stoneImage}', [StoneImageController::class, 'show'])->name('stone-images.show');
        Route::patch('/stone-images/{stoneImage}', [StoneImageController::class, 'update'])->name('stone-images.update');
        Route::delete('/stone-images/{stoneImage}', [StoneImageController::class, 'destroy'])->name('stone-images.destroy');

        Route::get('/stone-intakes', [StoneIntakeController::class, 'index'])->name('stone-intakes.index');
        Route::get('/stone-intakes/scan', [StoneIntakeController::class, 'scan'])->name('stone-intakes.scan');
        Route::post('/stone-intakes/lookup', [StoneIntakeController::class, 'lookup'])
            ->middleware('throttle:stone-lookup')
            ->name('stone-intakes.lookup');
        Route::post('/stone-intakes/draft', [StoneIntakeController::class, 'saveDraft'])->name('stone-intakes.draft.save');
        Route::delete('/stone-intakes/draft', [StoneIntakeController::class, 'discardDraft'])->name('stone-intakes.draft.discard');
        Route::post('/stone-intakes', [StoneIntakeController::class, 'store'])->name('stone-intakes.store');
        Route::get('/stone-intakes/{stoneIntake}', [StoneIntakeController::class, 'show'])->name('stone-intakes.show');

        // Global jewellery catalogs (categories / metals / purities)
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::get('/categories/{category}/sub-categories', [CategoryController::class, 'subCategories'])->name('categories.sub-categories');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
        Route::post('/categories/{category}/sub-categories', [CategoryController::class, 'storeSub'])->name('categories.sub-categories.store');
        Route::put('/categories/{category}/sub-categories/{subCategory}', [CategoryController::class, 'updateSub'])->name('categories.sub-categories.update');
        Route::delete('/categories/{category}/sub-categories/{subCategory}', [CategoryController::class, 'destroySub'])->name('categories.sub-categories.destroy');

        Route::get('/metals', [MetalTypeController::class, 'index'])->name('metals.index');
        Route::get('/metals/{metalType}/purities', [MetalTypeController::class, 'purities'])->name('metals.purities');
        Route::post('/metals', [MetalTypeController::class, 'storeMetal'])->name('metals.store');
        Route::put('/metals/{metalType}', [MetalTypeController::class, 'updateMetal'])->name('metals.update');
        Route::delete('/metals/{metalType}', [MetalTypeController::class, 'destroyMetal'])->name('metals.destroy');
        Route::post('/metals/purities', [MetalTypeController::class, 'storePurity'])->name('metals.purities.store');
        Route::put('/metals/purities/{purity}', [MetalTypeController::class, 'updatePurity'])->name('metals.purities.update');
        Route::delete('/metals/purities/{purity}', [MetalTypeController::class, 'destroyPurity'])->name('metals.purities.destroy');

        // Jewellery pieces (one row per physical item)
        Route::get('/jewellery-items', [JewelleryItemController::class, 'index'])->name('jewellery-items.index');
        Route::get('/jewellery-items/create', [JewelleryItemController::class, 'create'])->name('jewellery-items.create');
        Route::post('/jewellery-items', [JewelleryItemController::class, 'store'])->name('jewellery-items.store');
        Route::post('/jewellery-items/labels/data', [JewelleryLabelController::class, 'data'])->name('jewellery-items.labels.data');
        Route::get('/jewellery-items/labels/bulk/{kind}', [JewelleryLabelController::class, 'bulk'])
            ->whereIn('kind', ['barcode', 'qr', 'both'])
            ->name('jewellery-items.labels.bulk');
        Route::post('/jewellery-items/stones/lookup', [JewelleryItemController::class, 'lookupStone'])
            ->middleware('throttle:stone-lookup')
            ->name('jewellery-items.stones.lookup');
        Route::get('/jewellery-items/stones/search', [JewelleryItemController::class, 'searchStones'])->name('jewellery-items.stones.search');
        Route::get('/jewellery-items/categories/{category}/sub-categories', [JewelleryItemController::class, 'subCategories'])->name('jewellery-items.sub-categories');
        Route::get('/jewellery-items/{jewelleryItem}', [JewelleryItemController::class, 'show'])->name('jewellery-items.show');
        Route::get('/jewellery-items/{jewelleryItem}/edit', [JewelleryItemController::class, 'edit'])->name('jewellery-items.edit');
        Route::put('/jewellery-items/{jewelleryItem}', [JewelleryItemController::class, 'update'])->name('jewellery-items.update');
        Route::delete('/jewellery-items/{jewelleryItem}', [JewelleryItemController::class, 'destroy'])->name('jewellery-items.destroy');
        Route::post('/jewellery-items/{jewelleryItem}/status', [JewelleryItemController::class, 'updateStatus'])->name('jewellery-items.status');
        Route::get('/jewellery-items/{jewelleryItem}/label/{kind}', [JewelleryLabelController::class, 'show'])
            ->whereIn('kind', ['barcode', 'qr', 'both'])
            ->name('jewellery-items.label');
        Route::get('/jewellery-items/{jewelleryItem}/label', [JewelleryLabelController::class, 'legacy']);
        Route::get('/jewellery-items/{jewelleryItem}/images/{image}', [JewelleryImageController::class, 'show'])->name('jewellery-items.images.show');
        Route::delete('/jewellery-items/{jewelleryItem}/images/{image}', [JewelleryImageController::class, 'destroy'])->name('jewellery-items.images.destroy');

        // Gold rates (read-only for branch staff)
        Route::get('/gold-rates', [GoldRateController::class, 'index'])->name('gold-rates.index');

        // Customers (global — not branch-scoped)
        Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('/customers/export', [CustomerController::class, 'export'])->name('customers.export');
        Route::get('/customers/search', [CustomerController::class, 'search'])->name('customers.search');
        // Static path must register before {customer} or "lookup" is captured as a uuid.
        Route::get('/customers/lookup', [CustomerController::class, 'lookup'])->name('customers.lookup');
        Route::get('/customers/create', [CustomerController::class, 'create'])->name('customers.create');
        Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
        Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
        Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
        Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
        Route::post('/customers/{customer}/deactivate', [CustomerController::class, 'deactivate'])->name('customers.deactivate');

        // Quotations
        Route::get('/quotations', [QuotationController::class, 'index'])->name('quotations.index');
        Route::get('/quotations/create', [QuotationController::class, 'create'])->name('quotations.create');
        Route::post('/quotations', [QuotationController::class, 'store'])->name('quotations.store');
        Route::post('/quotations/lookup', [QuotationController::class, 'lookup'])
            ->middleware('throttle:quotation-lookup')
            ->name('quotations.lookup');
        Route::get('/quotations/search', [QuotationController::class, 'search'])
            ->name('quotations.search');
        Route::post('/quotations/draft', [QuotationController::class, 'saveDraft'])->name('quotations.draft.save');
        Route::delete('/quotations/draft', [QuotationController::class, 'discardDraft'])->name('quotations.draft.discard');
        Route::post('/quotations/recalculate', [QuotationController::class, 'recalculate'])->name('quotations.recalculate');
        // Static path must register before {quotation} or "invoice" is captured as a uuid.
        Route::post('/quotations/invoice', [QuotationController::class, 'storeInvoice'])->name('quotations.invoice');
        Route::get('/quotations/{quotation}', [QuotationController::class, 'show'])->name('quotations.show');
        Route::get('/quotations/{quotation}/edit', [QuotationController::class, 'edit'])->name('quotations.edit');
        Route::put('/quotations/{quotation}', [QuotationController::class, 'update'])->name('quotations.update');
        Route::post('/quotations/{quotation}/cancel', [QuotationController::class, 'cancel'])->name('quotations.cancel');
        Route::post('/quotations/{quotation}/convert', [QuotationController::class, 'convert'])->name('quotations.convert');
        Route::get('/quotations/{quotation}/print', [QuotationController::class, 'print'])->name('quotations.print');

        // Creditors (branch-scoped parties for performas — not sales customers)
        Route::get('/creditors', [CreditorController::class, 'index'])->name('creditors.index');
        Route::get('/creditors/export', [CreditorController::class, 'export'])->name('creditors.export');
        Route::get('/creditors/search', [CreditorController::class, 'search'])->name('creditors.search');
        Route::get('/creditors/create', [CreditorController::class, 'create'])->name('creditors.create');
        Route::post('/creditors', [CreditorController::class, 'store'])->name('creditors.store');
        Route::get('/creditors/{creditor}', [CreditorController::class, 'show'])->name('creditors.show');
        Route::get('/creditors/{creditor}/edit', [CreditorController::class, 'edit'])->name('creditors.edit');
        Route::put('/creditors/{creditor}', [CreditorController::class, 'update'])->name('creditors.update');
        Route::post('/creditors/{creditor}/deactivate', [CreditorController::class, 'deactivate'])->name('creditors.deactivate');
        Route::post('/creditors/{creditor}/activate', [CreditorController::class, 'activate'])->name('creditors.activate');

        // Performas (credit issue — stock out now, prices on settlement)
        // Payments: pending invoices and delivery notes are paid from their own page.
        Route::post('/invoices/{invoice}/payment', [\App\Http\Controllers\Branch\PaymentController::class, 'invoice'])->name('invoices.payment');
        Route::post('/delivery-notes/{deliveryNote}/payment', [\App\Http\Controllers\Branch\PaymentController::class, 'deliveryNote'])->name('delivery-notes.payment');

        Route::get('/performas', [PerformaController::class, 'index'])->name('performas.index');
        Route::get('/performas/create', [PerformaController::class, 'create'])->name('performas.create');
        Route::post('/performas', [PerformaController::class, 'store'])->name('performas.store');
        Route::post('/performas/lookup', [PerformaController::class, 'lookup'])
            ->middleware('throttle:quotation-lookup')
            ->name('performas.lookup');
        Route::get('/performas/search', [PerformaController::class, 'search'])
            ->name('performas.search');
        Route::get('/performas/{performa}', [PerformaController::class, 'show'])->name('performas.show');
        Route::get('/performas/{performa}/edit', [PerformaController::class, 'edit'])->name('performas.edit');
        Route::put('/performas/{performa}', [PerformaController::class, 'update'])->name('performas.update');
        Route::get('/performas/{performa}/print', [PerformaController::class, 'print'])->name('performas.print');
        Route::get('/performas/{performa}/settle', [PerformaController::class, 'settleForm'])->name('performas.settle');
        Route::post('/performas/{performa}/settle', [PerformaController::class, 'settle'])->name('performas.settle.store');
        Route::post('/performas/{performa}/return', [PerformaController::class, 'returnUnpaid'])->name('performas.return');

        /**
         * Reports (Module 6) — Admin only.
         *
         * One route per verb rather than one per report: the {report} segment
         * is a slug validated against ReportCatalog inside the controller, and
         * an unknown value 404s before any service runs. Every route is gated
         * again by ReportPolicy, which refuses Manager, Cashier, Inventory
         * Staff and SuperAdmin regardless of permissions.
         *
         * Note the download route binds a ReportExport, not a business record —
         * no report route accepts an invoice, quotation, item or stone uuid.
         */
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/exports/{reportExport}', [ReportController::class, 'download'])->name('reports.download');
        Route::get('/reports/{report}', [ReportController::class, 'show'])->name('reports.show');
        Route::get('/reports/{report}/export', [ReportController::class, 'export'])
            // Generating and exporting a report is expensive; a stuck browser
            // tab must not be able to hammer it.
            ->middleware('throttle:report-export')
            ->name('reports.export');

        // Invoices
        // Delivery notes (albarán). Issued from the POS; static paths first.
        Route::get('/delivery-notes', [DeliveryNoteController::class, 'index'])->name('delivery-notes.index');
        Route::post('/delivery-notes', [DeliveryNoteController::class, 'store'])->name('delivery-notes.store');
        Route::get('/delivery-notes/{deliveryNote}', [DeliveryNoteController::class, 'show'])->name('delivery-notes.show');
        Route::get('/delivery-notes/{deliveryNote}/print', [DeliveryNoteController::class, 'print'])->name('delivery-notes.print');
        Route::put('/delivery-notes/{deliveryNote}', [DeliveryNoteController::class, 'update'])->name('delivery-notes.update');
        Route::post('/delivery-notes/{deliveryNote}/cancel', [DeliveryNoteController::class, 'cancel'])->name('delivery-notes.cancel');
        Route::post('/delivery-notes/{deliveryNote}/convert', [DeliveryNoteController::class, 'convert'])->name('delivery-notes.convert');

        Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::get('/invoices/{invoice}/print', [InvoiceController::class, 'print'])->name('invoices.print');
        Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
        Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');

        /**
         * Branch settings (Module 7). Contact edits the Branch row;
         * print/footers write branch_settings. Company settings is the
         * Admin read-only mirror of SuperAdmin's catalog.
         */
        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
        Route::put('/settings/contact', [SettingController::class, 'updateContact'])->name('settings.contact');
        Route::put('/settings/{group}', [SettingController::class, 'update'])->name('settings.update');
        Route::get('/company-settings', [SettingController::class, 'global'])->name('company-settings.show');

        // Soft delete + Trash. No route can delete a record permanently.
        Route::delete('/records/{type}/{uuid}', [TrashController::class, 'destroy'])->name('records.destroy');
        Route::get('/trash', [TrashController::class, 'index'])->name('trash.index');
        Route::post('/trash/{type}/{uuid}/restore', [TrashController::class, 'restore'])->name('trash.restore');
    });
