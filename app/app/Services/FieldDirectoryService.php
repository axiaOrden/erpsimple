<?php

namespace App\Services;

use App\Enums\CustomerType;
use App\Models\CustomerEmployee;
use App\Models\CustomerFjp;
use App\Models\CustomerMaster;
use App\Models\CustomerVisitAttendance;
use App\Models\EmployeeMaster;
use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Models\StockCount;
use App\Models\TransitStock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * READ-ONLY directories for the field-sales experience: the employee's
 * PRIMARY customers, the assigned customer directory (SECONDARY/VAN),
 * today's Fixed Journey Plan rows, and the "requires my attention" summary.
 *
 * Everything is scoped to ONE employee (assignment via `customer_employee`)
 * and ONE company; no aggregate here invents business state — each figure
 * comes from an authoritative document.
 */
class FieldDirectoryService
{
    /** Customers the employee is assigned to (the commercial relationship). */
    public function assignedCustomerIds(EmployeeMaster $employee): Collection
    {
        return CustomerEmployee::where('employee_id', $employee->employee_id)
            ->pluck('customer_id')
            ->unique()
            ->values();
    }

    /**
     * PRIMARY tab: assigned Primary customers with first/last check-in and the
     * latest submitted stock count.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function primaryDirectory(EmployeeMaster $employee): Collection
    {
        $ids = $this->assignedCustomerIds($employee);

        $customers = CustomerMaster::whereIn('customer_id', $ids)
            ->where('active', true)
            ->where('customer_type', CustomerType::PRIMARY)
            ->orderBy('business_name')
            ->get();

        return $this->decorateVisits($employee, $customers);
    }

    /**
     * SECONDARY tab: the assigned customer directory (SECONDARY + VAN — the
     * customer types a field employee actually sells to).
     */
    public function secondaryDirectory(EmployeeMaster $employee, ?string $search, int $perPage = 15): LengthAwarePaginator
    {
        $ids = $this->assignedCustomerIds($employee);

        return CustomerMaster::query()
            ->whereIn('customer_id', $ids)
            ->where('active', true)
            ->whereIn('customer_type', [CustomerType::SECONDARY->value, CustomerType::VAN->value])
            ->when($search !== null && trim($search) !== '', function ($q) use ($search) {
                $term = '%'.trim($search).'%';

                $q->where(function ($w) use ($term) {
                    $w->where('business_name', 'like', $term)
                        ->orWhere('customer_id', 'like', $term)
                        ->orWhere('contact_person', 'like', $term)
                        ->orWhere('phone_number', 'like', $term)
                        ->orWhere('city', 'like', $term)
                        ->orWhere('address', 'like', $term);
                });
            })
            ->orderBy('business_name')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * FJP screen: today's applicable customers (rotation week + weekday match),
     * optionally filtered by search, each with the visit summary. Customers
     * already checked in today are included — their status shows the progress.
     *
     * SCOPING (mandatory): the schedule belongs to THIS employee's company and
     * an assigned customer. Colleagues in the same company share the customer's
     * preferred schedule, but do not gain access to unassigned customers.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, total: int, paginator: LengthAwarePaginator}
     */
    public function todayPlan(EmployeeMaster $employee, ?string $search, int $perPage = 15): array
    {
        $week = app(FjpRotationService::class)->rotationWeek(Carbon::today());
        // Compact numeric weekday index: 0 = Sunday … 6 = Saturday.
        $day = Carbon::today()->dayOfWeek;

        $plans = $this->activeFjpQuery($employee)
            ->where('preferred_day', $day)
            ->where(fn ($q) => $q->whereNull('preferred_week')->orWhere('preferred_week', $week))
            ->with('customer')
            ->orderBy('preferred_week')
            ->get()
            ->filter(fn (CustomerFjp $plan) => $plan->customer !== null && $plan->customer->active);

        $term = $search !== null ? trim($search) : '';

        if ($term !== '') {
            $plans = $plans->filter(function (CustomerFjp $plan) use ($term) {
                $customer = $plan->customer;

                return stripos((string) $customer->business_name, $term) !== false
                    || stripos((string) $customer->customer_id, $term) !== false
                    || stripos((string) $customer->contact_person, $term) !== false
                    || stripos((string) $customer->city, $term) !== false;
            });
        }

        $rows = $this->decorateVisits($employee, $plans->map(fn (CustomerFjp $plan) => $plan->customer));

        $rows = $rows->map(function (array $row) use ($plans) {
            $plan = $plans->firstWhere(fn (CustomerFjp $p) => $p->customer_id === $row['customer']->customer_id);
            $row['plan'] = $plan;

            return $row;
        });

        $page = max(1, (int) request()->query('page', 1));
        $total = $rows->count();
        $slice = $rows->values()->slice(($page - 1) * $perPage, $perPage)->values();

        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $slice, $total, $perPage, $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );

        return ['rows' => $slice, 'total' => $total, 'paginator' => $paginator];
    }

    /**
     * "What requires my attention?" — bounded queries over the employee's own
     * documents plus the debtor exposure of assigned customers.
     *
     * @return array<string, mixed>
     */
    public function attention(EmployeeMaster $employee, SalesLifecycleService $lifecycle): array
    {
        $ids = $this->assignedCustomerIds($employee);

        $recentOrders = SalesOrder::where('company_id', $employee->company_id)
            ->where('sales_employee_id', $employee->employee_id)
            ->orderByDesc('order_date')
            ->limit(150)
            ->get();

        $analyses = $lifecycle->classifyOrders($recentOrders->where('order_status', '!=', 'DRAFT'));

        $ongoing = 0;
        $awaitingPod = 0;

        foreach ($recentOrders as $order) {
            if ($order->order_status->value === 'DRAFT') {
                continue;
            }

            $analysis = $analyses[$order->sales_order_no] ?? null;

            if ($analysis === null) {
                continue;
            }

            if ($analysis['state'] === 'ONGOING') {
                $ongoing++;
            }

            if (in_array('Awaiting POD', $analysis['reasons'], true)) {
                $awaitingPod++;
            }
        }

        // Invoices belong to the SELLING company (the employee's employer).
        $invoices = Invoice::whereIn('customer_id', $ids)
            ->where('company_id', $employee->company_id)
            ->whereIn('payment_status', ['UNPAID', 'PARTIALLY_PAID'])
            ->get();

        $outstanding = '0.00';

        foreach ($invoices as $invoice) {
            $outstanding = Decimal::add($outstanding, $invoice->outstandingAmount(), 2);
        }

        $plan = $this->todayPlan($employee, null, 1000)['rows'];
        $visitsRemaining = $plan->filter(fn (array $row) => $row['status'] === 'PENDING')->count();

        $custody = TransitStock::where('company_id', $employee->company_id)
            ->where('holding_employee_id', $employee->employee_id)
            ->where('quantity', '>', 0)
            ->get();

        $blocked = CustomerMaster::whereIn('customer_id', $ids)->where('active', true)->count();

        return [
            'draft_orders' => $recentOrders->filter(fn (SalesOrder $o) => $o->order_status->value === 'DRAFT')->count(),
            'ongoing_orders' => $ongoing,
            'awaiting_pod_orders' => $awaitingPod,
            'unpaid_invoices' => $invoices->count(),
            'outstanding' => $outstanding,
            'visits_today' => $plan->count(),
            'visits_remaining' => $visitsRemaining,
            'custody_lines' => $custody->count(),
            'customers_total' => $blocked,
            'blocked_customers' => $this->blockedCustomers($ids, $employee->company_id),
        ];
    }

    /**
     * Assigned customers with a positive net exposure (strict outstanding-debt
     * rule): their new orders cannot be confirmed until settlement. Uses the
     * authoritative InvoiceService exposure math — one query set, no
     * reimplementation.
     *
     * @param  Collection<int, string>  $ids
     * @return Collection<int, array{customer: CustomerMaster, outstanding: string, available_credit: string, net_exposure: string}>
     */
    public function blockedCustomers(Collection $ids, ?string $companyId): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        $outstandingByCustomer = [];

        foreach (Invoice::whereIn('customer_id', $ids)
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->get(['customer_id', 'invoice_amount', 'settled_amount', 'credit_amount']) as $invoice) {
            $outstandingByCustomer[$invoice->customer_id] = Decimal::add(
                $outstandingByCustomer[$invoice->customer_id] ?? '0.00',
                Decimal::sub(Decimal::sub((string) $invoice->invoice_amount, (string) $invoice->settled_amount, 2), (string) $invoice->credit_amount, 2),
                2,
            );
        }

        $exposed = collect($outstandingByCustomer)
            ->filter(fn (string $amount) => Decimal::compare($amount, '0', 2) > 0)
            ->sortDesc();

        if ($exposed->isEmpty()) {
            return collect();
        }

        $customers = CustomerMaster::whereIn('customer_id', $exposed->keys()->all())->get()->keyBy('customer_id');

        return $exposed->map(fn (string $amount, string $customerId) => [
            'customer' => $customers[$customerId],
            'outstanding' => $amount,
        ])->values();
    }

    /**
     * The ONE scoping rule for every FJP read a field employee makes:
     * employee company + assigned customers + active rows. A customer can have
     * schedules for several companies; another company's row is never returned.
     *
     * @return Builder<CustomerFjp>
     */
    public function activeFjpQuery(EmployeeMaster $employee, ?string $customerId = null)
    {
        return CustomerFjp::query()
            ->where('company_id', $employee->company_id)
            ->whereIn('customer_id', $this->assignedCustomerIds($employee))
            ->where('active', true)
            ->when($customerId !== null, fn ($q) => $q->where('customer_id', $customerId));
    }

    /**
     * One assigned customer's company-level preferred visits, ordered for display.
     *
     * @return Collection<int, CustomerFjp>
     */
    public function preferredVisits(EmployeeMaster $employee, string $customerId): Collection
    {
        return $this->activeFjpQuery($employee, $customerId)
            ->orderByRaw('preferred_week IS NULL')
            ->orderBy('preferred_week')
            ->orderBy('preferred_day')
            ->get();
    }

    /**
     * Attach first/last check-in (today and overall) and the latest submitted
     * stock count to a set of customers — three bounded queries, no N+1.
     *
     * @param  Collection<int, CustomerMaster>  $customers
     * @return Collection<int, array<string, mixed>>
     */
    private function decorateVisits(EmployeeMaster $employee, Collection $customers): Collection
    {
        if ($customers->isEmpty()) {
            return collect();
        }

        $ids = $customers->pluck('customer_id')->all();

        $today = CustomerVisitAttendance::where('employee_id', $employee->employee_id)
            ->whereIn('customer_id', $ids)
            ->whereDate('attendance_datetime', Carbon::today())
            ->orderBy('attendance_datetime')
            ->get()
            ->groupBy('customer_id');

        $lastAttendance = CustomerVisitAttendance::where('employee_id', $employee->employee_id)
            ->whereIn('customer_id', $ids)
            ->select('customer_id', DB::raw('MAX(attendance_datetime) as last_at'))
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');

        $lastCounts = StockCount::where('employee_id', $employee->employee_id)
            ->whereIn('customer_id', $ids)
            ->where('count_status', 'SUBMITTED')
            ->orderByDesc('count_date')
            ->orderByDesc('count_no')
            ->get()
            ->groupBy('customer_id')
            ->map(fn (Collection $group) => $group->first());

        return $customers->map(function (CustomerMaster $customer) use ($today, $lastAttendance, $lastCounts) {
            $records = $today->get($customer->customer_id);

            $status = 'PENDING';

            if ($records !== null && $records->isNotEmpty()) {
                $status = $records->count() === 1 ? 'CHECKED_IN' : 'CHECKED_OUT';
            }

            $lastAt = $lastAttendance->get($customer->customer_id)?->last_at;
            $count = $lastCounts->get($customer->customer_id);

            $first = $records?->min('attendance_datetime');
            $last = $records?->max('attendance_datetime');

            return [
                'customer' => $customer,
                'status' => $status,
                'first_check_in' => $first,
                'last_check_in' => $last,
                'check_in' => $first?->format('H:i'),
                'check_out' => $last?->format('H:i'),
                'records' => $records?->count() ?? 0,
                'last_check_in_overall' => $lastAt !== null ? Carbon::parse($lastAt) : null,
                'last_stock_count' => $count,
                'default_count_type' => match ($customer->customer_type->value) {
                    'PRIMARY' => 'PRIMARY_OPERATIONAL',
                    'VAN' => 'VAN_CLOSING',
                    default => 'SECONDARY_OBSERVATION',
                },
            ];
        })->values();
    }
}
