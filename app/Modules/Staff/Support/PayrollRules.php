<?php

namespace App\Modules\Staff\Support;

/**
 * Deductions on a monthly salary. Defaults to check with the tax office or an accountant whenever the law changes:
 *  - compulsory insurance the employee pays: BHXH 8% + BHYT 1.5% + BHTN 1% of the insured salary;
 *    the employer adds BHXH 17.5% + BHYT 3% + BHTN 1%;
 *  - personal income tax on salary: taxable = gross − insurance − personal deduction − dependants, progressive rates.
 */
final class PayrollRules
{
    public const EMPLOYEE_INSURANCE = 10.5;

    public const EMPLOYER_INSURANCE = 21.5;

    public const PERSONAL_DEDUCTION = 15500000;

    public const DEPENDENT_DEDUCTION = 6200000;

    /** Monthly taxable income brackets: [up to, rate %]. */
    public const PIT_BRACKETS = [[5000000, 5], [10000000, 10], [18000000, 15], [32000000, 20], [52000000, 25], [80000000, 30], [PHP_INT_MAX, 35]];

    public static function pit(float $taxable): float
    {
        $tax = 0.0;
        $lower = 0.0;
        foreach (self::PIT_BRACKETS as [$upper, $rate]) {
            if ($taxable <= $lower) {
                break;
            }
            $tax += (min($taxable, $upper) - $lower) * $rate / 100;
            $lower = (float) $upper;
        }

        return round($tax);
    }
}
