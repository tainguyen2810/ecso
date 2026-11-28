<script setup lang="ts">
import { computed, ref } from 'vue';
import { AlertTriangle, CircleCheck, PlugZap, RefreshCw } from 'lucide-vue-next';
import { btn, card, th } from '../channel';
import { marketplaceNames } from '../../orders/labels';
import Alert from '@/shared/ui/Alert.vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDate, formatDateTime, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import type { StockCheckData, SyncOverviewData, SyncShopData } from '@/shared/types';
import { shopStatus, statusInfo, timeOnly } from './labels';

const props = defineProps<{ data: SyncOverviewData }>();
const emit = defineEmits<{ changed: [] }>();
const toast = useToast();
const busy = ref<string | null>(null);

const lost = computed(() => props.data.shops.filter((s) => s.status === 'expired' || s.status === 'error'));
const byStatus = computed(() => props.data.tasks.by_status);
const total = computed(() => Object.values(byStatus.value).reduce((a, b) => a + b, 0));
const successRate = computed(() => (total.value ? Math.round((byStatus.value.success / total.value) * 1000) / 10 : null));

const tiles = computed(() => [
    { status: 'pending', value: byStatus.value.pending + byStatus.value.retry_scheduled, tone: 'slate', note: byStatus.value.retry_scheduled ? `trong đó ${formatNumber(byStatus.value.retry_scheduled)} chờ thử lại` : 'chờ đến lượt xử lý' },
    { status: 'processing', value: byStatus.value.processing, tone: 'blue', note: 'đang đọc đơn từ sàn' },
    { status: 'success', value: byStatus.value.success, tone: 'green', note: successRate.value === null ? 'chưa có tác vụ' : `${successRate.value.toLocaleString('vi-VN')}% tổng số` },
    { status: 'failed', value: byStatus.value.failed, tone: 'red', note: 'sàn từ chối hoặc dữ liệu sai' },
    { status: 'dead_letter', value: byStatus.value.dead_letter, tone: 'red', note: 'đã thử tối đa, cần xem lại' },
] as const);

// Orders are read whatever the stock switch says; the stock check needs stock push on.
const canSyncNow = (s: SyncShopData) => s.status === 'connected';
const canCheckStock = (s: SyncShopData) => s.status === 'connected' && s.sync_enabled;

/** Đợt 16 "Đối soát tồn với sàn": the latest check of a shop and the SKUs that differed. */
const checkShop = ref<SyncShopData | null>(null);
const checkData = ref<StockCheckData | null>(null);
const checkOpen = ref(false);
async function openCheck(s: SyncShopData) {
    checkShop.value = s;
    checkData.value = s.stock_check;
    checkOpen.value = true;
    try {
        const { data } = await api.get(`/marketplace-accounts/${s.id}/stock-check`);
        checkData.value = data.data;
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}
async function runCheck(s: SyncShopData) {
    busy.value = `check-${s.id}`;
    try {
        await api.post(`/marketplace-accounts/${s.id}/stock-check`);
        toast.success(`Đang đối soát tồn của ${s.name}. Kết quả hiện ở đây sau ít phút.`);
        checkOpen.value = false;
        emit('changed');
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = null;
    }
}
const checkLine = (c: StockCheckData | null) => {
    if (!c) return 'Chưa đối soát';
    if (c.status === 'running') return 'Đang đối soát…';
    if (c.status === 'failed') return 'Lần đối soát gần nhất bị lỗi';
    return c.mismatched ? `Lệch ${formatNumber(c.mismatched)}/${formatNumber(c.checked)} SKU, đã sửa` : `Khớp ${formatNumber(c.checked)} SKU`;
};

async function syncNow(s: SyncShopData) {
    busy.value = s.id;
    try {
        await api.post(`/sync-center/accounts/${s.id}/sync-now`);
        toast.success(`Đã xếp lượt đọc đơn của ${s.name}. Kết quả hiện ở Hàng đợi sau ít phút.`);
        emit('changed');
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = null;
    }
}
const dots: Record<string, string> = { slate: 'bg-slate-300', blue: 'bg-blue-500', green: 'bg-emerald-500', red: 'bg-red-500', amber: 'bg-amber-500' };
function conn(s: SyncShopData): { label: string; tone: string } {
    if (s.status !== 'connected') return { label: shopStatus[s.status]?.label ?? s.status, tone: 'red' };
    if (!s.sync_enabled) return { label: 'Tắt đẩy tồn', tone: 'slate' };
    if (s.circuit_open) return { label: 'Tạm ngừng đẩy', tone: 'amber' };
    if (s.token_expires_at) {
        const days = Math.ceil((new Date(s.token_expires_at).getTime() - Date.now()) / 86_400_000);
        return { label: days > 0 ? `Hết hạn ${days} ngày nữa` : `Hết hạn ${formatDate(s.token_expires_at)}`, tone: 'amber' };
    }
    return { label: 'Hoạt động', tone: 'green' };
}

const hourly = computed(() => props.data.hourly ?? []);
const hourMax = computed(() => Math.max(1, ...hourly.value.map((h) => h.n)));
const hourLabel = (iso: string) => new Intl.DateTimeFormat('vi-VN', { hour: '2-digit', minute: '2-digit' }).format(new Date(iso));

const problemList = computed(() =>
    Object.entries(props.data.problems.failed_by_code)
        .filter(([, n]) => n > 0)
        .map(([code, n]) => ({ code, n, label: props.data.error_codes[code]?.label ?? code, hint: props.data.error_codes[code]?.hint ?? '' }))
        .sort((a, b) => b.n - a.n),
);
const problemTotal = computed(() => problemList.value.reduce((a, p) => a + p.n, 0));

const schedule = [
    { label: 'Nhận đơn qua thông báo của sàn', value: 'Tức thì', now: true },
    { label: 'Đối soát đơn sót', value: 'Theo từng shop', now: false },
    { label: 'Đẩy tồn khi kho đổi', value: 'Tức thì', now: true },
    { label: 'Đối soát tồn toàn bộ', value: 'Mỗi đêm 02:00', now: false },
    { label: 'Bản tin đồng bộ', value: '08:00 hằng ngày', now: false },
];
</script>

<template>
    <div class="grid gap-5 2xl:grid-cols-[minmax(0,1fr)_400px]">
        <div class="min-w-0 space-y-5">
            <div v-for="s in lost" :key="s.id" :class="[card, 'flex flex-wrap items-center gap-3 border-red-200 bg-red-50/60 p-4 text-[14px]']" data-testid="sync-center-lost">
                <AlertTriangle class="size-5 shrink-0 text-red-500" />
                <p class="min-w-0 flex-1 text-slate-700">
                    <b class="text-slate-900">Shop {{ s.name }} mất kết nối.</b> Đơn mới và tồn kho của shop này tạm dừng đồng bộ, dữ liệu không bị mất.
                    <span v-if="s.last_error" class="text-slate-500">({{ s.last_error }})</span>
                </p>
                <RouterLink to="/marketplaces" class="font-medium text-red-700 hover:underline">Kết nối lại →</RouterLink>
            </div>

            <section :class="[card, 'p-5']">
                <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
                    <div>
                        <h2 class="text-[17px] font-semibold text-slate-900">Tác vụ 24 giờ qua</h2>
                        <p class="text-[13px] text-slate-500">Bấm một ô để mở Hàng đợi đã lọc</p>
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-3 xl:grid-cols-5" data-testid="sync-center-task-tiles">
                    <RouterLink v-for="t in tiles" :key="t.status" :to="{ query: { tab: 'queue', status: t.status, since: '24h' } }" class="block rounded-[12px] border border-slate-200 px-4 py-3 transition hover:border-blue-300">
                        <span class="flex items-center gap-1.5 text-[13px] text-slate-500"><span :class="['size-2 rounded-full', dots[t.tone]]" />{{ statusInfo[t.status].label }}</span>
                        <p :class="['mt-1 text-[24px] font-bold tabular-nums', t.tone === 'red' && t.value ? 'text-red-600' : 'text-slate-900']">{{ formatNumber(t.value) }}</p>
                        <p class="text-[12px] text-slate-400">{{ t.note }}</p>
                    </RouterLink>
                </div>
                <div v-if="hourly.length" class="mt-5" data-testid="sync-center-hourly">
                    <div class="flex h-36 items-end gap-1">
                        <div v-for="h in hourly" :key="h.at" class="group relative flex h-full flex-1 items-end">
                            <div class="w-full rounded-t-[4px] bg-blue-500/85 transition group-hover:bg-blue-600" :style="{ height: `${Math.max(h.n ? 4 : 1, (h.n / hourMax) * 100)}%` }" />
                            <span class="pointer-events-none absolute -top-7 left-1/2 z-10 hidden -translate-x-1/2 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-[11px] text-white group-hover:block">{{ hourLabel(h.at) }} · {{ formatNumber(h.n) }}</span>
                        </div>
                    </div>
                    <div class="mt-2 flex justify-between text-[12px] text-slate-400">
                        <span v-for="(h, i) in hourly.filter((_, i) => i % 6 === 0).concat(hourly.slice(-1))" :key="i">{{ i === 0 ? `${hourLabel(h.at)} hôm qua` : hourLabel(h.at) }}</span>
                    </div>
                </div>
            </section>

            <section :class="[card, 'overflow-hidden']">
                <h2 class="border-b border-slate-100 px-5 py-4 text-[17px] font-semibold text-slate-900">Tình trạng từng shop</h2>
                <EmptyState v-if="!data.shops.length" :icon="PlugZap" title="Chưa kết nối shop nào" description="Kết nối website hoặc gian hàng ở Shop & tài khoản để bắt đầu đồng bộ." />
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-[14px]" data-testid="sync-center-shops">
                        <thead class="border-b border-slate-100 text-left">
                            <tr>
                                <th :class="[th, 'pl-5']">Shop</th>
                                <th :class="th">Kết nối</th>
                                <th :class="th">Đơn hàng</th>
                                <th :class="th">Tồn kho</th>
                                <th :class="[th, 'text-right']">Lỗi đơn mở</th>
                                <th :class="[th, 'text-right']">Tồn lệch</th>
                                <th :class="th" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="s in data.shops" :key="s.id">
                                <td class="px-4 py-3.5 pl-5">
                                    <span class="inline-flex items-center gap-3">
                                        <MarketplaceLogo :marketplace="s.marketplace" :name="s.name" size="md" />
                                        <span><span class="block font-semibold text-slate-900">{{ s.name }}</span><span class="text-[12px] text-slate-500">{{ marketplaceNames[s.marketplace] ?? s.marketplace }}</span></span>
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3.5">
                                    <span class="inline-flex items-center gap-2 text-slate-700"><span :class="['size-2 rounded-full', dots[conn(s).tone]]" />{{ conn(s).label }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3.5">
                                    <span class="block text-slate-700">Nhận đơn {{ timeOnly(s.last_webhook_at) }}</span>
                                    <span class="text-[12px] text-slate-500">Đối soát {{ timeOnly(s.last_order_poll_at) }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3.5">
                                    <RouterLink v-if="s.pending_push" :to="{ query: { tab: 'queue', type: 'stock_push', account: s.id, status: 'pending' } }" class="inline-flex items-center gap-2 text-blue-600 hover:underline"><span class="size-2 rounded-full bg-amber-500" />{{ formatNumber(s.pending_push) }} SKU chờ đẩy</RouterLink>
                                    <span v-else class="inline-flex items-center gap-2 text-slate-500"><span class="size-2 rounded-full bg-emerald-500" />Không có SKU chờ đẩy</span>
                                </td>
                                <td class="px-4 py-3.5 text-right tabular-nums">
                                    <RouterLink v-if="s.open_errors" :to="{ query: { tab: 'log', account: s.id } }" class="font-semibold text-red-600 hover:underline">{{ formatNumber(s.open_errors) }}</RouterLink>
                                    <span v-else class="text-slate-400">0</span>
                                </td>
                                <td class="px-4 py-3.5 text-right tabular-nums" :title="s.stock_drift ? 'Số SKU mà sàn báo khác số phần mềm đã đẩy lên gần nhất' : ''">
                                    <span :class="s.stock_drift ? 'font-semibold text-amber-600' : 'text-slate-400'">{{ formatNumber(s.stock_drift) }}</span>
                                    <button type="button" class="block w-full whitespace-nowrap text-right text-[12px] text-blue-600 hover:underline" data-testid="stock-check-open" @click="openCheck(s)">{{ checkLine(s.stock_check) }}</button>
                                </td>
                                <td class="px-4 py-3.5 pr-5 text-right">
                                    <button v-if="data.permissions.operate" type="button" :class="btn.small" :disabled="!canSyncNow(s) || busy === s.id" data-testid="sync-center-sync-now" @click="syncNow(s)">
                                        <RefreshCw :class="['size-3.5', busy === s.id && 'animate-spin']" /> Đồng bộ ngay
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="border-t border-slate-100 px-5 py-3 text-[12px] text-slate-500">
                    Mỗi đêm lúc 2 giờ, phần mềm đọc lại tồn trên từng sàn, so với tồn đang bán và đẩy lại số đúng cho SKU lệch. Bản tin 8 giờ sáng báo số SKU đã sửa.
                    Tồn lệch là số SKU mà sàn báo khác số đã đẩy lên gần nhất, thường do ai đó sửa tồn trực tiếp trên sàn; tồn kho trong phần mềm không bị đổi theo số này.
                </p>
            </section>
        </div>

        <aside class="grid content-start gap-5 md:grid-cols-2 2xl:grid-cols-1">
            <section :class="[card, 'overflow-hidden']" data-testid="sync-center-problems">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                    <h2 class="text-[17px] font-semibold text-slate-900">Lỗi cần xử lý</h2>
                    <span v-if="problemList.length" class="rounded-md bg-red-50 px-2 py-0.5 text-[12px] font-semibold text-red-600">{{ formatNumber(problemTotal) }}</span>
                </div>
                <p v-if="!problemList.length" class="flex items-center gap-2 px-5 py-4 text-[14px] text-slate-500"><CircleCheck class="size-4 text-emerald-500" /> Không có lỗi nào cần xử lý.</p>
                <RouterLink v-for="p in problemList" :key="p.code" :to="{ query: { tab: 'problems' } }" class="flex items-start gap-3 border-b border-slate-100 px-5 py-3.5 last:border-0 hover:bg-slate-50">
                    <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-red-50 text-red-500"><AlertTriangle class="size-4" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium text-slate-900">{{ p.label }}</span>
                        <span class="block text-[12px] text-slate-500">{{ p.hint }}</span>
                    </span>
                    <span class="whitespace-nowrap text-[13px] font-medium text-blue-600">{{ formatNumber(p.n) }} →</span>
                </RouterLink>
            </section>

            <section :class="[card, 'p-5']">
                <div class="flex items-start justify-between">
                    <div>
                        <h2 class="text-[17px] font-semibold text-slate-900">Chia tồn cho từng shop</h2>
                        <p class="text-[13px] text-slate-500">Mỗi shop công bố toàn bộ, một phần trăm hoặc tối đa bao nhiêu cái</p>
                    </div>
                    <RouterLink :to="{ query: { tab: 'allocation' } }" class="whitespace-nowrap text-[13px] font-medium text-blue-600 hover:underline">Sửa →</RouterLink>
                </div>
            </section>

            <section :class="[card, 'p-5']">
                <h2 class="mb-2 text-[17px] font-semibold text-slate-900">Lịch đồng bộ</h2>
                <dl class="divide-y divide-slate-100 text-[14px]">
                    <div v-for="r in schedule" :key="r.label" class="flex items-center justify-between gap-3 py-2.5">
                        <dt class="text-slate-600">{{ r.label }}</dt>
                        <dd :class="['whitespace-nowrap rounded-md px-2 py-0.5 text-[12px] font-medium', r.now ? 'bg-blue-50 text-blue-700' : 'bg-slate-100 text-slate-600']">{{ r.value }}</dd>
                    </div>
                </dl>
            </section>
        </aside>
    </div>

    <Dialog v-model:open="checkOpen" :title="`Đối soát tồn · ${checkShop?.name ?? ''}`" :description="checkData?.finished_at ? `Lần gần nhất: ${formatDateTime(checkData.finished_at)}${checkData.trigger === 'manual' ? ' (bấm tay)' : ' (tự động ban đêm)'}` : 'Mỗi đêm lúc 2 giờ, so tồn trên sàn với tồn trong phần mềm.'" size="lg">
        <div data-testid="stock-check-dialog">
            <p v-if="!checkData" class="text-sm text-muted-foreground">Shop này chưa được đối soát. Bấm Đối soát ngay để chạy lần đầu.</p>
            <p v-else-if="checkData.status === 'running'" class="text-sm text-muted-foreground">Đang đọc tồn trên sàn, vui lòng đợi ít phút.</p>
            <Alert v-else-if="checkData.status === 'failed'" :icon="AlertTriangle" tone="red">{{ checkData.error }}</Alert>
            <template v-else>
                <p class="text-sm">
                    Đã so <b>{{ formatNumber(checkData.checked) }}</b> SKU đã liên kết.
                    <template v-if="checkData.mismatched"><b class="text-amber-600">{{ formatNumber(checkData.mismatched) }}</b> SKU lệch, đã đẩy lại tồn đúng lên sàn.</template>
                    <template v-else>Tất cả đều khớp.</template>
                </p>
                <table v-if="checkData.items?.length" class="mt-3 w-full text-sm">
                    <thead class="text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                        <tr><th class="py-1.5 font-medium">Sản phẩm</th><th class="py-1.5 text-right font-medium">Trên sàn</th><th class="py-1.5 text-right font-medium">Đúng là</th></tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="(it, i) in checkData.items" :key="i">
                            <td class="py-2 pr-3"><span class="block">{{ it.name }}</span><span class="font-mono text-xs text-muted-foreground">{{ it.sku }}</span></td>
                            <td :class="['whitespace-nowrap py-2 text-right tabular-nums', it.marketplace_qty > it.ecso_qty ? 'font-medium text-rose-600' : 'text-muted-foreground']">{{ formatNumber(it.marketplace_qty) }}</td>
                            <td class="whitespace-nowrap py-2 text-right font-semibold tabular-nums">{{ formatNumber(it.ecso_qty) }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="checkData.items?.length" class="mt-2 text-xs text-muted-foreground">Số đỏ: sàn đang bán nhiều hơn hàng thật, dễ bị đặt quá số lượng.</p>
            </template>
        </div>
        <template #footer>
            <Button variant="outline" @click="checkOpen = false">Đóng</Button>
            <Button v-if="data.permissions.operate && checkShop" :disabled="!canCheckStock(checkShop)" :loading="busy === `check-${checkShop.id}`" data-testid="stock-check-run" @click="runCheck(checkShop)"><RefreshCw /> Đối soát ngay</Button>
        </template>
    </Dialog>
</template>
