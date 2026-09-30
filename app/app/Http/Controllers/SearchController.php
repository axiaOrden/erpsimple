<?php

namespace App\Http\Controllers;

use App\Enums\CustomerType;
use App\Models\CustomerEmployee;
use App\Models\CustomerMaster;
use App\Models\EmployeeMaster;
use App\Models\EmployeeProduct;
use App\Models\ProductMaster;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

/**
 * Server-side searchable selectors (rule 31): the browser never receives
 * whole master-data tables; each endpoint returns a small JSON page of
 * matches scoped to the caller's company/assignment rights.
 */
class SearchController extends Controller
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    public function customers(Request $request)
    {
        $q = (string) $request->query('q', '');
        $user = $request->user();

        $query = CustomerMaster::query()
            ->where('active', true)
            ->when($q !== '', fn ($w) => $w->where(function ($x) use ($q) {
                $x->where('business_name', 'like', "%{$q}%")
                    ->orWhere('customer_id', 'like', "%{$q}%")
                    ->orWhere('city', 'like', "%{$q}%");
            }))
            ->orderBy('business_name')
            ->limit(15);

        // Sales employees only see customers assigned to them.
        if ($user->isSalesEmployee() && $user->employee_id !== null) {
            $assigned = CustomerEmployee::where('employee_id', $user->employee_id)
                ->pluck('customer_id');

            $query->whereIn('customer_id', $assigned);
        }

        return response()->json([
            'results' => $query->get()->map(fn (CustomerMaster $c) => [
                'id' => $c->customer_id,
                'label' => $c->business_name,
                'meta' => $c->customer_type->value.($c->city ? ' · '.$c->city : ''),
                'type' => $c->customer_type->value,
                'parent_customer_id' => $c->parent_customer_id,
            ]),
        ]);
    }

    public function supplyingPrimaries(Request $request)
    {
        // PRIMARY customers only (potential supplying distributors).
        $q = (string) $request->query('q', '');

        $primaries = CustomerMaster::query()
            ->where('active', true)
            ->where('customer_type', CustomerType::PRIMARY)
            ->when($q !== '', fn ($w) => $w->where(function ($x) use ($q) {
                $x->where('business_name', 'like', "%{$q}%")
                    ->orWhere('customer_id', 'like', "%{$q}%");
            }))
            ->orderBy('business_name')
            ->limit(15)
            ->get();

        return response()->json([
            'results' => $primaries->map(fn (CustomerMaster $c) => [
                'id' => $c->customer_id,
                'label' => $c->business_name,
                'meta' => 'PRIMARY'.($c->city ? ' · '.$c->city : ''),
            ]),
        ]);
    }

    public function products(Request $request)
    {
        $q = (string) $request->query('q', '');
        $user = $request->user();
        $companyId = $this->companyContext->companyId();

        if ($companyId === null) {
            return response()->json(['results' => []]);
        }

        $query = ProductMaster::query()
            ->forCompany($companyId)
            ->where('active', true)
            ->when($q !== '', fn ($w) => $w->where(function ($x) use ($q) {
                $x->where('product_description', 'like', "%{$q}%")
                    ->orWhere('product_sku', 'like', "%{$q}%");
            }))
            ->with('basicUnit')
            ->orderBy('product_description')
            ->limit(15);

        // Employee product scope: rows exist ⇒ restricted to those products.
        if ($user->isSalesEmployee() && $user->employee_id !== null) {
            $scoped = EmployeeProduct::where('employee_id', $user->employee_id)
                ->pluck('product_id');

            if ($scoped->isNotEmpty()) {
                $query->whereIn('product_id', $scoped);
            }
        }

        return response()->json([
            'results' => $query->get()->map(fn (ProductMaster $p) => [
                'id' => $p->product_id,
                'label' => $p->product_description,
                'meta' => $p->product_sku.' · '.$p->basic_unit,
                'basic_unit' => $p->basic_unit,
            ]),
        ]);
    }

    public function employees(Request $request)
    {
        $this->authorize('viewAny', EmployeeMaster::class);

        $q = (string) $request->query('q', '');
        $companyId = $this->companyContext->companyId();

        $employees = EmployeeMaster::query()
            ->forCompany((string) $companyId)
            ->where('active', true)
            ->when($q !== '', fn ($w) => $w->where(function ($x) use ($q) {
                $x->where('employee_name', 'like', "%{$q}%")
                    ->orWhere('employee_id', 'like', "%{$q}%");
            }))
            ->orderBy('employee_name')
            ->limit(15)
            ->get();

        return response()->json([
            'results' => $employees->map(fn (EmployeeMaster $e) => [
                'id' => $e->employee_id,
                'label' => $e->employee_name,
                'meta' => $e->company_id,
            ]),
        ]);
    }
}
