<?php

namespace App\Models;

use App\Models\Concerns\HasCompositeKey;
use Illuminate\Database\Eloquent\Model;

/**
 * Employee product scope. No rows for an employee = access to all active
 * products of their company. Rows present = restricted to these products.
 */
class EmployeeProduct extends Model
{
    use HasCompositeKey;

    public $timestamps = false;

    protected $table = 'employee_product';

    public $incrementing = false;

    protected $fillable = ['employee_id', 'product_id'];

    public function employee()
    {
        return $this->belongsTo(EmployeeMaster::class, 'employee_id', 'employee_id');
    }

    public function product()
    {
        return $this->belongsTo(ProductMaster::class, 'product_id', 'product_id');
    }

    protected function compositeKeyColumns(): array
    {
        return ['employee_id', 'product_id'];
    }
}
