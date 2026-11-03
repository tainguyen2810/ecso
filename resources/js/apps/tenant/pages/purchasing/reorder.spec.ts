import { describe, expect, it } from 'vitest';
import { draftFromRows, type ReorderRow } from './reorder';

const row = (id: string, supplier: string | null, suggested = 5): ReorderRow => ({
    variant: { id, sku: id, name: id },
    sold: 30,
    daily: 1,
    available: 2,
    safety_stock: 0,
    on_order: 0,
    suggested,
    unit_cost: '1000.00',
    supplier: supplier ? { id: supplier, name: supplier } : null,
});

describe('draftFromRows', () => {
    it('pre-selects the supplier only when every row shares it', () => {
        expect(draftFromRows([row('a', 's1'), row('b', 's1')], 'w', {}).supplier_id).toBe('s1');
        expect(draftFromRows([row('a', 's1'), row('b', 's2')], 'w', {}).supplier_id).toBe('');
        expect(draftFromRows([row('a', null)], 'w', {}).supplier_id).toBe('');
    });
    it('uses the edited quantity over the suggestion', () => {
        expect(draftFromRows([row('a', 's1', 5)], 'w', { a: 12 }).lines[0]!.quantity).toBe(12);
    });
});
