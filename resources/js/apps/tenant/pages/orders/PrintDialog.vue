<script setup lang="ts">
import { ref, watch } from 'vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import { cn } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { defaultInvoiceSize, invoiceSizes, printInvoice, setDefaultInvoiceSize, type InvoiceSize } from './invoice';
import type { OrderDetail } from '@/shared/types';

/** "Chọn thông tin bạn muốn in": pick a template (default from Cài đặt → Mẫu in), optionally make it the default. */
const props = defineProps<{ orders: OrderDetail[] }>();
const open = defineModel<boolean>('open', { required: true });
const tenant = useTenantStore();

const size = ref<InvoiceSize>(defaultInvoiceSize(tenant.current?.id));
const remember = ref(false);
watch(open, (v) => v && ((size.value = defaultInvoiceSize(tenant.current?.id)), (remember.value = false)));

function print() {
    if (remember.value) setDefaultInvoiceSize(tenant.current?.id, size.value);
    printInvoice(props.orders.length === 1 ? props.orders[0]! : props.orders, tenant.current?.name ?? '', size.value);
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open" title="Chọn thông tin bạn muốn in" size="lg">
        <p class="mb-3 text-sm"><span class="font-medium">Hóa đơn bán hàng</span> · {{ orders.length }} đơn</p>
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
