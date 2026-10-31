import { describe, expect, it } from 'vitest';
import { cycleLabels, featureLabels, invoiceStatus, marginPercent, paymentStatus, subscriptionStatus } from './labels';

describe('billing labels', () => {
    it('labels every subscription, invoice and payment status the API returns', () => {
        for (const s of ['active', 'trialing', 'past_due']) expect(subscriptionStatus[s]?.label).toBeTruthy();
        for (const s of ['pending', 'paid', 'cancelled']) expect(invoiceStatus[s]?.label).toBeTruthy();
        for (const s of ['applied', 'underpaid', 'unapplied']) expect(paymentStatus[s]?.label).toBeTruthy();
        expect(Object.keys(cycleLabels)).toEqual(['monthly', 'yearly']);
    });

    it('labels the metered and hard limits shown on the usage card', () => {
        for (const k of ['limit.users', 'limit.shops', 'limit.warehouses', 'limit.orders_per_month']) expect(featureLabels[k]).toBeTruthy();
    });

    it('computes margin only when there is revenue', () => {
        expect(marginPercent(25, 100)).toBe(25);
        expect(marginPercent(1, 3)).toBe(33.3);
        expect(marginPercent(10, 0)).toBeNull();
    });
});
