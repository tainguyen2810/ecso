<?php

namespace App\Modules\Sales\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Services\CustomerDebt;
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
 * @property-read int|string|null $overdue
 * @property-read string|null $last_order_at
 * @property list<string>|null $tags
 * @property Carbon|null $birthday
 */
class Customer extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const STATUSES = ['active', 'inactive'];

    public const GENDERS = ['male', 'female', 'other'];

    public const TYPES = ['individual', 'company'];

    /** Segments of the customer list; the figures they use are below. */
    public const SEGMENTS = [
        'lapsed' => 'Lâu không mua', 'frequent' => 'Mua thường xuyên', 'high_value' => 'Giá trị cao', 'new' => 'Khách mới', 'follow_up' => 'Cần liên hệ',
    ];

    public const LAPSED_DAYS = 90;

    public const FREQUENT_DAYS = 180;

    public const FREQUENT_ORDERS = 3;

    public const NEW_DAYS = 30;

    /** Where the customer came from: the type of the sales channel of their first order, a referral, or a file import. */
    public const SOURCES = [...SalesChannel::TYPES, 'referral', 'import'];

    protected $attributes = ['status' => 'active'];

    protected $fillable = ['name', 'type', 'source', 'email', 'address', 'note', 'birthday', 'gender', 'tax_code', 'invoice_name', 'invoice_address', 'invoice_email', 'tags', 'status', 'payment_terms_days', 'credit_limit'];

    protected $hidden = ['id', 'tenant_id', 'phone_hash', 'email_key', 'created_by', 'customer_group_id', 'assigned_user_id'];

    protected static function booted(): void
    {
        // The email as a lookup key, so a buyer from another channel is found by it whatever its case.
        static::saving(fn (Customer $c) => $c->email_key = self::emailKey($c->email));
    }

    public static function emailKey(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email !== '' ? mb_substr($email, 0, 150) : null;
    }

    protected function casts(): array
    {
        return ['phone' => 'encrypted', 'address' => 'encrypted', 'invoice_address' => 'encrypted', 'birthday' => 'date', 'tags' => 'array', 'credit_limit' => 'decimal:2'];
    }

    public function setPhone(?string $phone): void
    {
        $normalized = PhoneNumber::normalize($phone);
        $this->phone = $normalized;
        $this->phone_hash = PhoneNumber::hash($normalized);
        $this->phone_last4 = $normalized ? substr($normalized, -4) : null;
    }

    /**
     * Adds orders_count, total_spent, last_order_at (cancelled and returned orders do not count, as in the sales
     * reports), debt and its overdue part, which follow CustomerDebt.
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
            ->selectRaw(CustomerDebt::customerDebtSql().' as debt')
            ->selectRaw(CustomerDebt::customerOverdueSql().' as overdue')
            ->selectSub($base('MAX(placed_at)'), 'last_order_at');
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

    /** @return HasMany<CustomerDebtEntry, $this> */
    public function debtEntries(): HasMany
    {
        return $this->hasMany(CustomerDebtEntry::class);
    }

    /** @return HasMany<CustomerIdentity, $this> */
    public function identities(): HasMany
    {
        return $this->hasMany(CustomerIdentity::class);
    }

    /** @return HasMany<OrderPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }
}
