<?php

namespace App\Modules\Sales\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One promotion received by one order, and how much it was worth.
 *
 * @property int $promotion_id
 * @property int $order_id
 * @property int|null $customer_id
 * @property string $amount
 */
class PromotionUsage extends Model
{
    use BelongsToTenant;

    protected $fillable = ['promotion_id', 'order_id', 'customer_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
