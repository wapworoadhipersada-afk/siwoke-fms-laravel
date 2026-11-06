<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Modules\IAM\Domain\Enums\UserType;
use Modules\IAM\Domain\Models\Device;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $employee_no
 * @property string|null $user_code
 * @property string|null $photo_path
 * @property string|null $photo_thumb_path
 * @property UserType $user_type
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'email', 'password', 'phone', 'employee_no', 'user_code', 'user_type', 'is_active'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'photo_path', 'photo_thumb_path'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, LogsActivity, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * Database defaults, mirrored so a freshly created model is usable before it is reloaded.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'user_type' => 'staff',
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            $user->ulid ??= (string) Str::ulid();
        });
    }

    /**
     * @return HasMany<Device, $this>
     */
    /**
     * People are addressed by their public ULID in links, never by their internal number.
     */
    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * @var list<string>
     */
    protected $appends = ['photo_url'];

    /**
     * Where the profile picture is served from (through a controller that checks who is asking), or null.
     */
    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path === null ? null : url('/iam/users/'.$this->ulid.'/photo?thumb=1', secure: false);
    }

    /**
     * @return HasMany<Device, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('iam')
            ->logOnly(['name', 'email', 'phone', 'employee_no', 'user_type', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'user_type' => UserType::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }
}
