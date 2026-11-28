<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { CircleCheck, ClipboardCheck, Lightbulb, PackageX, RefreshCw, ShieldAlert, TriangleAlert } from 'lucide-vue-next';
import ProductsHead from '../products/ui/ProductsHead.vue';
import HeadButton from '../products/ui/HeadButton.vue';
import StatusChip from '../products/ui/StatusChip.vue';
import SideCard from '../reports/SideCard.vue';
import { card, rowButton, th, thead } from '../products/ui/ui';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDateTime, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';

/**
 * "Đối soát tồn kho" (mẫu Tailwind 28/11): the ledger check (tồn = tổng sổ kho, số đang giữ = các đơn đang giữ) and
 * orders handed over while stock was short, each with a stocktake shortcut. Read-only; corrections go through a
 * stocktake or an adjustment.
 */
interface Named { id: string; sku?: string; code?: string; name: string }
interface LedgerRow { warehouse: Named | null; variant: Named | null; problem: string; label: string; expected: number; actual: number }
interface ShortRow { order: { id: string; number: string; marketplace: string }; warehouse: Named | null; variant: Named | null; quantity: number; shortage: number; shipped_at: string }

const tenant = useTenantStore();
const data = ref<{ checked_at: string; scope?: { skus: number; warehouses: number }; ledger: LedgerRow[]; shortages: ShortRow[] } | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const canCount = computed(() => tenant.can('inventory.adjust'));
const shortSkus = computed(() => new Set(data.value?.shortages.map((r) => r.variant?.id)).size);
const shortQty = computed(() => data.value?.shortages.reduce((s, r) => s + r.shortage, 0) ?? 0);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        data.value = (await api.get('/inventory/reconciliation')).data.data;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
const causes = [
    ['Bàn giao trước khi nhập', 'hàng đã có ở kho nhưng phiếu nhập chưa tạo.'],
    ['Đếm sai lần kiểm trước', 'kiểm hàng lại SKU để tồn đúng.'],
    ['Bán ngoài hệ thống', 'bán tay không tạo đơn, tồn trên phần mềm cao hơn thực tế.'],
];
</script>

<template>
    <ProductsHead title="Đối soát tồn kho" description="Kiểm tra tồn kho khớp với sổ kho, và các đơn đã bàn giao khi kho không đủ hàng.">
        <HeadButton v-if="canCount && data?.shortages.length" :icon="ClipboardCheck" to="/inventory/stocktake/new">Kiểm hàng các SKU này</HeadButton>
        <HeadButton :icon="RefreshCw" primary :disabled="loading" @click="load">Kiểm tra lại</HeadButton>
    </ProductsHead>

    <ErrorState v-if="loadError" :message="loadError" @retry="load" />
    <div v-else-if="loading && !data" class="space-y-3"><Skeleton v-for="i in 3" :key="i" class="h-20" /></div>
    <template v-else-if="data">
        <div class="mb-5 grid gap-5 lg:grid-cols-2">
            <section :class="cn(card, 'flex gap-4 p-5')" :data-testid="data.ledger.length ? 'ledger-card' : 'ledger-ok'">
                <span :class="cn('grid size-11 shrink-0 place-items-center rounded-[12px]', data.ledger.length ? 'bg-red-50 text-red-500' : 'bg-emerald-50 text-emerald-600')">
                    <component :is="data.ledger.length ? ShieldAlert : CircleCheck" class="size-5" />
                </span>
                <div class="min-w-0">
                    <h3 class="flex items-center gap-2 text-[17px] font-semibold text-slate-900">
                        Sổ kho <StatusChip :tone="data.ledger.length ? 'red' : 'green'">{{ data.ledger.length ? `${data.ledger.length} chỗ không khớp` : 'Khớp' }}</StatusChip>
                    </h3>
                    <p class="mt-1 text-[14px] text-slate-600">Tồn từng kho bằng tổng các dòng nhập, xuất trong sổ kho; số đang giữ bằng tổng các đơn đang giữ hàng.</p>
                    <p class="mt-2 flex flex-wrap gap-x-6 text-[13px] text-slate-500">
                        <span v-if="data.scope"><b class="text-slate-800">{{ formatNumber(data.scope.skus) }}</b> SKU × <b class="text-slate-800">{{ formatNumber(data.scope.warehouses) }}</b> kho đã kiểm</span>
                        <span>Kiểm lúc {{ formatDateTime(data.checked_at) }} (tự động mỗi đêm)</span>
                    </p>
                </div>
            </section>
            <section :class="cn(card, 'flex gap-4 p-5')">
                <span :class="cn('grid size-11 shrink-0 place-items-center rounded-[12px]', data.shortages.length ? 'bg-amber-50 text-amber-600' : 'bg-emerald-50 text-emerald-600')">
                    <component :is="data.shortages.length ? TriangleAlert : CircleCheck" class="size-5" />
                </span>
                <div class="min-w-0">
                    <h3 class="flex items-center gap-2 text-[17px] font-semibold text-slate-900">
                        Đơn xuất thiếu hàng <StatusChip :tone="data.shortages.length ? 'amber' : 'green'">{{ data.shortages.length ? `${data.shortages.length} đơn · ${shortSkus} SKU` : 'Không có' }}</StatusChip>
                    </h3>
                    <p class="mt-1 text-[14px] text-slate-600">Đơn đã bàn giao khi kho không đủ: phần thiếu chưa trừ khỏi tồn. EzChat Bán hàng tự trừ bù khi có hàng nhập về.</p>
                    <p class="mt-2 flex flex-wrap gap-x-6 text-[13px] text-slate-500">
                        <span>Thiếu tổng <b class="text-amber-600">{{ formatNumber(shortQty) }} sp</b></span>
                        <span>Trong 90 ngày qua</span>
                    </p>
                </div>
            </section>
        </div>

        <div class="grid gap-5 2xl:grid-cols-[minmax(0,1fr)_380px]">
            <div class="min-w-0 space-y-5">
                <section v-if="data.ledger.length" :class="cn(card, 'overflow-hidden')" data-testid="ledger-problems">
                    <header class="border-b border-slate-100 px-5 py-4">
                        <h3 class="text-[16px] font-semibold text-slate-900">Sổ kho không khớp</h3>
                        <p class="mt-0.5 text-[13px] text-slate-500">Có {{ data.ledger.length }} chỗ không khớp. EzChat Bán hàng không tự sửa sổ kho; hãy kiểm hàng SKU đó hoặc liên hệ hỗ trợ.</p>
                    </header>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[700px] text-[14px]">
                            <thead :class="thead">
                                <tr>
                                    <th :class="th">SKU, kho</th>
                                    <th :class="th">Vấn đề</th>
                                    <th :class="cn(th, 'text-right')">Theo sổ kho</th>
                                    <th :class="cn(th, 'text-right')">Đang ghi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(r, i) in data.ledger" :key="i" class="border-t border-slate-100">
                                    <td class="px-4 py-3">
                                        <RouterLink v-if="r.variant" :to="`/inventory/sku/${r.variant.id}`" class="font-medium text-slate-800 hover:text-blue-700">{{ r.variant.name }}</RouterLink>
                                        <p class="text-[12px] text-slate-500">{{ r.variant?.sku }} · {{ r.warehouse?.name ?? '—' }}</p>
                                    </td>
                                    <td class="px-4 py-3">{{ r.label }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ formatNumber(r.expected) }}</td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums text-red-600">{{ formatNumber(r.actual) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section :class="cn(card, 'overflow-hidden')">
                    <header class="border-b border-slate-100 px-5 py-4">
                        <h3 class="text-[16px] font-semibold text-slate-900">Đơn xuất thiếu hàng</h3>
                        <p class="mt-0.5 text-[13px] text-slate-500">Nếu hàng thực ra đã đi, hãy kiểm hàng lại SKU đó để tồn kho đúng thực tế.</p>
                    </header>
                    <EmptyState v-if="!data.shortages.length" :icon="PackageX" title="Không có đơn xuất thiếu" description="90 ngày qua không có đơn nào bàn giao khi kho thiếu hàng." />
                    <div v-else class="overflow-x-auto">
                        <table class="w-full min-w-[820px] text-[14px]" data-testid="shortage-table">
                            <thead :class="thead">
                                <tr>
                                    <th :class="th">Đơn, bàn giao</th>
                                    <th :class="th">SKU, kho</th>
                                    <th :class="cn(th, 'text-right')">Số lượng đơn</th>
                                    <th :class="cn(th, 'text-right')">Thiếu</th>
                                    <th :class="th"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(r, i) in data.shortages" :key="i" class="border-t border-slate-100 hover:bg-slate-50/70">
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-2.5">
                                            <MarketplaceLogo :marketplace="r.order.marketplace" size="sm" />
                                            <div>
                                                <RouterLink :to="`/orders/${r.order.id}`" class="font-mono text-[13px] font-semibold text-blue-700 hover:underline">{{ r.order.number }}</RouterLink>
                                                <p class="whitespace-nowrap text-[12px] text-slate-500">{{ formatDateTime(r.shipped_at) }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <RouterLink v-if="r.variant" :to="`/inventory/sku/${r.variant.id}`" class="font-medium text-slate-800 hover:text-blue-700">{{ r.variant.name }}</RouterLink>
                                        <p class="text-[12px] text-slate-500">{{ r.variant?.sku }} · {{ r.warehouse?.name ?? '—' }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ formatNumber(r.quantity) }}</td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums text-amber-600">{{ formatNumber(r.shortage) }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <RouterLink v-if="r.variant" :to="`/inventory/sku/${r.variant.id}`" :class="rowButton">Xem thẻ kho</RouterLink>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <aside class="grid content-start gap-5 md:grid-cols-2 2xl:grid-cols-1">
                <SideCard title="Vì sao bị lệch" description="Các nguyên nhân thường gặp">
                    <template #actions><span class="grid size-10 place-items-center rounded-[10px] bg-amber-50 text-amber-600"><Lightbulb class="size-5" /></span></template>
                    <ul class="space-y-3 p-5 text-[14px] text-slate-600">
                        <li v-for="[title, text] in causes" :key="title"><b class="text-slate-800">{{ title }}:</b> {{ text }}</li>
                    </ul>
                </SideCard>
            </aside>
        </div>
    </template>
</template>
