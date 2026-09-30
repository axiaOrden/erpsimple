<?php

namespace App\Services;

use App\Models\CustomerEmployee;
use App\Models\CustomerMaster;
use App\Models\CustomerVisitAttendance;
use App\Models\EmployeeMaster;
use Illuminate\Support\Carbon;

/**
 * Customer visit attendance (rule 6).
 *
 * Multiple records per employee/customer/day are allowed:
 * MIN(attendance_datetime) = effective check-in,
 * MAX(attendance_datetime) = effective check-out.
 *
 * Offline sync (Phase 3): operations carry a client UUID; the device-captured
 * timestamp is preserved in the payload and echoed back, while the server
 * records its own received/synced times. Obvious clock skew is flagged, not
 * silently rewritten.
 */
class AttendanceService
{
    /** Device/server clock difference tolerated before flagging as skew. */
    private const MAX_CLOCK_SKEW_MINUTES = 30;

    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * Record an attendance ping. Returns the created model.
     *
     * @param  array{idempotency_key?: string, customer_id: string, device_timestamp?: string|null,
     *        gps_latitude?: float|null, gps_longitude?: float|null, gps_accuracy?: float|null,
     *        remarks?: string|null}  $data
     * @return array{attendance: CustomerVisitAttendance, device_timestamp_flag: ?string}
     */
    public function record(EmployeeMaster $employee, array $data): array
    {
        $customer = CustomerMaster::where('customer_id', $data['customer_id'])->first();

        if ($customer === null || ! $customer->active) {
            abort(422, 'Unknown or inactive customer.');
        }

        $this->assertEmployeeAssigned($employee, $customer);

        // Device-claimed capture time is PRESERVED (device_captured_at) but is
        // never authoritative: attendance_datetime (server receive time) orders
        // attendance. Material skew sets device_timestamp_flag = true.
        $flag = false;
        $flagMessage = null;
        $deviceCapturedAt = null;

        if (! empty($data['device_timestamp'])) {
            try {
                $device = Carbon::parse($data['device_timestamp']);
                $deviceCapturedAt = $device;
                $skew = $device->diffInMinutes(now(), false);

                if (abs((float) $skew) > self::MAX_CLOCK_SKEW_MINUTES) {
                    $flag = true;
                    $flagMessage = sprintf(
                        'Device clock differs by %d minutes from server time; server receive time was used for ordering.',
                        (int) abs((float) $skew),
                    );
                }
            } catch (\Throwable) {
                $flag = true;
                $flagMessage = 'Device timestamp was unparseable and was ignored for ordering; server receive time was used.';
            }
        }

        $attendance = CustomerVisitAttendance::create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->employee_id,
            'customer_id' => $customer->customer_id,
            'attendance_datetime' => now(),
            'device_captured_at' => $deviceCapturedAt,
            'device_timestamp_flag' => $flag,
            'gps_latitude' => $data['gps_latitude'] ?? null,
            'gps_longitude' => $data['gps_longitude'] ?? null,
            'gps_accuracy' => $data['gps_accuracy'] ?? null,
            'remarks' => $data['remarks'] ?? null,
        ]);

        return ['attendance' => $attendance, 'device_timestamp_flag' => $flagMessage];
    }

    /**
     * Effective in/out summary for an employee/customer/day.
     *
     * @return array{records: int, check_in: ?string, check_out: ?string}
     */
    public function effectiveVisit(EmployeeMaster $employee, string $customerId, Carbon $day): array
    {
        $records = CustomerVisitAttendance::where('employee_id', $employee->employee_id)
            ->where('customer_id', $customerId)
            ->whereDate('attendance_datetime', $day->toDateString())
            ->orderBy('attendance_datetime')
            ->get();

        return [
            'records' => $records->count(),
            'check_in' => $records->first()?->attendance_datetime?->format('H:i'),
            'check_out' => $records->last()?->attendance_datetime?->format('H:i'),
        ];
    }

    /** An employee may only record attendance for customers assigned to them. */
    private function assertEmployeeAssigned(EmployeeMaster $employee, CustomerMaster $customer): void
    {
        $assigned = CustomerEmployee::where('employee_id', $employee->employee_id)
            ->where('customer_id', $customer->customer_id)
            ->exists();

        if (! $assigned) {
            abort(403, 'You are not assigned to this customer.');
        }
    }
}
