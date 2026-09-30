<?php

namespace App\Http\Controllers;

use App\Models\AppUser;
use App\Models\Delivery;
use App\Models\EmployeeProduct;
use App\Models\Inventory;
use App\Models\SalesOrder;
use App\Services\DeliveryService;
use App\Services\SyncService;
use App\Services\TransitService;
use Illuminate\Http\Request;

/**
 * Phase 5 — Delivery capture from a confirmed Sales Order.
 *
 * Allocation is authoritative and high-risk, so it is:
 *  - all-or-nothing per delivery (service transaction)
 *  - idempotent per user + operation + key (SyncService replay)
 *  - re-validated server-side: SO ownership/access, confirmed state,
 *    source compatibility, product scope, quantities/units
 */
class DeliveryController extends Controller
{
    public function __construct(
        private readonly DeliveryService $deliveries,
        private readonly SyncService $sync,
        private readonly TransitService $transit,
    ) {}

    /** Allocate form: ordered / allocated / remaining / availability per line. */
    public function create(Request $request, SalesOrder $order)
    {
        $user = $request->user();
        $this->assertCanFulfill($user, $order);

        if ($order->order_status->value === 'DRAFT') {
            return redirect()->route('orders.show', $order)->with('status', 'Confirm the order before creating a delivery.');
        }

        $employee = $user->employee;

        $transitBalances = collect();

        if ($employee !== null) {
            foreach ($order->items->pluck('product_id')->unique() as $productId) {
                $transitBalances[$productId] = $this->transit->reusableBalance(
                    $employee->employee_id,
                    $productId,
                    $order->source_customer_id,
                );
            }
        }

        return view('deliveries.create', [
            'order' => $order,
            'lines' => $this->deliveries->allocationContext($order),
            'transitBalances' => $transitBalances,
            'inventory' => Inventory::where('customer_id', $order->source_customer_id)
                ->whereIn('product_id', $order->items->pluck('product_id'))
                ->get()
                ->keyBy('product_id'),
        ]);
    }

    /**
     * Idempotent allocation endpoint. A lost response + retry replays the
     * original successful payload and must never decrement stock twice.
     */
    public function store(Request $request, SalesOrder $order)
    {
        $user = $request->user();
        $this->assertCanFulfill($user, $order);

        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sales_order_item_no' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit' => ['required', 'string'],
            'lines.*.transit_qty' => ['nullable', 'numeric', 'min:0'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);

        $result = $this->sync->process('delivery_allocation', $request, function () use ($request, $order, $validated) {
            $allocation = $this->deliveries->createAndAllocate(
                $order,
                $request->user()->employee,
                collect($validated['lines']),
            );

            return [
                'delivery_no' => $allocation['delivery']->delivery_no,
                'delivery_status' => $allocation['delivery']->delivery_status->value,
                'order_status' => $order->fresh()->order_status->value,
                'allocated_at' => $allocation['delivery']->allocated_at?->toIso8601String(),
            ];
        });

        if ($result['replayed']) {
            return redirect()
                ->route('deliveries.show', $result['payload']['delivery_no'])
                ->with('status', 'Delivery '.$result['payload']['delivery_no'].' (idempotent replay — no double allocation).');
        }

        return redirect()
            ->route('deliveries.show', $result['payload']['delivery_no'])
            ->with('status', 'Delivery '.$result['payload']['delivery_no'].' allocated. Stock transferred unrestricted → restricted; on-hand unchanged.');
    }

    /**
     * Release an ALLOCATED delivery back to DRAFT (Phase 5 correction):
     * restricted stock returns to unrestricted at the source, ON HAND
     * unchanged, no movement, demand freed for re-allocation. Idempotent.
     */
    public function release(Request $request, string $deliveryNo)
    {
        $delivery = Delivery::with('salesOrder')->findOrFail($deliveryNo);
        $this->assertCanFulfill($request->user(), $delivery->salesOrder);

        $request->validate(['idempotency_key' => ['required', 'string', 'max:64']]);

        $result = $this->sync->process('delivery_release', $request, function () use ($delivery) {
            $released = $this->deliveries->releaseDelivery($delivery);

            return [
                'delivery_no' => $released->delivery_no,
                'delivery_status' => $released->delivery_status->value,
                'order_status' => $released->salesOrder->fresh()->order_status->value,
            ];
        });

        $message = $result['replayed']
            ? 'Delivery '.$result['payload']['delivery_no'].' (idempotent replay — no double release).'
            : 'Delivery '.$result['payload']['delivery_no'].' released. Restricted stock returned to unrestricted — you can edit and allocate it again.';

        return redirect()->route('deliveries.show', $deliveryNo)->with('status', $message);
    }

    /**
     * Allocate an existing DRAFT (released) delivery with NEW lines —
     * replaces the draft lines wholesale, then allocates. Idempotent.
     */
    public function reallocate(Request $request, string $deliveryNo)
    {
        $delivery = Delivery::with('salesOrder')->findOrFail($deliveryNo);
        $this->assertCanFulfill($request->user(), $delivery->salesOrder);

        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sales_order_item_no' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit' => ['required', 'string'],
            'lines.*.transit_qty' => ['nullable', 'numeric', 'min:0'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);

        $result = $this->sync->process('delivery_reallocate', $request, function () use ($request, $delivery, $validated) {
            $allocation = $this->deliveries->reallocateDraft(
                $delivery,
                $delivery->salesOrder,
                $request->user()->employee,
                collect($validated['lines']),
            );

            return [
                'delivery_no' => $allocation['delivery']->delivery_no,
                'delivery_status' => $allocation['delivery']->delivery_status->value,
                'order_status' => $delivery->salesOrder->fresh()->order_status->value,
            ];
        });

        $message = $result['replayed']
            ? 'Delivery '.$result['payload']['delivery_no'].' (idempotent replay — no double allocation).'
            : 'Delivery '.$result['payload']['delivery_no'].' allocated. Stock transferred unrestricted → restricted; on-hand unchanged.';

        return redirect()->route('deliveries.show', $deliveryNo)->with('status', $message);
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $deliveries = Delivery::query()
            ->with(['sourceCustomer', 'items'])
            ->when($user->isSalesEmployee(), fn ($q) => $q->where('created_by', $user->employee_id))
            ->when(! $user->isSalesEmployee() && ! $user->isSuperadmin(), fn ($q) => $q->where('company_id', $user->company_id))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('deliveries.index', ['deliveries' => $deliveries]);
    }

    public function show(Request $request, string $deliveryNo)
    {
        // NOTE: items.salesOrderItem (array-key belongsTo) cannot be
        // eager-loaded on this Laravel version — load items→product only;
        // the SO link on the Delivery covers traceability.
        $delivery = Delivery::with(['items.product', 'sourceCustomer', 'salesOrder'])
            ->findOrFail($deliveryNo);

        $this->assertCanFulfill($request->user(), $delivery->salesOrder);

        // A released/DRAFT delivery offers the edit-and-reallocate form:
        // show the live demand/stock context per SO line.
        $context = $delivery->delivery_status->value === 'DRAFT'
            ? $this->deliveries->allocationContext($delivery->salesOrder)
            : collect();

        return view('deliveries.show', [
            'delivery' => $delivery,
            'context' => $context,
            'draftItems' => $delivery->items->keyBy('sales_order_item_no'),
        ]);
    }

    /**
     * Server-side authorization for delivery operations on an order:
     * ownership/access, confirmed state, supplying eligibility, product scope.
     */
    private function assertCanFulfill(AppUser $user, SalesOrder $order): void
    {
        if ($user->isSalesEmployee()) {
            abort_if($order->sales_employee_id !== $user->employee_id, 403, 'This order belongs to another employee.');
            abort_if($user->employee === null, 403, 'Your login is not linked to an employee record.');

            // Product scope: employees allocate only products within their
            // scope. ANY out-of-scope line blocks the whole order (the old
            // condition let a mixed in/out-of-scope order through).
            $scoped = EmployeeProduct::where('employee_id', $user->employee_id)->pluck('product_id');

            if ($scoped->isNotEmpty()) {
                $outOfScope = $order->items()->pluck('product_id')->diff($scoped);

                abort_if($outOfScope->isNotEmpty(), 403, 'This order contains products outside your product scope.');
            }

            return;
        }

        if (! $user->isSuperadmin()) {
            abort_if($order->company_id !== $user->company_id, 403, 'This order belongs to another company.');
        }
    }
}
