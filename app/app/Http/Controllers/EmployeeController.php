<?php

namespace App\Http\Controllers;

use App\Models\AppUser;
use App\Models\CompanyMaster;
use App\Models\EmployeeMaster;
use App\Services\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class EmployeeController extends Controller
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', EmployeeMaster::class);

        $companyId = $this->resolveCompanyId($request);

        $employees = EmployeeMaster::query()
            ->forCompany($companyId)
            ->when($request->query('q'), fn ($query, $q) => $query->where(function ($w) use ($q) {
                $w->where('employee_name', 'like', "%{$q}%")
                    ->orWhere('employee_id', 'like', "%{$q}%");
            }))
            ->with('user')
            ->orderBy('employee_id')
            ->paginate(20)
            ->withQueryString();

        return view('employees.index', [
            'employees' => $employees,
            'companyId' => $companyId,
            'companies' => $this->companyChoices(),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', EmployeeMaster::class);

        return view('employees.form', [
            'employee' => new EmployeeMaster,
            'companyId' => $this->resolveCompanyId($request),
            'companies' => $this->companyChoices(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', EmployeeMaster::class);

        $companyId = $this->resolveCompanyId($request);

        $validated = $request->validate([
            'employee_id' => ['required', 'string', 'max:50', 'unique:employee_master,employee_id'],
            'employee_name' => ['required', 'string', 'max:255'],
            'email_address' => ['nullable', 'email', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:50'],
            'active' => ['sometimes', 'boolean'],
            'create_login' => ['sometimes', 'boolean'],
            'login_email' => ['nullable', 'required_if:create_login,1', 'email', 'max:255', 'unique:app_user,email'],
            'login_password' => ['nullable', 'required_if:create_login,1', Password::defaults()],
            'login_role' => ['nullable', 'required_if:create_login,1', Rule::in(['COMPANY_ADMIN', 'SALES_EMPLOYEE'])],
        ]);

        $employee = EmployeeMaster::create([
            'employee_id' => $validated['employee_id'],
            'company_id' => $companyId,
            'employee_name' => $validated['employee_name'],
            'email_address' => $validated['email_address'] ?? null,
            'phone_number' => $validated['phone_number'] ?? null,
            'active' => $request->boolean('active', true),
        ]);

        if ($request->boolean('create_login')) {
            AppUser::create([
                'employee_id' => $employee->employee_id,
                'company_id' => $companyId,
                'name' => $employee->employee_name,
                'email' => $validated['login_email'],
                'password_hash' => $validated['login_password'], // hashed by model mutator
                'role' => $validated['login_role'],
                'active' => true,
            ]);
        }

        return redirect()
            ->route('employees.index', $request->query('company') ? ['company' => $companyId] : [])
            ->with('status', 'Employee created.');
    }

    public function edit(EmployeeMaster $employee)
    {
        $this->authorize('update', $employee);

        return view('employees.form', [
            'employee' => $employee,
            'companyId' => $employee->company_id,
            'companies' => $this->companyChoices(),
        ]);
    }

    public function update(Request $request, EmployeeMaster $employee)
    {
        $this->authorize('update', $employee);

        $validated = $request->validate([
            'employee_name' => ['required', 'string', 'max:255'],
            'email_address' => ['nullable', 'email', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:50'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $employee->update([...$validated, 'active' => $request->boolean('active', $employee->active)]);

        // Keep the linked login name in sync.
        $employee->user?->update(['name' => $employee->employee_name]);

        return redirect()
            ->route('employees.index', $request->query('company') ? ['company' => $employee->company_id] : [])
            ->with('status', 'Employee updated.');
    }

    public function deactivate(EmployeeMaster $employee)
    {
        $this->authorize('delete', $employee);

        $employee->update(['active' => false]);
        // Deactivating an employee also disables their login.
        $employee->user()?->update(['active' => false]);

        return back()->with('status', 'Employee deactivated.');
    }

    public function activate(EmployeeMaster $employee)
    {
        $this->authorize('update', $employee);

        $employee->update(['active' => true]);

        return back()->with('status', 'Employee activated.');
    }

    private function resolveCompanyId(Request $request): string
    {
        $user = $request->user();

        if ($user->isSuperadmin()) {
            $requested = $request->query('company') ?? $request->input('company_id');

            if (is_string($requested) && $requested !== '' && CompanyMaster::where('company_id', $requested)->exists()) {
                return $requested;
            }
        }

        return $this->companyContext->companyId();
    }

    private function companyChoices()
    {
        $user = auth()->user();

        if ($user->isSuperadmin()) {
            return CompanyMaster::where('active', true)->orderBy('company_id')->get();
        }

        return CompanyMaster::where('company_id', $user->company_id)->get();
    }
}
