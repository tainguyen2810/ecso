import { describe, expect, it } from 'vitest';
import { setupActionLabel } from './shopSetup';

describe('shop setup labels', () => {
    it('labels each SKU by what step 2 will do', () => {
        expect(setupActionLabel('link', false)).toEqual({ label: 'Ghép', tone: 'success' });
        expect(setupActionLabel('create', false)).toEqual({ label: 'Tạo mới', tone: 'info' });
        expect(setupActionLabel('later', true)).toEqual({ label: 'Để sau', tone: 'muted' });
    });

    it('creates listings without a seller SKU only when the box is ticked', () => {
        expect(setupActionLabel('create_generated', false).label).toBe('Để sau');
        expect(setupActionLabel('create_generated', true).label).toBe('Tạo mới (mã theo ID)');
    });
});
