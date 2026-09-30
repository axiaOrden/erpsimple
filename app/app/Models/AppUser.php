<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;

/**
 * Application user — authenticates against the DDL's `app_user` table.
 * The password column is `password_hash` (exposed via getAuthPassword()).
 */
class AppUser extends Authenticatable
{
    use HasFactory;
    use Notifiable;

    protected $table = 'app_user';

    protected $primaryKey = 'user_id';

    protected $fillable = [
        'employee_id',
        'company_id',
        'name',
        'email',
        'password_hash',
        'role',
        'active',
    ];

    protected $hidden = ['password_hash', 'remember_token'];

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'active' => 'boolean',
        ];
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    // app_user has no remember_token column: keep remember-me sessions
    // purely cookie-based by making the token persistence a no-op.
    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        // intentionally unsupported
    }

    /** Hash plain passwords on assignment so the password broker works unchanged. */
    public function setPasswordHashAttribute($value): void
    {
        if ($value === null) {
            return;
        }

        // Plain passwords get hashed; already-hashed values are stored as-is.
        if (Hash::needsRehash((string) $value)) {
            $value = Hash::make((string) $value);
        }

        $this->attributes['password_hash'] = $value;
    }

    // -- Role helpers -------------------------------------------------------

    public function isSuperadmin(): bool
    {
        return $this->role === UserRole::SUPERADMIN;
    }

    public function isCompanyAdmin(): bool
    {
        return $this->role === UserRole::COMPANY_ADMIN;
    }

    public function isSalesEmployee(): bool
    {
        return $this->role === UserRole::SALES_EMPLOYEE;
    }

    /**
     * The company whose data this user is currently working in.
     * SUPERADMINs may switch; everyone else is fixed to their own company.
     */
    public function currentCompanyId(): ?string
    {
        if ($this->isSuperadmin()) {
            return session('company_context', $this->company_id);
        }

        return $this->company_id;
    }

    // -- Relationships ------------------------------------------------------

    public function employee()
    {
        return $this->belongsTo(EmployeeMaster::class, 'employee_id', 'employee_id');
    }

    public function company()
    {
        return $this->belongsTo(CompanyMaster::class, 'company_id', 'company_id');
    }
}
