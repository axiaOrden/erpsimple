<?php

namespace Database\Seeders;

use App\Models\AppUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SplFileObject;

class EmanlEmployeeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = $this->rows();

        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                DB::table('employee_master')->updateOrInsert(
                    ['employee_id' => trim($row['employee_id'])],
                    [
                        'company_id' => 'EMANL',
                        'employee_name' => trim($row['employee_name']),
                        'email_address' => $this->nullable($row['email']),
                        'region_code' => trim($row['region_code']),
                        'partner_function' => trim($row['partner_function']),
                        'partner_id' => null,
                        'active' => true,
                        'created_at' => $this->date($row['created_at']),
                        'updated_at' => $this->date($row['updated_at']),
                    ],
                );
            }

            foreach ($rows as $row) {
                DB::table('employee_master')
                    ->where('employee_id', trim($row['employee_id']))
                    ->update(['partner_id' => $this->nullable($row['partner_id'])]);
            }

            foreach ($rows as $row) {
                $email = $this->nullable($row['email']);

                if (trim($row['partner_function']) !== 'SP' || $email === null) {
                    continue;
                }

                $user = AppUser::firstOrNew(['employee_id' => trim($row['employee_id'])]);
                $user->fill([
                    'company_id' => 'EMANL',
                    'name' => trim($row['employee_name']),
                    'email' => $email,
                    'role' => 'SALES_EMPLOYEE',
                    'active' => true,
                ]);

                if (! $user->exists) {
                    $user->password_hash = 'password';
                }

                $user->save();
            }
        });
    }

    /** @return list<array<string, string>> */
    private function rows(): array
    {
        $path = database_path('seeders/data/emanl-employees.csv');
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

            if ($row === false || trim($row['company_id'] ?? '') !== 'EMANL' || trim($row['employee_id'] ?? '') === '') {
                throw new RuntimeException("CSV file {$path} contains an invalid EMANL employee row.");
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function date(string $value): string
    {
        return Carbon::createFromFormat('n/j/Y H:i', trim($value))->format('Y-m-d H:i:s');
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
