<?php

namespace App\Modules\Staff\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;

/**
 * A way of paying commission. kind:
 *  - counter: rate % of the member's counter sales (sold in a cash shift);
 *  - online: rate % of the member's own-channel orders made outside the counter (chat, website, phone);
 *  - category_unit: amount per unit sold of a product category (and its sub-categories), e.g. per installation;
 *  - store_bonus: extra rate % of each member's sales when the store's month revenue passes the threshold.
 * user_ids: who it applies to (null = everyone).
 *
 * @property string $kind
 * @property string $rate
 * @property string $amount
 * @property int|null $category_id
 * @property string $threshold
 * @property array<int, int>|null $user_ids
 * @property bool $is_active
 */
class CommissionRule extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const KINDS = ['counter', 'online', 'category_unit', 'store_bonus'];

    protected $guarded = ['id', 'ulid', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'category_id'];

    protected function casts(): array
    {
        return ['user_ids' => 'array', 'is_active' => 'boolean'];
    }

    public function appliesTo(int $userId): bool
    {
        return $this->user_ids === null || in_array($userId, array_map('intval', $this->user_ids), true);
    }
}
