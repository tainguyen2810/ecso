<?php

namespace App\Modules\Analytics\Support;

use App\Modules\Orders\Models\OrderReturn;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One definition of what a return is worth, for every report that subtracts returns from revenue or adds returned
 * goods back to cost.
 *
 *  - A return counts once it is approved: requested (not yet accepted) and rejected returns are left out.
 *  - Its value is the refund agreed on it, or else what the buyer paid for the returned quantities (each line's
 *    discount spread over its units), the same rule as OrderReturn::goodsValue().
 *  - The cost put back is the cost captured when the goods shipped (order_items.cost_snapshot) times the quantity
 *    inspected as sellable, so returning goods never revalues them at today's cost. Damaged goods stay in cost.
 */
final class ReturnValues
{
    public const COUNTED = [OrderReturn::APPROVED, OrderReturn::IN_TRANSIT, OrderReturn::RECEIVED, OrderReturn::INSPECTED, OrderReturn::CLOSED];

    /** Per return: goods value of its lines and shipped cost of the quantity put back on the shelf. */
    public static function lines(): Builder
    {
        return DB::table('order_return_lines as rl')->join('order_items as ri', 'ri.id', '=', 'rl.order_item_id')
            ->groupBy('rl.order_return_id')
            ->selectRaw('rl.order_return_id, '
                .'SUM(CASE WHEN ri.quantity > 0 THEN rl.quantity * (ri.unit_price * ri.quantity - ri.discount) / ri.quantity ELSE 0 END) as goods_value, '
                .'SUM(COALESCE(rl.sellable_qty, 0) * COALESCE(ri.cost_snapshot, 0)) as cost_back');
    }

    /** SQL for a return's value, given the return alias and the alias of lines() joined to it. */
    public static function valueSql(string $return = 'r', string $lines = 'rv'): string
    {
        return "COALESCE({$return}.refund_amount, {$lines}.goods_value, 0)";
    }
}
