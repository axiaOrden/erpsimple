<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\CompanyMaster;
use App\Models\CustomerFjp;
use App\Models\CustomerMaster;
use App\Models\CustomerVisitAttendance;
use App\Models\EmployeeMaster;
use App\Services\FjpRotationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FieldSalesTest extends TestCase
{
    use DatabaseTransactions;

    private CompanyMaster $companyA;

    private CompanyMaster $companyB;

    private EmployeeMaster $employeeA;

    private EmployeeMaster $employeeB;

    private AppUser $sellerA;

    private AppUser $sellerB;

    private AppUser $adminA;

    private CustomerMaster $customerMine;

    private CustomerMaster $customerOther;

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
        $this->adminA = AppUser::factory()->companyAdmin()->create([
            'employee_id' => null,
            'company_id' => $this->companyA->company_id,
        ]);

        $this->customerMine = CustomerMaster::factory()->forEmployee($this->employeeA)->create();
        $this->customerOther = CustomerMaster::factory()->forEmployee($this->employeeB)->create();
    }

    // ---- FJP authorization --------------------------------------------------

    public function test_fjp_admin_list_is_company_scoped(): void
    {
        $otherEmployee = $this->employeeB;

        CustomerFjp::create([
            'company_id' => $this->companyA->company_id,
            'employee_id' => $this->employeeA->employee_id,
            'customer_id' => $this->customerMine->customer_id,
            'preferred_day' => 'MONDAY',
            'active' => true,
        ]);
        CustomerFjp::create([
            'company_id' => $this->companyB->company_id,
            'employee_id' => $otherEmployee->employee_id,
            'customer_id' => $this->customerOther->customer_id,
            'preferred_day' => 'TUESDAY',
            'active' => true,
        ]);

        $response = $this->actingAs($this->adminA)->get('/fjp');

        $response->assertOk()
            ->assertSee($this->customerMine->business_name)
            ->assertDontSee($this->customerOther->business_name);
    }

    public function test_admin_cannot_create_fjp_for_other_company_employee(): void
    {
        $response = $this->actingAs($this->adminA)->post('/fjp', [
            'employee_id' => $this->employeeB->employee_id, // other company
            'customer_id' => $this->customerMine->customer_id,
            'preferred_day' => 'MONDAY',
        ]);

        $response->assertSessionHasErrors('employee_id');
        $this->assertDatabaseMissing('customer_fjp', ['employee_id' => $this->employeeB->employee_id]);
    }

    public function test_admin_cannot_edit_fjp_of_other_company(): void
    {
        $plan = CustomerFjp::create([
            'company_id' => $this->companyB->company_id,
            'employee_id' => $this->employeeB->employee_id,
            'customer_id' => $this->customerOther->customer_id,
            'preferred_day' => 'MONDAY',
            'active' => true,
        ]);

        $this->actingAs($this->adminA)->get('/fjp/'.$plan->fjp_id.'/edit')->assertForbidden();
        $this->actingAs($this->adminA)->patch('/fjp/'.$plan->fjp_id, [
            'employee_id' => $this->employeeA->employee_id,
            'customer_id' => $this->customerMine->customer_id,
            'preferred_day' => 'FRIDAY',
        ])->assertForbidden();
    }

    public function test_sales_employee_cannot_manage_fjp(): void
    {
        $this->actingAs($this->sellerA)->get('/fjp')->assertForbidden();
        $this->actingAs($this->sellerA)->post('/fjp', [
            'employee_id' => $this->employeeA->employee_id,
            'customer_id' => $this->customerMine->customer_id,
            'preferred_day' => 'MONDAY',
        ])->assertForbidden();
    }

    // ---- Today's visits scoping -------------------------------------------------

    public function test_sales_employee_sees_only_own_visits(): void
    {
        $today = strtoupper(now()->format('l'));

        CustomerFjp::create([
            'company_id' => $this->companyA->company_id,
            'employee_id' => $this->employeeA->employee_id,
            'customer_id' => $this->customerMine->customer_id,
            'preferred_day' => $today,
            'active' => true,
        ]);
        CustomerFjp::create([
            'company_id' => $this->companyB->company_id,
            'employee_id' => $this->employeeB->employee_id,
            'customer_id' => $this->customerOther->customer_id,
            'preferred_day' => $today,
            'active' => true,
        ]);

        $response = $this->actingAs($this->sellerA)->get('/visits');

        $response->assertOk()
            ->assertSee($this->customerMine->business_name)
            ->assertDontSee($this->customerOther->business_name);
    }

    public function test_sales_employee_cannot_open_unassigned_customer_view(): void
    {
        $this->actingAs($this->sellerA)
            ->get('/visits/customers/'.$this->customerOther->customer_id)
            ->assertForbidden();

        $this->actingAs($this->sellerA)
            ->get('/visits/customers/'.$this->customerMine->customer_id)
            ->assertOk();
    }

    // ---- Attendance authorization & spoofing -------------------------------------

    public function test_attendance_cannot_be_submitted_for_another_employee(): void
    {
        // sellerB tries to record attendance attributed to employeeA by posting
        // to a customer only assigned to employeeA — the server must bind the
        // attendance to the AUTHENTICATED employee, never a payload field.
        $response = $this->actingAs($this->sellerB)->post('/visits/attendance', [
            'customer_id' => $this->customerMine->customer_id, // assigned to employeeA
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('customer_visit_attendance', [
            'customer_id' => $this->customerMine->customer_id,
        ]);
    }

    public function test_attendance_is_bound_to_authenticated_employee(): void
    {
        $response = $this->actingAs($this->sellerA)->post('/visits/attendance', [
            'customer_id' => $this->customerMine->customer_id,
            'employee_id' => $this->employeeB->employee_id, // spoof attempt
            'company_id' => $this->companyB->company_id, // spoof attempt
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('customer_visit_attendance', [
            'customer_id' => $this->customerMine->customer_id,
            'employee_id' => $this->employeeA->employee_id,
            'company_id' => $this->companyA->company_id,
        ]);
        $this->assertDatabaseMissing('customer_visit_attendance', [
            'employee_id' => $this->employeeB->employee_id,
        ]);
    }

    public function test_multiple_attendance_events_same_day_allowed(): void
    {
        foreach ([1, 2, 3] as $i) {
            $this->actingAs($this->sellerA)->post('/visits/attendance', [
                'customer_id' => $this->customerMine->customer_id,
                'remarks' => 'ping '.$i,
            ])->assertRedirect();
        }

        $count = CustomerVisitAttendance::where('employee_id', $this->employeeA->employee_id)
            ->where('customer_id', $this->customerMine->customer_id)
            ->whereDate('attendance_datetime', today())
            ->count();

        $this->assertSame(3, $count);
    }

    public function test_admin_cannot_record_attendance_without_employee_profile(): void
    {
        $this->actingAs($this->adminA)->post('/visits/attendance', [
            'customer_id' => $this->customerMine->customer_id,
        ])->assertForbidden();
    }

    // ---- Offline sync: idempotency -------------------------------------------------

    public function test_sync_attendance_creates_record_with_client_key(): void
    {
        $response = $this->actingAs($this->sellerA)
            ->postJson('/sync/attendance', [
                'idempotency_key' => 'client-uuid-123',
                'customer_id' => $this->customerMine->customer_id,
                'device_timestamp' => now()->toIso8601String(),
                'gps_latitude' => 6.5244,
                'gps_longitude' => 3.3792,
                'gps_accuracy' => 12.5,
                'remarks' => 'queued offline',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('customer_visit_attendance', [
            'employee_id' => $this->employeeA->employee_id,
            'customer_id' => $this->customerMine->customer_id,
        ]);
    }

    public function test_sync_retry_does_not_duplicate_attendance(): void
    {
        $payload = [
            'idempotency_key' => 'client-uuid-retry',
            'customer_id' => $this->customerMine->customer_id,
            'device_timestamp' => now()->toIso8601String(),
        ];

        $first = $this->actingAs($this->sellerA)->postJson('/sync/attendance', $payload);
        $first->assertStatus(201);

        // Lost-response retry with the SAME client UUID.
        $retry = $this->actingAs($this->sellerA)->postJson('/sync/attendance', $payload);
        $retry->assertStatus(200);
        $this->assertTrue((bool) $retry->headers->get('X-Idempotent-Replay'));
        $this->assertEquals($first->json('attendance_id'), $retry->json('attendance_id'));

        $count = CustomerVisitAttendance::where('employee_id', $this->employeeA->employee_id)
            ->where('customer_id', $this->customerMine->customer_id)
            ->count();

        $this->assertSame(1, $count, 'A retried operation must not create a duplicate.');
    }

    public function test_different_keys_create_separate_attendance(): void
    {
        $base = [
            'customer_id' => $this->customerMine->customer_id,
            'device_timestamp' => now()->toIso8601String(),
        ];

        $this->actingAs($this->sellerA)->postJson('/sync/attendance', $base + ['idempotency_key' => 'key-a'])->assertStatus(201);
        $this->actingAs($this->sellerA)->postJson('/sync/attendance', $base + ['idempotency_key' => 'key-b'])->assertStatus(201);

        $this->assertSame(2, CustomerVisitAttendance::where('employee_id', $this->employeeA->employee_id)->count());
    }

    public function test_sync_requires_idempotency_key(): void
    {
        $this->actingAs($this->sellerA)
            ->postJson('/sync/attendance', ['customer_id' => $this->customerMine->customer_id])
            ->assertStatus(422);
    }

    public function test_sync_rejects_unassigned_customer_even_offline(): void
    {
        // Revalidation on sync: never trust the device just because it was authenticated.
        $this->actingAs($this->sellerB)
            ->postJson('/sync/attendance', [
                'idempotency_key' => 'key-evil',
                'customer_id' => $this->customerMine->customer_id,
            ])
            ->assertStatus(403);
    }

    public function test_sync_key_is_scoped_per_endpoint_and_user(): void
    {
        // The same client UUID used by a different employee is a different logical
        // operation and must not replay employee A's response.
        $payloadA = [
            'idempotency_key' => 'shared-key',
            'customer_id' => $this->customerMine->customer_id,
        ];
        $payloadB = [
            'idempotency_key' => 'shared-key',
            'customer_id' => $this->customerOther->customer_id,
        ];

        $this->actingAs($this->sellerA)->postJson('/sync/attendance', $payloadA)->assertStatus(201);

        $responseB = $this->actingAs($this->sellerB)->postJson('/sync/attendance', $payloadB);
        $responseB->assertStatus(201);
        $this->assertFalse((bool) $responseB->headers->get('X-Idempotent-Replay'));
    }

    // ---- FJP rotation matching in Today's Visits -------------------------------

    public function test_todays_visits_respect_rotation_week(): void
    {
        config(['fjp.rotation_anchor' => '2026-01-05']);

        $today = now();
        $currentWeek = app(FjpRotationService::class)->rotationWeek($today);
        $otherWeek = $currentWeek === 4 ? 1 : $currentWeek + 1;
        $todayName = strtoupper($today->format('l'));

        // Plan for the CURRENT rotation week: must appear.
        $visible = CustomerFjp::create([
            'company_id' => $this->companyA->company_id,
            'employee_id' => $this->employeeA->employee_id,
            'customer_id' => $this->customerMine->customer_id,
            'preferred_week' => $currentWeek,
            'preferred_day' => $todayName,
            'active' => true,
        ]);

        // Plan for a DIFFERENT rotation week (same weekday): must NOT appear.
        $hidden = CustomerFjp::create([
            'company_id' => $this->companyA->company_id,
            'employee_id' => $this->employeeA->employee_id,
            'customer_id' => CustomerMaster::factory()->forEmployee($this->employeeA)->create()->customer_id,
            'preferred_week' => $otherWeek,
            'preferred_day' => $todayName,
            'active' => true,
        ]);

        $response = $this->actingAs($this->sellerA)->get('/visits');

        $response->assertOk();

        // Assert on the resolved visit rows (the hidden customer still appears
        // in the manual check-in dropdown as an assigned customer).
        $visitCustomerIds = collect($response->viewData('visits'))
            ->pluck('customer.customer_id');

        $this->assertTrue($visitCustomerIds->contains($visible->customer_id));
        $this->assertFalse($visitCustomerIds->contains($hidden->customer_id));
    }

    public function test_null_rotation_week_plans_appear_every_week(): void
    {
        config(['fjp.rotation_anchor' => '2026-01-05']);

        $todayName = strtoupper(now()->format('l'));

        CustomerFjp::create([
            'company_id' => $this->companyA->company_id,
            'employee_id' => $this->employeeA->employee_id,
            'customer_id' => $this->customerMine->customer_id,
            'preferred_week' => null,
            'preferred_day' => $todayName,
            'active' => true,
        ]);

        $this->actingAs($this->sellerA)->get('/visits')
            ->assertOk()
            ->assertSee($this->customerMine->business_name);
    }

    public function test_fjp_validation_rejects_week_five(): void
    {
        $this->actingAs($this->adminA)->post('/fjp', [
            'employee_id' => $this->employeeA->employee_id,
            'customer_id' => $this->customerMine->customer_id,
            'preferred_week' => 5,
            'preferred_day' => 'MONDAY',
        ])->assertSessionHasErrors('preferred_week');

        $this->assertDatabaseMissing('customer_fjp', ['preferred_week' => 5]);
    }

    // ---- Device timestamp handling ---------------------------------------------------

    public function test_device_timestamp_skew_is_flagged_not_applied(): void
    {
        $future = now()->addHours(5)->toIso8601String();

        $response = $this->actingAs($this->sellerA)
            ->postJson('/sync/attendance', [
                'idempotency_key' => 'key-skew',
                'customer_id' => $this->customerMine->customer_id,
                'device_timestamp' => $future,
            ]);

        $response->assertStatus(201);
        $this->assertNotNull($response->json('device_timestamp_flag'), 'Obvious clock skew must be flagged.');

        // Server time is used for ordering; device time is preserved but flagged.
        $record = CustomerVisitAttendance::find($response->json('attendance_id'));
        $this->assertTrue($record->attendance_datetime->diffInMinutes(now()) < 5);
        $this->assertNotNull($record->device_captured_at, 'Device-claimed time must be preserved for audit.');
        $this->assertTrue($record->device_timestamp_flag, 'Material skew must set the audit flag.');
    }

    public function test_reasonable_device_timestamp_is_preserved_unflagged(): void
    {
        $response = $this->actingAs($this->sellerA)
            ->postJson('/sync/attendance', [
                'idempotency_key' => 'key-no-skew',
                'customer_id' => $this->customerMine->customer_id,
                'device_timestamp' => now()->subMinutes(2)->toIso8601String(),
            ]);

        $response->assertStatus(201);

        $record = CustomerVisitAttendance::find($response->json('attendance_id'));
        $this->assertNotNull($record->device_captured_at);
        $this->assertFalse($record->device_timestamp_flag, 'Minor clock drift is not material.');
    }

    public function test_online_attendance_without_device_timestamp_has_no_device_columns(): void
    {
        $this->actingAs($this->sellerA)->post('/visits/attendance', [
            'customer_id' => $this->customerMine->customer_id,
        ])->assertRedirect();

        $record = CustomerVisitAttendance::where('employee_id', $this->employeeA->employee_id)->first();
        $this->assertNull($record->device_captured_at);
        $this->assertFalse($record->device_timestamp_flag);
    }
}
