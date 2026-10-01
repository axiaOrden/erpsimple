<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Controlled sales-order rejection reason (master data, never free text).
 *
 * Users may only choose rows with `user_selectable = TRUE`. reason_id 0
 * (`SYSTEM_DEFAULT`) is application automation: it is used when dependent
 * DEAL/free demand is closed because its parent paid demand was terminally
 * rejected, and it is never rendered in a user dropdown.
 *
 * Reasons are resolved and stored by STABLE ID / CODE — display labels are
 * presentation only and may be renamed without touching history.
 */
class SalesOrderRejectionReason extends Model
{
    public const CODE_SYSTEM_DEFAULT = 'SYSTEM_DEFAULT';

    public const CODE_CUSTOMER_REQUEST = 'CUSTOMER_REQUEST';

    public const CODE_UNAVAILABLE_STOCK = 'UNAVAILABLE_STOCK';

    protected $table = 'sales_order_rejection_reason';

    protected $primaryKey = 'reason_id';

    public $incrementing = false;

    const UPDATED_AT = null;

    protected $fillable = [
        'reason_id',
        'reason_code',
        'reason_name',
        'user_selectable',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'user_selectable' => 'boolean',
            'active' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /** The automation-only reason for system closures. */
    public static function systemDefault(): self
    {
        return self::byCode(self::CODE_SYSTEM_DEFAULT);
    }

    /** Resolve one active reason by its stable code. */
    public static function byCode(string $code): self
    {
        return self::query()
            ->where('reason_code', strtoupper(trim($code)))
            ->where('active', true)
            ->firstOrFail();
    }

    /**
     * The reasons a sales employee may choose (never SYSTEM_DEFAULT).
     *
     * @return Collection<int, self>
     */
    public static function userSelectableOptions(): Collection
    {
        return self::query()
            ->where('active', true)
            ->where('user_selectable', true)
            ->orderBy('reason_id')
            ->get();
    }

    public function isSystemReason(): bool
    {
        return ! $this->user_selectable;
    }

    public function items()
    {
        return $this->hasMany(SalesOrderItem::class, 'rejection_reason_id', 'reason_id');
    }
}
