<script setup lang="ts">
import { computed, onMounted, ref, watch, type Component } from 'vue';
import { RouterLink, type RouteLocationRaw } from 'vue-router';
import { Boxes, ChevronRight, Landmark, PackagePlus, ShoppingBag, Undo2, Users } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import RangePicker from './RangePicker.vue';
import Card from '@/shared/ui/Card.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useReportQuery } from './useReportQuery';

interface Stat {
    label: string;
    value: string;
    tone?: 'danger' | 'success';
}
interface Group {
    key: string;
    title: string;
    icon: Component;
    headline: Stat;
    stats: Stat[];
    links: { label: string; to: RouteLocationRaw; hint?: string }[];
}

const tenant = useTenantStore();
const { range } = useReportQuery();
const data = ref<any>(null);
const error = ref<string | null>(null);
const loading = ref(false);

async function load() {
    loading.value = true;
    error.value = null;
    try {
        data.value = (await api.get('/reports/overview', { params: range.value })).data.data;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
watch(() => [range.value.from, range.value.to], load);

const sales = (by?: string): RouteLocationRaw => ({ name: 'report-sales', query: { ...range.value, ...(by ? { by } : {}) } });
const detail = (report: string, query: Record<string, string> = {}, withRange = true): RouteLocationRaw => ({
    name: 'report-detail',
    params: { report },
    query: { ...(withRange ? range.value : {}), ...query },
});
const pct = (v: number | null | undefined) => (v === null || v === undefined ? null : `${v > 0 ? '+' : ''}${v.toLocaleString('vi-VN')}%`);

const groups = computed<Group[]>(() => {
    const d = data.value;
    if (!d) return [];
    const out: Group[] = [
        {
            key: 'sales',
            title: 'Bán hàng',
            icon: ShoppingBag,
            headline: { label: 'Doanh thu', value: formatMoney(d.sales.revenue) },
            stats: [
                { label: 'Đơn hàng', value: formatNumber(d.sales.orders) },
                { label: 'Lãi gộp', value: formatMoney(d.sales.gross_profit), tone: d.sales.gross_profit < 0 ? 'danger' : undefined },
                ...(d.sales.top_channels[0] ? [{ label: 'Kênh bán nhiều nhất', value: d.sales.top_channels[0].label }] : []),
            ],
            links: [
                { label: 'Doanh thu & lợi nhuận theo ngày', to: sales() },
                { label: 'Theo kênh bán', to: sales('channel') },
                { label: 'Theo nhân viên', to: sales('staff') },
                { label: 'Theo kho xuất', to: sales('warehouse') },
                { label: 'Theo sản phẩm', to: detail('product-sales') },
                { label: 'Bán chạy, bán chậm', to: { name: 'report-products' } },
            ],
        },
        {
            key: 'returns',
            title: 'Trả hàng',
            icon: Undo2,
            headline: { label: 'Đơn trả hàng', value: formatNumber(d.returns.returns) },
            stats: [
                { label: 'Số lượng trả', value: formatNumber(d.returns.quantity) },
                { label: 'Đã hoàn tiền', value: formatMoney(d.returns.refund_amount) },
            ],
            links: [
                { label: 'Trả hàng theo đơn', to: detail('returns', { by: 'order' }) },
                { label: 'Trả hàng theo sản phẩm', to: detail('returns', { by: 'product' }) },
            ],
        },
        {
            key: 'customers',
            title: 'Khách hàng',
            icon: Users,
            headline: { label: 'Khách mua hàng', value: formatNumber(d.customers.customers) },
            stats: [
                { label: 'Khách mới', value: formatNumber(d.customers.new), tone: d.customers.new ? 'success' : undefined },
                { label: 'Khách quay lại', value: formatNumber(d.customers.returning) },
                { label: 'Đơn khách lẻ / sàn', value: formatNumber(d.customers.anonymous_orders) },
            ],
            links: [
                { label: 'Khách mua nhiều nhất, khách mới', to: detail('customers') },
                { label: 'Doanh thu, lợi nhuận theo khách', to: sales('customer') },
                ...(tenant.can('finance.view') ? [{ label: 'Công nợ khách hàng', to: detail('receivables', {}, false) }] : []),
            ],
        },
    ];
    if (d.finance) {
        out.push({
            key: 'finance',
            title: 'Tài chính',
            icon: Landmark,
            headline: { label: 'Lợi nhuận', value: formatMoney(d.finance.profit), tone: d.finance.profit < 0 ? 'danger' : undefined },
            stats: [
                ...(pct(d.finance.profit_change) ? [{ label: 'So với kỳ trước', value: pct(d.finance.profit_change)!, tone: (d.finance.profit_change < 0 ? 'danger' : 'success') as Stat['tone'] }] : []),
                { label: 'Thực thu từ khách', value: formatMoney(d.finance.payments.net) },
                { label: 'Khách còn nợ', value: formatMoney(d.finance.receivable.due), tone: d.finance.receivable.due > 0 ? 'danger' : undefined },
            ],
            links: [
                { label: 'Báo cáo lãi lỗ', to: { name: 'report-profit-loss', query: range.value } },
                { label: 'Thanh toán theo phương thức', to: detail('payments', { by: 'method' }) },
                { label: 'Thanh toán theo nhân viên', to: detail('payments', { by: 'staff' }) },
                { label: 'Sổ quỹ', to: { name: 'report-cashbook' } },
                { label: 'Công nợ khách hàng', to: detail('receivables', {}, false) },
            ],
        });
    }
    if (d.purchases) {
        out.push({
            key: 'purchases',
            title: 'Nhập hàng',
            icon: PackagePlus,
            headline: { label: 'Giá trị nhập kho', value: formatMoney(d.purchases.value) },
            stats: [
                { label: 'Phiếu nhập', value: formatNumber(d.purchases.receipts) },
                { label: 'Hàng trả NCC', value: formatMoney(d.purchases.returned) },
                { label: 'Còn nợ NCC', value: formatMoney(d.payable.debt), tone: d.payable.debt > 0 ? 'danger' : undefined },
            ],
            links: [
                { label: 'Nhập hàng theo nhà cung cấp', to: detail('purchases', { by: 'supplier' }) },
                { label: 'Nhập hàng theo sản phẩm', to: detail('purchases', { by: 'product' }) },
                { label: 'Nhập hàng theo ngày', to: detail('purchases', { by: 'day' }) },
                { label: 'Công nợ nhà cung cấp', to: detail('payables', {}, false) },
            ],
        });
    }
    if (d.stock) {
        out.push({
            key: 'stock',
            title: 'Kho hàng',
            icon: Boxes,
            headline: { label: 'Giá trị tồn hiện tại', value: formatMoney(d.stock.value) },
            stats: [
                { label: 'SKU còn hàng', value: formatNumber(d.stock.skus) },
                { label: 'Dưới định mức', value: formatNumber(d.stock.low.skus), tone: d.stock.low.skus ? 'danger' : undefined },
                { label: 'Đã hết hàng', value: formatNumber(d.stock.low.out_of_stock), tone: d.stock.low.out_of_stock ? 'danger' : undefined },
            ],
            links: [
                { label: 'Tồn kho tại ngày', to: detail('stock-on-hand', {}, false) },
                { label: 'Xuất nhập tồn', to: { name: 'report-stock' } },
                { label: 'Hàng dưới định mức', to: detail('low-stock', {}, false) },
                { label: 'Thẻ kho từng SKU', to: { name: 'inventory' }, hint: 'Chọn SKU ở trang Tồn kho' },
            ],
        });
    }
    return out;
});
</script>

<template>
    <PageHeader title="Báo cáo" description="Số liệu chính của kỳ đang chọn. Bấm vào một báo cáo để xem chi tiết, lọc và xuất Excel.">
        <RangePicker :from="range.from" :to="range.to" @update:from="(v) => (range = { ...range, from: v })" @update:to="(v) => (range = { ...range, to: v })" />
    </PageHeader>

    <ErrorState v-if="error" :message="error" @retry="load" />
    <div v-else-if="!data" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"><Skeleton v-for="i in 6" :key="i" class="h-72" /></div>
    <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" :class="loading && 'opacity-60'" data-testid="report-centre">
        <Card v-for="g in groups" :key="g.key" class="flex flex-col" :data-testid="`report-group-${g.key}`">
            <div class="flex items-start gap-3 border-b p-4">
                <div class="flex size-9 shrink-0 items-center justify-center rounded-md bg-secondary text-primary"><component :is="g.icon" class="size-4.5" /></div>
                <div class="min-w-0 flex-1">
                    <h2 class="text-sm font-semibold">{{ g.title }}</h2>
                    <p class="mt-2 text-xs text-muted-foreground">{{ g.headline.label }}</p>
                    <p :class="cn('text-2xl font-semibold tabular-nums', g.headline.tone === 'danger' && 'text-destructive')">{{ g.headline.value }}</p>
                    <dl class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                        <div v-for="s in g.stats" :key="s.label" class="flex gap-1">
                            <dt class="text-muted-foreground">{{ s.label }}:</dt>
                            <dd :class="cn('font-medium tabular-nums', s.tone === 'danger' && 'text-destructive', s.tone === 'success' && 'text-success')">{{ s.value }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
            <ul class="flex-1 divide-y text-sm">
                <li v-for="l in g.links" :key="l.label">
                    <RouterLink :to="l.to" class="flex items-center justify-between gap-2 px-4 py-2.5 hover:bg-muted/50">
                        <span>{{ l.label }}<span v-if="l.hint" class="block text-xs text-muted-foreground">{{ l.hint }}</span></span>
                        <ChevronRight class="size-4 shrink-0 text-muted-foreground" />
                    </RouterLink>
                </li>
            </ul>
        </Card>
    </div>
</template>
