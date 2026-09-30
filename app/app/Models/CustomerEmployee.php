<?php

namespace App\Models;

use App\Models\Concerns\HasCompositeKey;
use Illuminate\Database\Eloquent\Model;

/** Assignment of an employee to a customer (the commercial relationship). */
class CustomerEmployee extends Model
{
    use HasCompositeKey;

    public $timestamps = false;

    protected $table = 'customer_employee';

    public $incrementing = false;

    protected $fillable = ['customer_id', 'employee_id', 'role', 'valid_from', 'valid_to'];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    /** Is this assignment valid today? */
    public function isValidOn(\DateTimeInterface $date): bool
    {
        if ($this->valid_from !== null && $date->format('Y-m-d') < $this->valid_from->format('Y-m-d')) {
            return false;
        }

        if ($this->valid_to !== null && $date->format('Y-m-d') > $this->valid_to->format('Y-m-d')) {
            return false;
        }

        return true;
    }

    public function customer()
    {
        return $this->belongsTo(CustomerMaster::class, 'customer_id', 'customer_id');
    }

    public function employee()
    {
        return $this->belongsTo(EmployeeMaster::class, 'employee_id', 'employee_id');
    }

    protected function compositeKeyColumns(): array
    {
        return ['customer_id', 'employee_id', 'role'];
    }
}
