<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, type RouteLocationRaw } from 'vue-router';
import { useTenantStore } from '@/shared/stores/tenant';
import { reportGroups, type ReportGroupKey, type ReportLink } from './groups';
import { useReportQuery } from './useReportQuery';
import ReportFrame from './ReportFrame.vue';
import SalesDashboard from './SalesDashboard.vue';
import PurchasesDashboard from './PurchasesDashboard.vue';
import StockDashboard from './StockDashboard.vue';
import FinanceDashboard from './FinanceDashboard.vue';
import CustomersDashboard from './CustomersDashboard.vue';

/**
 * The five report menus (mẫu 28/11): one frame (tabs, period, "Xuất báo cáo") and each menu's own dashboard.
 * Each menu has its dashboard: headline figures, its reports as cards with their figures, and a side column.
 */
const route = useRoute();
const tenant = useTenantStore();
const { range } = useReportQuery();
const key = computed(() => route.params.group as ReportGroupKey);
const group = computed(() => reportGroups[key.value]);
const links = computed(() => group.value.links.filter((l) => tenant.can(l.permission)));

const descriptions: Record<ReportGroupKey, string> = {
    sales: 'Doanh thu, lãi gộp, kênh bán và sản phẩm bán chạy trong kỳ.',
    purchases: 'Hàng nhập kho, thanh toán và công nợ nhà cung cấp trong kỳ.',
    stock: 'Tồn kho, xuất nhập tồn, định mức và kiểm kê hàng hóa.',
    finance: 'Lãi lỗ, sổ quỹ, thu chi và công nợ của cửa hàng.',
    customers: 'Ai mua nhiều, khách mới hay quay lại, giá trị mỗi khách và công nợ.',
};

function target(l: ReportLink, by?: string): RouteLocationRaw {
    const query = l.ranged ? { ...range.value } : {};
    if ('report' in l.to) return { name: 'report-detail', params: { report: l.to.report }, query: { ...query, ...(by ?? l.to.by ? { by: by ?? l.to.by } : {}) } };
    return { name: l.to.name, query: { ...query, ...(l.to.query ?? {}) } };
}

const dashboards = { sales: SalesDashboard, purchases: PurchasesDashboard, stock: StockDashboard, finance: FinanceDashboard, customers: CustomersDashboard };
const dashboard = ref<{ exportCsv: () => void } | null>(null);
function exportReport() {
    dashboard.value?.exportCsv();
}
</script>

<template>
    <ReportFrame :group="key" :description="descriptions[key]" :from="range.from" :to="range.to" @update:range="(r) => (range = r)" @export="exportReport" />

    <component :is="dashboards[key]" :key="key" ref="dashboard" :range="range" :links="links" :target="target" />
</template>
