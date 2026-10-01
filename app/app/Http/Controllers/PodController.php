<?php

namespace App\Http\Controllers;

use App\Enums\DifferenceDisposition;
use App\Enums\DifferenceReason;
use App\Models\CustomerEmployee;
use App\Models\Delivery;
use App\Models\DeliveryConfirmation;
use App\Models\DeliveryItem;
use App\Models\EmployeeMaster;
use App\Models\ProductMaster;
use App\Services\InvoiceService;
use App\Services\PodService;
use App\Services\SyncService;
use Illuminate\Http\Request;

/**
 * Phase 7 — POD screens. One authoritative confirmation per shipped Delivery
 * Item; submissions are idempotent (lost response + retry replays).
 */
class PodController extends Controller
{
    public function __construct(
        private readonly PodService $pod,
        private readonly SyncService $sync,
        private readonly InvoiceService $invoices,
    ) {}

    /** POD workspace for one SHIPPED delivery. */
    public function show(Request $request, Delivery $delivery)
    {
        $this->pod->assertCanConfirm($request->user(), $delivery);

        $delivery->load(['items.product', 'sourceCustomer', 'salesOrder.soldToCustomer']);

        // Contextual follow-through: the accepted quantity becomes the invoice
        // (Model A, one per SO) — surface it here instead of sending the
        // employee to a global finance module. The POD hand-over is the share
        // moment, so the public token is ensured here (idempotent).
        $invoice = $delivery->salesOrder?->invoice()->first();

        if ($invoice !== null) {
            $this->invoices->publicToken($invoice);
            $invoice->refresh();
        }

        return view('pod.show', [
            'delivery' => $delivery,
            'invoice' => $invoice,
            'reasons' => collect(DifferenceReason::cases())
                ->filter(fn ($r) => $r !== DifferenceReason::NONE)
                ->mapWithKeys(fn ($r) => [$r->value => $r->value === 'SHORT_DELIVERY' ? 'Short delivery' : ucfirst(strtolower(str_replace('_', ' ', $r->value)))]),
        ]);
    }

    /**
     * Confirm ONE delivery item. Idempotent: a lost response + retry with the
     * same key replays the original result and never creates a duplicate
     * confirmation.
     */
    public function confirm(Request $request, Delivery $delivery)
    {
        $this->pod->assertCanConfirm($request->user(), $delivery);

        $validated = $request->validate([
            'delivery_item_no' => ['required', 'integer'],
            'confirmed_qty' => ['required', 'numeric', 'min:0'],
            'confirmed_unit' => ['required', 'string'],
            'difference_reason' => ['nullable', 'string'],
            'difference_disposition' => ['nullable', 'string'],
            'remarks' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);

        $result = $this->sync->process('pod_confirm', $request, function () use ($request, $delivery, $validated) {
            $confirmed = $this->pod->confirmItem(
                $request->user()->employee,
                $delivery->delivery_no,
                (int) $validated['delivery_item_no'],
                (string) $validated['confirmed_qty'],
                (string) $validated['confirmed_unit'],
                DifferenceReason::tryFrom((string) ($validated['difference_reason'] ?? '')) ?? DifferenceReason::NONE,
                $validated['remarks'] ?? null,
                DifferenceDisposition::tryFrom((string) ($validated['difference_disposition'] ?? '')),
            );

            return [
                'delivery_no' => $confirmed['delivery']->delivery_no,
                'delivery_status' => $confirmed['delivery']->delivery_status->value,
                'shipment_no' => $confirmed['shipment']->shipment_no,
                'shipment_status' => $confirmed['shipment']->shipment_status->value,
                'confirmation_status' => $confirmed['confirmation']->confirmation_status->value,
                'confirmed_qty' => (string) $confirmed['confirmation']->confirmed_qty,
            ];
        });

        $message = $result['replayed']
            ? 'POD (idempotent replay — no duplicate confirmation).'
            : 'Item confirmed: '.$result['payload']['confirmation_status']
                .' · delivery now '.$result['payload']['delivery_status']
                .($result['payload']['shipment_status'] === 'COMPLETED' ? ' · shipment COMPLETED' : '');

        return redirect()->route('pod.show', $delivery)->with('status', $message);
    }

    /** Recent confirmations, scoped like shipments. */
    public function index(Request $request)
    {
        $user = $request->user();

        $confirmations = DeliveryConfirmation::query()
            ->when($user->isSalesEmployee(), function ($q) use ($user) {
                // Receiving side: confirmations of deliveries whose SOLD-TO
                // customer is assigned to the employee.
                $assigned = CustomerEmployee::where('employee_id', $user->employee_id)->pluck('customer_id');
                $q->whereIn('delivery_no', Delivery::whereIn('customer_id', $assigned)->select('delivery_no'));
            })
            ->when(! $user->isSalesEmployee() && ! $user->isSuperadmin(), function ($q) use ($user) {
                $q->whereIn('delivery_no', Delivery::where('company_id', $user->company_id)->select('delivery_no'));
            })
            ->orderByDesc('confirmation_id')
            ->limit(100)
            ->get();

        // Bulk item/product/employee lookup — composite-keyed relations
        // cannot be eager-loaded on this Laravel version (see DeliveryItem).
        $items = DeliveryItem::whereIn('delivery_no', $confirmations->pluck('delivery_no')->unique())
            ->get()
            ->keyBy(fn ($i) => $i->delivery_no.'|'.$i->item_no);

        $products = ProductMaster::whereIn('product_id', $items->pluck('product_id')->unique())
            ->get()
            ->keyBy('product_id');

        $employees = EmployeeMaster::whereIn('employee_id', $confirmations->pluck('confirmed_by')->unique())
            ->get()
            ->keyBy('employee_id');

        return view('pod.index', [
            'confirmations' => $confirmations,
            'items' => $items,
            'products' => $products,
            'employees' => $employees,
        ]);
    }
}
