<?php

namespace App\Http\Controllers;

use App\Enums\Weekday;
use App\Models\CompanyMaster;
use App\Models\CustomerFjp;
use App\Models\CustomerMaster;
use App\Models\EmployeeMaster;
use App\Services\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Fixed Journey Plan admin (rule 5): company + customer + week/day.
 * FJP never implies a supplying Primary; it schedules visits (primarily to
 * Secondary customers).
 */
class FjpController extends Controller
{
    /**
     * Numeric weekday indexes in display order (Monday-first). The column is
     * 0 = Sunday … 6 = Saturday; the picker simply lists Monday first.
     */
    private static function days(): array
    {
        return array_map(fn (Weekday $day) => $day->value, Weekday::ordered());
    }

    public function __construct(private readonly CompanyContext $companyContext) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', EmployeeMaster::class);

        $companyId = $this->resolveCompanyId($request);

        $plans = CustomerFjp::query()
            ->forCompany($companyId)
            ->with('customer')
            ->when($request->query('q'), fn ($q, $term) => $q->whereHas('customer', fn ($c) => $c->where('business_name', 'like', "%{$term}%")))
            ->orderBy('customer_id')
            ->paginate(20)
            ->withQueryString();

        return view('fjp.index', [
            'plans' => $plans,
            'companyId' => $companyId,
            'companies' => $this->companyChoices(),
            'days' => self::days(),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', CustomerFjp::class);

        $companyId = $this->resolveCompanyId($request);

        return view('fjp.form', [
            'plan' => new CustomerFjp,
            'companyId' => $companyId,
            'customers' => $this->companyCustomers($companyId),
            'days' => self::days(),
            'companies' => $this->companyChoices(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', CustomerFjp::class);

        $companyId = $this->resolveCompanyId($request);
        $this->normalizePreferredVisitsInput($request);

        $validated = $request->validate([
            'customer_id' => ['required', Rule::in($this->companyCustomerIds($companyId))],
            'preferred_visits' => ['required', 'array', 'min:1', 'max:8'],
            'preferred_visits.*.preferred_week' => ['nullable', 'integer', 'min:1', 'max:4'],
            'preferred_visits.*.preferred_day' => ['required', 'integer', Rule::in(self::days())],
            'active' => ['sometimes', 'boolean'],
        ]);

        $this->assertSecondaryPreference($validated['customer_id']);

        $created = $this->storePreferredVisits(
            $companyId,
            (int) $validated['customer_id'],
            $validated['preferred_visits'],
            $request->boolean('active', true),
        );

        return redirect()
            ->route('fjp.index', $request->query('company') ? ['company' => $companyId] : [])
            ->with('status', $created.' preferred visit row(s) saved.');
    }

    public function edit(Request $request, CustomerFjp $plan)
    {
        $this->authorize('update', $plan);

        return view('fjp.form', [
            'plan' => $plan,
            'companyId' => $plan->company_id,
            'customers' => $this->companyCustomers($plan->company_id),
            'days' => self::days(),
            'companies' => $this->companyChoices(),
        ]);
    }

    public function update(Request $request, CustomerFjp $plan)
    {
        $this->authorize('update', $plan);
        $this->normalizePreferredVisitsInput($request);

        $validated = $request->validate([
            'customer_id' => ['required', Rule::in($this->companyCustomerIds($plan->company_id))],
            'preferred_visits' => ['required', 'array', 'min:1', 'max:8'],
            'preferred_visits.*.preferred_week' => ['nullable', 'integer', 'min:1', 'max:4'],
            'preferred_visits.*.preferred_day' => ['required', 'integer', Rule::in(self::days())],
            'active' => ['sometimes', 'boolean'],
        ]);

        $this->assertSecondaryPreference($validated['customer_id']);

        DB::transaction(function () use ($plan, $validated, $request): void {
            $visits = collect($validated['preferred_visits'])
                ->unique(fn (array $visit) => ($visit['preferred_week'] ?? '*').'|'.$visit['preferred_day'])
                ->values();
            $first = $visits->shift();

            $savedPlan = CustomerFjp::updateOrCreate(
                $this->scheduleAttributes($plan->company_id, (int) $validated['customer_id'], $first),
                ['active' => $request->boolean('active', $plan->active)],
            );

            if ($savedPlan->fjp_id !== $plan->fjp_id) {
                $plan->delete();
            }

            $this->storePreferredVisits(
                $plan->company_id,
                (int) $validated['customer_id'],
                $visits->all(),
                $request->boolean('active', $plan->active),
            );
        });

        return redirect()
            ->route('fjp.index', $request->query('company') ? ['company' => $plan->company_id] : [])
            ->with('status', 'Journey plan updated.');
    }

    public function import(Request $request)
    {
        $this->authorize('create', CustomerFjp::class);

        $companyId = $this->resolveCompanyId($request);
        $request->validate([
            'fjp_file' => [
                'required',
                'file',
                'extensions:csv,txt',
                'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel',
                'max:2048',
            ],
        ]);

        $eligibleCustomerIds = array_fill_keys($this->companyCustomerIds($companyId), true);
        $rows = [];
        $errors = [];
        $handle = fopen($request->file('fjp_file')->getRealPath(), 'rb');

        if ($handle === false) {
            throw ValidationException::withMessages(['fjp_file' => 'The uploaded file could not be read.']);
        }

        $lineNumber = 0;

        while (($columns = fgetcsv($handle, separator: '|', escape: '')) !== false) {
            $lineNumber++;
            $columns = array_map(fn ($value) => trim((string) $value), $columns);

            if ($columns === [''] || ($lineNumber === 1 && in_array(strtolower($columns[0] ?? ''), ['customer id', 'customer_id'], true))) {
                continue;
            }

            if (count($columns) !== 3) {
                $errors[] = "Line {$lineNumber}: expected customer id|preferred week|preferred day.";

                continue;
            }

            [$customerId, $weekValue, $dayValue] = $columns;
            $customerId = filter_var($customerId, FILTER_VALIDATE_INT);
            $week = in_array(strtoupper($weekValue), ['', '*', 'EVERY'], true)
                ? null
                : filter_var($weekValue, FILTER_VALIDATE_INT);
            $day = Weekday::parse($dayValue)?->value;

            if ($customerId === false || ! isset($eligibleCustomerIds[$customerId])) {
                $errors[] = "Line {$lineNumber}: customer is not assigned within {$companyId}.";
            } elseif ($week === false || ($week !== null && ($week < 1 || $week > 4))) {
                $errors[] = "Line {$lineNumber}: preferred week must be 1-4, blank, * or EVERY.";
            } elseif ($day === null) {
                $errors[] = "Line {$lineNumber}: preferred day must be Sunday-Saturday or 0-6.";
            } else {
                $rows[$customerId.'|'.($week ?? '*').'|'.$day] = [
                    'customer_id' => $customerId,
                    'preferred_week' => $week,
                    'preferred_day' => $day,
                ];
            }
        }

        fclose($handle);

        if ($errors !== []) {
            throw ValidationException::withMessages(['fjp_file' => $errors]);
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['fjp_file' => 'The file contains no preferred-visit rows.']);
        }

        $customerIds = collect($rows)->pluck('customer_id')->unique()->values();

        DB::transaction(function () use ($companyId, $customerIds, $rows): void {
            CustomerFjp::query()
                ->forCompany($companyId)
                ->whereIn('customer_id', $customerIds)
                ->delete();

            foreach ($rows as $row) {
                CustomerFjp::create($row + ['company_id' => $companyId, 'active' => true]);
            }
        });

        $route = $request->routeIs('customers.fjp.import') ? 'customers.index' : 'fjp.index';

        return redirect()
            ->route($route, $request->query('company') ? ['company' => $companyId] : [])
            ->with('status', count($rows).' preferred visit row(s) imported for '.$customerIds->count().' customer(s).');
    }

    public function destroySelectedCustomers(Request $request)
    {
        $this->authorize('create', CustomerFjp::class);

        $companyId = $this->resolveCompanyId($request);
        $validated = $request->validate([
            'customer_ids' => ['required', 'array', 'min:1'],
            'customer_ids.*' => ['integer', 'distinct', 'exists:customer_master,customer_id'],
        ]);

        $deleted = CustomerFjp::query()
            ->forCompany($companyId)
            ->whereIn('customer_id', $validated['customer_ids'])
            ->delete();

        return back()->with('status', $deleted.' preferred visit row(s) cleared for the selected customer(s).');
    }

    public function destroySelected(Request $request)
    {
        $this->authorize('create', CustomerFjp::class);

        $companyId = $this->resolveCompanyId($request);
        $validated = $request->validate([
            'fjp_ids' => ['required', 'array', 'min:1'],
            'fjp_ids.*' => ['integer', 'distinct'],
        ]);

        $deleted = CustomerFjp::query()
            ->forCompany($companyId)
            ->whereIn('fjp_id', $validated['fjp_ids'])
            ->delete();

        return back()->with('status', $deleted.' preferred visit row(s) cleared.');
    }

    public function deactivate(CustomerFjp $plan)
    {
        $this->authorize('update', $plan);

        $plan->update(['active' => false]);

        return back()->with('status', 'Journey plan deactivated.');
    }

    public function activate(CustomerFjp $plan)
    {
        $this->authorize('update', $plan);

        $plan->update(['active' => true]);

        return back()->with('status', 'Journey plan activated.');
    }

    /** FJP is primarily for SECONDARY visits (rule 5); primaries allowed but flagged in UI copy only. */
    private function assertSecondaryPreference(string $customerId): void
    {
        // Intentionally non-blocking: PRIMARY/VAN visits can be scheduled too.
        // The business intent (secondary-first) is surfaced in the UI copy.
    }

    private function normalizePreferredVisitsInput(Request $request): void
    {
        if (! $request->has('preferred_visits') && $request->has('preferred_day')) {
            $request->merge([
                'preferred_visits' => [[
                    'preferred_week' => $request->input('preferred_week'),
                    'preferred_day' => $request->input('preferred_day'),
                ]],
            ]);
        }
    }

    /**
     * @param  array<int, array{preferred_week?: ?int, preferred_day: int}>  $visits
     */
    private function storePreferredVisits(string $companyId, int $customerId, array $visits, bool $active): int
    {
        $uniqueVisits = collect($visits)
            ->unique(fn (array $visit) => ($visit['preferred_week'] ?? '*').'|'.$visit['preferred_day']);

        foreach ($uniqueVisits as $visit) {
            CustomerFjp::updateOrCreate(
                $this->scheduleAttributes($companyId, $customerId, $visit),
                ['active' => $active],
            );
        }

        return $uniqueVisits->count();
    }

    /**
     * @param  array{preferred_week?: ?int, preferred_day: int}  $visit
     * @return array{company_id: string, customer_id: int, preferred_week: ?int, preferred_day: int}
     */
    private function scheduleAttributes(string $companyId, int $customerId, array $visit): array
    {
        return [
            'company_id' => $companyId,
            'customer_id' => $customerId,
            'preferred_week' => $visit['preferred_week'] ?? null,
            'preferred_day' => $visit['preferred_day'],
        ];
    }

    /** @return Collection<int, CustomerMaster> */
    private function companyCustomers(string $companyId): Collection
    {
        return CustomerMaster::query()
            ->where('active', true)
            ->whereHas('employees', fn ($query) => $query->where('employee_master.company_id', $companyId))
            ->orderBy('business_name')
            ->get();
    }

    /** @return array<int, int> */
    private function companyCustomerIds(string $companyId): array
    {
        return $this->companyCustomers($companyId)
            ->pluck('customer_id')
            ->map(fn ($customerId) => (int) $customerId)
            ->all();
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
