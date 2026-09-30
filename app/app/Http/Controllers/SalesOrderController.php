<?php

namespace App\Http\Controllers;

use App\Enums\OrderType;
use App\Models\CustomerEmployee;
use App\Models\CustomerMaster;
use App\Models\EmployeeMaster;
use App\Models\EmployeeProduct;
use App\Models\ProductMaster;
use App\Models\SalesOrder;
use App\Services\CompanyContext;
use App\Services\DealService;
use App\Services\Decimal;
use App\Services\PricingService;
use App\Services\ProductUnitService;
use App\Services\SalesOrderService;
use App\Services\SyncService;
use Illuminate\Http\Request;

/**
 * Sales Order capture (Phase 4). SO = DEMAND ONLY — no inventory effect.
 * Flow: Customer → Supplying Primary → Source → Products → Qty/Unit →
 * Pricing/Deals → Review → Save Draft / Confirm.
 */
class SalesOrderController extends Controller
{
    public function __construct(
        private readonly SalesOrderService $orders,
        private readonly PricingService $pricing,
        private readonly DealService $deals,
        private readonly ProductUnitService $units,
        private readonly CompanyContext $companyContext,
        private readonly SyncService $sync,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();

        $orders = SalesOrder::query()
            ->forUser($user)
            ->when($user->isSalesEmployee() && $user->employee_id, fn ($q) => $q->where('sales_employee_id', $user->employee_id))
            ->with(['soldToCustomer', 'supplyingCustomer'])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('orders.index', ['orders' => $orders]);
    }

    public function show(Request $request, SalesOrder $order)
    {
        $this->assertCanView($request->user(), $order);

        $order->load(['items.product', 'items.orderUnit', 'soldToCustomer', 'supplyingCustomer', 'sourceCustomer']);

        return view('orders.show', ['order' => $order]);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        $employee = $this->employeeOf($user);

        $assignedCustomerIds = CustomerEmployee::where('employee_id', $employee->employee_id)
            ->pluck('customer_id');

        return view('orders.create', [
            'assignedCustomers' => CustomerMaster::whereIn('customer_id', $assignedCustomerIds)
                ->where('active', true)->orderBy('business_name')->get(),
            // A Sales Employee supplies only through Primaries assigned to them
            // (no fixed Primary → Secondary mapping; server-enforced everywhere).
            'primaries' => CustomerMaster::whereIn('customer_id', $this->orders->eligibleSupplyingIds($employee))
                ->orderBy('business_name')->get(),
        ]);
    }

    /**
     * Advisory context for the capture screen (server-computed): allowed
     * products (company + employee scope) with current recommended prices,
     * plus current deal evaluation for the current cart.
     */
    public function context(Request $request)
    {
        $user = $request->user();
        $employee = $this->employeeOf($user);

        $validated = $request->validate([
            'supplying_customer_id' => ['required', 'exists:customer_master,customer_id'],
            'sold_to_customer_id' => ['required', 'exists:customer_master,customer_id'],
            'lines' => ['nullable', 'array'],
            'lines.*.product_id' => ['required', 'string'],
            'lines.*.qty' => ['required', 'numeric', 'min:0'],
            'lines.*.unit' => ['required', 'string'],
        ]);

        $supplying = CustomerMaster::find($validated['supplying_customer_id']);
        $soldTo = CustomerMaster::find($validated['sold_to_customer_id']);

        if ($supplying === null || $soldTo === null) {
            return response()->json(['error' => 'Unknown customer.'], 422);
        }

        // Supplying eligibility is enforced here too — the advisory context
        // must not leak prices/deals through an unauthorized Primary.
        if (! $this->orders->eligibleSupplyingIds($employee)->contains($supplying->customer_id)) {
            return response()->json(['error' => 'You are not authorized to supply through this Primary. It must be assigned to you.'], 403);
        }

        // Products: active + company + employee product scope (no rows = all).
        // Company is derived from the employee — same rule as createDraft() —
        // since customer_master carries no company_id (customers are global).
        $companyId = $employee->company_id;

        $productsQuery = ProductMaster::query()
            ->where('company_id', $companyId)
            ->where('active', true);

        $scoped = EmployeeProduct::where('employee_id', $employee->employee_id)->pluck('product_id');
        if ($scoped->isNotEmpty()) {
            $productsQuery->whereIn('product_id', $scoped);
        }

        $products = $productsQuery->with('basicUnit')->orderBy('product_description')->get();

        $resolution = $this->pricing->resolveMany($products, today(), $soldTo->sales_region);

        $cart = collect($validated['lines'] ?? [])->map(fn ($l) => [
            'product_id' => (string) $l['product_id'],
            'qty' => (string) $l['qty'],
            'unit' => (string) $l['unit'],
        ]);

        $deals = $cart->isNotEmpty()
            ? $this->deals->evaluate($companyId, $cart, today())
            : ['rewards' => [], 'qualifying_deals' => [], 'ambiguous' => false];

        return response()->json([
            'products' => $products->map(fn (ProductMaster $p) => [
                'id' => $p->product_id,
                'label' => $p->product_description,
                'sku' => $p->product_sku,
                'basic_unit' => $p->basic_unit,
                'recommended_price' => $resolution['prices'][$p->product_id]['price'] ?? null,
                'price_condition_no' => $resolution['prices'][$p->product_id]['condition_price_no'] ?? null,
            ]),
            'price_ambiguous' => array_keys($resolution['ambiguous']),
            'deals' => $deals,
        ]);
    }

    public function store(Request $request)
    {
        $employee = $this->employeeOf($request->user());

        $validated = $request->validate([
            'supplying_customer_id' => ['required', 'string'],
            'source_customer_id' => ['required', 'string'],
            'sold_to_customer_id' => ['required', 'string'],
            'order_type' => ['nullable', 'in:STANDARD,VAN_ORDER'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'string'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit' => ['required', 'string'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.price_override_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $action = $request->input('action', 'draft'); // draft | confirm

        $result = $this->orders->createDraft(
            $employee,
            $validated['supplying_customer_id'],
            $validated['source_customer_id'],
            $validated['sold_to_customer_id'],
            collect($validated['lines']),
            OrderType::from($validated['order_type'] ?? 'STANDARD'),
        );

        $order = $result['order'];
        $conflicts = [];

        if ($action === 'confirm') {
            $confirmation = $this->orders->confirm($order);
            $order = $confirmation['order'];
            $conflicts = $confirmation['conflicts'];
        }

        if ($conflicts !== []) {
            return redirect()
                ->route('orders.show', $order)
                ->with('conflicts', $conflicts);
        }

        return redirect()
            ->route($order->order_status->value === 'DRAFT' ? 'orders.edit' : 'orders.show', $order)
            ->with('status', $order->order_status->value === 'DRAFT' ? 'Draft saved (not yet an order).' : 'Order confirmed.');
    }

    public function edit(Request $request, SalesOrder $order)
    {
        $this->assertCanView($request->user(), $order);

        if ($order->order_status->value !== 'DRAFT') {
            return redirect()->route('orders.show', $order);
        }

        $order->load(['items.product', 'soldToCustomer', 'supplyingCustomer', 'sourceCustomer']);
        $employee = $this->employeeOf($request->user());

        $assignedCustomerIds = CustomerEmployee::where('employee_id', $employee->employee_id)
            ->pluck('customer_id');

        return view('orders.edit', [
            'order' => $order,
            'assignedCustomers' => CustomerMaster::whereIn('customer_id', $assignedCustomerIds)
                ->where('active', true)->orderBy('business_name')->get(),
            'primaries' => CustomerMaster::whereIn('customer_id', $this->orders->eligibleSupplyingIds($employee))
                ->orderBy('business_name')->get(),
        ]);
    }

    public function update(Request $request, SalesOrder $order)
    {
        $this->assertCanView($request->user(), $order);

        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'string'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit' => ['required', 'string'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.price_override_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $action = $request->input('action', 'draft');

        $result = $this->orders->updateDraft($order, collect($validated['lines']));
        $order = $result['order'];
        $conflicts = [];

        if ($action === 'confirm') {
            $confirmation = $this->orders->confirm($order);
            $order = $confirmation['order'];
            $conflicts = $confirmation['conflicts'];
        }

        if ($conflicts !== []) {
            return redirect()->route('orders.show', $order)->with('conflicts', $conflicts);
        }

        return redirect()
            ->route($order->order_status->value === 'DRAFT' ? 'orders.edit' : 'orders.show', $order)
            ->with('status', $order->order_status->value === 'DRAFT' ? 'Draft updated.' : 'Order confirmed.');
    }

    /**
     * Confirm an existing draft authoritatively (re-prices, re-evaluates deals).
     */
    public function confirm(Request $request, SalesOrder $order)
    {
        $this->assertCanView($request->user(), $order);

        $result = $this->orders->confirm($order);

        if ($result['conflicts'] !== []) {
            return redirect()->route('orders.show', $order)->with('conflicts', $result['conflicts']);
        }

        return redirect()->route('orders.show', $order)->with('status', 'Order confirmed. Demand is now immutable.');
    }

    /**
     * Reject a confirmed item (reason required). Original demand is preserved.
     */
    public function rejectItem(Request $request, SalesOrder $order, string $itemNo)
    {
        $this->assertCanView($request->user(), $order);
        $employee = $this->employeeOf($request->user());

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:255'],
        ]);

        $item = $order->items()->where('item_no', (int) $itemNo)->firstOrFail();

        $this->orders->rejectItem($item, $validated['rejection_reason'], $employee);

        return back()->with('status', 'Item rejected. Remaining demand will not be newly allocated.');
    }

    /**
     * Offline draft ingestion (idempotent). Revalidates everything server-side;
     * returns conflicts instead of silently confirming.
     */
    public function syncDraft(Request $request)
    {
        $employee = $this->employeeOf($request->user());

        $result = $this->sync->process('order_draft', $request, function () use ($request, $employee) {
            $validated = $request->validate([
                'supplying_customer_id' => ['required', 'string'],
                'source_customer_id' => ['required', 'string'],
                'sold_to_customer_id' => ['required', 'string'],
                'lines' => ['required', 'array', 'min:1'],
                'lines.*.product_id' => ['required', 'string'],
                'lines.*.qty' => ['required', 'numeric', 'gt:0'],
                'lines.*.unit' => ['required', 'string'],
                'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
                'lines.*.price_override_reason' => ['nullable', 'string', 'max:255'],
                'request_confirm' => ['sometimes', 'boolean'],
            ]);

            $draft = $this->orders->createDraft(
                $employee,
                $validated['supplying_customer_id'],
                $validated['source_customer_id'],
                $validated['sold_to_customer_id'],
                collect($validated['lines']),
            );

            $payload = [
                'sales_order_no' => $draft['order']->sales_order_no,
                'order_status' => $draft['order']->order_status->value,
                'server_draft' => true,
                'confirmed' => false,
                'conflicts' => [],
                'pricing_changes' => [],
                'synced_at' => now()->toIso8601String(),
            ];

            // Advisory comparison: what changed vs the device's cached values.
            $cachedPrices = collect($request->input('cached_prices', []));
            $pricingChanges = [];

            foreach ($draft['order']->items as $item) {
                $cached = $cachedPrices->get($item->product_id);

                if ($cached !== null && $item->recommended_price !== null
                    && Decimal::compare((string) $cached, (string) $item->recommended_price) !== 0) {
                    $pricingChanges[] = [
                        'product_id' => $item->product_id,
                        'cached' => (string) $cached,
                        'server' => (string) $item->recommended_price,
                    ];
                }
            }

            $payload['pricing_changes'] = $pricingChanges;

            // Only confirm when explicitly requested AND the device's cached
            // prices still match current server pricing. Any material change
            // keeps the order a SERVER DRAFT for the user to re-confirm.
            if ($request->boolean('request_confirm') && $pricingChanges === []) {
                $confirmation = $this->orders->confirm($draft['order']);

                if ($confirmation['conflicts'] !== []) {
                    $payload['conflicts'] = $confirmation['conflicts'];
                } else {
                    $payload['confirmed'] = true;
                    $payload['order_status'] = $confirmation['order']->order_status->value;
                }
            }

            return $payload;
        });

        return response()->json($result['payload'], $result['replayed'] ? 200 : 201)
            ->header('X-Idempotent-Replay', $result['replayed'] ? '1' : '0');
    }

    private function assertCanView($user, SalesOrder $order): void
    {
        if ($user->isSalesEmployee()) {
            if ($order->sales_employee_id !== $user->employee_id) {
                abort(403, 'This order belongs to another employee.');
            }

            return;
        }

        if ($user->isCompanyAdmin() && $order->company_id !== $user->company_id) {
            abort(403, 'This order belongs to another company.');
        }
    }

    private function employeeOf($user): EmployeeMaster
    {
        $employee = $user->employee;

        abort_if($employee === null, 403, 'Your login is not linked to an employee record.');

        return $employee;
    }
}
