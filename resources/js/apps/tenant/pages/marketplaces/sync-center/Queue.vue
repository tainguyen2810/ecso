<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Download, Inbox, RotateCw, Search } from 'lucide-vue-next';
import Pagination from '../../../components/Pagination.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import Input from '@/shared/ui/Input.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError, downloadFile } from '@/shared/lib/api';
import { cn, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import type { SyncOverviewData, SyncTaskData, SyncTaskStatus } from '@/shared/types';
import TaskDrawer from './TaskDrawer.vue';
import { sinceIso, statusInfo, taskStatuses, taskTypes, timeOnly, timeWindows } from './labels';

/** Hàng đợi: every sync task (webhooks, stock pushes, order polls, price pushes) with filters, detail and retry. */
const props = defineProps<{ overview: SyncOverviewData }>();
const emit = defineEmits<{ changed: [] }>();
const route = useRoute();
const router = useRouter();
const toast = useToast();

const q = (k: string) => (typeof route.query[k] === 'string' ? (route.query[k] as string) : '');
const filters = ref({ status: q('status'), type: q('type'), account: q('account'), error_code: q('error_code'), since: q('since') || '7d', q: q('q') });
const rows = ref<SyncTaskData[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0, by_status: {} as Partial<Record<SyncTaskStatus, number>> });
const loading = ref(true);
const busy = ref<string | null>(null);
const open = ref<SyncTaskData | null>(null);

const params = computed(() => ({
    status: filters.value.status || undefined,
    type: filters.value.type || undefined,
    account: filters.value.account || undefined,
    error_code: filters.value.error_code || undefined,
    q: filters.value.q.trim() || undefined,
    from: sinceIso(filters.value.since),
}));

async function load(page = 1) {
    loading.value = true;
    try {
        const { data } = await api.get('/sync-center/tasks', { params: { ...params.value, page } });
        rows.value = data.data;
        meta.value = data.meta;
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        loading.value = false;
    }
}
onMounted(() => load());

let timer: ReturnType<typeof setTimeout> | undefined;
watch(
    filters,
    (f) => {
        router.replace({ query: { tab: 'queue', ...Object.fromEntries(Object.entries(f).filter(([, v]) => v)) } });
        clearTimeout(timer);
        timer = setTimeout(() => load(), 250);
    },
    { deep: true },
);

const allCount = computed(() => Object.values(meta.value.by_status).reduce((a, b) => a + (b ?? 0), 0));
const typeOptions = [{ value: '', label: 'Loại: tất cả' }, ...Object.entries(taskTypes).map(([value, label]) => ({ value, label }))];
const shopOptions = computed(() => [{ value: '', label: 'Shop: tất cả' }, ...props.overview.shops.map((s) => ({ value: s.id, label: s.name }))]);
const codeOptions = computed(() => [{ value: '', label: 'Mã lỗi: tất cả' }, ...Object.entries(props.overview.error_codes).map(([value, i]) => ({ value, label: i.label }))]);

async function retry(t: SyncTaskData) {
    busy.value = t.id;
    try {
        const { data } = await api.post(`/sync-center/tasks/${t.type}/${t.key}/retry`);
        const s = (data.data as SyncTaskData).status;
        toast.success(s === 'success' ? 'Đã thử lại thành công.' : 'Đã xếp tác vụ để thử lại.');
        await load(meta.value.current_page);
        emit('changed');
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = null;
    }
}

async function exportXlsx() {
    busy.value = 'export';
    try {
        const qs = new URLSearchParams(Object.entries(params.value).filter((e): e is [string, string] => !!e[1])).toString();
        await downloadFile(`/sync-center/tasks/export${qs ? '?' + qs : ''}`, 'nhat-ky-dong-bo.xlsx');
    } catch (e) {
        toast.error((e as ApiError).message ?? 'Không xuất được file.');
    } finally {
        busy.value = null;
    }
}

function onDrawerChanged() {
    load(meta.value.current_page);
    emit('changed');
}
</script>

<template>
    <Card>
        <div class="flex flex-wrap items-center gap-2 border-b p-3">
            <div class="relative w-full sm:w-64">
                <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                <Input v-model="filters.q" class="pl-8" placeholder="Mã đơn sàn, SKU, correlation ID…" data-testid="sync-queue-search" />
            </div>
            <Select v-model="filters.type" :options="typeOptions" class="w-auto" data-testid="sync-queue-type" />
            <Select v-model="filters.account" :options="shopOptions" class="w-auto max-w-48" />
            <Select v-model="filters.error_code" :options="codeOptions" class="w-auto max-w-52" data-testid="sync-queue-code" />
            <Select v-model="filters.since" :options="timeWindows" class="w-auto" />
            <Button v-if="overview.permissions.export" variant="outline" class="ml-auto" :loading="busy === 'export'" @click="exportXlsx"><Download /> Xuất Excel</Button>
        </div>
        <div class="flex gap-1.5 overflow-x-auto border-b px-3 py-2.5" data-testid="sync-queue-chips">
            <button type="button" :class="cn('shrink-0 rounded-full border px-3 py-1 text-xs', !filters.status ? 'border-blue-200 bg-blue-50 font-semibold text-blue-600' : 'bg-card hover:bg-muted')" @click="filters.status = ''">
                Tất cả <b class="ml-0.5 tabular-nums">{{ formatNumber(allCount) }}</b>
            </button>
            <button
                v-for="s in taskStatuses"
                :key="s.value"
                type="button"
                :class="cn('shrink-0 rounded-full border px-3 py-1 text-xs', filters.status === s.value ? 'border-blue-200 bg-blue-50 font-semibold text-blue-600' : 'bg-card hover:bg-muted')"
                @click="filters.status = s.value"
            >
                {{ s.label }} <b class="ml-0.5 tabular-nums">{{ formatNumber(meta.by_status[s.value] ?? 0) }}</b>
            </button>
        </div>

        <div v-if="loading && !rows.length" class="space-y-2 p-4"><Skeleton v-for="i in 5" :key="i" class="h-11" /></div>
        <EmptyState v-else-if="!rows.length" :icon="Inbox" title="Không có tác vụ nào" description="Thử đổi bộ lọc hoặc khoảng thời gian." />
        <div v-else :class="['overflow-x-auto', loading && 'opacity-60']">
            <table class="w-full text-sm" data-testid="sync-queue">
                <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-5 py-2.5 font-medium">Thời gian</th>
                        <th class="px-4 py-2.5 font-medium">Shop</th>
                        <th class="px-4 py-2.5 font-medium">Loại</th>
                        <th class="px-4 py-2.5 font-medium">Đối tượng</th>
                        <th class="px-4 py-2.5 font-medium">Hành động</th>
                        <th class="px-4 py-2.5 font-medium">Trạng thái</th>
                        <th class="px-4 py-2.5 text-right font-medium">Lần thử</th>
                        <th class="px-4 py-2.5 font-medium">Thông báo</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="t in rows" :key="t.id" :class="['cursor-pointer hover:bg-muted/40', open?.id === t.id && 'bg-blue-50/50']" data-testid="sync-queue-row" @click="open = t">
                        <td class="whitespace-nowrap px-5 py-3 tabular-nums">{{ timeOnly(t.time) }}</td>
                        <td class="px-4 py-3">
                            <span v-if="t.account" class="inline-flex max-w-44 items-center gap-2 truncate" :title="t.account.name"><MarketplaceLogo :marketplace="t.account.marketplace" :name="t.account.name" size="sm" /> {{ t.account.name }}</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">{{ taskTypes[t.type] }}</td>
                        <td class="max-w-52 truncate px-4 py-3 font-medium" :title="t.object ?? ''">{{ t.object ?? '—' }}</td>
                        <td class="max-w-40 truncate px-4 py-3 text-muted-foreground" :title="t.action ?? ''">{{ t.action }}</td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <Badge :tone="statusInfo[t.status].tone">{{ statusInfo[t.status].label }}</Badge>
                            <span @click.stop><Button v-if="t.can_retry && overview.permissions.operate" size="sm" variant="ghost" class="ml-1 h-7 px-2" title="Thử lại" aria-label="Thử lại" :loading="busy === t.id" data-testid="sync-queue-retry" @click="retry(t)"><RotateCw /></Button></span>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ t.attempts ?? '—' }}</td>
                        <td class="min-w-56 max-w-72 px-4 py-3 text-xs">
                            <span v-if="t.error_label" class="mr-1.5 whitespace-nowrap rounded bg-slate-100 px-1.5 py-0.5 text-slate-600">{{ t.error_label }}</span>
                            <span class="line-clamp-2 text-muted-foreground" :title="t.message ?? ''">{{ t.message }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="load" />
    </Card>

    <TaskDrawer v-if="open" :task="open" :overview="overview" @close="open = null" @changed="onDrawerChanged" />
</template>
