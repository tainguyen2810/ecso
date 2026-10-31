import { describe, expect, it } from 'vitest';
import { documentKinds, documentStatus, movementLabels } from './labels';

describe('inventory labels', () => {
    it('has a Vietnamese label for every movement type the backend writes', () => {
        const backendTypes = [
            'OPENING', 'RECEIPT', 'RESERVE', 'RELEASE', 'SHIP', 'SHIP_UNRESERVED', 'RETURN_RESTOCK', 'DAMAGE_OUT',
            'ADJUSTMENT_IN', 'ADJUSTMENT_OUT', 'TRANSFER_OUT', 'TRANSFER_IN', 'SUPPLIER_RETURN', 'RESERVATION_REALLOCATE',
        ];
        expect(Object.keys(movementLabels).sort()).toEqual(backendTypes.sort());
    });

    it('maps every document kind to the permission the API checks', () => {
        expect(documentKinds.receipts.permission).toBe('inventory.receive');
        expect(documentKinds.adjustments.permission).toBe('inventory.adjust');
        expect(documentKinds.transfers.permission).toBe('inventory.transfer');
        for (const s of ['pending', 'approved', 'rejected', 'cancelled', 'draft', 'in_transit', 'received']) {
            expect(documentStatus[s]).toBeDefined();
        }
    });
});
