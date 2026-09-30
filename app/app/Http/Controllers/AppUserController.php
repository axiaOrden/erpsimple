<?php

namespace App\Http\Controllers;

use App\Models\AppUser;
use App\Models\CompanyMaster;
use App\Models\EmployeeMaster;
use App\Services\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class AppUserController extends Controller
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', AppUser::class);

        $companyId = $this->resolveCompanyId($request);

        $users = AppUser::query()
            ->when($companyId !== null && ! $request->user()->isSuperadmin(), fn ($q) => $q->where('company_id', $companyId))
            ->when($request->user()->isSuperadmin() && $request->filled('company'), fn ($q) => $q->where('company_id', $request->query('company')))
            ->when($request->query('q'), fn ($query, $q) => $query->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            }))
            ->with(['company', 'employee'])
            ->orderBy('user_id')
            ->paginate(20)
            ->withQueryString();

        return view('users.index', [
            'users' => $users,
            'companyId' => $companyId,
            'companies' => $this->companyChoices(),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', AppUser::class);

        $companyId = $this->resolveCompanyId($request);

        return view('users.form', [
            'user' => new AppUser,
            'companyId' => $companyId,
            'employees' => EmployeeMaster::forCompany($companyId)->where('active', true)->orderBy('employee_name')->get(),
            'companies' => $this->companyChoices(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', AppUser::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:app_user,email'],
            'password' => ['required', Password::defaults()],
            'role' => ['required', 'in:SUPERADMIN,COMPANY_ADMIN,SALES_EMPLOYEE'],
            'employee_id' => ['nullable', 'exists:employee_master,employee_id'],
            'company_id' => ['nullable', 'exists:company_master,company_id'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $actor = $request->user();

        if ($actor->isCompanyAdmin()) {
            // Server-side scope enforcement.
            if ($validated['role'] === 'SUPERADMIN') {
                abort(403, 'Company admins cannot create superadmins.');
            }

            $validated['company_id'] = $actor->company_id;

            if (! empty($validated['employee_id'])) {
                $employee = EmployeeMaster::find($validated['employee_id']);
                abort_if($employee === null || $employee->company_id !== $actor->company_id, 403, 'Employee belongs to another company.');
            }
        }

        if ($validated['role'] === 'SUPERADMIN') {
            $validated['employee_id'] = null;
            $validated['company_id'] = null;
        }

        $validated['active'] = $request->boolean('active', true);

        AppUser::create($validated); // password hashed by model mutator

        return redirect()->route('users.index')->with('status', 'User created.');
    }

    public function edit(Request $request, AppUser $user)
    {
        $this->authorize('update', $user);

        $companyId = $user->company_id ?? $this->companyContext->companyId();

        return view('users.form', [
            'user' => $user,
            'companyId' => $companyId,
            'employees' => $companyId !== null
                ? EmployeeMaster::forCompany($companyId)->orderBy('employee_name')->get()
                : collect(),
            'companies' => $this->companyChoices(),
        ]);
    }

    public function update(Request $request, AppUser $user)
    {
        $this->authorize('update', $user);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
            'password' => ['nullable', Password::defaults()],
            'role' => ['sometimes', 'in:SUPERADMIN,COMPANY_ADMIN,SALES_EMPLOYEE'],
            'employee_id' => ['nullable', 'exists:employee_master,employee_id'],
        ]);

        $actor = $request->user();

        if ($actor->isCompanyAdmin()) {
            unset($validated['role']); // admins cannot change roles

            if (! empty($validated['employee_id'])) {
                $employee = EmployeeMaster::find($validated['employee_id']);
                abort_if($employee === null || $employee->company_id !== $actor->company_id, 403, 'Employee belongs to another company.');
            }
        }

        if ($user->isSuperadmin()) {
            $validated['employee_id'] = null; // superadmins stay unbound
        }

        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        $user->update($validated);

        return redirect()->route('users.index')->with('status', 'User updated.');
    }

    public function deactivate(AppUser $user)
    {
        $this->authorize('delete', $user);

        $user->update(['active' => false]);

        return back()->with('status', 'User deactivated.');
    }

    public function activate(AppUser $user)
    {
        $this->authorize('update', $user);

        $user->update(['active' => true]);

        return back()->with('status', 'User activated.');
    }

    private function resolveCompanyId(Request $request): ?string
    {
        $user = $request->user();

        if ($user->isSuperadmin()) {
            $requested = $request->query('company') ?? $request->input('company_id');

            if (is_string($requested) && $requested !== '' && CompanyMaster::where('company_id', $requested)->exists()) {
                return $requested;
            }

            return $this->companyContext->companyId();
        }

        return $user->company_id;
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
