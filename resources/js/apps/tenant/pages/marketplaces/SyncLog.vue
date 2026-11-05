<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { AlertTriangle, CheckCircle2, ChevronLeft, History, RotateCw, X } from 'lucide-vue-next';
import Pagination from '../../components/Pagination.vue';
import Tabs from '../../components/Tabs.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import type { MarketplaceAccountData, SyncErrorData, SyncRunData } from '@/shared/types';

/**
 * Sync errors (one row per order that could not be synced, until it syncs or is dismissed) and sync history (one row
 * per pass). Shown as the "Nhật ký đơn" tab of Trung tâm đồng bộ (`embedded`).
 */
const props = defineProps<{ embedded?: boolean }>();
const route = useRoute();
const router = useRouter();
const toast = useToast();
const tenant = useTenantStore();
const canEdit = computed(() => tenant.can('marketplace.connect'));

const logTabs = ['errors', 'resolved', 'runs'] as const;
const initialTab = (props.embedded ? route.query.log : route.query.tab) as (typeof logTabs)[number];
const tab = ref<(typeof logTabs)[number]>(logTabs.includes(initialTab) ? initialTab : 'errors');
const account = ref<string>((route.query.account as string) ?? '');
const category = ref('');
const accounts = ref<MarketplaceAccountData[]>([]);
const errors = ref<SyncErrorData[]>([]);
const runs = ref<SyncRunData[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const openByCategory = ref<Record<string, number>>({});
const loading = ref(true);
const busy = ref<string | null>(null);

const categories: Record<string, { label: string; hint: string }> = {
    api: { label: 'Website không trả lời', hint: 'Quá thời gian, quá tải (429) hoặc lỗi máy chủ (5xx). ECSO tự thử lại ở lượt sau.' },
    auth: { label: 'Khóa API bị từ chối', hint: 'Khóa API bị thu hồi hoặc hết quyền. Kết nối lại website.' },
    not_found: { label: 'Không tìm thấy', hint: 'Đơn không còn trên website.' },
    data: { label: 'Dữ liệu không đọc được', hint: 'Website trả dữ liệu ECSO không nhận được.' },
    conflict: { label: 'Xung đột trạng thái', hint: 'Trạng thái trên website không đi tiếp được từ trạng thái trong ECSO (ví dụ lùi lại).' },
};
const runStatus: Record<string, { label: string; tone: 'success' | 'warning' | 'danger' | 'info' }> = {
    ok: { label: 'Thành công', tone: 'success' },
    partial: { label: 'Một phần', tone: 'warning' },
    failed: { label: 'Lỗi', tone: 'danger' },
    running: { label: 'Đang chạy', tone: 'info' },
};
const countLabels: Record<string, string> = { created: 'mới', updated: 'cập nhật', unchanged: 'không đổi', skipped: 'bỏ qua', stale: 'cũ', failed: 'lỗi', ignored_transition: 'xung đột', deleted_on_channel: 'đã xóa' };
const triggerLabels: Record<string, string> = { schedule: 'Tự động', user: 'Bấm tay' };

async function load(page = 1) {
    loading.value = true;
    try {
        const params = { account: account.value || undefined, page };
        if (tab.value === 'runs') {
            const { data } = await api.get('/sync-runs', { params });
            runs.value = data.data;
            meta.value = data.meta;
        } else {
            const { data } = await api.get('/sync-errors', { params: { ...params, category: category.value || undefined, state: tab.value === 'resolved' ? 'resolved' : 'open' } });
            errors.value = data.data;
            meta.value = data.meta;
            if (tab.value === 'errors') openByCategory.value = data.meta.open_by_category;
        }
    } finally {
        loading.value = false;
    }
}
onMounted(() => {
    load();
    api.get('/marketplace-accounts').then(({ data }) => (accounts.value = data.data)).catch(() => {});
});
watch([tab, account, category], () => {
    router.replace({ query: { ...route.query, ...(props.embedded ? { log: tab.value } : { tab: tab.value }), account: account.value || undefined } });
    load();
});

const openTotal = computed(() => Object.values(openByCategory.value).reduce((a, b) => a + b, 0));

async function retry(e: SyncErrorData) {
    busy.value = `retry-${e.id}`;
    try {
        const { data } = await api.post(`/sync-errors/${e.id}/retry`);
        toast.success(data.data.error.resolved_at ? `Đơn ${e.external_id} đã đồng bộ được.` : `Đơn ${e.external_id} vẫn lỗi.`);
        await load(meta.value.current_page);
    } catch (err) {
        toast.error((err as ApiError).message);
        await load(meta.value.current_page);
    } finally {
        busy.value = null;
    }
}
async function dismiss(e: SyncErrorData) {
    busy.value = `dismiss-${e.id}`;
    try {
        await api.post(`/sync-errors/${e.id}/resolve`);
        errors.value = errors.value.filter((x) => x.id !== e.id);
        openByCategory.value = { ...openByCategory.value, [e.category]: Math.max(0, (openByCategory.value[e.category] ?? 1) - 1) };
    } catch (err) {
        toast.error((err as ApiError).message);
    } finally {
        busy.value = null;
    }
}
const duration = (ms: number | null) => (ms === null ? '—' : ms < 1000 ? `${ms} ms` : `${(ms / 1000).toLocaleString('vi-VN', { maximumFractionDigits: 1 })} giây`);
</script>

<template>
    <RouterLink v-if="!embedded" to="/marketplaces" class="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ChevronLeft class="size-4" /> Kết nối sàn</RouterLink>
    <div :class="['flex flex-wrap items-end justify-between gap-4', embedded ? 'mb-4' : 'mb-5']">
        <div v-if="!embedded">
            <h1 class="text-xl font-semibold tracking-tight">Lịch sử và lỗi đồng bộ</h1>
            <p class="text-sm text-muted-foreground">Đơn website chưa đồng bộ được, và từng lượt ECSO đối soát đơn với website.</p>
        </div>
        <p v-else class="text-sm text-muted-foreground">Đơn sàn chưa đồng bộ được và từng lượt đối soát đơn.</p>
        <div class="w-64">
            <Select v-model="account" :options="[{ value: '', label: 'Mọi website và gian hàng' }, ...accounts.map((a) => ({ value: a.id, label: a.shop_name }))]" data-testid="sync-account" />
        </div>
    </div>

    <div v-if="tab === 'errors'" class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5" data-testid="sync-error-groups">
        <button
            v-for="(c, key) in categories"
            :key="key"
            type="button"
            :class="['rounded-lg border bg-card px-4 py-3 text-left shadow-xs transition hover:border-primary/40', category === key && 'border-primary ring-2 ring-blue-100']"
            :title="c.hint"
            @click="category = category === key ? '' : key"
        >
            <p class="text-xs text-muted-foreground">{{ c.label }}</p>
            <p :class="['text-xl font-semibold tabular-nums', openByCategory[key] ? 'text-rose-600' : 'text-slate-400']">{{ formatNumber(openByCategory[key] ?? 0) }}</p>
        </button>
    </div>

    <Card>
        <div class="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3">
            <Tabs v-model="tab" :tabs="[{ value: 'errors', label: 'Lỗi đang mở', count: openTotal }, { value: 'resolved', label: 'Đã xử lý' }, { value: 'runs', label: 'Lịch sử đồng bộ' }]" />
            <p v-if="category && tab !== 'runs'" class="text-xs text-muted-foreground">{{ categories[category]?.hint }}</p>
        </div>
        <div v-if="loading" class="space-y-2 p-4"><Skeleton v-for="i in 4" :key="i" class="h-12" /></div>

        <template v-else-if="tab === 'runs'">
            <EmptyState v-if="!runs.length" :icon="History" title="Chưa có lượt đồng bộ" description="ECSO đối soát đơn theo chu kỳ đặt ở trang từng website." />
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm" data-testid="sync-runs">
                    <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-5 py-2.5 font-medium">Bắt đầu</th>
                            <th class="px-4 py-2.5 font-medium">Website</th>
                            <th class="px-4 py-2.5 font-medium">Kiểu</th>
                            <th class="px-4 py-2.5 font-medium">Kết quả</th>
                            <th class="px-4 py-2.5 font-medium">Số đơn</th>
                            <th class="px-4 py-2.5 text-right font-medium">Thời gian</th>
                            <th class="px-5 py-2.5 font-medium">Lỗi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="r in runs" :key="r.id">
                            <td class="whitespace-nowrap px-5 py-3 tabular-nums">{{ formatDateTime(r.started_at) }}</td>
                            <td class="px-4 py-3">
                                <span v-if="r.account" class="inline-flex items-center gap-2"><MarketplaceLogo :marketplace="r.account.marketplace" :name="r.account.name" size="sm" /> {{ r.account.name }}</span>
                            </td>
                            <td class="px-4 py-3">{{ triggerLabels[r.trigger] ?? r.trigger }}</td>
                            <td class="px-4 py-3"><Badge :tone="runStatus[r.status]?.tone">{{ runStatus[r.status]?.label ?? r.status }}</Badge></td>
                            <td class="px-4 py-3 text-xs">
                                <span v-if="!Object.keys(r.counts).length" class="text-muted-foreground">Không có đơn thay đổi</span>
                                <span v-for="(n, k) in r.counts" :key="k" :class="['mr-2', k === 'failed' && 'font-medium text-rose-600']">{{ formatNumber(n) }} {{ countLabels[k] ?? k }}</span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-muted-foreground">{{ duration(r.duration_ms) }}</td>
                            <td class="max-w-72 truncate px-5 py-3 text-xs text-rose-600" :title="r.error ?? ''">{{ r.error }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <template v-else>
            <EmptyState v-if="!errors.length" :icon="CheckCircle2" :title="tab === 'errors' ? 'Không có lỗi đồng bộ' : 'Chưa có lỗi nào đã xử lý'" description="Đơn lỗi được thử lại ở lượt đối soát sau; khi đồng bộ được, lỗi tự đóng." />
            <ul v-else class="divide-y" data-testid="sync-errors">
                <li v-for="e in errors" :key="e.id" class="flex flex-wrap items-center gap-4 px-5 py-3">
                    <AlertTriangle :class="['size-5 shrink-0', e.resolved_at ? 'text-slate-300' : 'text-rose-500']" />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm">
                            <span class="font-mono font-medium">Đơn {{ e.external_id }}</span>
                            <span class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ categories[e.category]?.label ?? e.category }}</span>
                            <span v-if="e.account" class="ml-2 text-xs text-muted-foreground">{{ e.account.name }}</span>
                        </p>
                        <p class="truncate text-sm text-muted-foreground" :title="e.message">{{ e.message }}</p>
                        <p class="text-xs text-muted-foreground">
                            Lần đầu {{ formatDateTime(e.first_seen_at) }} · gần nhất {{ formatDateTime(e.last_seen_at) }} · {{ formatNumber(e.attempts) }} lần
                            <template v-if="e.resolved_at"> · {{ e.resolution === 'synced' ? 'đã đồng bộ được' : 'đã bỏ qua' }} {{ formatDateTime(e.resolved_at) }}</template>
                        </p>
                    </div>
                    <div v-if="canEdit && !e.resolved_at" class="flex shrink-0 gap-1">
                        <Button size="sm" variant="outline" :loading="busy === `retry-${e.id}`" data-testid="sync-error-retry" @click="retry(e)"><RotateCw /> Thử lại</Button>
                        <Button size="sm" variant="ghost" :loading="busy === `dismiss-${e.id}`" data-testid="sync-error-dismiss" @click="dismiss(e)"><X /> Bỏ qua</Button>
                    </div>
                </li>
            </ul>
        </template>
        <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="load" />
    </Card>
</template>
