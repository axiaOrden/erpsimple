<?php

namespace App\Http\Controllers;

use App\Models\CompanyMaster;
use App\Models\CustomerFjp;
use App\Models\CustomerMaster;
use App\Models\EmployeeMaster;
use App\Services\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Fixed Journey Plan admin (rule 5): company + employee + customer + week/day.
 * FJP never implies a supplying Primary; it schedules visits (primarily to
 * Secondary customers).
 */
class FjpController extends Controller
{
    private const DAYS = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];

    public function __construct(private readonly CompanyContext $companyContext) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', EmployeeMaster::class);

        $companyId = $this->resolveCompanyId($request);

        $plans = CustomerFjp::query()
            ->forCompany($companyId)
            ->with(['employee', 'customer'])
            ->when($request->query('q'), fn ($q, $term) => $q->whereHas('customer', fn ($c) => $c->where('business_name', 'like', "%{$term}%")))
            ->orderBy('employee_id')
            ->orderBy('customer_id')
            ->paginate(20)
            ->withQueryString();

        return view('fjp.index', [
            'plans' => $plans,
            'companyId' => $companyId,
            'companies' => $this->companyChoices(),
            'days' => self::DAYS,
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', CustomerFjp::class);

        $companyId = $this->resolveCompanyId($request);

        return view('fjp.form', [
            'plan' => new CustomerFjp,
            'companyId' => $companyId,
            'employees' => EmployeeMaster::forCompany($companyId)->where('active', true)->orderBy('employee_name')->get(),
            'customers' => CustomerMaster::where('active', true)->orderBy('business_name')->get(),
            'days' => self::DAYS,
            'companies' => $this->companyChoices(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', CustomerFjp::class);

        $companyId = $this->resolveCompanyId($request);

        $validated = $request->validate([
            'employee_id' => ['required', Rule::exists('employee_master', 'employee_id')->where('company_id', $companyId)],
            'customer_id' => ['required', 'exists:customer_master,customer_id'],
            'preferred_week' => ['nullable', 'integer', 'min:1', 'max:4'],
            'preferred_day' => ['required', Rule::in(self::DAYS)],
            'active' => ['sometimes', 'boolean'],
        ]);

        $this->assertSecondaryPreference($validated['customer_id']);

        CustomerFjp::create($validated + [
            'company_id' => $companyId,
            'active' => $request->boolean('active', true),
        ]);

        return redirect()
            ->route('fjp.index', $request->query('company') ? ['company' => $companyId] : [])
            ->with('status', 'Journey plan created.');
    }

    public function edit(Request $request, CustomerFjp $plan)
    {
        $this->authorize('update', $plan->employee);

        return view('fjp.form', [
            'plan' => $plan,
            'companyId' => $plan->company_id,
            'employees' => EmployeeMaster::forCompany($plan->company_id)->orderBy('employee_name')->get(),
            'customers' => CustomerMaster::where('active', true)->orderBy('business_name')->get(),
            'days' => self::DAYS,
            'companies' => $this->companyChoices(),
        ]);
    }

    public function update(Request $request, CustomerFjp $plan)
    {
        $this->authorize('update', $plan->employee);

        $validated = $request->validate([
            'employee_id' => ['required', Rule::exists('employee_master', 'employee_id')->where('company_id', $plan->company_id)],
            'customer_id' => ['required', 'exists:customer_master,customer_id'],
            'preferred_week' => ['nullable', 'integer', 'min:1', 'max:4'],
            'preferred_day' => ['required', Rule::in(self::DAYS)],
            'active' => ['sometimes', 'boolean'],
        ]);

        $this->assertSecondaryPreference($validated['customer_id']);

        $plan->update($validated + ['active' => $request->boolean('active', $plan->active)]);

        return redirect()
            ->route('fjp.index', $request->query('company') ? ['company' => $plan->company_id] : [])
            ->with('status', 'Journey plan updated.');
    }

    public function deactivate(CustomerFjp $plan)
    {
        $this->authorize('update', $plan->employee);

        $plan->update(['active' => false]);

        return back()->with('status', 'Journey plan deactivated.');
    }

    public function activate(CustomerFjp $plan)
    {
        $this->authorize('update', $plan->employee);

        $plan->update(['active' => true]);

        return back()->with('status', 'Journey plan activated.');
    }

    /** FJP is primarily for SECONDARY visits (rule 5); primaries allowed but flagged in UI copy only. */
    private function assertSecondaryPreference(string $customerId): void
    {
        // Intentionally non-blocking: PRIMARY/VAN visits can be scheduled too.
        // The business intent (secondary-first) is surfaced in the UI copy.
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

        return (string) $this->companyContext->companyId();
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
