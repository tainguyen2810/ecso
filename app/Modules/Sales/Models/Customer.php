<?php

namespace App\Modules\Sales\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Support\PhoneNumber;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A buyer of the seller's own channels. Phone and address are encrypted at rest; the phone is found through a
 * keyed hash (exact match) and its last four digits (partial search).
 *
 * @property-read int|string|null $orders_count
 * @property-read int|string|null $total_spent
 * @property-read int|string|null $debt
 * @property-read string|null $last_order_at
 * @property list<string>|null $tags
 * @property Carbon|null $birthday
 */
class Customer extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const STATUSES = ['active', 'inactive'];

    public const GENDERS = ['male', 'female', 'other'];

    protected $attributes = ['status' => 'active'];

    protected $fillable = ['name', 'email', 'address', 'note', 'birthday', 'gender', 'tax_code', 'tags', 'status'];

    protected $hidden = ['id', 'tenant_id', 'phone_hash', 'created_by', 'customer_group_id', 'assigned_user_id'];

    protected function casts(): array
    {
        return ['phone' => 'encrypted', 'address' => 'encrypted', 'birthday' => 'date', 'tags' => 'array'];
    }

    public function setPhone(?string $phone): void
    {
        $normalized = PhoneNumber::normalize($phone);
        $this->phone = $normalized;
        $this->phone_hash = PhoneNumber::hash($normalized);
        $this->phone_last4 = $normalized ? substr($normalized, -4) : null;
    }

    /**
     * Adds orders_count, total_spent, debt and last_order_at. Cancelled and returned orders do not count; debt is
     * what is still unpaid on the other orders.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeWithStats(Builder $query): Builder
    {
        $excluded = "'".OrderStatus::CANCELLED."','".OrderStatus::RETURNED."'";
        $base = fn (string $select) => Order::query()->selectRaw($select)
            ->whereColumn('orders.customer_id', 'customers.id')->whereRaw("orders.status NOT IN ({$excluded})");

        return $query->select('customers.*')
            ->selectSub($base('COUNT(*)'), 'orders_count')
            ->selectSub($base('COALESCE(SUM(total), 0)'), 'total_spent')
            // Debt = what the customer owes the shop, recorded through ECSO's own payments: only orders sold in
            // ECSO. Orders from a channel (own website, marketplaces) are paid through that channel, not owed.
            ->selectSub($base('COALESCE(SUM(GREATEST(total - paid_amount, 0)), 0)')->where('orders.marketplace', Order::SOURCE_MANUAL), 'debt')
            ->selectSub(Order::query()->selectRaw('MAX(placed_at)')->whereColumn('orders.customer_id', 'customers.id'), 'last_order_at');
    }

    /** @return BelongsTo<CustomerGroup, $this> */
    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /** @return HasMany<CustomerAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    /** @return HasMany<CustomerNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<OrderPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }
}
