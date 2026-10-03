<?php

namespace Tests\Feature;

use App\Models\CompanyMaster;
use App\Models\CustomerMaster;
use App\Models\EmployeeMaster;
use App\Models\SalesRegion;
use App\Services\CustomerRegistrationService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * REAL concurrency test for duplicate SECONDARY registration.
 *
 * Fixtures use FC11.-prefixed ids and are purged in setUp()/tearDown()
 * (no DatabaseTransactions — the child processes need to see each other's
 * committed rows).
 *
 * Mechanism under test: `CustomerRegistrationService` serializes
 * "check + create" for one canonical phone on a MariaDB advisory lock
 * (GET_LOCK) keyed by the canonical number, and `uq_cm_phone_canonical`
 * is the database backstop. Two simultaneous registrations of the same
 * number in DIFFERENT spellings must therefore produce exactly one customer
 * and one duplicate prompt — never two customers, never a 500.
 */
class CustomerRegistrationConcurrencyTest extends TestCase
{
    private const PREFIX = 'FC11.';

    /** @var array<int, int> */
    private array $childPids = [];

    /** @var array<int, string> */
    private array $scriptFiles = [];

    private CompanyMaster $company;

    private EmployeeMaster $employee;

    private string $phone;

    private string $canonical;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purgeResidue();

        $hex = bin2hex(random_bytes(4));

        $this->company = CompanyMaster::factory()->create(['company_id' => self::PREFIX.$hex]);
        SalesRegion::firstOrCreate(['region_code' => 'FC11.REGION'], [
            'description' => 'Concurrency Test Region',
            'zone' => 'TEST',
            'sort_order' => 1,
        ]);
        $this->employee = EmployeeMaster::factory()->create([
            'employee_id' => self::PREFIX.'E.'.$hex,
            'company_id' => $this->company->company_id,
            'region_code' => 'FC11.REGION',
        ]);

        // A unique, deterministic-for-this-run subscriber number (10 national
        // significant digits: 809XXXXXXX).
        $local = '809'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
        $this->phone = '0'.$local;
        $this->canonical = '+234'.$local;
    }

    protected function tearDown(): void
    {
        foreach ($this->childPids as $pid) {
            if (function_exists('posix_kill')) {
                @posix_kill($pid, SIGKILL);
            } else {
                @exec('kill -9 '.(int) $pid);
            }
            @pcntl_waitpid($pid, $status, WNOHANG);
        }

        $this->childPids = [];

        foreach ($this->scriptFiles as $path) {
            @unlink($path);
        }

        $this->scriptFiles = [];

        $this->purgeResidue();

        parent::tearDown();
    }

    public function test_two_simultaneous_registrations_of_the_same_number_create_one_customer(): void
    {
        // Equivalent spellings of the SAME number, submitted concurrently.
        $spellings = [
            $this->phone,                       // 0809…
            '+234'.substr($this->phone, 1),     // +234809…
        ];

        $dir = sys_get_temp_dir().'/fc11-'.bin2hex(random_bytes(4));
        @mkdir($dir);

        $scripts = [];
        $outs = [];
        $pids = [];

        foreach ($spellings as $index => $spelling) {
            $script = $dir.'/child-'.$index.'.php';
            file_put_contents($script, $this->childScript());
            $this->scriptFiles[] = $script;

            $out = $dir.'/out-'.$index.'.json';
            $scripts[] = $script;
            $outs[] = $out;

            $pid = pcntl_fork();

            if ($pid === 0) {
                // Child: wait for the start signal, then register.
                while (! is_file($dir.'/go')) {
                    usleep(10_000);
                }

                $command = sprintf(
                    'php %s %s %s %s %s %s 2>&1',
                    escapeshellarg($script),
                    escapeshellarg($out),
                    escapeshellarg($dir.'/go'),
                    escapeshellarg(base_path()),
                    escapeshellarg($this->employee->employee_id),
                    escapeshellarg($spelling),
                );

                @exec($command);

                exit(0);
            }

            $pids[] = $pid;
            $this->childPids[] = $pid;
        }

        // Release both children at once.
        file_put_contents($dir.'/go', 'GO');

        $results = [];

        foreach ($outs as $out) {
            $results[] = $this->childResult($out);
        }

        foreach ($pids as $pid) {
            @pcntl_waitpid($pid, $status);
        }

        $created = array_filter($results, fn (array $r) => ($r['result'] ?? null) === 'CREATED');
        $duplicated = array_filter($results, fn (array $r) => ($r['result'] ?? null) === 'DUPLICATE');

        $this->assertCount(1, $created, 'Exactly one registration may create the customer.');
        $this->assertCount(1, $duplicated, 'The loser must receive the duplicate prompt, not an error.');

        // Database truth: one customer for this canonical number, one assignment.
        $this->assertSame(1, CustomerMaster::where('phone_canonical', $this->canonical)->count());
        $this->assertSame($this->canonical, CustomerMaster::where('phone_canonical', $this->canonical)->value('phone_number'),
            'The stored number is the canonical form of both spellings.');
        $this->assertSame(1, DB::table('customer_employee')
            ->where('employee_id', $this->employee->employee_id)
            ->count());

        // Neither child surfaced a raw database error.
        foreach ($results as $result) {
            $this->assertArrayNotHasKey('error', $result, 'No child may surface an unhandled failure.');
        }

        @unlink($dir.'/go');
        @rmdir($dir);
    }

    /** The child registers with the real service and reports the outcome. */
    private function childScript(): string
    {
        return <<<'PHP'
<?php
require $argv[3].'/vendor/autoload.php';
$app = require $argv[3].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$out = $argv[1];
$go = $argv[2];
$employeeId = $argv[4];
$phone = $argv[5];

try {
    $service = $app->make(App\Services\CustomerRegistrationService::class);
    $employee = App\Models\EmployeeMaster::findOrFail($employeeId);

    $result = $service->register($employee, [
        'business_name' => 'Racing Store '.$phone,
        'phone' => $phone,
        'gps_latitude' => '6.5000000',
        'gps_longitude' => '3.3000000',
        'city' => 'Lagos',
        'preferred_visits' => [['preferred_week' => null, 'preferred_day' => 1]],
    ]);

    file_put_contents($out, json_encode([
        'result' => $result['duplicate'] !== null ? 'DUPLICATE' : 'CREATED',
        'customer_id' => $result['customer']?->customer_id,
        'duplicate_id' => $result['duplicate']?->customer_id,
    ]));
} catch (Throwable $e) {
    file_put_contents($out, json_encode(['result' => 'FAILED', 'error' => $e->getMessage()]));
}
PHP;
    }

    /** @return array<string, mixed> */
    private function childResult(string $out): array
    {
        for ($i = 0; $i < 400; $i++) {
            if (is_file($out) && str_starts_with((string) file_get_contents($out), '{"')) {
                $payload = json_decode((string) file_get_contents($out), true);

                $this->assertIsArray($payload);

                return $payload;
            }

            usleep(50_000);
        }

        $this->fail('Child registration never produced output ('.$out.').');
    }

    private function purgeResidue(): void
    {
        $companyLike = self::PREFIX.'%';
        $employeeLike = self::PREFIX.'E.%';

        $customerIds = DB::table('customer_employee')
            ->where('employee_id', 'like', $employeeLike)
            ->pluck('customer_id')
            ->all();

        DB::table('customer_fjp')->whereIn('customer_id', $customerIds)->delete();
        DB::table('customer_employee')->where('employee_id', 'like', $employeeLike)->delete();
        DB::table('customer_visit_attendance')->whereIn('customer_id', $customerIds)->delete();

        if ($customerIds !== []) {
            DB::table('customer_master')->whereIn('customer_id', $customerIds)->delete();
        }

        DB::table('customer_master')->where('phone_canonical', 'like', '+234809%')
            ->whereNotIn('customer_id', function ($q) {
                $q->select('customer_id')->from('customer_employee');
            })
            ->where('customer_id', 'like', 'CUS-%')
            ->delete();

        DB::table('app_user')->where('company_id', 'like', $companyLike)->delete();
        DB::table('employee_master')->where('employee_id', 'like', $employeeLike)->delete();
        DB::table('company_master')->where('company_id', 'like', $companyLike)->delete();
        DB::table('sales_region')->where('region_code', 'FC11.REGION')->delete();
    }
}
