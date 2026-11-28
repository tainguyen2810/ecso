<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, type RouteLocationRaw } from 'vue-router';
import { cn } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { reportGroups, type ReportGroupKey, type ReportLink } from './groups';
import { useReportQuery } from './useReportQuery';
import ReportFrame from './ReportFrame.vue';
import SalesDashboard from './SalesDashboard.vue';

/**
 * The five report menus (mẫu 28/11): one frame (tabs, period, "Xuất báo cáo") and each menu's own dashboard.
 * Bán hàng has its dashboard; the other menus list their reports as cards for now.
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

const dashboard = ref<InstanceType<typeof SalesDashboard> | null>(null);
function exportReport() {
    dashboard.value?.exportCsv();
}
</script>

<template>
    <ReportFrame :group="key" :description="descriptions[key]" :from="range.from" :to="range.to" :can-export="key === 'sales'" @update:range="(r) => (range = r)" @export="exportReport" />

    <SalesDashboard v-if="key === 'sales'" ref="dashboard" :range="range" :links="links" :target="target" />

    <template v-else>
        <p class="-mt-3 mb-4 text-sm text-muted-foreground">Danh sách {{ group.title.toLowerCase() }}</p>
        <div class="grid gap-4 md:grid-cols-2" :data-testid="`report-group-${key}`">
            <div v-for="l in links" :key="l.title" class="flex gap-4 rounded-lg bg-card p-5 shadow-card">
                <span class="grid size-11 shrink-0 place-items-center rounded-lg bg-blue-50 text-blue-600"><component :is="l.icon" class="size-5" /></span>
                <div class="min-w-0">
                    <RouterLink :to="target(l)" class="font-semibold text-primary hover:underline">{{ l.title }}</RouterLink>
                    <p class="mt-0.5 text-sm text-muted-foreground">{{ l.description }}</p>
                    <div v-if="l.chips" class="mt-2 flex flex-wrap gap-1.5">
                        <RouterLink v-for="c in l.chips" :key="c.by" :to="target(l, c.by)" :class="cn('rounded-full border px-2.5 py-0.5 text-xs text-muted-foreground hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700')">{{ c.label }}</RouterLink>
                    </div>
                </div>
            </div>
        </div>
    </template>
</template>
