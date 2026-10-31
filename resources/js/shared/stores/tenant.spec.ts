import { beforeEach, describe, expect, it } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { useTenantStore } from './tenant';

describe('tenant store', () => {
    beforeEach(() => setActivePinia(createPinia()));

    it('answers permission checks from the server-provided context only', () => {
        const store = useTenantStore();
        expect(store.can('members.manage')).toBe(false);

        store.context = {
            tenant: { id: '01', name: 'A', slug: 'a', timezone: 'Asia/Ho_Chi_Minh', currency: 'VND', status: 'active' },
            role: { key: 'VIEWER', name: 'Chỉ xem' },
            permissions: ['orders.view'],
            entitlements: {},
        };

        expect(store.can('orders.view')).toBe(true);
        expect(store.can('members.manage')).toBe(false);

        store.reset();
        expect(store.can('orders.view')).toBe(false);
    });
});
