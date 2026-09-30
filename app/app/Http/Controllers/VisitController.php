<?php

namespace App\Http\Controllers;

use App\Models\CustomerEmployee;
use App\Models\CustomerFjp;
use App\Models\CustomerMaster;
use App\Models\CustomerVisitAttendance;
use App\Services\AttendanceService;
use App\Services\FjpRotationService;
use App\Services\SyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Field-sales visit workflow (Phase 3): today's FJP visits, attendance
 * capture (online + offline-synced), and the customer field view.
 */
class VisitController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly SyncService $sync,
        private readonly FjpRotationService $rotation,
    ) {}

    /**
     * Mobile-first "Today's visits" experience.
     */
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
            ]);
        }

        $today = today();

        // FJP matching: current 4-week rotation week + today's weekday.
        // preferred_week is a continuous rotation position (1-4), NOT
        // week-of-month; null = every week on the configured day.
        $rotationWeek = $this->rotation->rotationWeek($today);

        $fjpToday = CustomerFjp::with('customer')
            ->where('employee_id', $employee->employee_id)
            ->where('active', true)
            ->where('preferred_day', strtoupper($today->format('l')))
            ->where(fn ($q) => $q->whereNull('preferred_week')->orWhere('preferred_week', $rotationWeek))
            ->get();

        // All assigned customers (also surfaced as manual visit options).
        $assignedCustomers = CustomerMaster::whereIn(
            'customer_id',
            CustomerEmployee::where('employee_id', $employee->employee_id)->pluck('customer_id'),
        )->where('active', true)->orderBy('business_name')->get();

        // Attendance today, grouped per customer for status chips.
        $attendanceToday = CustomerVisitAttendance::where('employee_id', $employee->employee_id)
            ->whereDate('attendance_datetime', $today)
            ->get()
            ->groupBy('customer_id');

        $visits = $fjpToday->map(fn (CustomerFjp $fjp) => $this->visitRow(
            $fjp->customer,
            $attendanceToday->get($fjp->customer_id),
        ));

        return view('visits.today', [
            'employee' => $employee,
            'visits' => $visits,
            'assignedCustomers' => $assignedCustomers,
            'attendanceToday' => $attendanceToday,
        ]);
    }

    /**
     * Customer field view: stock-ish info, previous visits, quick actions.
     */
    public function customer(Request $request, string $customerId): View
    {
        $user = $request->user();
        $employee = $user->employee;
        abort_if($employee === null, 403);

        $customer = CustomerMaster::where('customer_id', $customerId)->firstOrFail();

        // Assignment is the commercial relationship — server-side enforcement.
        $assigned = CustomerEmployee::where('employee_id', $employee->employee_id)
            ->where('customer_id', $customer->customer_id)
            ->exists();

        abort_unless($assigned, 403, 'You are not assigned to this customer.');

        $visits = CustomerVisitAttendance::where('employee_id', $employee->employee_id)
            ->where('customer_id', $customer->customer_id)
            ->orderByDesc('attendance_datetime')
            ->limit(10)
            ->get();

        $todaySummary = $this->attendance->effectiveVisit($employee, $customer->customer_id, today());

        return view('visits.customer', [
            'customer' => $customer,
            'visits' => $visits,
            'todaySummary' => $todaySummary,
            'inventory' => in_array($customer->customer_type->value, ['PRIMARY', 'SHIP_TO', 'VAN'], true)
                ? $customer->inventory()->with('product')->orderBy('product_id')->get()
                : collect(),
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

    /**
     * @param  Collection<int, CustomerVisitAttendance>|Collection<int, Collection<int, CustomerVisitAttendance>>  $records
     */
    private function visitRow(CustomerMaster $customer, $records): array
    {
        $flat = $records?->flatten();

        $checkIn = $flat?->min('attendance_datetime');
        $checkOut = $flat?->max('attendance_datetime');

        $status = 'PENDING';

        if ($flat !== null && $flat->isNotEmpty()) {
            $status = $flat->count() === 1 ? 'CHECKED_IN' : 'CHECKED_OUT';
        }

        return [
            'customer' => $customer,
            'records' => $flat?->count() ?? 0,
            'check_in' => $checkIn?->format('H:i'),
            'check_out' => $checkOut?->format('H:i'),
            'status' => $status,
        ];
    }
}
