<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { RefreshCw } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Tabs from '../../components/Tabs.vue';
import Button from '@/shared/ui/Button.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api } from '@/shared/lib/api';
import { formatDateTime } from '@/shared/lib/utils';
import type { SyncOverviewData } from '@/shared/types';
import SyncLog from './SyncLog.vue';
import Overview from './sync-center/Overview.vue';
import Problems from './sync-center/Problems.vue';
import Queue from './sync-center/Queue.vue';

/**
 * Trung tâm đồng bộ (docs/SYNC_CENTER_AUDIT.md §15): one page under Kết nối sàn to watch and operate the sync that
 * already runs. Shops and API keys stay on Kết nối sàn; nothing here connects or configures a shop.
 */
type Tab = 'overview' | 'queue' | 'log' | 'problems';
const route = useRoute();
const router = useRouter();
const tabs: Tab[] = ['overview', 'queue', 'log', 'problems'];
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
</script>

<template>
    <PageHeader title="Trung tâm đồng bộ" :description="`Theo dõi đơn, tồn kho và giá đồng bộ giữa EzSell và các kênh bán.${overview?.last_synced_at ? ' Lần đồng bộ gần nhất ' + formatDateTime(overview.last_synced_at) + '.' : ''}`">
        <template #links>
            <RouterLink to="/marketplaces" class="text-sm text-primary hover:underline">Shop &amp; tài khoản</RouterLink>
        </template>
        <Button variant="outline" :loading="loading" data-testid="sync-center-refresh" @click="load"><RefreshCw /> Làm mới</Button>
    </PageHeader>

    <div class="mb-5 border-b">
        <Tabs
            v-model="tab"
            variant="underline"
            :tabs="[
                { value: 'overview', label: 'Tổng quan' },
                { value: 'queue', label: 'Hàng đợi' },
                { value: 'log', label: 'Nhật ký đơn' },
                { value: 'problems', label: 'Lỗi cần xử lý', count: overview ? problemCount : null },
            ]"
            data-testid="sync-center-tabs"
        />
    </div>

    <ErrorState v-if="failed && !overview" @retry="load" />
    <div v-else-if="!overview" class="space-y-3"><Skeleton class="h-24" /><Skeleton class="h-64" /></div>
    <template v-else>
        <Overview v-if="tab === 'overview'" :data="overview" @changed="load" />
        <Queue v-else-if="tab === 'queue'" :overview="overview" @changed="load" />
        <SyncLog v-else-if="tab === 'log'" embedded />
        <Problems v-else :overview="overview" @changed="load" />
    </template>
</template>
