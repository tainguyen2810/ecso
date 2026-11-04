<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import { cn } from '@/shared/lib/utils';
import { useSettingsStore } from '@/shared/stores/settings';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import { defaultInvoiceSize, invoiceSizes, printInvoice, setDefaultInvoiceSize, type InvoiceSize } from './invoice';
import { printShopFrom } from './printShop';
import type { OrderDetail } from '@/shared/types';

/** "Chọn thông tin bạn muốn in": pick a template (default from Cấu hình → Mẫu in), optionally make it the default. */
const props = defineProps<{ orders: OrderDetail[] }>();
const open = defineModel<boolean>('open', { required: true });
const tenant = useTenantStore();
const settings = useSettingsStore();
const toast = useToast();

const configured = computed(() => settings.data?.print.defaults.sale);
const size = ref<InvoiceSize>(defaultInvoiceSize(tenant.current?.id, configured.value));
const remember = ref(false);
watch(open, async (v) => {
    if (!v) return;
    remember.value = false;
    await settings.load().catch(() => null);
    size.value = defaultInvoiceSize(tenant.current?.id, configured.value);
});
const copies = computed(() => settings.data?.sales.invoice_copies ?? 1);

async function print() {
    if (remember.value) {
        // The business-wide default lives in Cấu hình; without that right, only this browser remembers.
        if (tenant.can('settings.manage')) await settings.save('print', { defaults: { sale: size.value } }).catch(() => toast.error('Không lưu được mẫu mặc định.'));
        else setDefaultInvoiceSize(tenant.current?.id, size.value);
    }
    printInvoice(props.orders.length === 1 ? props.orders[0]! : props.orders, printShopFrom(settings.data, tenant.current?.name ?? ''), size.value);
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open" title="Chọn thông tin bạn muốn in" size="lg">
        <p class="mb-3 text-sm"><span class="font-medium">Hóa đơn bán hàng</span> · {{ orders.length }} đơn<span v-if="copies > 1"> · {{ copies }} liên mỗi đơn</span></p>
        <p class="mb-2 text-sm text-muted-foreground">Chọn mẫu in phiếu</p>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4" data-testid="print-sizes">
            <button
                v-for="s in invoiceSizes"
                :key="s.value"
                type="button"
                :class="cn('rounded-md border px-3 py-2.5 text-center text-sm font-medium hover:bg-muted/40', size === s.value && 'border-primary bg-primary text-primary-foreground hover:bg-primary')"
                :title="s.hint"
                @click="size = s.value"
            >
                {{ s.label }}
            </button>
        </div>
        <label class="mt-4 flex items-center gap-2 text-sm"><input v-model="remember" type="checkbox" class="size-4" /> Đặt làm mặc định cho chức năng in</label>
        <template #footer>
            <Button variant="outline" @click="open = false">Thoát</Button>
            <Button data-testid="print-confirm" @click="print">In</Button>
        </template>
    </Dialog>
</template>
