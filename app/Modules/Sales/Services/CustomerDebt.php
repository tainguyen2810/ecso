<?php

namespace App\Modules\Sales\Services;

use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Sales\Models\Customer;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The one rule for what a customer owes. The customer page, the debt book, the receivables report and debt receipts in
 * the cash book all use it, so they always show the same figure.
 *
 * Only orders of the seller's own channels count (a marketplace collects its buyers' money), and not orders that were
 * cancelled, fully returned, failed delivery or are cash on delivery (COD money is owed by the carrier, not the buyer).
 *
 *   due of an order   = total − value of its counted returns − (paid − refunded)
 *   debt of a customer = sum of the dues of their orders, never below 0
 *
 * Nothing is stored: the figure is always recomputed from the source documents (orders, returns, payments), so an order
 * that is synced or edited again can never add its debt twice, and every change traces back to a document.
 */
final class CustomerDebt
{
    /** Order statuses that never carry a debt. */
    public const EXCLUDED_STATUSES = [OrderStatus::CANCELLED, OrderStatus::RETURNED, OrderStatus::FAILED_DELIVERY];

    /** Return statuses whose value comes off what the buyer owes: approved or further, not requested or rejected. */
    public const COUNTED_RETURNS = [OrderReturn::APPROVED, OrderReturn::IN_TRANSIT, OrderReturn::RECEIVED, OrderReturn::INSPECTED, OrderReturn::CLOSED];

    /**
     * SQL value of one return (alias of an `order_returns` row): the refund agreed on it, or else what was paid for the
     * returned quantities, each line's discount spread over its units. Same rule as OrderReturn::goodsValue().
     */
    public static function returnValueSql(string $return = 'r'): string
    {
        return "COALESCE({$return}.refund_amount, (SELECT SUM(TRUNCATE(rl.quantity * (ri.unit_price * ri.quantity - ri.discount) / ri.quantity, 2))"
            .' FROM order_return_lines rl JOIN order_items ri ON ri.id = rl.order_item_id'
            ." WHERE rl.order_return_id = {$return}.id AND ri.quantity > 0), 0)";
    }

    /** SQL list of the counted return statuses, for an IN (...). */
    public static function countedReturnsSql(): string
    {
        return "'".implode("','", self::COUNTED_RETURNS)."'";
    }

    /**
     * SQL value of the counted returns of an order (an `orders.id` column), 0 when there are none. Usable anywhere an
     * order id is in scope: a select, a where, a sum.
     */
    public static function orderReturnsSql(string $orderId = 'orders.id'): string
    {
        return '(SELECT COALESCE(SUM('.self::returnValueSql('cr').'), 0) FROM order_returns cr'
            ." WHERE cr.order_id = {$orderId} AND cr.status IN (".self::countedReturnsSql().'))';
    }

    /** SQL due of an order (alias of an `orders` row); may be negative when the buyer is owed money back. */
    public static function dueSql(string $order = 'orders'): string
    {
        return "({$order}.total - ".self::orderReturnsSql("{$order}.id")." - ({$order}.paid_amount - {$order}.refunded_amount))";
    }

    /**
     * Keeps only the orders that can carry a debt.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function constrain(Builder $query, string $order = 'orders'): Builder
    {
        return $query->where("{$order}.marketplace", Order::SOURCE_MANUAL)
            ->whereNotIn("{$order}.status", self::EXCLUDED_STATUSES)
            ->where("{$order}.is_cod", false);
    }

    /**
     * SQL balance of the customer's debt entries (opening balances, adjustments, their receipts), voided entries and
     * entries whose cash voucher was voided left out.
     */
    public static function entriesSql(string $customerId = 'customers.id'): string
    {
        return '(SELECT COALESCE(SUM(de.amount), 0) FROM customer_debt_entries de LEFT JOIN cash_vouchers dv ON dv.id = de.cash_voucher_id'
            ." WHERE de.customer_id = {$customerId} AND de.voided_at IS NULL AND dv.voided_at IS NULL)";
    }

    /** SQL payment terms of a customer (alias of a `customers` row): its own, else its group's, else null. */
    public static function termsSql(string $customer = 'customers'): string
    {
        return "COALESCE({$customer}.payment_terms_days, (SELECT tg.payment_terms_days FROM customer_groups tg WHERE tg.id = {$customer}.customer_group_id))";
    }

    /**
     * SQL debt of a customer (alias of a `customers` row), never below 0. Usable as a select or in a where.
     */
    public static function customerDebtSql(string $customer = 'customers'): string
    {
        return 'GREATEST((SELECT COALESCE(SUM('.self::dueSql('dco').'), 0) FROM orders dco WHERE dco.customer_id = '.$customer.'.id'
            .self::constrainSql('dco').') + '.self::entriesSql("{$customer}.id").', 0)';
    }

    /**
     * SQL overdue part of a customer's debt: what is still owed on orders older than the payment terms, and on
     * entries past their due date. Never more than the debt itself; 0 when the customer has no terms.
     */
    public static function customerOverdueSql(string $customer = 'customers'): string
    {
        $terms = self::termsSql($customer);
        $orders = 'SELECT COALESCE(SUM(GREATEST('.self::dueSql('doo').', 0)), 0) FROM orders doo WHERE doo.customer_id = '.$customer.'.id'
            .self::constrainSql('doo')." AND {$terms} IS NOT NULL AND doo.placed_at < DATE_SUB(NOW(), INTERVAL {$terms} DAY)";
        $entries = 'SELECT COALESCE(SUM(oe.amount), 0) FROM customer_debt_entries oe WHERE oe.customer_id = '.$customer.'.id'
            .' AND oe.voided_at IS NULL AND oe.amount > 0 AND oe.due_date IS NOT NULL AND oe.due_date < CURDATE()';

        return "LEAST(({$orders}) + ({$entries}), ".self::customerDebtSql($customer).')';
    }

    /** constrain() as SQL conditions, for raw subqueries (starts with " AND"). */
    private static function constrainSql(string $order): string
    {
        $excluded = "'".implode("','", self::EXCLUDED_STATUSES)."'";

        return " AND {$order}.marketplace = '".Order::SOURCE_MANUAL."' AND {$order}.status NOT IN ({$excluded}) AND {$order}.is_cod = 0";
    }

    /** What the customer owes now. */
    public function forCustomer(Customer $customer): string
    {
        $sum = Customer::query()->whereKey($customer->id)->selectRaw(self::customerDebtSql().' as debt')->value('debt');

        return bcadd((string) ($sum ?? 0), '0', 2);
    }

    /** What the customer's debt entries (opening balances, adjustments, their receipts) leave owing, may be < 0. */
    public function entriesBalance(Customer $customer): string
    {
        $sum = Customer::query()->whereKey($customer->id)->selectRaw(self::entriesSql().' as balance')->value('balance');

        return bcadd((string) ($sum ?? 0), '0', 2);
    }

    /**
     * The customer's orders still owing something, oldest first, each with its `debt_due`.
     *
     * @return Collection<int, Order>
     */
    public function openOrders(Customer $customer, bool $lock = false): Collection
    {
        return self::constrain(Order::query()->where('orders.customer_id', $customer->id))
            ->select('orders.*')->selectRaw(self::dueSql().' as debt_due')
            ->whereRaw(self::dueSql().' > 0')
            ->orderBy('orders.placed_at')->orderBy('orders.id')
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get();
    }
}
