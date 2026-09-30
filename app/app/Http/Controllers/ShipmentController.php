<?php

namespace App\Http\Controllers;

use App\Models\CustomerEmployee;
use App\Models\CustomerMaster;
use App\Models\Delivery;
use App\Models\Shipment;
use App\Services\ShipmentService;
use App\Services\SyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Phase 6 — Shipment dispatch screens.
 *
 * Authorization = legitimate company/source operational scope (never
 * creator==salesperson: a shipment combines deliveries from several
 * salespeople). Sales employees operate shipments whose SOURCE is assigned
 * to them (customer_employee); company admins operate their company's
 * shipments; superadmin crosses companies. Customers are global — company
 * context comes from the shipment/delivery/product records, never
 * customer_master.
 */
class ShipmentController extends Controller
{
    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly SyncService $sync,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();

        $shipments = Shipment::query()
            ->with(['sourceCustomer'])
            ->withCount('deliveries')
            ->when($user->isSalesEmployee(), function ($q) use ($user) {
                $assigned = CustomerEmployee::where('employee_id', $user->employee_id)->pluck('customer_id');
                $q->whereIn('source_customer_id', $assigned);
            })
            ->when(! $user->isSalesEmployee() && ! $user->isSuperadmin(), fn ($q) => $q->where('company_id', $user->company_id))
            ->orderByDesc('created_on')
            ->paginate(20)
            ->withQueryString();

        return view('shipments.index', ['shipments' => $shipments]);
    }

    public function create(Request $request)
    {
        return view('shipments.create', [
            'sources' => $this->operableSources($request->user()),
            'selectedSourceId' => $request->string('source')->toString(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $employee = $user->employee;

        abort_if($employee === null, 403, 'Your login is not linked to an employee record.');

        $validated = $request->validate([
            'source_customer_id' => ['required', 'exists:customer_master,customer_id'],
            'vehicle_reference' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);

        $this->assertSourceInScope($user, $validated['source_customer_id']);

        $shipment = $this->shipments->createShipment(
            $employee,
            $user->currentCompanyId(),
            $validated['source_customer_id'],
            [
                'vehicle_reference' => $validated['vehicle_reference'] ?? null,
                'remarks' => $validated['remarks'] ?? null,
            ],
        );

        return redirect()->route('shipments.show', $shipment)
            ->with('status', 'Shipment '.$shipment->shipment_no.' created. Attach ALLOCATED deliveries.');
    }

    public function show(Request $request, Shipment $shipment)
    {
        $this->assertCanOperate($request->user(), $shipment);

        $shipment->load(['sourceCustomer', 'carrier', 'deliveries.items.product', 'deliveries.salesOrder']);

        return view('shipments.show', [
            'shipment' => $shipment,
            'issuePreview' => $this->shipments->issuePreview($shipment),
            'eligible' => $shipment->shipment_status->value === 'DRAFT'
                ? $this->shipments->eligibleDeliveries($shipment)
                : collect(),
        ]);
    }

    /** Attach an eligible delivery (DRAFT shipments only). */
    public function attach(Request $request, Shipment $shipment)
    {
        $this->assertCanOperate($request->user(), $shipment);

        $validated = $request->validate(['delivery_no' => ['required', 'exists:delivery,delivery_no']]);

        $this->shipments->attachDelivery($shipment, $validated['delivery_no']);

        return back()->with('status', 'Delivery '.$validated['delivery_no'].' attached.');
    }

    public function detach(Request $request, Shipment $shipment)
    {
        $this->assertCanOperate($request->user(), $shipment);

        $validated = $request->validate(['delivery_no' => ['required', 'exists:delivery,delivery_no']]);

        $this->shipments->detachDelivery($shipment, $validated['delivery_no']);

        return back()->with('status', 'Delivery '.$validated['delivery_no'].' detached.');
    }

    /** DRAFT → READY: locks the composition (no inventory effect). */
    public function ready(Request $request, Shipment $shipment)
    {
        $this->assertCanOperate($request->user(), $shipment);

        $this->shipments->markReady($shipment);

        return back()->with('status', 'Shipment '.$shipment->shipment_no.' is READY — composition locked. START will physically issue the goods.');
    }

    /** READY → DRAFT: operational correction before START (no inventory effect). */
    public function backToDraft(Request $request, Shipment $shipment)
    {
        $this->assertCanOperate($request->user(), $shipment);

        $this->shipments->backToDraft($shipment);

        return back()->with('status', 'Shipment '.$shipment->shipment_no.' returned to DRAFT — composition unlocked.');
    }

    /**
     * START = irreversible physical Goods Issue. Idempotent: a lost response
     * + retry replays the original result and never issues twice.
     */
    public function start(Request $request, Shipment $shipment)
    {
        $user = $request->user();
        $this->assertCanOperate($user, $shipment);

        $validated = $request->validate(['idempotency_key' => ['required', 'string', 'max:64']]);

        $result = $this->sync->process('shipment_start', $request, function () use ($request, $shipment) {
            $issued = $this->shipments->start($request->user()->employee, $shipment);

            return [
                'shipment_no' => $issued['shipment']->shipment_no,
                'shipment_status' => $issued['shipment']->shipment_status->value,
                'deliveries' => $issued['shipment']->deliveries->pluck('delivery_no'),
                'issued_items' => $issued['issued'],
                'started_on' => $issued['shipment']->started_on?->toIso8601String(),
            ];
        });

        unset($validated);

        $message = $result['replayed']
            ? 'Shipment '.$result['payload']['shipment_no'].' (idempotent replay — goods not issued twice).'
            : 'Shipment '.$result['payload']['shipment_no'].' IN TRANSIT — '.$result['payload']['issued_items'].' item(s) goods-issued. Restricted stock decremented; this cannot be undone through the shipment workflow.';

        return redirect()->route('shipments.show', $shipment)->with('status', $message);
    }

    // ---- Authorization ---------------------------------------------------------

    /** Stock-holder sources this user may dispatch from. */
    private function operableSources($user): Collection
    {
        $sources = CustomerMaster::query()
            ->whereIn('customer_type', ['PRIMARY', 'SHIP_TO', 'VAN'])
            ->where('active', true)
            ->orderBy('business_name');

        if ($user->isSalesEmployee()) {
            $assigned = CustomerEmployee::where('employee_id', $user->employee_id)->pluck('customer_id');
            $sources->whereIn('customer_id', $assigned);
        }

        return $sources->get();
    }

    private function assertSourceInScope($user, string $sourceCustomerId): void
    {
        if (! $user->isSalesEmployee() || $user->isSuperadmin()) {
            return;
        }

        $assigned = CustomerEmployee::where('employee_id', $user->employee_id)
            ->where('customer_id', $sourceCustomerId)
            ->exists();

        abort_if(! $assigned, 403, 'You can only dispatch from customers assigned to you.');
    }

    /**
     * Operational access to a shipment: sales employees need their SOURCE in
     * their assigned-customer scope (deliveries from other salespeople at the
     * same source are explicitly allowed — ruling §3); admins are scoped by
     * shipment company; superadmin crosses companies.
     */
    private function assertCanOperate($user, Shipment $shipment): void
    {
        if ($user->isSalesEmployee()) {
            abort_if($user->employee === null, 403, 'Your login is not linked to an employee record.');

            $assigned = CustomerEmployee::where('employee_id', $user->employee_id)
                ->where('customer_id', $shipment->source_customer_id)
                ->exists();

            abort_if(! $assigned, 403, 'This shipment loads from a customer outside your assigned scope.');

            return;
        }

        if (! $user->isSuperadmin()) {
            abort_if($shipment->company_id !== $user->company_id, 403, 'This shipment belongs to another company.');
        }
    }
}
