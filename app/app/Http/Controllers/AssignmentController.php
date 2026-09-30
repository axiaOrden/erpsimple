<?php

namespace App\Http\Controllers;

use App\Models\CustomerEmployee;
use App\Models\CustomerMaster;
use App\Models\EmployeeMaster;
use App\Models\EmployeeProduct;
use App\Models\ProductMaster;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

/**
 * Manages the two assignment relations for one employee:
 *  - customer_employee (which customers the employee sells to)
 *  - employee_product  (product scope; empty = all company products)
 */
class AssignmentController extends Controller
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    public function edit(Request $request, EmployeeMaster $employee)
    {
        $this->authorize('update', $employee);

        $assignedCustomerIds = CustomerEmployee::where('employee_id', $employee->employee_id)
            ->pluck('customer_id');

        $customers = CustomerMaster::where('active', true)
            ->orderBy('business_name')
            ->get()
            ->map(fn (CustomerMaster $c) => [
                'model' => $c,
                'assigned' => $assignedCustomerIds->contains($c->customer_id),
            ]);

        $scopedProductIds = EmployeeProduct::where('employee_id', $employee->employee_id)
            ->pluck('product_id');

        $products = ProductMaster::forCompany($employee->company_id)
            ->with('basicUnit')
            ->orderBy('product_id')
            ->get()
            ->map(fn (ProductMaster $p) => [
                'model' => $p,
                'scoped' => $scopedProductIds->contains($p->product_id),
            ]);

        return view('assignments.edit', [
            'employee' => $employee,
            'customers' => $customers,
            'products' => $products,
            'hasProductScope' => $scopedProductIds->isNotEmpty(),
        ]);
    }

    public function update(Request $request, EmployeeMaster $employee)
    {
        $this->authorize('update', $employee);

        $validated = $request->validate([
            'customer_ids' => ['nullable', 'array'],
            'customer_ids.*' => ['string', 'exists:customer_master,customer_id'],
            'product_scope_mode' => ['required', 'in:ALL,SELECTED'],
            'product_ids' => ['nullable', 'array', 'required_if:product_scope_mode,SELECTED'],
            'product_ids.*' => ['string', 'exists:product_master,product_id'],
        ]);

        // --- customer assignments (global customer list; role SE) ---
        $selectedCustomers = collect($validated['customer_ids'] ?? []);

        // Only customers belonging to the employee's company may be assigned.
        $validCustomers = $selectedCustomers->filter(function ($customerId) {
            // Customers are global; any active customer may be assigned. Keep
            // validation strict anyway: must exist and be active.
            return CustomerMaster::where('customer_id', $customerId)->where('active', true)->exists();
        });

        CustomerEmployee::where('employee_id', $employee->employee_id)
            ->whereNotIn('customer_id', $validCustomers->values())
            ->delete();

        foreach ($validCustomers as $customerId) {
            CustomerEmployee::updateOrCreate(
                ['customer_id' => $customerId, 'employee_id' => $employee->employee_id, 'role' => 'SE'],
                [],
            );
        }

        // --- product scope ---
        EmployeeProduct::where('employee_id', $employee->employee_id)->delete();

        if ($validated['product_scope_mode'] === 'SELECTED') {
            $productIds = collect($validated['product_ids'] ?? []);

            // Products must belong to the employee's company (server-side scope).
            $companyProductIds = ProductMaster::forCompany($employee->company_id)
                ->pluck('product_id');

            foreach ($productIds as $productId) {
                if ($companyProductIds->contains($productId)) {
                    EmployeeProduct::create([
                        'employee_id' => $employee->employee_id,
                        'product_id' => $productId,
                    ]);
                }
            }
        }
        // product_scope_mode ALL → no employee_product rows at all.

        return redirect()
            ->route('employees.index', $request->query('company') ? ['company' => $employee->company_id] : [])
            ->with('status', 'Assignments updated.');
    }
}
