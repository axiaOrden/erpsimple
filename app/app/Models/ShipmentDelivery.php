<?php

namespace App\Models;

use App\Models\Concerns\HasCompositeKey;
use Illuminate\Database\Eloquent\Model;

/** Pivot: one Delivery belongs to at most one Shipment (unique delivery_no). */
class ShipmentDelivery extends Model
{
    use HasCompositeKey;

    public $timestamps = false;

    protected $table = 'shipment_delivery';

    public $incrementing = false;

    protected $fillable = ['shipment_no', 'delivery_no', 'sequence_no', 'attached_on'];

    protected function casts(): array
    {
        return ['attached_on' => 'datetime'];
    }

    public function shipment()
    {
        return $this->belongsTo(Shipment::class, 'shipment_no', 'shipment_no');
    }

    public function delivery()
    {
        return $this->belongsTo(Delivery::class, 'delivery_no', 'delivery_no');
    }

    protected function compositeKeyColumns(): array
    {
        return ['shipment_no', 'delivery_no'];
    }
}
