<script setup lang="ts">
import { computed, onMounted, provide, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Activity, AlertTriangle, ArrowRightLeft, History, PlugZap, RefreshCw, Store } from 'lucide-vue-next';
import ChannelHead from './ChannelHead.vue';
import ReportKpi from '../reports/ReportKpi.vue';
import ReportChip from '../reports/ReportChip.vue';
import { btn, pill } from './channel';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api } from '@/shared/lib/api';
import { formatDateTime, formatNumber } from '@/shared/lib/utils';
import type { SyncOverviewData } from '@/shared/types';
import SyncLog from './SyncLog.vue';
import Allocation from './sync-center/Allocation.vue';
import Overview from './sync-center/Overview.vue';
import Problems from './sync-center/Problems.vue';
import Queue from './sync-center/Queue.vue';

/**
 * Trung tâm đồng bộ (docs/SYNC_CENTER_AUDIT.md §15): one page under Kết nối sàn to watch and operate the sync that
 * already runs, and to set how much stock each shop publishes (Chia tồn cho từng shop). Shops and API keys stay on Kết nối sàn.
 */
type Tab = 'overview' | 'queue' | 'log' | 'problems' | 'allocation';
provide('soft-cards', true);
const route = useRoute();
const router = useRouter();
const tabs: Tab[] = ['overview', 'queue', 'log', 'problems', 'allocation'];
const tab = ref<Tab>(tabs.includes(route.query.tab as Tab) ? (route.query.tab as Tab) : 'overview');
const overview = ref<SyncOverviewData | null>(null);
const failed = ref(false);
const loading = ref(false);

async function load() {
    loading.value = true;
    failed.value = false;
    try {
        overview.value = (await api.get('/sync-center/overview')).data.data;
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

// Links inside the page (a KPI, an error group) change the query; follow them.
watch(
    () => route.query.tab,
    (t) => {
        if (tabs.includes(t as Tab) && t !== tab.value) tab.value = t as Tab;
    },
);
watch(tab, (t) => {
    if (route.query.tab !== t) router.replace({ query: { tab: t } });
});

const problemCount = computed(() => Object.values(overview.value?.problems.failed_by_code ?? {}).reduce((a, b) => a + b, 0));
const taskTotal = computed(() => Object.values(overview.value?.tasks.by_status ?? {}).reduce((a, b) => a + b, 0));
const successRate = computed(() => (taskTotal.value ? `${(Math.round(((overview.value!.tasks.by_status.success ?? 0) / taskTotal.value) * 1000) / 10).toLocaleString('vi-VN')}%` : null));
const tabList = computed<{ value: Tab; label: string; count?: number }[]>(() => [
    { value: 'overview', label: 'Tổng quan' },
    { value: 'queue', label: 'Hàng đợi' },
    { value: 'log', label: 'Nhật ký đơn' },
    { value: 'problems', label: 'Lỗi cần xử lý', count: problemCount.value },
    { value: 'allocation', label: 'Chia tồn cho từng shop' },
]);
</script>

<template>
    <ChannelHead title="Trung tâm đồng bộ" :description="`Theo dõi đơn, tồn kho và giá đồng bộ giữa phần mềm và các kênh bán.${overview?.last_synced_at ? ' Lần đồng bộ gần nhất ' + formatDateTime(overview.last_synced_at) + '.' : ''}`">
        <RouterLink to="/marketplaces" :class="btn.outline"><Store class="size-4" /> Shop &amp; tài khoản</RouterLink>
        <button type="button" :class="btn.outline" @click="tab = 'log'"><History class="size-4" /> Nhật ký đồng bộ</button>
        <button type="button" :class="btn.primary" :disabled="loading" data-testid="sync-center-refresh" @click="load"><RefreshCw :class="['size-4', loading && 'animate-spin']" /> Làm mới</button>
    </ChannelHead>

    <div v-if="overview" class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="sync-center-kpis">
        <ReportKpi label="Kênh đang chạy" :icon="PlugZap" tone="green" plain :value="null" :text="`${formatNumber(overview.accounts.active)} / ${formatNumber(overview.accounts.total)}`" unit="">
            <template v-if="overview.accounts.disconnected || overview.accounts.token_expiring" #chips>
                <ReportChip v-if="overview.accounts.disconnected" tone="red">{{ overview.accounts.disconnected }} mất kết nối</ReportChip>
                <ReportChip v-if="overview.accounts.token_expiring" tone="amber">{{ overview.accounts.token_expiring }} sắp hết hạn</ReportChip>
            </template>
            {{ overview.shops.map((s) => s.name).join(', ') || 'Chưa kết nối shop nào' }}
        </ReportKpi>
        <ReportKpi label="Tác vụ 24 giờ" :icon="Activity" tone="blue" plain :value="taskTotal" unit="">
            <template v-if="successRate !== null" #chips><ReportChip tone="up">{{ successRate }} thành công</ReportChip></template>
            đơn, tồn, giá, sản phẩm
        </ReportKpi>
        <ReportKpi label="Lỗi cần xử lý" :icon="AlertTriangle" tone="red" :value="problemCount" unit="">{{ problemCount ? 'Bấm tab Lỗi cần xử lý để sửa' : 'Không có lỗi nào' }}</ReportKpi>
        <ReportKpi label="Tồn lệch" :icon="ArrowRightLeft" tone="amber" :value="overview.problems.stock_drift" unit="">SKU sàn báo khác số đã đẩy, đối soát lúc 2 giờ đêm</ReportKpi>
    </div>

    <div class="mb-5 flex max-w-full flex-wrap gap-1 self-start rounded-[12px] bg-slate-100 p-1 text-[14px] sm:inline-flex" data-testid="sync-center-tabs">
        <button v-for="t in tabList" :key="t.value" type="button" :class="pill(tab === t.value)" @click="tab = t.value">
            {{ t.label }}<span v-if="t.count" :class="['rounded-md px-1.5 text-[12px] tabular-nums', tab === t.value ? 'bg-red-50 text-red-600' : 'bg-white/70 text-slate-500']">{{ t.count }}</span>
        </button>
    </div>

    <ErrorState v-if="failed && !overview" @retry="load" />
    <div v-else-if="!overview" class="space-y-3"><Skeleton class="h-24" /><Skeleton class="h-64" /></div>
    <template v-else>
        <Overview v-if="tab === 'overview'" :data="overview" @changed="load" />
        <Queue v-else-if="tab === 'queue'" :overview="overview" @changed="load" />
        <SyncLog v-else-if="tab === 'log'" embedded />
        <Problems v-else-if="tab === 'problems'" :overview="overview" @changed="load" />
        <Allocation v-else />
    </template>
</template>
