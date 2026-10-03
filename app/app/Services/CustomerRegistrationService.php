<?php

namespace App\Services;

use App\Enums\CustomerType;
use App\Enums\Weekday;
use App\Models\CustomerEmployee;
use App\Models\CustomerFjp;
use App\Models\CustomerMaster;
use App\Models\EmployeeMaster;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Field registration of a SECONDARY customer by a sales employee.
 *
 * Rulings applied:
 *  - The employee CREATES customers; editing existing customers stays out of
 *    scope (master-data administration remains an admin action).
 *  - The new customer is automatically assigned to the registering employee
 *    through the existing `customer_employee` model — the same assignment
 *    that authorizes visits, stock counts and order capture.
 *  - The country is fixed by configuration and the GPS coordinates come from
 *    the device capture; neither is client-editable (the server sets/ignores
 *    them regardless of what a crafted request asks for).
 *  - Duplicate detection uses the CANONICAL phone number. Check + create run
 *    under a database advisory lock keyed on the canonical number, and the
 *    `uq_cm_phone_canonical` unique index is the final backstop, so two
 *    concurrent requests cannot both create the same customer.
 *  - Registration NEVER records attendance: check-in stays an explicit action.
 */
class CustomerRegistrationService
{
    public function __construct(
        private readonly PhoneNumberService $phones,
        private readonly FjpRotationService $rotation,
    ) {}

    /**
     * @param  array{
     *     business_name: string, contact_person?: ?string, phone?: ?string,
     *     gps_latitude: string|float, gps_longitude: string|float,
     *     address?: ?string, address2?: ?string, city?: ?string, state?: ?string,
     *     postal_code?: ?string, sales_region?: ?string, market?: ?string,
     *     preferred_visits?: array<int, array{preferred_week?: ?int, preferred_day: int|string}>
     * }  $data
     * @return array{customer: ?CustomerMaster, duplicate: ?CustomerMaster, visits: array<int, CustomerFjp>, matches_today: bool}
     */
    public function register(EmployeeMaster $employee, array $data): array
    {
        $canonical = $this->phones->canonical($data['phone'] ?? null);

        if (($data['phone'] ?? null) !== null && $data['phone'] !== '' && $canonical === null) {
            abort(422, 'Enter a valid '.$this->phones->countryName().' phone number.');
        }

        if ($canonical !== null && ! $this->phones->isValidCanonical($canonical)) {
            abort(422, 'Enter a valid '.$this->phones->countryName().' phone number.');
        }

        $lockName = $canonical !== null ? 'erp-customer-phone:'.sha1($canonical) : null;

        if ($lockName !== null) {
            $this->acquireAdvisoryLock($lockName);
        }

        try {
            $duplicate = $canonical !== null ? $this->findDuplicateByPhone($canonical) : null;

            if ($duplicate !== null) {
                return [
                    'customer' => null,
                    'duplicate' => $duplicate,
                    'visits' => [],
                    'matches_today' => false,
                ];
            }

            $result = DB::transaction(fn () => $this->createCustomer($employee, $data, $canonical));
        } catch (QueryException $e) {
            // DB backstop: the unique canonical-phone index fired between the
            // duplicate check and the insert.
            if ($this->isDuplicatePhoneViolation($e)) {
                return [
                    'customer' => null,
                    'duplicate' => $this->findDuplicateByPhone((string) $canonical),
                    'visits' => [],
                    'matches_today' => false,
                ];
            }

            throw $e;
        } finally {
            if ($lockName !== null) {
                $this->releaseAdvisoryLock($lockName);
            }
        }

        return $result;
    }

    /**
     * Existing customer for this canonical number, or null. Legacy rows have
     * no canonical column, so candidate rows are matched by their last
     * subscriber digits and then normalized in PHP — never by fuzzy text.
     */
    public function findDuplicateByPhone(string $canonical): ?CustomerMaster
    {
        $local = (string) $this->phones->localPart($canonical);

        // A free-text legacy number may be formatted any way at all, so the
        // candidate search strips the common separators in SQL and then the
        // canonical comparison below is what actually decides.
        $candidates = CustomerMaster::query()
            ->where('phone_canonical', $canonical)
            ->orWhereRaw(
                "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone_number, ''), ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') LIKE ?",
                ['%'.$local.'%'],
            )
            ->orderBy('customer_id')
            ->get();

        foreach ($candidates as $candidate) {
            if ($candidate->phone_canonical === $canonical) {
                return $candidate;
            }

            if ($this->phones->canonical($candidate->phone_number) === $canonical) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return array{customer: CustomerMaster, duplicate: null, visits: array<int, CustomerFjp>, matches_today: bool} */
    private function createCustomer(EmployeeMaster $employee, array $data, ?string $canonical): array
    {
        if ($employee->region_code === null || $employee->region_code === '') {
            abort(422, 'Your employee placement must have a sales region before registering customers.');
        }

        $customer = CustomerMaster::create([
            'business_name' => $data['business_name'],
            'customer_type' => CustomerType::SECONDARY, // server-set, never client-supplied
            'parent_customer_id' => null,
            'contact_person' => $data['contact_person'] ?? null,
            'phone_number' => $canonical,
            'phone_canonical' => $canonical,
            'gps_latitude' => $data['gps_latitude'],   // device capture, server-validated
            'gps_longitude' => $data['gps_longitude'],
            'address' => $data['address'] ?? null,
            'address2' => $data['address2'] ?? null,
            'state' => $data['state'] ?? null,
            'city' => $data['city'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'country' => $this->phones->countryName(), // locked to the configured country
            'sales_region' => $employee->region_code,
            'market' => $data['market'] ?? null,
            'active' => true,
        ]);

        // The registering employee owns the relationship (existing model).
        CustomerEmployee::create([
            'customer_id' => $customer->customer_id,
            'employee_id' => $employee->employee_id,
            'role' => 'SE',
            'valid_from' => today(),
        ]);

        $visits = $this->syncPreferredVisits($employee, $customer, $data['preferred_visits'] ?? []);

        return [
            'customer' => $customer->fresh(),
            'duplicate' => null,
            'visits' => $visits,
            'matches_today' => $this->matchesToday($visits),
        ];
    }

    /**
     * Preferred visit (Fixed Journey Plan) entries — the project's ONE
     * scheduling model (customer_fjp), never a parallel structure.
     *
     * The day is stored as the compact numeric weekday index
     * (0 = Sunday … 6 = Saturday); the platform never persists a combined
     * string such as `W1-Mon`.
     *
     * @param  array<int, array{preferred_week?: ?int, preferred_day: int|string}>  $entries
     * @return array<int, CustomerFjp>
     */
    private function syncPreferredVisits(EmployeeMaster $employee, CustomerMaster $customer, array $entries): array
    {
        $created = [];
        $seen = [];

        foreach ($entries as $entry) {
            if (! isset($entry['preferred_day']) || $entry['preferred_day'] === null || $entry['preferred_day'] === '') {
                continue;
            }

            $day = $this->normalizeDay($entry['preferred_day']);
            $week = isset($entry['preferred_week']) && $entry['preferred_week'] !== null && $entry['preferred_week'] !== ''
                ? (int) $entry['preferred_week']
                : null;

            if ($day === null) {
                abort(422, 'Preferred visit day must be a weekday.');
            }

            if ($week !== null && ($week < 1 || $week > FjpRotationService::WEEKS_IN_ROTATION)) {
                abort(422, 'Preferred visit week must be 1 to '.FjpRotationService::WEEKS_IN_ROTATION.', or empty for every week.');
            }

            $key = ($week ?? '*').'|'.$day;

            if (isset($seen[$key])) {
                continue; // duplicate entry in the same submission
            }

            $seen[$key] = true;

            $created[] = CustomerFjp::create([
                'company_id' => $employee->company_id,
                'customer_id' => $customer->customer_id,
                'preferred_week' => $week,
                'preferred_day' => $day,
                'active' => true,
            ]);
        }

        return $created;
    }

    /**
     * Does any preferred visit entry put this customer in TODAY's journey
     * plan? (Same rotation rule the FJP screens use.)
     *
     * @param  array<int, CustomerFjp>  $visits
     */
    public function matchesToday(array $visits, ?CarbonInterface $date = null): bool
    {
        $date ??= now();
        $today = Weekday::from((int) $date->format('w'))->value;
        $week = $this->rotation->rotationWeek(Carbon::parse($date->toDateString()));

        foreach ($visits as $visit) {
            if (! $visit->active) {
                continue;
            }

            if ((int) $visit->preferred_day === $today && $this->rotation->matchesWeek($visit->preferred_week, Carbon::parse($date->toDateString()))) {
                return true;
            }
        }

        return false;
    }

    /** UI weekday index (0=Sunday … 6=Saturday) or a legacy name → numeric index. */
    private function normalizeDay(int|string $day): ?int
    {
        return Weekday::parse($day)?->value;
    }

    private function acquireAdvisoryLock(string $name): void
    {
        DB::selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$name, 10]);
    }

    private function releaseAdvisoryLock(string $name): void
    {
        DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
    }

    private function isDuplicatePhoneViolation(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062
            && str_contains((string) ($e->errorInfo[2] ?? ''), 'uq_cm_phone_canonical');
    }
}
