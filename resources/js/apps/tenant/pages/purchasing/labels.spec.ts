import { describe, expect, it } from 'vitest';
import { owed, poStatus } from './labels';

describe('purchasing labels', () => {
    it('computes what is owed on an order', () => {
        expect(owed({ received_value: '930000.00', returned_value: '100000.00', paid_amount: '200000.00' })).toBe(630000);
    });
    it('labels every status', () => {
        expect(Object.keys(poStatus)).toEqual(['draft', 'ordered', 'partial', 'received', 'closed', 'cancelled']);
    });
});
