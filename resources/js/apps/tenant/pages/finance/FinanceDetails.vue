<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { Download } from 'lucide-vue-next';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDate, formatMoney, formatNumber } from '@/shared/lib/utils';
import { downloadCsv, toCsv } from '../reports/range';
import { feeStatusLabels, metricTitles, type Metric } from './finance';

/** The orders or returns behind one figure of the finance page, with the same filters; exportable to CSV. */
const props = defineProps<{ metric: Metric | null; params: Record<string, string> }>();
const emit = defineEmits<{ close: [] }>();

type Row = Record<string, string | number | null>;
const data = ref<{ rows: Row[]; total: Record<string, number>; truncated: boolean } | null>(null);
const error = ref<string | null>(null);
const open = computed({ get: () => props.metric !== null, set: (v) => !v && emit('close') });

async function load() {
    if (!props.metric) return;
    data.value = null;
    error.value = null;
    try {
        data.value = (await api.get('/finance/details', { params: { ...props.params, metric: props.metric } })).data.data;
    } catch (e) {
        error.value = (e as ApiError).message;
    }
}
watch(() => props.metric, load, { immediate: true });

const isReturns = computed(() => props.metric === 'returns');
const hasCost = computed(() => !!data.value && data.value.rows.some((r) => 'cogs' in r || 'cost_back' in r));
const columns = computed<{ key: string; label: string; money?: boolean; date?: boolean }[]>(() => {
    if (isReturns.value) {
        return [
            { key: 'code', label: 'Phiếu trả' },
            { key: 'order_code', label: 'Đơn hàng' },
            { key: 'shop', label: 'Shop / kênh' },
            { key: 'created_at', label: 'Ngày duyệt', date: true },
            { key: 'value', label: 'Giá trị hàng trả (chưa VAT)', money: true },
            ...(hasCost.value ? [{ key: 'cost_back', label: 'Giá vốn nhập lại', money: true }] : []),
        ];
    }
    const fees = props.metric === 'fees' || props.metric === 'payouts';
    return [
        { key: 'code', label: 'Mã đơn' },
        { key: 'shop', label: 'Shop / kênh' },
        { key: props.metric === 'revenue' || fees ? 'recognized_at' : 'placed_at', label: props.metric === 'revenue' || fees ? 'Ngày ghi nhận' : 'Ngày đặt', date: true },
        { key: 'goods', label: 'Tiền hàng (chưa VAT)', money: true },
        ...(fees ? [] : [{ key: 'tax', label: 'VAT', money: true }]),
        ...(fees ? [] : [{ key: 'shipping', label: 'Ship khách trả', money: true }]),
        ...(hasCost.value && !fees ? [{ key: 'cogs', label: 'Giá vốn', money: true }] : []),
        ...(props.metric === 'revenue' || fees ? [{ key: 'fee', label: 'Phí sàn', money: true }, { key: 'fee_status', label: 'Loại phí' }] : []),
        ...(props.metric === 'payouts' ? [{ key: 'payout', label: 'Sàn chuyển về', money: true }] : []),
    ];
});

function cell(row: Row, c: { key: string; money?: boolean; date?: boolean }): string {
    const v = row[c.key];
    if (c.key === 'fee_status') return feeStatusLabels[String(v)] ?? String(v ?? '');
    if (c.key === 'fee' && row.fee_status === 'unknown') return 'Chưa biết';
    if (c.money) return formatMoney(v);
    if (c.date) return formatDate(v as string | null);
    return String(v ?? '—');
}

function exportCsv() {
    if (!data.value || !props.metric) return;
    downloadCsv(
        `tai-chinh-${props.metric}-${props.params.from ?? ''}-${props.params.to ?? ''}.csv`,
        toCsv(
            columns.value.map((c) => c.label),
            data.value.rows.map((r) => columns.value.map((c) => (c.money ? (r[c.key] as number | null) : cell(r, c)))),
        ),
    );
}
</script>

<template>
    <Dialog v-model:open="open" :title="metric ? metricTitles[metric] : ''" size="xl" :description="data ? `${formatNumber(data.total.count)} dòng, cùng bộ lọc với trang Tài chính` : undefined">
        <ErrorState v-if="error" :message="error" @retry="load" />
        <div v-else-if="!data" class="space-y-2"><Skeleton v-for="i in 5" :key="i" class="h-7" /></div>
        <template v-else>
            <p v-if="data.truncated" class="mb-2 text-xs text-amber-700">Chỉ hiển thị 2.000 dòng đầu, hãy thu hẹp khoảng ngày.</p>
            <p v-if="!data.rows.length" class="py-6 text-center text-sm text-muted-foreground">Không có dòng nào trong kỳ.</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm" data-testid="finance-details">
                    <thead class="border-b text-left text-xs text-muted-foreground">
                        <tr>
                            <th v-for="c in columns" :key="c.key" :class="['whitespace-nowrap px-2 py-2 font-medium', c.money && 'text-right']">{{ c.label }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="r in data.rows" :key="String(r.id)">
                            <td v-for="c in columns" :key="c.key" :class="['whitespace-nowrap px-2 py-1.5', c.money && 'text-right tabular-nums']">
                                <RouterLink v-if="c.key === 'code' && !isReturns" :to="`/orders/${r.id}`" class="text-primary hover:underline">{{ r.code }}</RouterLink>
                                <RouterLink v-else-if="c.key === 'order_code'" :to="`/orders/${r.order_id}`" class="text-primary hover:underline">{{ r.order_code }}</RouterLink>
                                <Badge v-else-if="c.key === 'fee_status'" :tone="r.fee_status === 'unknown' ? 'warning' : r.fee_status === 'confirmed' ? 'success' : 'muted'">{{ cell(r, c) }}</Badge>
                                <template v-else>{{ cell(r, c) }}</template>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t font-semibold">
                        <tr>
                            <td v-for="(c, i) in columns" :key="c.key" :class="['whitespace-nowrap px-2 py-2', c.money && 'text-right tabular-nums']">
                                <template v-if="i === 0">Tổng</template>
                                <template v-else-if="c.money && c.key in data.total">{{ formatMoney(data.total[c.key]) }}</template>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </template>
        <template #footer>
            <Button variant="outline" size="sm" :disabled="!data?.rows.length" @click="exportCsv"><Download /> Xuất Excel (CSV)</Button>
        </template>
    </Dialog>
</template>
