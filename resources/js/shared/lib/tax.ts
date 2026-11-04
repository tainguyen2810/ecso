/** How an order is taxed: the VAT rate in % and whether the selling prices already include it. */
export interface TaxRule {
    rate: number;
    included: boolean;
}

/**
 * Sale tax on an amount, rounded to the đồng, exactly as the server computes it (ManualOrderService::saleTax):
 * prices that include tax hold it inside (amount × r / (100 + r)), prices before tax get it added on top.
 */
export function saleTax(amount: number, rule: TaxRule | null | undefined): number {
    const rate = Number(rule?.rate) || 0;
    if (rate <= 0 || amount <= 0) return 0;
    return Math.round(rule!.included ? (amount * rate) / (100 + rate) : (amount * rate) / 100);
}

/** What the tax adds to the amount the buyer pays: nothing when it is already inside the prices. */
export function addedTax(amount: number, rule: TaxRule | null | undefined): number {
    return rule && !rule.included ? saleTax(amount, rule) : 0;
}
