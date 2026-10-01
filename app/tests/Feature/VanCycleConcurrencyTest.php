<?php

namespace Tests\Feature;

use App\Models\CompanyMaster;
use App\Models\CustomerMaster;
use App\Models\EmployeeMaster;
use App\Models\ProductMaster;
use App\Models\SalesOrder;
use App\Services\SalesOrderService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * REAL MariaDB row-locking concurrency test for the VAN one-cycle rule.
 *
 * Deliberately does NOT use DatabaseTransactions: a wrapping test transaction
 * would isolate this connection from any other and make FOR UPDATE locking
 * meaningless. Fixtures are written with CVN.-prefixed IDs and purged in
 * tearDown() instead.
 *
 * L: two attempts to start a new VAN cycle concurrently cannot both succeed.
 * The child process bootstraps a SECOND full Laravel app, locks the VAN's
 * `customer_master` row FOR UPDATE, and then confirms its own VAN draft inside
 * that same transaction. The parent starts its own confirmation while the lock
 * is held: it must WAIT on the VAN row, and after the child commits it must
 * still be refused — proving the guard's determination is made against the
 * committed state of the winner, not against a pre-lock snapshot.
 */
class VanCycleConcurrencyTest extends TestCase
{
    private const PREFIX_COMPANY = 'CVN.';

    private int $childPid = 0;

    private string $productId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->purgeResidue();
    }

    protected function tearDown(): void
    {
        if ($this->childPid > 0) {
            if (function_exists('posix_kill')) {
                @posix_kill($this->childPid, SIGKILL);
            } else {
                @exec('kill -9 '.(int) $this->childPid);
            }
            pcntl_waitpid($this->childPid, $status, WNOHANG);
            $this->childPid = 0;
        }

        $this->purgeResidue();

        parent::tearDown();
    }

    public function test_two_concurrent_van_confirmations_cannot_both_start_a_cycle(): void
    {
        [$employee, $primary, $van] = $this->fixtures();

        $orders = app(SalesOrderService::class);

        $draftA = $this->draft($orders, $employee, $primary, $van);
        $draftB = $this->draft($orders, $employee, $primary, $van);

        // ---- Child: holds the VAN row lock, then confirms draft A inside it ----
        $signal = tempnam(sys_get_temp_dir(), 'vanconc');
        $scriptResource = tmpfile();
        fwrite($scriptResource, $this->childScript());
        $scriptPath = stream_get_meta_data($scriptResource)['uri'];
        $basePath = dirname(__DIR__, 2);

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'pcntl_fork failed.');

        if ($pid === 0) {
            pcntl_exec(PHP_BINARY, [
                $scriptPath, $van->customer_id, $draftA->sales_order_no, $signal, $basePath,
            ]);
            exit(127); // exec failed
        }

        $this->childPid = $pid;

        $locked = false;

        for ($i = 0; $i < 100; $i++) {
            if (is_file($signal) && str_starts_with((string) file_get_contents($signal), 'LOCKED')) {
                $locked = true;
                break;
            }
            usleep(50_000);
        }

        $this->assertTrue($locked, 'Child never acquired the VAN customer row lock.');

        // Parent: the REAL confirmation of the second cycle while the child
        // holds the VAN row and is about to confirm the first one.
        $start = microtime(true);

        $result = $orders->confirm($draftB->fresh());

        $blockedFor = microtime(true) - $start;

        $this->assertGreaterThan(
            1.0,
            $blockedFor,
            'The confirmation returned instantly — it did NOT wait on the VAN row lock; serialization is not real.',
        );

        $this->assertNotSame([], $result['conflicts'], 'The loser must be refused.');
        $this->assertStringContainsString('unfinished transaction', $result['conflicts'][0]);
        $this->assertStringContainsString($draftA->sales_order_no, $result['conflicts'][0]);

        // Exactly ONE active VAN cycle exists: the child's.
        $this->assertSame('CONFIRMED', $draftA->fresh()->order_status->value);
        $this->assertSame('DRAFT', $draftB->fresh()->order_status->value);

        $active = SalesOrder::where('sold_to_customer_id', $van->customer_id)
            ->where('company_id', $employee->company_id)
            ->where('order_status', '!=', 'DRAFT')
            ->count();

        $this->assertSame(1, $active, 'Two concurrent attempts must not create two active VAN cycles.');

        @pcntl_waitpid($pid, $status, WNOHANG);
        $this->childPid = 0;
        @unlink($signal);
    }

    public function test_serial_van_cycle_transition_is_exact_without_contention(): void
    {
        // Harness canary: with no locking counterpart the rule behaves exactly
        // as in the transactional suite (residue purge works, no leakage).
        [$employee, $primary, $van] = $this->fixtures();

        $orders = app(SalesOrderService::class);
        $draft = $this->draft($orders, $employee, $primary, $van);

        $result = $orders->confirm($draft->fresh());

        $this->assertSame([], $result['conflicts']);
        $this->assertSame('CONFIRMED', $draft->fresh()->order_status->value);

        $status = $orders->vanCycleStatus($van->customer_id, $employee->company_id);
        $this->assertNotNull($status);
        $this->assertSame(['open demand not yet allocated'], $status['blocking'][0]['reasons']);

        try {
            $this->draft($orders, $employee, $primary, $van);
            $this->fail('A second VAN cycle must be refused while the first is unfinished.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(1, SalesOrder::where('company_id', $employee->company_id)->count());
    }

    // ---- Fixtures ---------------------------------------------------------------

    /** @return array{0: EmployeeMaster, 1: CustomerMaster, 2: CustomerMaster} */
    private function fixtures(): array
    {
        $hex = bin2hex(random_bytes(4));
        $companyId = self::PREFIX_COMPANY.$hex;

        $company = CompanyMaster::factory()->create(['company_id' => $companyId]);
        $employee = EmployeeMaster::factory()->create([
            'employee_id' => 'CVNE.'.$hex,
            'company_id' => $companyId,
        ]);

        $primary = CustomerMaster::factory()->primary()->forEmployee($employee)
            ->create(['customer_id' => 'CVNP.'.$hex]);
        $van = CustomerMaster::factory()->van()->forEmployee($employee)
            ->create(['customer_id' => 'CVNV.'.$hex]);

        $this->productId = 'CVNQ.'.$hex;

        ProductMaster::factory()->forCompany($company)->create([
            'product_id' => $this->productId,
            'basic_unit' => 'PCS',
        ]);

        return [$employee, $primary, $van];
    }

    private function draft(SalesOrderService $orders, EmployeeMaster $employee, CustomerMaster $primary, CustomerMaster $van): SalesOrder
    {
        return $orders->createDraft(
            $employee,
            $primary->customer_id,
            $primary->customer_id,
            $van->customer_id,
            collect([[
                'product_id' => $this->productId,
                'qty' => '10',
                'unit' => 'PCS',
                'unit_price' => null,
                'price_override_reason' => null,
            ]]),
        )['order'];
    }

    /**
     * Child bootstrap: own Laravel app, own transaction — hold the VAN row,
     * then run the REAL confirmation of the competing draft while holding it.
     */
    private function childScript(): string
    {
        return <<<'PHP'
<?php
require $argv[4].'/vendor/autoload.php';
$app = require $argv[4].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$vanId = $argv[1];
$draftNo = $argv[2];
$signal = $argv[3];

Illuminate\Support\Facades\DB::transaction(function () use ($vanId, $draftNo, $signal) {
    // Hold the VAN customer row — the rule's serialization point.
    Illuminate\Support\Facades\DB::table('customer_master')
        ->where('customer_id', $vanId)
        ->lockForUpdate()
        ->first();

    file_put_contents($signal, 'LOCKED');

    sleep(3); // hold the lock while the parent's confirmation starts

    // The REAL competing confirmation, committed with this transaction.
    $result = app(App\Services\SalesOrderService::class)
        ->confirm(App\Models\SalesOrder::findOrFail($draftNo));

    file_put_contents($signal, $result['conflicts'] === [] ? 'CONFIRMED' : 'REFUSED');
});
PHP;
    }

    /** Remove every fixture row this class may have created (children-first). */
    private function purgeResidue(): void
    {
        $companyLike = self::PREFIX_COMPANY.'%';
        $customerLike = 'CVN%';
        $employeeLike = 'CVNE.%';

        DB::table('sales_order_item')->whereIn('sales_order_no', function ($q) use ($companyLike) {
            $q->select('sales_order_no')->from('sales_order')->where('company_id', 'like', $companyLike);
        })->delete();
        DB::table('sales_order')->where('company_id', 'like', $companyLike)->delete();
        DB::table('customer_employee')->whereIn('customer_id', function ($q) use ($customerLike) {
            $q->select('customer_id')->from('customer_master')->where('customer_id', 'like', $customerLike);
        })->delete();
        DB::table('customer_master')->where('customer_id', 'like', $customerLike)->delete();
        DB::table('employee_product')->whereIn('product_id', function ($q) use ($companyLike) {
            $q->select('product_id')->from('product_master')->where('company_id', 'like', $companyLike);
        })->delete();
        DB::table('product_unit_conversion')->whereIn('product_id', function ($q) use ($companyLike) {
            $q->select('product_id')->from('product_master')->where('company_id', 'like', $companyLike);
        })->delete();
        DB::table('product_master')->where('company_id', 'like', $companyLike)->delete();
        DB::table('employee_master')->where('employee_id', 'like', $employeeLike)->delete();
        DB::table('company_master')->where('company_id', 'like', $companyLike)->delete();
    }
}
