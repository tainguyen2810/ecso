<script setup lang="ts">
import { computed } from 'vue';
import { AlertTriangle, Boxes, PackageX, Wallet } from 'lucide-vue-next';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import type { StockSummary } from '@/shared/types';

/**
 * Stock at a glance above the product and stock lists: variants, out of stock, running low, stock value.
 * A card picks that stock state in the list below. When most variants have no stock, a hint explains that the
 * stock was probably never entered (orders then all say "Thiếu hàng") and links to an adjustment.
 */
const props = defineProps<{ summary: StockSummary | null; active?: string }>();
const emit = defineEmits<{ select: [state: string] }>();
const tenant = useTenantStore();

const cards = computed(() => {
    const s = props.summary;
    return [
        { state: 'all', label: 'Phiên bản', value: s ? formatNumber(s.skus) : '…', icon: Boxes, tone: 'text-slate-500 bg-slate-100' },
        { state: 'out', label: 'Hết hàng', value: s ? formatNumber(s.out_of_stock) : '…', icon: PackageX, tone: 'text-rose-600 bg-rose-50', alert: (s?.out_of_stock ?? 0) > 0 },
        { state: 'low', label: 'Sắp hết hàng', value: s ? formatNumber(s.low_stock) : '…', icon: AlertTriangle, tone: 'text-amber-600 bg-amber-50' },
        ...(tenant.can('products.cost.view') ? [{ state: '', label: 'Giá trị tồn kho', value: s ? formatMoney(s.inventory_value) : '…', icon: Wallet, tone: 'text-emerald-600 bg-emerald-50' }] : []),
    ];
});
const neverEntered = computed(() => {
    const s = props.summary;
    return !!s && s.skus >= 10 && s.out_of_stock / s.skus >= 0.3;
});
</script>

<template>
    <div class="mb-4 space-y-3">
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4" data-testid="stock-overview">
            <component
                :is="c.state ? 'button' : 'div'"
                v-for="c in cards"
                :key="c.label"
                :type="c.state ? 'button' : undefined"
                :class="cn('flex items-center gap-3 rounded-xl border bg-card px-4 py-3 text-left', c.state && 'hover:border-primary/40', c.state && active === c.state && 'border-primary ring-2 ring-primary/15')"
                :data-testid="c.state ? `stock-card-${c.state}` : undefined"
                @click="c.state && emit('select', c.state)"
            >
                <span :class="cn('grid size-10 shrink-0 place-items-center rounded-lg', c.tone)"><component :is="c.icon" class="size-5" :stroke-width="1.75" /></span>
                <span class="min-w-0">
                    <span class="block text-xs text-muted-foreground">{{ c.label }}</span>
                    <span :class="cn('block truncate text-lg font-semibold tabular-nums', c.alert && 'text-rose-600')">{{ c.value }}</span>
                </span>
            </component>
        </div>
        <div v-if="neverEntered" class="flex flex-wrap items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm" data-testid="stock-setup-hint">
            <PackageX class="size-5 shrink-0 text-amber-600" />
            <span class="min-w-60 flex-1">
                <b>{{ formatNumber(summary!.out_of_stock) }} / {{ formatNumber(summary!.skus) }} phiên bản đang có tồn 0.</b>
                Nếu kho thật vẫn còn hàng, hãy nhập số hàng đang có một lần, đơn mới sẽ không còn báo "Thiếu hàng".
            </span>
            <RouterLink v-if="tenant.can('inventory.adjust')" to="/inventory/adjustments/new" class="inline-flex h-8 items-center rounded-lg border bg-white px-3 text-sm font-medium hover:bg-slate-50">Nhập số tồn đang có</RouterLink>
        </div>
    </div>
</template>
