<?php

namespace App\Http\Controllers;

use App\Enums\CustomerType;
use App\Models\CustomerFjp;
use App\Models\CustomerMaster;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', CustomerMaster::class);

        $q = $request->query('q');
        $type = $request->query('type');

        $customers = CustomerMaster::query()
            ->when($q, fn ($query) => $query->where(function ($w) use ($q) {
                $w->where('business_name', 'like', "%{$q}%")
                    ->orWhere('customer_id', 'like', "%{$q}%")
                    ->orWhere('ext_origin_id', 'like', "%{$q}%")
                    ->orWhere('city', 'like', "%{$q}%");
            }))
            ->when($type, fn ($query) => $query->where('customer_type', $type))
            ->with('parent')
            ->orderBy('business_name')
            ->paginate(20)
            ->withQueryString();

        return view('customers.index', [
            'customers' => $customers,
            'types' => array_column(CustomerType::cases(), 'value'),
            'filters' => ['q' => $q, 'type' => $type],
        ]);
    }

    public function create()
    {
        $this->authorize('create', CustomerMaster::class);

        return view('customers.form', [
            'customer' => new CustomerMaster,
            'primaries' => CustomerMaster::where('customer_type', CustomerType::PRIMARY)->orderBy('business_name')->get(),
            'types' => array_column(CustomerType::cases(), 'value'),
        ]);
    }

    public function show(CustomerMaster $customer)
    {
        $this->authorize('view', $customer);

        $companyId = (string) $this->companyContext->companyId();
        $isAssignedWithinCompany = $customer->employees()
            ->where('employee_master.company_id', $companyId)
            ->exists();

        return view('customers.show', [
            'customer' => $customer->load('salesRegion'),
            'companyId' => $companyId,
            'isAssignedWithinCompany' => $isAssignedWithinCompany,
            'plan' => CustomerFjp::query()
                ->forCompany($companyId)
                ->where('customer_id', $customer->customer_id)
                ->orderByRaw('preferred_week IS NULL')
                ->orderBy('preferred_week')
                ->orderBy('preferred_day')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', CustomerMaster::class);

        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'customer_type' => ['required', 'in:PRIMARY,SECONDARY,VAN,SHIP_TO'],
            'parent_customer_id' => ['nullable', 'exists:customer_master,customer_id'],
            'ext_origin_id' => ['nullable', 'string', 'max:100'],
            'ext_origin_company' => ['nullable', 'string', 'max:100'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:50'],
            'email_address' => ['nullable', 'email', 'max:255'],
            'gps_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'gps_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'address' => ['nullable', 'string', 'max:255'],
            'address2' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'country' => ['nullable', 'string', 'max:100'],
            'sales_region' => ['nullable', 'string', 'max:100'],
            'market' => ['nullable', 'string', 'max:100'],
            'active' => ['sometimes', 'boolean'],
        ]);

        // SHIP_TO → PRIMARY parent discipline (server-side validation,
        // surfaced as normal validation errors rather than aborts).
        $parentError = $this->parentRuleError($validated);

        if ($parentError !== null) {
            return back()->withErrors(['parent_customer_id' => $parentError])->withInput();
        }

        $validated['active'] = $request->boolean('active', true);

        CustomerMaster::create($validated);

        return redirect()->route('customers.index')->with('status', 'Customer created.');
    }

    public function edit(CustomerMaster $customer)
    {
        $this->authorize('update', $customer);

        return view('customers.form', [
            'customer' => $customer,
            'primaries' => CustomerMaster::where('customer_type', CustomerType::PRIMARY)
                ->where('customer_id', '!=', $customer->customer_id)
                ->orderBy('business_name')
                ->get(),
            'types' => array_column(CustomerType::cases(), 'value'),
        ]);
    }

    public function update(Request $request, CustomerMaster $customer)
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'customer_type' => ['required', 'in:PRIMARY,SECONDARY,VAN,SHIP_TO'],
            'parent_customer_id' => [
                'nullable',
                'exists:customer_master,customer_id',
                function (string $attribute, mixed $value, \Closure $fail) use ($customer) {
                    if ($value === $customer->customer_id) {
                        $fail('A customer cannot be its own parent.');
                    }
                },
            ],
            'ext_origin_id' => ['nullable', 'string', 'max:100'],
            'ext_origin_company' => ['nullable', 'string', 'max:100'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:50'],
            'email_address' => ['nullable', 'email', 'max:255'],
            'gps_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'gps_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'address' => ['nullable', 'string', 'max:255'],
            'address2' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'country' => ['nullable', 'string', 'max:100'],
            'sales_region' => ['nullable', 'string', 'max:100'],
            'market' => ['nullable', 'string', 'max:100'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $parentError = $this->parentRuleError($validated);

        if ($parentError !== null) {
            return back()->withErrors(['parent_customer_id' => $parentError])->withInput();
        }

        $customer->update($validated);

        return redirect()->route('customers.index')->with('status', 'Customer updated.');
    }

    public function deactivate(CustomerMaster $customer)
    {
        $this->authorize('delete', $customer);

        $customer->update(['active' => false]);

        return back()->with('status', 'Customer deactivated.');
    }

    public function activate(CustomerMaster $customer)
    {
        $this->authorize('update', $customer);

        $customer->update(['active' => true]);

        return back()->with('status', 'Customer activated.');
    }

    /**
     * SHIP_TO must have a PRIMARY parent; no other type may have one.
     * Returns a validation message or null when the rule passes.
     */
    private function parentRuleError(array $validated): ?string
    {
        $type = $validated['customer_type'];
        $parentId = $validated['parent_customer_id'] ?? null;

        if ($type === 'SHIP_TO') {
            if (empty($parentId)) {
                return 'A SHIP_TO location requires a PRIMARY parent customer.';
            }

            $parent = CustomerMaster::find($parentId);

            if ($parent === null || $parent->customer_type !== CustomerType::PRIMARY) {
                return 'A SHIP_TO location must belong to a PRIMARY customer.';
            }
        } elseif (! empty($parentId)) {
            return 'Only SHIP_TO customers may have a parent customer.';
        }

        return null;
    }
}
