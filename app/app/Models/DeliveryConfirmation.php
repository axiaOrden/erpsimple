<?php

namespace App\Models;

use App\Enums\ConfirmationStatus;
use App\Enums\DifferenceReason;
use Illuminate\Database\Eloquent\Model;

/** POD: per delivery item confirmation with difference reason. */
class DeliveryConfirmation extends Model
{
    public $timestamps = false;

    protected $table = 'delivery_confirmation';

    protected $primaryKey = 'confirmation_id';

    protected $fillable = [
        'delivery_no',
        'delivery_item_no',
        'confirmed_qty',
        'confirmed_unit',
        'difference_qty',
        'difference_unit',
        'difference_reason',
        'confirmation_status',
        'confirmation_date',
        'confirmed_by',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'difference_reason' => DifferenceReason::class,
            'confirmation_status' => ConfirmationStatus::class,
            'confirmed_qty' => 'decimal:3',
            'difference_qty' => 'decimal:3',
            'confirmation_date' => 'datetime',
        ];
    }

    public function deliveryItem()
    {
        return $this->belongsTo(DeliveryItem::class, ['delivery_no', 'delivery_item_no'], ['delivery_no', 'item_no']);
    }

    public function confirmedByEmployee()
    {
        return $this->belongsTo(EmployeeMaster::class, 'confirmed_by', 'employee_id');
    }
}
