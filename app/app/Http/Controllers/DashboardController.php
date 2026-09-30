<?php

namespace App\Http\Controllers;

use App\Models\AppUser;
use App\Models\CompanyMaster;
use App\Models\CustomerEmployee;
use App\Models\CustomerFjp;
use App\Models\CustomerMaster;
use App\Models\CustomerVisitAttendance;
use App\Models\EmployeeMaster;
use App\Models\ProductMaster;
use App\Services\FjpRotationService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Role-aware dashboards. Phase 1 shows meaningful, real counts from the
 * master data (no invented KPIs); operational panels fill in as later
 * phases land.
 */
class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        return match (true) {
            $user->isSalesEmployee() => $this->salesEmployee($user),
            $user->isCompanyAdmin() => $this->companyAdmin($user),
            default => $this->superadmin($user),
        };
    }

    private function salesEmployee(AppUser $user): View
    {
        $employee = $user->employee;

        $assignedCustomerIds = $employee
            ? CustomerEmployee::where('employee_id', $employee->employee_id)->pluck('customer_id')
            : collect();

        $customers = CustomerMaster::whereIn('customer_id', $assignedCustomerIds)
            ->where('active', true)
            ->orderBy('business_name')
            ->get();

        // Today's Fixed Journey Plan visits (rotation week + weekday match).
        $today = strtoupper(now()->format('l'));
        $rotationWeek = app(FjpRotationService::class)->rotationWeek(now());

        $todayVisits = $employee
            ? CustomerFjp::with('customer')
                ->where('employee_id', $employee->employee_id)
                ->where('active', true)
                ->where('preferred_day', $today)
                ->where(fn ($q) => $q->whereNull('preferred_week')->orWhere('preferred_week', $rotationWeek))
                ->orderBy('preferred_week')
                ->get()
            : collect();

        // Visit attendance recorded today (MIN = check-in, MAX = check-out).
        $attendanceToday = $employee
            ? CustomerVisitAttendance::where('employee_id', $employee->employee_id)
                ->whereDate('attendance_datetime', today())
                ->orderBy('attendance_datetime')
                ->get()
            : collect();

        return view('dashboard.employee', [
            'user' => $user,
            'employee' => $employee,
            'customers' => $customers,
            'todayVisits' => $todayVisits,
            'attendanceToday' => $attendanceToday,
            'checkIn' => $attendanceToday->first(),
            'checkOut' => $attendanceToday->last(),
        ]);
    }

    private function companyAdmin(AppUser $user): View
    {
        $companyId = $user->company_id;

        return view('dashboard.company-admin', [
            'company' => CompanyMaster::find($companyId),
            'productCount' => ProductMaster::where('company_id', $companyId)->where('active', true)->count(),
            'customerCount' => CustomerMaster::where('active', true)->count(),
            'employeeCount' => EmployeeMaster::where('company_id', $companyId)->where('active', true)->count(),
            'userCount' => AppUser::where('company_id', $companyId)->where('active', true)->count(),
        ]);
    }

    private function superadmin(AppUser $user): View
    {
        $companies = CompanyMaster::orderBy('company_id')->get()->map(fn (CompanyMaster $c) => [
            'company' => $c,
            'products' => ProductMaster::where('company_id', $c->company_id)->count(),
            'employees' => EmployeeMaster::where('company_id', $c->company_id)->count(),
            'users' => AppUser::where('company_id', $c->company_id)->count(),
        ]);

        return view('dashboard.superadmin', ['companies' => $companies]);
    }
}
