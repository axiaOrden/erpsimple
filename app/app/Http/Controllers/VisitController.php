<?php

namespace App\Http\Controllers;

use App\Models\CustomerEmployee;
use App\Models\CustomerMaster;
use App\Models\CustomerVisitAttendance;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Models\Shipment;
use App\Models\StockCount;
use App\Services\AttendanceService;
use App\Services\FieldDirectoryService;
use App\Services\FjpRotationService;
use App\Services\InvoiceService;
use App\Services\SalesLifecycleService;
use App\Services\SalesOrderService;
use App\Services\SyncService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Field-sales visit workflow.
 *
 *  - FJP screen: TODAY's applicable customers (rotation week + weekday), each
 *    with first/last check-in, last stock count, preferred visit and visit
 *    status — plus a search that ALSO finds assigned customers who are not on
 *    today's plan (the employee can still visit them).
 *  - Customer context page: everything the employee needs while standing in
 *    front of the customer (attendance, counts, orders, deliveries/POD,
 *    invoice + payment, history) WITHOUT leaving the customer.
 */
class VisitController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly SyncService $sync,
        private readonly FjpRotationService $rotation,
        private readonly SalesOrderService $orders,
        private readonly InvoiceService $invoices,
        private readonly SalesLifecycleService $lifecycle,
        private readonly FieldDirectoryService $directory,
    ) {}

    /** FJP screen — today's applicable customers. */
    public function today(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->isSalesEmployee() || $user->isCompanyAdmin() || $user->isSuperadmin(), 403);

        $employee = $user->employee;

        if ($employee === null) {
            return view('visits.today', [
                'employee' => null,
                'visits' => collect(),
                'assignedCustomers' => collect(),
                'searchMatches' => collect(),
                'search' => null,
                'planTotal' => 0,
                'perPage' => 15,
                'nextPerPage' => 30,
                'paginator' => null,
                'rotationWeek' => null,
            ]);
        }

        $search = $request->query('q');
        $search = is_string($search) && trim($search) !== '' ? trim($search) : null;
        $perPage = $this->perPage($request);

        $plan = $this->directory->todayPlan($employee, $search, $perPage);
        $planIds = $plan['rows']->map(fn (array $row) => $row['customer']->customer_id)->all();

        // A searched customer may be assigned without appearing in today's
        // plan; surfacing them keeps the FJP screen usable as the day's
        // worklist instead of a dead end.
        $searchMatches = $search === null
            ? collect()
            : $this->directory->secondaryDirectory($employee, $search, 8)->getCollection()
                ->reject(fn (CustomerMaster $c) => in_array($c->customer_id, $planIds, true))
                ->values();

        return view('visits.today', [
            'employee' => $employee,
            // Kept as `visits` (rows of arrays with a `customer` key) — the
            // FJP row contract other screens and tests rely on.
            'visits' => $plan['rows'],
            'planTotal' => $plan['total'],
            'paginator' => $plan['paginator'],
            'search' => $search,
            'searchMatches' => $searchMatches,
            'perPage' => $perPage,
            'nextPerPage' => min(120, $perPage * 2),
            'rotationWeek' => $this->rotation->rotationWeek(today()),
            'assignedCustomers' => CustomerMaster::whereIn('customer_id', $this->directory->assignedCustomerIds($employee))
                ->where('active', true)
                ->orderBy('business_name')
                ->limit(100)
                ->get(),
        ]);
    }

    /**
     * Contextual customer page (Primary and Secondary alike): no reason to
     * leave the customer to find their orders, deliveries, invoice or proof.
     */
    public function customer(Request $request, string $customerId): View
    {
        $user = $request->user();
        $employee = $user->employee;
        abort_if($employee === null, 403);

        $customer = CustomerMaster::with('salesRegion')->where('customer_id', $customerId)->firstOrFail();

        // Assignment is the commercial relationship — server-side enforcement.
        $assigned = CustomerEmployee::where('employee_id', $employee->employee_id)
            ->where('customer_id', $customer->customer_id)
            ->exists();

        abort_unless($assigned, 403, 'You are not assigned to this customer.');

        $companyId = $employee->company_id;

        $visits = CustomerVisitAttendance::where('employee_id', $employee->employee_id)
            ->where('customer_id', $customer->customer_id)
            ->orderByDesc('attendance_datetime')
            ->limit(10)
            ->get();

        $todaySummary = $this->attendance->effectiveVisit($employee, $customer->customer_id, today());

        $openOrders = SalesOrder::with('items')
            ->where('sales_employee_id', $employee->employee_id)
            ->where('sold_to_customer_id', $customer->customer_id)
            ->whereNotIn('order_status', ['COMPLETED', 'COMPLETELY_REJECTED'])
            ->latest()
            ->limit(5)
            ->get();

        $orderAnalysis = $this->lifecycle->classifyOrders($openOrders);

        $shipments = Shipment::with('deliveries')
            ->where('company_id', $companyId)
            ->where('source_customer_id', $customer->customer_id)
            ->whereIn('shipment_status', ['DRAFT', 'READY', 'IN_TRANSIT'])
            ->latest()
            ->limit(5)
            ->get();

        $invoices = Invoice::where('customer_id', $customer->customer_id)
            ->where('company_id', $companyId)
            ->orderByDesc('invoice_date')
            ->limit(5)
            ->get();

        return view('visits.customer', [
            'customer' => $customer,
            'visits' => $visits,
            'todaySummary' => $todaySummary,
            'inventory' => in_array($customer->customer_type->value, ['PRIMARY', 'SHIP_TO', 'VAN'], true)
                ? $customer->inventory()->with('product')->orderBy('product_id')->get()
                : collect(),
            'recentPurchases' => $this->orders->recentPurchaseHistory($employee, $customer->customer_id),
            'recentCounts' => StockCount::with('items')
                ->where('employee_id', $employee->employee_id)
                ->where('customer_id', $customer->customer_id)
                ->latest()
                ->limit(3)
                ->get(),
            'openOrders' => $openOrders,
            'orderAnalysis' => $orderAnalysis,
            'activeDeliveries' => Delivery::with('items')
                ->where('company_id', $companyId)
                ->where('customer_id', $customer->customer_id)
                ->whereIn('delivery_status', ['ALLOCATED', 'SHIPPED', 'PARTIALLY_DELIVERED'])
                ->latest()
                ->limit(5)
                ->get(),
            'invoices' => $invoices,
            'shipments' => $shipments,
            // Preferred visits for THIS employee + company + customer, ACTIVE
            // rows only: the same customer may carry schedules for other
            // companies/employees, and those are never shown here.
            'plan' => $this->directory->preferredVisits($employee, $customer->customer_id),
            'exposure' => $this->invoices->exposure($customer->customer_id, $companyId),
        ]);
    }

    /**
     * Online attendance capture (form post from the customer/visit screen).
     */
    public function storeAttendance(Request $request)
    {
        $user = $request->user();
        $employee = $user->employee;
        abort_if($employee === null, 403, 'Only field employees record visits.');

        $validated = $request->validate([
            'customer_id' => ['required', 'exists:customer_master,customer_id'],
            'gps_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'gps_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'gps_accuracy' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);

        $this->attendance->record($employee, $validated);

        return back()->with('status', 'Visit recorded.');
    }

    /**
     * Offline sync endpoint (idempotent). The device queues attendance
     * operations in IndexedDB and posts them here when connectivity returns.
     */
    public function syncAttendance(Request $request)
    {
        $user = $request->user();
        $employee = $user->employee;

        if ($employee === null) {
            return response()->json(['error' => 'Only field employees sync attendance.'], 403);
        }

        $result = $this->sync->process('attendance', $request, function () use ($request, $employee) {
            $validated = $request->validate([
                'customer_id' => ['required', 'exists:customer_master,customer_id'],
                'device_timestamp' => ['nullable', 'date'],
                'gps_latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'gps_longitude' => ['nullable', 'numeric', 'between:-180,180'],
                'gps_accuracy' => ['nullable', 'numeric', 'min:0'],
                'remarks' => ['nullable', 'string', 'max:255'],
            ]);

            ['attendance' => $attendance, 'device_timestamp_flag' => $flag] =
                $this->attendance->record($employee, $validated);

            return [
                'attendance_id' => $attendance->attendance_id,
                'customer_id' => $attendance->customer_id,
                'server_received_at' => $attendance->attendance_datetime->toIso8601String(),
                'device_timestamp' => $validated['device_timestamp'] ?? null,
                'device_timestamp_flag' => $flag,
                'synced_at' => now()->toIso8601String(),
            ];
        });

        return response()->json($result['payload'], $result['replayed'] ? 200 : 201)
            ->header('X-Idempotent-Replay', $result['replayed'] ? '1' : '0');
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', 15);

        return max(5, min(120, $perPage <= 0 ? 15 : $perPage));
    }
}
