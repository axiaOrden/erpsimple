<?php

namespace Tests\Feature;

use App\Enums\Weekday;
use App\Models\AppUser;
use App\Models\CompanyMaster;
use App\Models\CustomerFjp;
use App\Models\CustomerMaster;
use App\Models\EmployeeMaster;
use App\Services\FieldDirectoryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fixed Journey Plan isolation and storage contract (UAT correction pass).
 *
 *  - A customer can carry FJP rows for SEVERAL companies/employees. A field
 *    employee may only ever see the rows that belong to their own employee
 *    record, their own company, that customer and are ACTIVE.
 *  - `preferred_day` is stored as a compact numeric weekday index
 *    (0 = Sunday … 6 = Saturday). `preferred_week` stays the numeric rotation
 *    position. No combined `W1-Mon` string is ever persisted.
 */
class FjpIsolationTest extends TestCase
{
    use DatabaseTransactions;

    private CompanyMaster $companyA;

    private CompanyMaster $companyB;

    private EmployeeMaster $employeeA;

    private EmployeeMaster $employeeB;

    private AppUser $sellerA;

    private AppUser $sellerB;

    /** One customer, shared by both companies (customers are global). */
    private CustomerMaster $shared;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = CompanyMaster::factory()->create();
        $this->companyB = CompanyMaster::factory()->create();

        $this->employeeA = EmployeeMaster::factory()->create(['company_id' => $this->companyA->company_id]);
        $this->employeeB = EmployeeMaster::factory()->create(['company_id' => $this->companyB->company_id]);

        $this->sellerA = AppUser::factory()->salesEmployee()->create([
            'employee_id' => $this->employeeA->employee_id,
            'company_id' => $this->companyA->company_id,
        ]);
        $this->sellerB = AppUser::factory()->salesEmployee()->create([
            'employee_id' => $this->employeeB->employee_id,
            'company_id' => $this->companyB->company_id,
        ]);

        $this->shared = CustomerMaster::factory()
            ->forEmployee($this->employeeA)
            ->forEmployee($this->employeeB)
            ->create();
    }

    /** A plan for the given employee on the weekday matching TODAY. */
    private function planFor(EmployeeMaster $employee, CompanyMaster $company, ?int $week = null, ?int $day = null, bool $active = true): CustomerFjp
    {
        return CustomerFjp::create([
            'company_id' => $company->company_id,
            'employee_id' => $employee->employee_id,
            'customer_id' => $this->shared->customer_id,
            'preferred_week' => $week,
            'preferred_day' => $day ?? (int) now()->dayOfWeek,
            'active' => $active,
        ]);
    }

    // ---- storage contract ---------------------------------------------------

    public function test_preferred_day_is_stored_as_a_numeric_weekday_index(): void
    {
        $plan = CustomerFjp::create([
            'company_id' => $this->companyA->company_id,
            'employee_id' => $this->employeeA->employee_id,
            'customer_id' => $this->shared->customer_id,
            'preferred_week' => 2,
            'preferred_day' => Weekday::Wednesday->value,
            'active' => true,
        ]);

        $raw = DB::table('customer_fjp')->where('fjp_id', $plan->fjp_id)->first();

        $this->assertSame(3, (int) $raw->preferred_day, 'Wednesday is stored as the numeric index 3.');
        $this->assertSame(2, (int) $raw->preferred_week, 'The rotation week stays numeric and untouched.');

        $column = DB::selectOne("SHOW COLUMNS FROM customer_fjp LIKE 'preferred_day'");
        $this->assertStringContainsString('tinyint', strtolower((string) $column->Type));
        $this->assertStringNotContainsString('enum', strtolower((string) $column->Type));

        // The combined presentation form lives only in the view layer.
        $this->assertSame('W2-Wed', $plan->fresh()->visitChip());
        $this->assertSame('Week 2 Wednesday', $plan->fresh()->visitLabel());
    }

    public function test_the_full_week_is_addressable_and_zero_is_sunday(): void
    {
        $this->assertSame(0, Weekday::Sunday->value);
        $this->assertSame(6, Weekday::Saturday->value);
        $this->assertSame(Weekday::Monday, Weekday::tryFromName('monday'));
        $this->assertSame(Weekday::Monday, Weekday::parse('1'));

        foreach (Weekday::cases() as $day) {
            $plan = $this->planFor(
                $this->employeeA,
                $this->companyA,
                week: null,
                day: $day->value,
            );

            $this->assertSame(
                $day,
                $plan->fresh()->weekday(),
                $day->name.' must survive a database round trip.',
            );
        }
    }

    // ---- cross-company isolation -------------------------------------------

    public function test_todays_plan_never_shows_another_companys_schedule(): void
    {
        // Company A schedules the customer TODAY; company B schedules another day.
        $otherDay = ((int) now()->dayOfWeek + 1) % 7;

        $mine = $this->planFor($this->employeeA, $this->companyA);
        $this->planFor($this->employeeB, $this->companyB, day: $otherDay);

        $visits = collect(
            $this->actingAs($this->sellerA)->get(route('visits.today'))->assertOk()->viewData('visits'),
        );

        $this->assertCount(1, $visits);
        $this->assertSame($mine->fjp_id, $visits->first()['plan']->fjp_id);
        $this->assertSame($this->companyA->company_id, $visits->first()['plan']->company_id);

        // Company B has no schedule for today: nothing appears on B's plan.
        $visitsB = collect(
            $this->actingAs($this->sellerB)->get(route('visits.today'))->assertOk()->viewData('visits'),
        );

        $this->assertCount(0, $visitsB, 'Another company\'s weekday schedule must never leak into my plan.');
    }

    public function test_customer_profile_only_lists_the_employees_own_company_schedule(): void
    {
        $mine = $this->planFor($this->employeeA, $this->companyA, week: 1, day: Weekday::Monday->value);
        $otherCompany = $this->planFor($this->employeeB, $this->companyB, week: 3, day: Weekday::Friday->value);

        $plan = $this->actingAs($this->sellerA)
            ->get(route('visits.customer', $this->shared->customer_id))
            ->assertOk()
            ->viewData('plan');

        $this->assertCount(1, $plan);
        $this->assertSame($mine->fjp_id, $plan->first()->fjp_id);
        $this->assertFalse(
            $plan->contains(fn (CustomerFjp $row) => $row->fjp_id === $otherCompany->fjp_id),
            'Another company\'s FJP row must never be rendered on the customer profile.',
        );
    }

    public function test_customer_profile_only_lists_active_rows(): void
    {
        $active = $this->planFor($this->employeeA, $this->companyA, week: 2, day: Weekday::Tuesday->value);
        $inactive = $this->planFor($this->employeeA, $this->companyA, week: 4, day: Weekday::Thursday->value, active: false);

        $plan = $this->actingAs($this->sellerA)
            ->get(route('visits.customer', $this->shared->customer_id))
            ->assertOk()
            ->viewData('plan');

        $this->assertTrue($plan->contains(fn (CustomerFjp $row) => $row->fjp_id === $active->fjp_id));
        $this->assertFalse($plan->contains(fn (CustomerFjp $row) => $row->fjp_id === $inactive->fjp_id));
    }

    public function test_inactive_rows_leave_todays_plan(): void
    {
        $this->planFor($this->employeeA, $this->companyA, active: false);

        $visits = collect(
            $this->actingAs($this->sellerA)
                ->get(route('visits.today'))
                ->assertOk()
                ->viewData('visits'),
        );

        $this->assertCount(0, $visits);
    }

    public function test_another_employees_schedule_in_the_same_company_is_not_mine(): void
    {
        $colleague = EmployeeMaster::factory()->create(['company_id' => $this->companyA->company_id]);

        $this->planFor($colleague, $this->companyA);
        $this->planFor($this->employeeA, $this->companyA);

        $visits = collect(
            $this->actingAs($this->sellerA)
                ->get(route('visits.today'))
                ->assertOk()
                ->viewData('visits'),
        );

        $this->assertCount(1, $visits, 'Only my own plan row may appear.');
        $this->assertSame($this->employeeA->employee_id, $visits->first()['plan']->employee_id);
    }

    public function test_preferred_visits_for_a_customer_are_weekday_ordered_and_company_scoped(): void
    {
        // Insert out of order to prove the ordering is the query's, not insertion order.
        $this->planFor($this->employeeA, $this->companyA, week: 2, day: Weekday::Wednesday->value);
        $this->planFor($this->employeeA, $this->companyA, week: 1, day: Weekday::Friday->value);
        $this->planFor($this->employeeA, $this->companyA, week: 1, day: Weekday::Monday->value);
        $this->planFor($this->employeeB, $this->companyB, week: 1, day: Weekday::Monday->value);

        $rows = app(FieldDirectoryService::class)
            ->preferredVisits($this->employeeA, $this->shared->customer_id);

        $this->assertCount(3, $rows);
        $this->assertSame(
            [[1, Weekday::Monday->value], [1, Weekday::Friday->value], [2, Weekday::Wednesday->value]],
            $rows->map(fn (CustomerFjp $row) => [$row->preferred_week, $row->preferred_day])->all(),
        );
    }
}
