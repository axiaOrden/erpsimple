<?php

namespace App\Http\Controllers;

use App\Enums\OrderType;
use App\Enums\PriceOverrideReason;
use App\Models\CustomerEmployee;
use App\Models\CustomerMaster;
use App\Models\EmployeeMaster;
use App\Models\EmployeeProduct;
use App\Models\ProductMaster;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderRejectionReason;
use App\Services\CompanyContext;
use App\Services\DealEntitlementService;
use App\Services\DealService;
use App\Services\Decimal;
use App\Services\PricingService;
use App\Services\ProductUnitService;
use App\Services\SalesLifecycleService;
use App\Services\SalesOrderService;
use App\Services\SyncService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

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
        private readonly DealEntitlementService $entitlements,
    ) {}

    /**
     * Order screen: date window (default today → today), search, and the
     * ONGOING / COMPLETED split DERIVED from authoritative state — no new
     * database status is invented for the UI.
     */
    public function index(Request $request, SalesLifecycleService $lifecycle)
    {
        $user = $request->user();

        $from = $request->date('from') ?? today();
        $to = $request->date('to') ?? today();

        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        $tab = strtoupper((string) $request->query('tab', 'ONGOING'));
        $tab = in_array($tab, ['ONGOING', 'COMPLETED'], true) ? $tab : 'ONGOING';
        $search = trim((string) $request->query('q', ''));
        $perPage = $this->perPage($request);

        // Bounded window: classification is an in-memory derivation, so the
        // candidate set is capped rather than streamed over an unbounded range.
        $orders = SalesOrder::query()
            // Row-level scope: a sales employee sees their own orders only; an
            // administrator sees their company's; a superadmin's context
            // company (or every company when no context is set).
            ->when($user->isSalesEmployee() && $user->employee_id, fn ($q) => $q->where('sales_employee_id', $user->employee_id))
            ->when(! $user->isSalesEmployee() && ! $user->isSuperadmin(), fn ($q) => $q->where('company_id', $user->company_id))
            ->when($user->isSuperadmin() && $user->currentCompanyId() !== null, fn ($q) => $q->where('company_id', $user->currentCompanyId()))
            ->whereDate('order_date', '>=', $from->toDateString())
            ->whereDate('order_date', '<=', $to->toDateString())
            ->when($search !== '', fn ($q) => $q->where(function ($w) use ($search) {
                $w->where('sales_order_no', 'like', '%'.$search.'%')
                    ->orWhereHas('soldToCustomer', fn ($c) => $c->where('business_name', 'like', '%'.$search.'%'))
                    ->orWhereHas('supplyingCustomer', fn ($c) => $c->where('business_name', 'like', '%'.$search.'%'));
            }))
            ->with(['soldToCustomer', 'supplyingCustomer', 'invoice'])
            ->orderByDesc('order_date')
            ->orderByDesc('sales_order_no')
            ->limit(300)
            ->get();

        $analysis = $lifecycle->classifyOrders($orders);

        $counts = ['ONGOING' => 0, 'COMPLETED' => 0];

        foreach ($orders as $order) {
            $state = $analysis[$order->sales_order_no]['state'] ?? 'COMPLETED';
            $counts[$state]++;
        }

        $filtered = $orders
            ->filter(fn (SalesOrder $order) => ($analysis[$order->sales_order_no]['state'] ?? 'COMPLETED') === $tab)
            ->values();

        $page = max(1, (int) $request->query('page', 1));

        $paginator = new LengthAwarePaginator(
            $filtered->slice(($page - 1) * $perPage, $perPage)->values(),
            $filtered->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        $augmented = $paginator->getCollection()->map(fn (SalesOrder $order) => ['order' => $order, 'analysis' => $analysis[$order->sales_order_no] ?? null]);

        return view('orders.index', [
            'rows' => $augmented,
            'paginator' => $paginator,
            'counts' => $counts,
            'tab' => $tab,
            'search' => $search,
            'from' => $from,
            'to' => $to,
            'perPage' => $perPage,
            'nextPerPage' => min(120, $perPage * 2),
        ]);
    }

    public function show(Request $request, SalesOrder $order, SalesLifecycleService $lifecycle)
    {
        $this->assertCanView($request->user(), $order);

        $order->load(['items.product', 'items.orderUnit', 'soldToCustomer', 'supplyingCustomer', 'sourceCustomer']);

        // DEAL/free dependency explanation per free line, so the order screen
        // shows WHY a free line can (not) be fulfilled on its own.
        $dependencyNotes = $order->items
            ->filter(fn (SalesOrderItem $item) => (bool) $item->is_free_item)
            ->mapWithKeys(function (SalesOrderItem $item) {
                $context = $this->entitlements->context($item);

                return [$item->item_no => $context !== null ? $this->entitlements->note($context) : null];
            })
            ->filter()
            ->all();

        return view('orders.show', [
            'order' => $order,
            'analysis' => $lifecycle->orderAnalysis($order),
            'invoice' => $order->invoice()->first(),
            'dependencyNotes' => $dependencyNotes,
            // Controlled rejection reasons — SYSTEM_DEFAULT (automation) is
            // never selectable by a user.
            'rejectionReasons' => SalesOrderRejectionReason::userSelectableOptions(),
        ]);
    }

    /** Load-more growth for the order list (per_page doubles, capped). */
    private function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', 20);

        return max(5, min(120, $perPage <= 0 ? 20 : $perPage));
    }

    public function create(Request $request)
    {
        $user = $request->user();
        $employee = $this->employeeOf($user);

        $assignedCustomerIds = CustomerEmployee::where('employee_id', $employee->employee_id)
            ->pluck('customer_id');

        // VAN one-cycle rule: INFORMATIONAL here (the enforcement lives in
        // SalesOrderService, server-side and concurrency-safe) — the screen
        // explains why a VAN cannot start another order and links the blocking
        // documents.
        $selectedCustomerId = $request->string('customer')->toString();

        $vanBlock = $selectedCustomerId !== ''
            ? $this->orders->vanCycleStatus($selectedCustomerId, $employee->company_id)
            : null;

        return view('orders.create', [
            'assignedCustomers' => CustomerMaster::whereIn('customer_id', $assignedCustomerIds)
                ->where('active', true)->orderBy('business_name')->get(),
            // A Sales Employee supplies only through Primaries assigned to them
            // (no fixed Primary → Secondary mapping; server-enforced everywhere).
            'primaries' => CustomerMaster::whereIn('customer_id', $this->orders->eligibleSupplyingIds($employee))
                ->orderBy('business_name')->get(),
            'selectedCustomerId' => $selectedCustomerId,
            'vanBlock' => $vanBlock,
            'priceOverrideReasons' => PriceOverrideReason::cases(),
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

        // The unit picker is authoritative: the product's BASE unit plus any
        // alternative that carries a MAINTAINED product_unit_conversion row.
        // A unit is never offered globally (no CTN on products without one).
        $products = $productsQuery->with(['basicUnit', 'unitConversions'])
            ->orderBy('product_description')
            ->get();

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
                'alt_units' => $this->maintainedUnits($p),
                'recommended_price' => $resolution['prices'][$p->product_id]['price'] ?? null,
                'price_condition_no' => $resolution['prices'][$p->product_id]['condition_price_no'] ?? null,
            ]),
            'price_ambiguous' => array_keys($resolution['ambiguous']),
            'deals' => $deals,
        ]);
    }

    /**
     * Units an employee may ORDER in, beyond the product's base unit: only the
     * alternatives with a maintained `product_unit_conversion` row (non-zero
     * numerator and denominator). No unit is ever exposed globally.
     *
     * @return array<int, string>
     */
    private function maintainedUnits(ProductMaster $product): array
    {
        return $product->unitConversions
            ->filter(fn ($conversion) => (float) $conversion->numerator != 0.0 && (float) $conversion->denominator != 0.0)
            ->pluck('alternative_unit')
            ->reject(fn (string $unit) => $unit === $product->basic_unit)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Read-only recent-purchase guidance for one assigned customer. GUIDANCE
     * ONLY — the client renders it; it never creates lines or constrains the
     * order. Company-scoped and employee-product-scoped inside the service.
     */
    public function customerHistory(Request $request)
    {
        $employee = $this->employeeOf($request->user());

        $validated = $request->validate([
            'sold_to_customer_id' => ['required', 'integer'],
        ]);

        $customer = CustomerMaster::find($validated['sold_to_customer_id']);

        if ($customer === null) {
            return response()->json(['error' => 'Unknown customer.'], 422);
        }

        // Only the employee's own assigned customers' history is exposed.
        $assigned = CustomerEmployee::where('employee_id', $employee->employee_id)
            ->where('customer_id', $customer->customer_id)
            ->exists();

        if (! $assigned) {
            return response()->json(['error' => 'You are not assigned to this customer.'], 403);
        }

        return response()->json([
            'history' => $this->orders->recentPurchaseHistory($employee, $customer->customer_id),
        ]);
    }

    public function store(Request $request)
    {
        $employee = $this->employeeOf($request->user());

        $validated = $request->validate([
            'supplying_customer_id' => ['required', 'integer'],
            'source_customer_id' => ['required', 'integer'],
            'sold_to_customer_id' => ['required', 'integer'],
            'order_type' => ['nullable', 'in:STANDARD,VAN_ORDER'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'string'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit' => ['required', 'string'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.price_override_reason' => ['nullable', Rule::enum(PriceOverrideReason::class)],
        ]);

        $action = $request->input('action', 'draft'); // draft | confirm

        // VAN one-cycle rule: a friendly, informational precheck — the
        // AUTHORITATIVE, concurrency-safe enforcement is inside the service
        // (createDraft / confirm), so a race can never slip a second cycle
        // through even if this precheck is stale.
        $vanBlock = $this->orders->vanCycleStatus(
            $validated['sold_to_customer_id'], $employee->company_id,
        );

        if ($vanBlock !== null) {
            return redirect()
                ->route('orders.create', ['customer' => $validated['sold_to_customer_id']])
                ->withInput()
                ->with('van_block', $vanBlock['message']);
        }

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
            'priceOverrideReasons' => PriceOverrideReason::cases(),
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
            'lines.*.price_override_reason' => ['nullable', Rule::enum(PriceOverrideReason::class)],
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
     * Reject a confirmed item. Original demand is preserved.
     *
     * The reason is CONTROLLED master data: free text is never accepted, and
     * the automation-only SYSTEM_DEFAULT reason can never be chosen here (it
     * is reserved for automatic closure of dependent DEAL/free demand).
     */
    public function rejectItem(Request $request, SalesOrder $order, string $itemNo)
    {
        $this->assertCanView($request->user(), $order);
        $employee = $this->employeeOf($request->user());

        $validated = $request->validate([
            'rejection_reason_id' => [
                'required', 'integer',
                Rule::exists('sales_order_rejection_reason', 'reason_id')
                    ->where('user_selectable', true)
                    ->where('active', true),
            ],
        ], [
            'rejection_reason_id.exists' => 'Choose one of the available rejection reasons.',
        ]);

        $item = $order->items()->where('item_no', (int) $itemNo)->firstOrFail();

        $reason = SalesOrderRejectionReason::findOrFail($validated['rejection_reason_id']);

        $this->orders->rejectItem($item, $reason, $employee);

        return back()->with('status', 'Item rejected ('.$reason->reason_name
            .'). Remaining demand will not be newly allocated; any dependent free deal quantity that cannot be earned is closed automatically.');
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
                'supplying_customer_id' => ['required', 'integer'],
                'source_customer_id' => ['required', 'integer'],
                'sold_to_customer_id' => ['required', 'integer'],
                'lines' => ['required', 'array', 'min:1'],
                'lines.*.product_id' => ['required', 'string'],
                'lines.*.qty' => ['required', 'numeric', 'gt:0'],
                'lines.*.unit' => ['required', 'string'],
                'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
                'lines.*.price_override_reason' => ['nullable', Rule::enum(PriceOverrideReason::class)],
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
