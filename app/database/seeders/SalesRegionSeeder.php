<?php

namespace Database\Seeders;

use App\Models\SalesRegion;
use Illuminate\Database\Seeder;
use RuntimeException;
use SplFileObject;

class SalesRegionSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->rows() as $row) {
            SalesRegion::updateOrCreate(
                ['region_code' => trim($row['region_code'])],
                [
                    'description' => trim($row['description']),
                    'zone' => trim($row['zone']),
                    'sort_order' => (int) $row['sort_order'],
                ],
            );
        }
    }

    /** @return list<array<string, string>> */
    private function rows(): array
    {
        $path = database_path('seeders/data/sales-regions.csv');
        $file = new SplFileObject($path);
        $file->setCsvControl(',', '"', '');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        $headers = $file->fgetcsv();
        $rows = [];

        while (! $file->eof()) {
            $values = $file->fgetcsv();

            if (! is_array($values) || $values === [null]) {
                continue;
            }

            $row = is_array($headers) && count($headers) === count($values)
                ? array_combine($headers, $values)
                : false;

            if ($row === false || trim($row['region_code'] ?? '') === '') {
                throw new RuntimeException("CSV file {$path} contains an invalid region row.");
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
