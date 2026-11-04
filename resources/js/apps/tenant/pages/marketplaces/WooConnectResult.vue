<script setup lang="ts">
import { ref } from 'vue';
import { Check } from 'lucide-vue-next';
import FormField from '@/shared/ui/FormField.vue';
import Select from '@/shared/ui/Select.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import type { MarketplaceAccountData, Warehouse, WooConnectChecks } from '@/shared/types';

/** What ECSO checked and set up after a WooCommerce store was connected, plus the one choice left: the warehouse. */
const props = defineProps<{ account: MarketplaceAccountData; checks: WooConnectChecks; warehouses: Warehouse[] }>();
const toast = useToast();
const warehouseId = ref(props.account.warehouse?.id ?? '');

async function changeWarehouse(id: string | undefined) {
    if (!id) return;
    try {
        await api.patch(`/marketplace-accounts/${props.account.id}`, { warehouse_id: id });
        warehouseId.value = id;
        toast.success('Đã chọn kho trừ hàng cho đơn Woo.');
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}
</script>

<template>
    <div class="space-y-4" data-testid="woo-connect-result">
        <ul class="space-y-1.5 rounded-md border border-emerald-100 bg-emerald-50/70 px-4 py-3 text-sm text-emerald-800">
            <li class="flex gap-2"><Check class="mt-0.5 size-4 shrink-0" /> Website chạy HTTPS<template v-if="checks.woocommerce_version">, WooCommerce {{ checks.woocommerce_version }}</template></li>
            <li class="flex gap-2"><Check class="mt-0.5 size-4 shrink-0" /> Đã được cấp quyền Đọc/Ghi</li>
            <li class="flex gap-2"><Check class="mt-0.5 size-4 shrink-0" /> Đã đăng ký nhận đơn mới, đơn cập nhật, sản phẩm mới, sản phẩm sửa</li>
            <li v-if="checks.products !== null" class="flex gap-2">
                <Check class="mt-0.5 size-4 shrink-0" /> Tìm thấy {{ formatNumber(checks.products) }} sản phẩm<template v-if="checks.orders !== null">, {{ formatNumber(checks.orders) }} đơn</template>. ECSO đang lấy sản phẩm về và ghép theo SKU.
            </li>
        </ul>
        <FormField label="Đơn Woo trừ hàng ở kho" hint="Đổi sau được trong trang gian hàng.">
            <Select class="sm:w-72" :model-value="warehouseId" :options="warehouses.map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` }))" data-testid="woo-warehouse" @update:model-value="changeWarehouse" />
        </FormField>
    </div>
</template>
