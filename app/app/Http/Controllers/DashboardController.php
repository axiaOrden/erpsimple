<?php

namespace App\Http\Controllers;

use App\Models\AppUser;
use App\Models\CompanyMaster;
use App\Models\CustomerMaster;
use App\Models\CustomerVisitAttendance;
use App\Models\EmployeeMaster;
use App\Models\ProductMaster;
use App\Services\FieldDirectoryService;
use App\Services\SalesLifecycleService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Role-aware dashboards.
 *
 * The SALES EMPLOYEE home is an OPERATIONAL dashboard (what am I selling
 * today, where is each SKU in its lifecycle, whom do I need to visit, what
 * needs my attention) — not a menu of ERP modules. Administrators keep their
 * master-data overview.
 *
 * All figures come from the read services (SalesLifecycleService /
 * FieldDirectoryService); nothing is recomputed in Blade.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly SalesLifecycleService $lifecycle,
        private readonly FieldDirectoryService $directory,
    ) {}

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

        if ($employee === null) {
            return view('dashboard.employee', [
                'user' => $user,
                'employee' => null,
                'greeting' => $this->greeting(),
                'lifecycle' => null,
                'attention' => null,
                'plan' => collect(),
                'primaries' => collect(),
                'assignedCount' => 0,
                'attendanceToday' => collect(),
            ]);
        }

        $plan = $this->directory->todayPlan($employee, null, 20);

        return view('dashboard.employee', [
            'user' => $user,
            'employee' => $employee,
            'greeting' => $this->greeting(),
            'lifecycle' => $this->lifecycle->todayLifecycle($employee),
            'attention' => $this->directory->attention($employee, $this->lifecycle),
            'plan' => $plan['rows'],
            'planTotal' => $plan['total'],
            'primaries' => $this->directory->primaryDirectory($employee)->take(4),
            'assignedCount' => $this->directory->assignedCustomerIds($employee)->count(),
            'attendanceToday' => CustomerVisitAttendance::where('employee_id', $employee->employee_id)
                ->whereDate('attendance_datetime', today())
                ->orderBy('attendance_datetime')
                ->get(),
        ]);
    }

    private function greeting(): string
    {
        $hour = (int) now()->format('H');

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
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
