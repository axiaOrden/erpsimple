<?php

namespace App\Models;

use App\Enums\TransitAllocationStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 9 — a transit quantity reserved for a Delivery Item.
 * Reversible (ACTIVE → RELEASED) until Shipment START finalizes it
 * (ACTIVE → FINALIZED); rows are history, never deleted.
 */
class TransitStockAllocation extends Model
{
    protected $table = 'transit_stock_allocation';

    protected $primaryKey = 'transit_allocation_id';

    public $timestamps = false;

    protected $fillable = [
        'transit_id',
        'delivery_no',
        'delivery_item_no',
        'allocated_qty',
        'allocated_by',
        'allocated_at',
        'alloc_status',
        'released_at',
        'finalized_at',
    ];

    protected $casts = [
        'alloc_status' => TransitAllocationStatus::class,
        'allocated_qty' => 'decimal:3',
        'delivery_item_no' => 'integer',
        'allocated_at' => 'datetime',
        'released_at' => 'datetime',
        'finalized_at' => 'datetime',
    ];

    public function transit()
    {
        return $this->belongsTo(TransitStock::class, 'transit_id', 'transit_id');
    }
}
