<?php

namespace App\Models;

use App\Enums\LiabilityParty;
use App\Enums\TransitAllocationStatus;
use App\Enums\TransitStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 9 — one traceable balance of issued-but-unaccepted stock.
 * Identity: company → origin shipment → origin delivery → delivery item →
 * source → product → quantity → holder. Never an anonymous product pool.
 *
 * original_quantity is written once at creation and never updated;
 * quantity is the remaining open balance.
 */
class TransitStock extends Model
{
    protected $table = 'transit_stock';

    protected $primaryKey = 'transit_id';

    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'origin_shipment_no',
        'origin_delivery_no',
        'origin_delivery_item_no',
        'source_customer_id',
        'product_id',
        'original_quantity',
        'quantity',
        'basic_unit',
        'holding_employee_id',
        'claimed_by_employee_id',
        'verified_by_employee_id',
        'transit_status',
        'liability_party',
        'origin_confirmation_id',
        'parent_transit_id',
        'resolved_to_delivery_no',
        'resolved_movement_id',
        'resolved_by',
        'resolved_at',
        'remarks',
    ];

    protected $casts = [
        'transit_status' => TransitStatus::class,
        'liability_party' => LiabilityParty::class,
        'original_quantity' => 'decimal:3',
        'quantity' => 'decimal:3',
        'origin_delivery_item_no' => 'integer',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(ProductMaster::class, 'product_id', 'product_id');
    }

    /** Reservations currently ACTIVE against this row (excludes it from the free balance). */
    public function activeAllocations()
    {
        return $this->hasMany(TransitStockAllocation::class, 'transit_id', 'transit_id')
            ->where('alloc_status', TransitAllocationStatus::ACTIVE->value);
    }

    public function holder()
    {
        return $this->belongsTo(EmployeeMaster::class, 'holding_employee_id', 'employee_id');
    }

    /**
     * Open FREE balances for one holder (product, source) — FIFO consumption
     * order. A row carrying an ACTIVE reservation is excluded: its quantity
     * is reserved, not freely reusable (physical conservation — the balance
     * moved root → child on reservation and returns only on release).
     */
    public function scopeReusableFor(Builder $q, string $employeeId, string $productId, string $sourceCustomerId): Builder
    {
        return $q->where('holding_employee_id', $employeeId)
            ->where('product_id', $productId)
            ->where('source_customer_id', $sourceCustomerId)
            ->where('transit_status', TransitStatus::REUSABLE->value)
            ->where('quantity', '>', 0)
            ->whereDoesntHave('activeAllocations')
            ->orderBy('created_at')
            ->orderBy('transit_id');
    }

    /** Company-scoped visibility (custody-aware lists). */
    public function scopeForCompany(Builder $q, string $companyId): Builder
    {
        return $q->where('company_id', $companyId);
    }
}
