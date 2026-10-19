<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\StoreCustomerRequest;
use App\Http\Requests\Branch\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\Quotation;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CustomerController
 *
 * Global customer CRUD and AJAX search. Not branch-scoped — any branch user
 * with permission sees and edits all customers.
 */
class CustomerController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private CustomerService $customerService,
    ) {}

    /**
     * Customer registry list.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Customer::class);

        return view('branch.customers.index', [
            'customers' => $this->customerService->paginate($request->only(['search', 'status'])),
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    /**
     * AJAX search for the quotation and POS customer pickers.
     *
     * Gated like lookup(): a cashier who may raise a document must be able to
     * attach an existing customer even without access to the registry screens.
     */
    public function search(Request $request): JsonResponse
    {
        $this->authorizeCustomerPicker($request);

        $term = (string) $request->query('q', '');
        // catalog=1 is the full registry for POS. A typed q= stays the short
        // list the quotation Select2 picker already expects.
        $customers = $request->boolean('catalog')
            ? $this->customerService->catalog()
            : $this->customerService->search($term);

        return $this->jsonSuccess(__('OK'), [
            // values() so JSON is a list. Eloquent map() keeps row ids as
            // keys; that encodes as an object and Select2's .map() throws,
            // leaving the widget stuck on "Searching…".
            'customers' => $customers->map(fn (Customer $c): array => [
                'uuid' => $c->uuid,
                'customer_code' => $c->customer_code,
                'name' => $c->name,
                'phone' => $c->phone,
                'email' => $c->email,
                'nie' => $c->nie,
                'address' => $c->address,
                'city' => $c->city,
            ])->values(),
        ]);
    }

    /**
     * Exact phone lookup for the POS customer strip.
     *
     * Cashiers with quotations.create may look up even when they cannot
     * open the customer registry. A miss is success with customer=null so
     * the screen can collect name/email without a 404.
     */
    public function lookup(Request $request): JsonResponse
    {
        $this->authorizeCustomerPicker($request);

        $phone = (string) $request->validate([
            'phone' => ['required', 'string', 'max:30'],
        ])['phone'];

        $customer = $this->customerService->findByPhone($phone);

        if ($customer === null) {
            return $this->jsonSuccess(__('No matching customer.'), ['customer' => null]);
        }

        return $this->jsonSuccess(__('Found.'), [
            'customer' => [
                'uuid' => $customer->uuid,
                'customer_code' => $customer->customer_code,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'nie' => $customer->nie,
                'address' => $customer->address,
            ],
        ]);
    }

    /**
     * Create form.
     */
    public function create(): View
    {
        $this->authorize('create', Customer::class);

        return view('branch.customers.create');
    }

    /**
     * Persist new customer.
     */
    public function store(StoreCustomerRequest $request): RedirectResponse|JsonResponse
    {
        $customer = $this->customerService->create($request->validated(), $request->user());

        if ($request->expectsJson()) {
            return $this->jsonSuccess(__('Customer created.'), [
                'customer' => [
                    'uuid' => $customer->uuid,
                    'customer_code' => $customer->customer_code,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                ],
            ], 201);
        }

        return redirect()
            ->route('branch.customers.show', $customer)
            ->with('success', __('Customer created successfully.'));
    }

    /**
     * Customer detail with cross-branch quotation and invoice history.
     */
    public function show(Customer $customer): View
    {
        $this->authorize('view', $customer);

        return view('branch.customers.show', [
            'customer' => $customer->load(['creator', 'updater']),
            'quotations' => $this->customerService->quotationHistory($customer),
            'invoices' => $this->customerService->invoiceHistory($customer),
            'hasHistory' => $this->customerService->hasHistory($customer),
        ]);
    }

    /**
     * Edit form.
     */
    public function edit(Customer $customer): View
    {
        $this->authorize('update', $customer);

        return view('branch.customers.edit', ['customer' => $customer]);
    }

    /**
     * Save customer edits.
     */
    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->customerService->update($customer, $request->validated(), $request->user());

        return redirect()
            ->route('branch.customers.show', $customer)
            ->with('success', __('Customer updated successfully.'));
    }

    /**
     * Deactivate customer (soft — status flag).
     */
    public function deactivate(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('deactivate', $customer);

        $request->validate([
            'confirm' => ['accepted'],
        ], [
            'confirm.accepted' => __('Please confirm deactivation.'),
        ]);

        $this->customerService->deactivate($customer, $request->user());

        return redirect()
            ->route('branch.customers.index')
            ->with('success', __('Customer deactivated.'));
    }

    /**
     * Gate for the read-only customer pickers (POS and quotation builder).
     *
     * Either permission is enough: raising a document implies being allowed to
     * attach the customer it is billed to, even when the operator has no
     * access to the customer registry screens.
     */
    private function authorizeCustomerPicker(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user !== null && ($user->can('create', Quotation::class) || $user->can('viewAny', Customer::class)),
            403
        );
    }
}
