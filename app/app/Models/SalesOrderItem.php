<?php

namespace App\Models;

use App\Enums\LineSource;
use App\Enums\RejectionStatus;
use App\Models\Concerns\HasCompositeKey;
use Illuminate\Database\Eloquent\Model;

class SalesOrderItem extends Model
{
    use HasCompositeKey;

    public $timestamps = false;

    protected $table = 'sales_order_item';

    public $incrementing = false;

    protected $fillable = [
        'sales_order_no',
        'item_no',
        'product_id',
        'line_source',
        'is_free_item',
        'parent_item_no',
        'order_qty',
        'order_unit',
        'recommended_price',
        'unit_price',
        'price_overridden',
        'price_override_reason',
        'discount_amount',
        'tax_amount',
        'subtotal_amount',
        'condition_price_no',
        'deal_no',
        'deal_blocked',
        'rejection_status',
        'rejection_reason',
        'rejected_by',
        'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'line_source' => LineSource::class,
            'is_free_item' => 'boolean',
            'deal_blocked' => 'boolean',
            'price_overridden' => 'boolean',
            'rejection_status' => RejectionStatus::class,
            'order_qty' => 'decimal:3',
            'recommended_price' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'subtotal_amount' => 'decimal:2',
            'rejected_at' => 'datetime',
        ];
    }

    protected function compositeKeyColumns(): array
    {
        return ['sales_order_no', 'item_no'];
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_no', 'sales_order_no');
    }

    public function product()
    {
        return $this->belongsTo(ProductMaster::class, 'product_id', 'product_id');
    }

    public function orderUnit()
    {
        return $this->belongsTo(UnitMaster::class, 'order_unit', 'unit_code');
    }

    public function parentItem()
    {
        return $this->belongsTo(self::class, ['sales_order_no', 'parent_item_no'], ['sales_order_no', 'item_no']);
    }

    public function dealLines()
    {
        return $this->hasMany(self::class, 'sales_order_no', 'sales_order_no')
            ->where('parent_item_no', $this->item_no);
    }

    public function deliveries()
    {
        return $this->hasMany(DeliveryItem::class, ['sales_order_no', 'sales_order_item_no'], ['sales_order_no', 'item_no']);
    }

    public function isRejected(): bool
    {
        return $this->rejection_status === RejectionStatus::REJECTED;
    }
}
