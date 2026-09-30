<?php

namespace Tests\Unit;

use App\Models\CompanyMaster;
use App\Models\CustomerMaster;
use App\Models\CustomerVisitAttendance;
use App\Models\EmployeeMaster;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AttendanceEffectiveVisitTest extends TestCase
{
    use DatabaseTransactions;

    public function test_min_is_checkin_and_max_is_checkout(): void
    {
        $company = CompanyMaster::factory()->create();
        $employee = EmployeeMaster::factory()->create(['company_id' => $company->company_id]);
        $customer = CustomerMaster::factory()->forEmployee($employee)->create();

        // Three legitimate pings on the same day.
        CustomerVisitAttendance::create([
            'company_id' => $company->company_id,
            'employee_id' => $employee->employee_id,
            'customer_id' => $customer->customer_id,
            'attendance_datetime' => Carbon::today()->setTime(9, 2),
        ]);
        CustomerVisitAttendance::create([
            'company_id' => $company->company_id,
            'employee_id' => $employee->employee_id,
            'customer_id' => $customer->customer_id,
            'attendance_datetime' => Carbon::today()->setTime(10, 15),
        ]);
        CustomerVisitAttendance::create([
            'company_id' => $company->company_id,
            'employee_id' => $employee->employee_id,
            'customer_id' => $customer->customer_id,
            'attendance_datetime' => Carbon::today()->setTime(11, 45),
        ]);

        $service = app(AttendanceService::class);
        $summary = $service->effectiveVisit($employee, $customer->customer_id, Carbon::today());

        $this->assertSame(3, $summary['records']);
        $this->assertSame('09:02', $summary['check_in']);
        $this->assertSame('11:45', $summary['check_out']);
    }

    public function test_other_days_do_not_leak_into_summary(): void
    {
        $company = CompanyMaster::factory()->create();
        $employee = EmployeeMaster::factory()->create(['company_id' => $company->company_id]);
        $customer = CustomerMaster::factory()->forEmployee($employee)->create();

        CustomerVisitAttendance::create([
            'company_id' => $company->company_id,
            'employee_id' => $employee->employee_id,
            'customer_id' => $customer->customer_id,
            'attendance_datetime' => Carbon::today()->subDay()->setTime(8, 0),
        ]);
        CustomerVisitAttendance::create([
            'company_id' => $company->company_id,
            'employee_id' => $employee->employee_id,
            'customer_id' => $customer->customer_id,
            'attendance_datetime' => Carbon::today()->setTime(14, 30),
        ]);

        $service = app(AttendanceService::class);
        $summary = $service->effectiveVisit($employee, $customer->customer_id, Carbon::today());

        $this->assertSame(1, $summary['records']);
        $this->assertSame('14:30', $summary['check_in']);
    }
}
