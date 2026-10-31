import { describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import SkuPicker from './SkuPicker.vue';

vi.mock('@/shared/lib/api', () => ({
    api: {
        get: vi.fn(async () => ({
            data: {
                data: [
                    { id: 'v1', sku: 'ATC-TRANG-M', barcode: '8930000000011', name: 'Trắng / M', product: { id: 'p1', name: 'Áo thun' } },
                    { id: 'v2', sku: 'ATC-TRANG-L', barcode: null, name: 'Trắng / L', product: { id: 'p1', name: 'Áo thun' } },
                ],
            },
        })),
    },
}));

describe('SkuPicker', () => {
    it('picks the exact barcode match on Enter (scanner input), not the first row', async () => {
        vi.useFakeTimers();
        const wrapper = mount(SkuPicker);
        const input = wrapper.get('input');

        await input.trigger('focus');
        await input.setValue('8930000000011');
        vi.advanceTimersByTime(250);
        await flushPromises();
        await input.trigger('keydown', { key: 'Enter' });

        expect(wrapper.emitted('pick')?.[0]?.[0]).toMatchObject({ id: 'v1' });
        vi.useRealTimers();
    });

    it('hides variants that are already on the document', async () => {
        const wrapper = mount(SkuPicker, { props: { exclude: ['v1'] } });
        await wrapper.get('input').trigger('focus');
        await flushPromises();

        expect(wrapper.text()).toContain('ATC-TRANG-L');
        expect(wrapper.text()).not.toContain('ATC-TRANG-M');
    });
});
