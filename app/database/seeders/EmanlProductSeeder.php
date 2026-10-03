<?php

namespace Database\Seeders;

use App\Models\ProductMaster;
use App\Models\ProductUnitConversion;
use Illuminate\Database\Seeder;
use RuntimeException;
use SplFileObject;

class EmanlProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = $this->readCsv(database_path('seeders/data/emanl-products.csv'));
        $conversions = $this->readCsv(database_path('seeders/data/emanl-alternative-units.csv'));

        foreach ($products as $row) {
            $externalId = trim($row['ext_product_id']);
            $existingProduct = ProductMaster::query()
                ->where('company_id', 'EMANL')
                ->where(function ($query) use ($externalId) {
                    $query->where('ext_product_id', $externalId)
                        ->orWhere('product_sku', $externalId);
                })
                ->first();
            $productId = $existingProduct?->product_id ?? 'EMANL-'.$externalId;

            ProductMaster::updateOrCreate(
                ['product_id' => $productId],
                [
                    'company_id' => 'EMANL',
                    'product_description' => trim($row['Description']),
                    'product_category' => trim($row['Category']),
                    'product_sku' => trim($row['SKU ID']),
                    'sku_description' => trim($row['SKU Description']),
                    'basic_unit' => strtoupper(trim($row['Basic Unit'])),
                    'ext_product_id' => $externalId,
                    'issuing_company' => trim($row['Issuing Company']) ?: 'EMANL',
                    'active' => true,
                ],
            );
        }

        foreach ($conversions as $row) {
            $externalId = trim($row['ext_product_id']);
            $productId = ProductMaster::where('company_id', 'EMANL')
                ->where('ext_product_id', $externalId)
                ->value('product_id');

            if ($productId === null) {
                throw new RuntimeException("Alternative unit references unknown EMANL product {$externalId}.");
            }

            ProductUnitConversion::updateOrCreate(
                [
                    'product_id' => $productId,
                    'alternative_unit' => $this->normalizeUnit($row['Alternative Unit']),
                ],
                [
                    'numerator' => trim($row['Numerator']),
                    'denominator' => trim($row['Denumereator']),
                ],
            );
        }
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

            if (! is_array($values) || $values === [null]) {
                continue;
            }

            if (count($headers) !== count($values)) {
                throw new RuntimeException("CSV file {$path} contains a row with an unexpected column count.");
            }

            $row = array_combine($headers, $values);

            if ($row === false) {
                throw new RuntimeException("CSV file {$path} could not be parsed.");
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function normalizeUnit(string $unit): string
    {
        return match (strtoupper(trim($unit))) {
            'PC' => 'PCS',
            default => strtoupper(trim($unit)),
        };
    }
}
