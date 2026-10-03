<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\EmployeeMaster;
use App\Models\SalesRegion;
use Database\Seeders\EmanlEmployeeSeeder;
use Database\Seeders\SalesRegionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmanlEnterpriseSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_regions_and_employee_hierarchy_are_seeded_idempotently(): void
    {
        $this->seed([SalesRegionSeeder::class, EmanlEmployeeSeeder::class]);
        $this->seed([SalesRegionSeeder::class, EmanlEmployeeSeeder::class]);

        $this->assertSame(11, SalesRegion::count());
        $this->assertSame(203, EmployeeMaster::where('company_id', 'EMANL')->whereNotNull('region_code')->count());
        $this->assertSame(193, EmployeeMaster::where('company_id', 'EMANL')->whereNotNull('partner_id')->count());

        $employee = EmployeeMaster::findOrFail('133101011');

        $this->assertSame('NGR004', $employee->region_code);
        $this->assertSame('ASM', $employee->partner_function);
        $this->assertSame('19160407', $employee->partner_id);
        $this->assertSame('Southeast', $employee->region->description);
        $this->assertSame('Adekunle Abioye', $employee->partner->employee_name);

        $salesperson = EmployeeMaster::findOrFail('185010212');
        $account = AppUser::where('employee_id', $salesperson->employee_id)->firstOrFail();

        $this->assertSame('ibrahim.sanni.yunusa@euro-mega.com', $salesperson->email_address);
        $this->assertSame($salesperson->email_address, $account->email);
        $this->assertSame('SALES_EMPLOYEE', $account->role->value);
        $this->assertTrue(Hash::check('password', $account->password_hash));
        $this->assertSame(133, AppUser::whereIn(
            'employee_id',
            EmployeeMaster::where('company_id', 'EMANL')->where('partner_function', 'SP')->pluck('employee_id'),
        )->count());
    }
}
