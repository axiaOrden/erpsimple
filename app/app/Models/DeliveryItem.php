<?php

namespace App\Models;

use App\Models\Concerns\HasCompositeKey;
use Illuminate\Database\Eloquent\Model;

class DeliveryItem extends Model
{
    use HasCompositeKey;

    public $timestamps = false;

    protected $table = 'delivery_item';

    public $incrementing = false;

    protected $fillable = [
        'delivery_no',
        'item_no',
        'sales_order_no',
        'sales_order_item_no',
        'product_id',
        'is_free_item',
        'allocated_qty',
        'delivery_unit',
    ];

    protected function casts(): array
    {
        return ['allocated_qty' => 'decimal:3'];
    }

    public function delivery()
    {
        return $this->belongsTo(Delivery::class, 'delivery_no', 'delivery_no');
    }

    /**
     * Traceability: Delivery Item → Sales Order Item on the composite
     * (sales_order_no, item_no) key.
     *
     * NOTE: Eloquent's standard BelongsTo cannot EAGER-load an array-keyed
     * foreign relation (str_contains() TypeError on Laravel 13); lazy loading
     * works. Eager-load item→product instead and reach the order through
     * Delivery::salesOrder until a composite-KEY belongsTo exists.
     */
    public function salesOrderItem()
    {
        return $this->belongsTo(SalesOrderItem::class, ['sales_order_no', 'sales_order_item_no'], ['sales_order_no', 'item_no']);
    }

    public function product()
    {
        return $this->belongsTo(ProductMaster::class, 'product_id', 'product_id');
    }

    /**
     * NOTE: a composite-keyed hasMany (delivery_no + delivery_item_no) is NOT
     * expressible with stock Eloquent on this Laravel version (TypeError even
     * on lazy access). Query App\Models\DeliveryConfirmation directly:
     *   DeliveryConfirmation::where('delivery_no', …)->where('delivery_item_no', item_no)
     * (Same Eloquent limitation as DeliveryItem::salesOrderItem eager loading.)
     */
    protected function compositeKeyColumns(): array
    {
        return ['delivery_no', 'item_no'];
    }
}
