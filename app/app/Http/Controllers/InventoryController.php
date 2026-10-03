<?php

namespace App\Http\Controllers;

use App\Enums\CountType;
use App\Enums\MovementType;
use App\Models\AppUser;
use App\Models\CustomerEmployee;
use App\Models\CustomerMaster;
use App\Models\EmployeeProduct;
use App\Models\Inventory;
use App\Models\ProductMaster;
use App\Models\StockCount;
use App\Services\InventoryService;
use App\Services\StockCountService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Phase 5 — inventory visibility, physical adjustments and stock counts.
 * Customers are global: company context comes from the PRODUCT (master data),
 * never from a customer_id column.
 */
class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly StockCountService $counts,
    ) {}

    /**
     * Stock overview. Sales employees see stock at the customers they are
     * assigned to; company admins/superadmins see their company-product
     * inventory across ALL stock holders.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $view = $this->visibleInventoryQuery($user)
            ->with(['customer', 'product'])
            ->orderBy('customer_id')
            ->orderBy('product_id')
            ->get();

        $stockHolders = $this->visibleStockHolders($user);
        $products = ProductMaster::query()->orderBy('product_description')->get();

        return view('inventory.index', [
            'rows' => $view,
            'stockHolders' => $stockHolders,
            'products' => $products,
            'filters' => $request->only(['customer_id', 'product_id', 'customer_type']),
        ]);
    }

    /** Adjust (receive/damage/correct) physical stock — admins only. */
    public function adjust(Request $request)
    {
        $this->authorizeAdjust($request->user());

        $validated = $request->validate([
            'customer_id' => ['required', 'exists:customer_master,customer_id'],
            'product_id' => ['required', 'exists:product_master,product_id'],
            'qty' => ['required', 'numeric'],
            'unit' => ['required', 'string'],
            'movement_type' => ['required', Rule::in(['GOODS_RECEIPT', 'ADJUSTMENT', 'DAMAGE'])],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->inventory->adjustPhysical(
            $request->user()->employee,
            $validated['customer_id'],
            $validated['product_id'],
            $validated['qty'],
            $validated['unit'],
            MovementType::from($validated['movement_type']),
            ['remarks' => $validated['remarks'] ?? null],
        );

        return back()->with('status', 'Stock adjusted: '.$result['movement']->movement_type->value
            .' '.rtrim(rtrim($result['movement']->quantity, '0'), '.').' '.$result['movement']->basic_unit
            .' at '.$validated['customer_id'].'.');
    }

    /** Stock count creation form. */
    public function createCount(Request $request)
    {
        $user = $request->user();
        $employee = $user->employee;

        abort_if($employee === null, 403, 'Your login is not linked to an employee record.');

        $customers = $this->visibleCountCustomers($user)->values();
        $lockedCustomer = null;
        $lockedCountType = null;

        if ($request->filled('customer')) {
            $lockedCustomer = $customers->first(
                fn (CustomerMaster $customer) => (string) $customer->customer_id === $request->string('customer')->toString(),
            );
            abort_if($lockedCustomer === null, 403, 'This customer is outside your count scope.');

            $lockedCountType = match ($lockedCustomer->customer_type->value) {
                'PRIMARY' => CountType::PRIMARY_OPERATIONAL->value,
                'SECONDARY' => CountType::SECONDARY_OBSERVATION->value,
                'VAN' => CountType::VAN_CLOSING->value,
                default => abort(422, 'This customer type does not support a contextual stock count.'),
            };
        }

        return view('inventory.count-create', [
            'customers' => $customers,
            'lockedCustomer' => $lockedCustomer,
            'lockedCountType' => $lockedCountType,
            'products' => ProductMaster::query()
                ->when(! $user->isSuperadmin(), fn ($q) => $q->where('company_id', $user->company_id))
                ->when($user->isSalesEmployee(), function ($query) use ($employee) {
                    $scopedProductIds = EmployeeProduct::where('employee_id', $employee->employee_id)->pluck('product_id');

                    if ($scopedProductIds->isNotEmpty()) {
                        $query->whereIn('product_id', $scopedProductIds);
                    }
                })
                ->where('active', true)
                ->orderBy('product_description')->get(),
            'countTypes' => [
                CountType::PRIMARY_OPERATIONAL->value => 'Primary operational (authoritative)',
                CountType::SECONDARY_OBSERVATION->value => 'Secondary observation (informational)',
                CountType::VAN_CLOSING->value => 'Van closing',
            ],
        ]);
    }

    /** Store a DRAFT count (scope-validated per line). */
    public function storeCount(Request $request)
    {
        $user = $request->user();
        $employee = $user->employee;

        abort_if($employee === null, 403, 'Your login is not linked to an employee record.');

        $validated = $request->validate([
            'customer_id' => ['required', 'exists:customer_master,customer_id'],
            'count_type' => ['required', Rule::in(array_column(CountType::cases(), 'value'))],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'string'],
            'lines.*.counted_qty' => ['required', 'numeric', 'min:0'],
            'lines.*.count_unit' => ['required', 'string'],
        ]);

        abort_unless(
            $this->visibleCountCustomers($user)->contains(
                fn (CustomerMaster $customer) => (int) $customer->customer_id === (int) $validated['customer_id'],
            ),
            403,
            'This customer is outside your count scope.',
        );

        $count = $this->counts->createCount(
            $employee,
            $validated['customer_id'],
            $validated['count_type'],
            collect($validated['lines']),
        );

        return redirect()
            ->route('inventory.counts.show', $count)
            ->with('status', 'Draft count '.$count->count_no.' created.');
    }

    public function indexCounts(Request $request)
    {
        $user = $request->user();
        $employee = $user->employee;

        $counts = StockCount::query()
            ->with(['customer', 'employee'])
            ->when($employee !== null && $user->isSalesEmployee(), fn ($q) => $q->where('employee_id', $employee->employee_id))
            ->when($employee !== null && ! $user->isSalesEmployee() && ! $user->isSuperadmin(), fn ($q) => $q->where('company_id', $user->company_id))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('inventory.counts', ['counts' => $counts]);
    }

    public function showCount(Request $request, StockCount $count)
    {
        $this->assertCanViewCount($request->user(), $count);

        $count->load(['items.product', 'customer', 'employee']);

        return view('inventory.count-show', ['count' => $count]);
    }

    /** Authoritative submission (guards inside the service transaction). */
    public function submitCount(Request $request, StockCount $count)
    {
        $this->assertCanViewCount($request->user(), $count);

        $result = $this->counts->submitCount($request->user()->employee, $count);

        if ($result['movements'] > 0) {
            return back()->with('status', 'Count submitted — authoritative baseline posted for '
                .$result['movements'].' product(s).');
        }

        return back()->with('status', 'Count submitted (observational — inventory unchanged).');
    }

    /** Inventory visible to this user, with filters applied. */
    private function visibleInventoryQuery($user)
    {
        $query = Inventory::query()
            ->join('product_master as pm', 'pm.product_id', '=', 'inventory.product_id')
            ->where('pm.active', true)
            ->select('inventory.*');

        $filters = request()->only(['customer_id', 'product_id', 'customer_type']);

        if (($filters['customer_id'] ?? '') !== '') {
            $query->where('inventory.customer_id', $filters['customer_id']);
        }

        if (($filters['product_id'] ?? '') !== '') {
            $query->where('inventory.product_id', $filters['product_id']);
        }

        if (($filters['customer_type'] ?? '') !== '') {
            $query->whereHas('customer', fn ($q) => $q->where('customer_type', $filters['customer_type']));
        }

        if ($user->isSalesEmployee()) {
            // Operational scope: stock at MY assigned customers only.
            $assigned = CustomerEmployee::where('employee_id', $user->employee_id)->pluck('customer_id');
            $query->whereIn('inventory.customer_id', $assigned);
        } elseif (! $user->isSuperadmin()) {
            // Company admins: their company-product inventory across holders.
            $companyProductIds = ProductMaster::where('company_id', $user->company_id)->pluck('product_id');
            $query->whereIn('inventory.product_id', $companyProductIds);
        }

        return $query;
    }

    private function visibleStockHolders($user): Collection
    {
        $holders = CustomerMaster::query()
            ->whereIn('customer_type', ['PRIMARY', 'SHIP_TO', 'VAN'])
            ->where('active', true)
            ->orderBy('business_name');

        if ($user->isSalesEmployee()) {
            $assigned = CustomerEmployee::where('employee_id', $user->employee_id)->pluck('customer_id');
            $holders->whereIn('customer_id', $assigned);
        }

        return $holders->get();
    }

    /** Customers an employee may physically count, including observational Secondaries. */
    private function visibleCountCustomers($user): Collection
    {
        $customers = CustomerMaster::query()
            ->whereIn('customer_type', ['PRIMARY', 'SECONDARY', 'SHIP_TO', 'VAN'])
            ->where('active', true)
            ->orderBy('business_name');

        if ($user->isSalesEmployee()) {
            $assigned = CustomerEmployee::where('employee_id', $user->employee_id)->pluck('customer_id');
            $customers->whereIn('customer_id', $assigned);
        }

        return $customers->get();
    }

    private function authorizeAdjust(AppUser $user): void
    {
        abort_if($user->isSalesEmployee(), 403, 'Only administrators adjust stock.');

        if (! $user->isSuperadmin()) {
            // Company admin: product must belong to their company (validated deeper in the service).
            $holder = CustomerMaster::find(request()->input('customer_id'));
            abort_if($holder !== null && request()->has('product_id')
                && ! ProductMaster::where('company_id', $user->company_id)->where('product_id', request()->input('product_id'))->exists(),
                403, 'Product belongs to another company.');
        }
    }

    private function assertCanViewCount($user, StockCount $count): void
    {
        if ($user->isSalesEmployee()) {
            abort_if($count->employee_id !== $user->employee_id, 403, 'This count belongs to another employee.');

            return;
        }

        if (! $user->isSuperadmin()) {
            abort_if($count->company_id !== $user->company_id, 403, 'This count belongs to another company.');
        }
    }
}
