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
// Which paper: the sales invoice, or the delivery slip the shipper takes along (no prices, COD to collect, signatures).
const doc = ref<'invoice' | 'delivery'>('invoice');
watch(open, async (v) => {
    if (!v) return;
    remember.value = false;
    doc.value = 'invoice';
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
    printInvoice(props.orders.length === 1 ? props.orders[0]! : props.orders, printShopFrom(settings.data, tenant.current?.name ?? ''), size.value, doc.value);
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open" title="Chọn thông tin bạn muốn in" size="lg">
        <div class="mb-3 flex flex-wrap items-center gap-2 text-sm" data-testid="print-docs">
            <button type="button" :class="cn('rounded-full border px-3 py-1', doc === 'invoice' ? 'border-blue-300 bg-blue-50 font-medium text-blue-700' : 'hover:bg-muted')" @click="doc = 'invoice'">Hóa đơn bán hàng</button>
            <button type="button" :class="cn('rounded-full border px-3 py-1', doc === 'delivery' ? 'border-blue-300 bg-blue-50 font-medium text-blue-700' : 'hover:bg-muted')" data-testid="print-delivery" @click="doc = 'delivery'">Phiếu giao hàng</button>
            <span class="text-muted-foreground">· {{ orders.length }} đơn<span v-if="doc === 'invoice' && copies > 1"> · {{ copies }} liên mỗi đơn</span></span>
        </div>
        <p v-if="doc === 'delivery'" class="mb-2 text-xs text-muted-foreground">Phiếu cho người giao: không in giá, in số tiền cần thu hộ (COD) và chỗ ký nhận. Mẫu K80/K57 in theo khổ A5.</p>
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
