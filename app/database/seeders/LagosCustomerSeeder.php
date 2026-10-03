<?php

namespace Database\Seeders;

use App\Models\CustomerMaster;
use App\Services\PhoneNumberService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SplFileObject;

class LagosCustomerSeeder extends Seeder
{
    public function run(): void
    {
        $rows = $this->readCsv(database_path('seeders/data/lagos-customers.csv'));
        $phoneNumbers = app(PhoneNumberService::class);
        $canonicalCounts = [];

        foreach ($rows as $row) {
            $canonical = $phoneNumbers->canonical($row['phone_number']);

            if ($canonical !== null) {
                $canonicalCounts[$canonical] = ($canonicalCounts[$canonical] ?? 0) + 1;
            }
        }

        DB::transaction(function () use ($rows, $phoneNumbers, $canonicalCounts): void {
            foreach ($rows as $row) {
                $externalId = $this->nullable($row['ext_origin_id']);
                $originCompany = $this->nullable($row['ext_origin_company']);
                $canonical = $phoneNumbers->canonical($row['phone_number']);

                if ($canonical !== null && $canonicalCounts[$canonical] > 1) {
                    $canonical = null;
                }

                $identity = $externalId !== null
                    ? ['ext_origin_company' => $originCompany, 'ext_origin_id' => $externalId]
                    : [
                        'ext_origin_company' => $originCompany,
                        'business_name' => trim($row['business_name']),
                        'phone_number' => $this->nullable($row['phone_number']),
                    ];

                CustomerMaster::updateOrCreate($identity, [
                    'business_name' => trim($row['business_name']),
                    'customer_type' => trim($row['customer_type']),
                    'parent_customer_id' => null,
                    'ext_origin_id' => $externalId,
                    'ext_origin_company' => $originCompany,
                    'contact_person' => $this->nullable($row['contact_person']),
                    'phone_number' => $this->nullable($row['phone_number']),
                    'phone_canonical' => $canonical,
                    'email_address' => $this->nullable($row['email_address']),
                    'gps_latitude' => $this->nullable($row['gps_latitude']),
                    'gps_longitude' => $this->nullable($row['gps_longitude']),
                    'address' => $this->nullable($row['address']),
                    'address2' => $this->nullable($row['address2']),
                    'state' => $this->nullable($row['state']),
                    'city' => $this->nullable($row['city']),
                    'postal_code' => $this->nullable($row['postal_code']),
                    'country' => $this->nullable($row['country']),
                    'sales_region' => $this->nullable($row['sales_region']),
                    'market' => $this->nullable($row['market']),
                    'active' => $this->boolean($row['active'], true),
                ]);
            }
        });
    }

    /**
     * @return list<array<string, string>>
     */
    private function readCsv(string $path): array
    {
        $file = new SplFileObject($path);
        $file->setCsvControl(',', '"', '');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        $headers = $file->fgetcsv();

        if (! is_array($headers)) {
            throw new RuntimeException("CSV file {$path} has no header row.");
        }

        $rows = [];

        while (! $file->eof()) {
            $values = $file->fgetcsv();

            if (! is_array($values) || $values === [null] || count(array_filter($values, fn ($value): bool => $value !== null && trim((string) $value) !== '')) === 0) {
                continue;
            }

            if (count($headers) !== count($values)) {
                throw new RuntimeException("CSV file {$path} contains a row with an unexpected column count.");
            }

            $row = array_combine($headers, $values);

            if ($row === false || trim($row['business_name']) === '' || trim($row['customer_type']) === '') {
                throw new RuntimeException("CSV file {$path} contains an invalid customer row.");
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function boolean(?string $value, bool $default): bool
    {
        $value = $this->nullable($value);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
