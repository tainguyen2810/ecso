<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read int|string|null $orders_count
 * @property-read int|string|null $purchased
 * @property-read int|string|null $paid
 * @property-read int|string|null $debt
 * @property-read int|string|float|null $overdue SupplierAllocation, set by the caller
 * @property int|null $payment_terms_days
 */
class Supplier extends Model
{
    use BelongsToTenant, HasPublicUlid;

    protected $fillable = ['name', 'contact_name', 'phone', 'email', 'address', 'tax_code', 'payment_terms_days', 'note', 'is_active'];

    protected $hidden = ['id', 'tenant_id'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * Adds orders_count, purchased (goods received less goods returned), paid (payments not voided) and debt.
     *
     * @param  Builder<Supplier>  $query
     * @return Builder<Supplier>
     */
    public function scopeWithBalance(Builder $query): Builder
    {
        $purchased = PurchaseOrder::query()->selectRaw('COALESCE(SUM(received_value - returned_value), 0)')->whereColumn('purchase_orders.supplier_id', 'suppliers.id');
        $paid = SupplierPayment::query()->selectRaw('COALESCE(SUM('.SupplierPayment::SIGNED_SQL.'), 0)')->whereColumn('supplier_payments.supplier_id', 'suppliers.id')->whereNull('voided_at');

        return $query->select('suppliers.*')
            ->selectSub(PurchaseOrder::query()->selectRaw('COUNT(*)')->whereColumn('purchase_orders.supplier_id', 'suppliers.id')->where('status', '!=', PurchaseOrder::CANCELLED), 'orders_count')
            ->selectSub($purchased, 'purchased')
            ->selectSub($paid, 'paid')
            ->selectRaw('('.$purchased->toRawSql().') - ('.$paid->toRawSql().') as debt');
    }

    /** @return HasMany<PurchaseOrder, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /** @return HasMany<SupplierPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    /** @return HasMany<SupplierReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(SupplierReturn::class);
    }
}
