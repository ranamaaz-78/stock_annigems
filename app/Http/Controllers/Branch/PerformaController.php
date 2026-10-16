<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\ReturnPerformaRequest;
use App\Http\Requests\Branch\SettlePerformaRequest;
use App\Http\Requests\Branch\StorePerformaRequest;
use App\Http\Requests\Branch\UpdatePerformaRequest;
use App\Models\Performa;
use App\Models\TaxRate;
use App\Repositories\GoldRateRepository;
use App\Services\PerformaService;
use App\Services\PerformaSettlementService;
use App\Services\SalesLookupService;
use App\Services\TaxRateService;
use App\Support\Settings\DocumentSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * PerformaController
 *
 * HTTP layer for credit issue, settlement, and unpaid returns.
 */
class PerformaController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private PerformaService $performaService,
        private PerformaSettlementService $settlementService,
        private SalesLookupService $lookupService,
        private GoldRateRepository $goldRateRepository,
        private TaxRateService $taxRates,
    ) {}

    /**
     * Performa list.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Performa::class);

        return view('branch.performas.index', [
            'performas' => $this->performaService->paginate($request->only(['status', 'from', 'to', 'search'])),
            'filters' => $request->only(['status', 'from', 'to', 'search']),
        ]);
    }

    /**
     * Performas are issued from the POS now. This old address sends people
     * there with Performa already chosen; ?creditor=uuid carries over so the
     * creditor is filled in.
     */
    public function create(Request $request): RedirectResponse
    {
        $this->authorize('create', Performa::class);

        return redirect()->route('branch.pos.show', array_filter([
            'kind' => 'performa',
            'creditor' => $request->query('creditor'),
        ]));
    }

    /**
     * Persist a new performa and deduct stock.
     */
    public function store(StorePerformaRequest $request): RedirectResponse|JsonResponse
    {
        try {
            $performa = $this->performaService->issue($request->validated(), $request->user());
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return $this->jsonFailure($e->getMessage(), $e->errors(), 422);
            }

            throw $e;
        } catch (Throwable $e) {
            Log::error('Performa issue failed.', [
                'user_id' => $request->user()?->id,
                'exception' => $e,
            ]);

            $message = 'The performa could not be saved. Please try again or contact support.';

            if ($request->expectsJson()) {
                return $this->jsonFailure($message, [], 500);
            }

            return back()->withInput()->withErrors(['performa' => $message]);
        }

        if ($request->expectsJson()) {
            return $this->jsonSuccess('Performa '.$performa->performa_number.' issued.', [
                'uuid' => $performa->uuid,
                'number' => $performa->performa_number,
                'print_url' => route('branch.performas.print', $performa),
            ]);
        }

        return redirect()->route('branch.performas.show', $performa)->with('success', 'Performa issued.');
    }

    /**
     * Editing happens in the POS, which opens with this performa loaded.
     */
    public function edit(Performa $performa): RedirectResponse
    {
        $this->authorize('update', $performa);

        return redirect()->route('branch.pos.show', ['performa' => $performa->uuid]);
    }

    /**
     * Save an edited performa: lines nobody paid for are replaced, stock follows.
     */
    public function update(UpdatePerformaRequest $request, Performa $performa): RedirectResponse|JsonResponse
    {
        try {
            $performa = $this->performaService->update($performa, $request->validated(), $request->user());
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return $this->jsonFailure($e->getMessage(), $e->errors(), 422);
            }

            throw $e;
        } catch (Throwable $e) {
            Log::error('Performa update failed.', [
                'user_id' => $request->user()?->id,
                'exception' => $e,
            ]);

            $message = 'The performa could not be saved. Please try again or contact support.';

            if ($request->expectsJson()) {
                return $this->jsonFailure($message, [], 500);
            }

            return back()->withInput()->withErrors(['performa' => $message]);
        }

        if ($request->expectsJson()) {
            return $this->jsonSuccess('Performa '.$performa->performa_number.' updated.', [
                'uuid' => $performa->uuid,
                'show_url' => route('branch.performas.show', $performa),
                'number' => $performa->performa_number,
                'print_url' => route('branch.performas.print', $performa),
            ]);
        }

        return redirect()->route('branch.performas.show', $performa)->with('success', 'Performa updated.');
    }

    /**
     * Performa detail.
     */
    public function show(Performa $performa): View
    {
        $this->authorize('view', $performa);

        return view('branch.performas.show', [
            'performa' => $this->performaService->loadDetail($performa),
        ]);
    }

    /**
     * Printable quantity memo.
     */
    public function print(Performa $performa): View
    {
        $this->authorize('view', $performa);

        return view('branch.performas.print', [
            'performa' => $this->performaService->loadDetail($performa),
        ]);
    }

    /**
     * Settlement workspace — outstanding lines only.
     */
    public function settleForm(Performa $performa): View
    {
        $this->authorize('settle', $performa);

        $tax = DocumentSettings::tax();

        return view('branch.performas.settle', [
            'performa' => $this->performaService->loadDetail($performa),
            'lines' => $this->performaService->outstandingPayload($performa),
            'rateMap' => $this->goldRateRepository->currentRateMap(),
            'paymentMethods' => \App\Enums\PaymentMethod::options(),
            'currencySymbol' => setting('currency_symbol', 'Rs'),
            'currencyDecimals' => (int) setting('currency_decimals', 2),
            'taxEnabled' => $tax['enabled'],
            'taxType' => $tax['type'],
            'taxRate' => $tax['rate'],
            'taxLabel' => $tax['label'],
            // The cashier picks the IVA (default preselected) and an optional recargo.
            'ivaRates' => $this->taxRates->active(TaxRate::KIND_IVA)->map(fn (TaxRate $r): array => ['uuid' => $r->uuid, 'name' => $r->name, 'rate' => (float) $r->rate])->values()->all(),
            'recargoRates' => $this->taxRates->active(TaxRate::KIND_RECARGO)->map(fn (TaxRate $r): array => ['uuid' => $r->uuid, 'name' => $r->name, 'rate' => (float) $r->rate])->values()->all(),
            'defaultIvaUuid' => $tax['uuid'],
        ]);
    }

    /**
     * Take payment and issue a completed invoice.
     */
    public function settle(SettlePerformaRequest $request, Performa $performa): RedirectResponse|JsonResponse
    {
        try {
            $invoice = $this->settlementService->settle($performa, $request->validated(), $request->user());
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return $this->jsonFailure($e->getMessage(), $e->errors(), 422);
            }

            throw $e;
        } catch (Throwable $e) {
            Log::error('Performa settlement failed.', [
                'performa_id' => $performa->id,
                'user_id' => $request->user()?->id,
                'exception' => $e,
            ]);

            $message = 'The payment could not be saved. Please try again or contact support.';

            if ($request->expectsJson()) {
                return $this->jsonFailure($message, [], 500);
            }

            return back()->withInput()->withErrors(['performa' => $message]);
        }

        if ($request->expectsJson()) {
            return $this->jsonSuccess('Payment recorded.', ['uuid' => $invoice->uuid]);
        }

        return redirect()->route('branch.invoices.show', $invoice)->with('success', 'Payment recorded.');
    }

    /**
     * Return unpaid goods to stock.
     */
    public function returnUnpaid(ReturnPerformaRequest $request, Performa $performa): RedirectResponse|JsonResponse
    {
        try {
            $this->performaService->returnUnpaid($performa, $request->validated(), $request->user());
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return $this->jsonFailure($e->getMessage(), $e->errors(), 422);
            }

            throw $e;
        }

        if ($request->expectsJson()) {
            return $this->jsonSuccess('Unpaid goods returned to stock.');
        }

        return redirect()->route('branch.performas.show', $performa)->with('success', 'Unpaid goods returned to stock.');
    }

    /**
     * Barcode, short-code, or unique-name lookup for the issue workspace.
     */
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('create', Performa::class);

        $request->validate(['code' => ['required', 'string', 'max:80']]);

        $result = $this->lookupService->lookup($request->input('code'), $request->user());

        if ($result['status'] === 'found') {
            return $this->jsonSuccess('Found.', ['line' => $result['line']]);
        }

        return $this->jsonFailure($result['message'] ?? 'Not found.', ['code' => [$result['message'] ?? 'Not found.']], 422);
    }

    /**
     * Typeahead: jewellery and stones by name, code, or short code.
     */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('create', Performa::class);

        $request->validate(['q' => ['nullable', 'string', 'max:80']]);

        return $this->jsonSuccess('OK.', [
            'items' => $this->lookupService->search((string) $request->query('q', ''), $request->user()),
        ]);
    }
}
