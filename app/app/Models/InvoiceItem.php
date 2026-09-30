<?php

namespace App\Models;

use App\Models\Concerns\HasCompositeKey;
use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    use HasCompositeKey;

    public $timestamps = false;

    protected $table = 'invoice_item';

    public $incrementing = false;

    protected $fillable = [
        'invoice_no',
        'item_no',
        'product_id',
        'is_free_item',
        'quantity',
        'invoice_unit',
        'unit_price',
        'discount_amount',
        'tax_amount',
        'subtotal_amount',
        'sales_order_no',
        'sales_order_item_no',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'subtotal_amount' => 'decimal:2',
        ];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_no', 'invoice_no');
    }

    public function product()
    {
        return $this->belongsTo(ProductMaster::class, 'product_id', 'product_id');
    }

    public function salesOrderItem()
    {
        return $this->belongsTo(SalesOrderItem::class, ['sales_order_no', 'sales_order_item_no'], ['sales_order_no', 'item_no']);
    }

    protected function compositeKeyColumns(): array
    {
        return ['invoice_no', 'item_no'];
    }
}
