<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Billing\Models\Subscription;
use App\Modules\Identity\Models\User;
use App\Shared\Concerns\HasPublicUlid;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

/**
 * @property-read int|null $members_count  withCount('memberships')
 * @property array<string, mixed>|null $settings
 * @property string|null $logo_path
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasPublicUlid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = ['name', 'timezone', 'currency'];

    protected $hidden = ['id', 'owner_user_id'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    /** Relative URL so it works on every domain the app is served from. */
    public function logoUrl(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @tenant-bypass the tenant row itself is the scope root.
     *
     * @return HasMany<TenantUser, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantUser::class)->withoutGlobalScopes();
    }

    /** @return HasOne<Subscription, $this> */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->withoutGlobalScopes();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
