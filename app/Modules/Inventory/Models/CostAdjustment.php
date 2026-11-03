<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Cost price adjustment (điều chỉnh giá vốn), applied on creation. */
class CostAdjustment extends Model
{
    use BelongsToTenant, HasPublicUlid;

    protected $fillable = ['note'];

    /** @return HasMany<CostAdjustmentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(CostAdjustmentLine::class)->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
