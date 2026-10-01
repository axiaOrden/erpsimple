<?php

namespace App\Models;

use App\Enums\Weekday;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixed Journey Plan: company + employee + customer + preferred visit schedule.
 *
 * `preferred_day` is a numeric weekday index (0 = Sunday … 6 = Saturday) and
 * `preferred_week` is the rotation-week position (null = every week). The
 * combined presentation form (`W1-Mon`) is never stored.
 */
class CustomerFjp extends Model
{
    protected $table = 'customer_fjp';

    protected $primaryKey = 'fjp_id';

    const UPDATED_AT = null;

    protected $fillable = [
        'company_id',
        'employee_id',
        'customer_id',
        'preferred_week',
        'preferred_day',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'preferred_week' => 'integer',
            'preferred_day' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Defensive normalization: a legacy caller may still hand over the old
     * enum name ('MONDAY'); the column is numeric now, so it is converted
     * once, here — never stored as text.
     */
    public function setPreferredDayAttribute(mixed $value): void
    {
        $day = Weekday::parse(is_string($value) || is_int($value) ? $value : null);

        $this->attributes['preferred_day'] = $day?->value ?? $value;
    }

    /** Weekday as an enum, or null when the row holds an unexpected value. */
    public function weekday(): ?Weekday
    {
        return Weekday::parse($this->preferred_day);
    }

    /** Compact presentation chip label: `W1-Mon` / `Every Mon`. */
    public function visitChip(): string
    {
        $day = $this->weekday()?->short() ?? '?';

        return $this->preferred_week === null ? 'Every '.$day : 'W'.$this->preferred_week.'-'.$day;
    }

    /** Screen-reader friendly description: `Week 1 Monday` / `Every Monday`. */
    public function visitLabel(): string
    {
        $day = $this->weekday()?->label() ?? 'Unknown day';

        return $this->preferred_week === null ? 'Every '.$day : 'Week '.$this->preferred_week.' '.$day;
    }

    public function company()
    {
        return $this->belongsTo(CompanyMaster::class, 'company_id', 'company_id');
    }

    public function employee()
    {
        return $this->belongsTo(EmployeeMaster::class, 'employee_id', 'employee_id');
    }

    public function customer()
    {
        return $this->belongsTo(CustomerMaster::class, 'customer_id', 'customer_id');
    }

    public function scopeForCompany($query, string $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
