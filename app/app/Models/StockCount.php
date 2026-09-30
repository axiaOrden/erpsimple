<?php

namespace App\Models;

use App\Enums\CountStatus;
use App\Enums\CountType;
use Illuminate\Database\Eloquent\Model;

class StockCount extends Model
{
    protected $table = 'stock_count';

    protected $primaryKey = 'count_no';

    public $incrementing = false;

    protected $keyType = 'string';

    const UPDATED_AT = null;

    protected $fillable = [
        'count_no',
        'company_id',
        'customer_id',
        'employee_id',
        'count_type',
        'count_status',
        'count_date',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'count_type' => CountType::class,
            'count_status' => CountStatus::class,
            'count_date' => 'date',
            'created_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function company()
    {
        return $this->belongsTo(CompanyMaster::class, 'company_id', 'company_id');
    }

    public function customer()
    {
        return $this->belongsTo(CustomerMaster::class, 'customer_id', 'customer_id');
    }

    public function employee()
    {
        return $this->belongsTo(EmployeeMaster::class, 'employee_id', 'employee_id');
    }

    public function items()
    {
        return $this->hasMany(StockCountItem::class, 'count_no', 'count_no');
    }
}
