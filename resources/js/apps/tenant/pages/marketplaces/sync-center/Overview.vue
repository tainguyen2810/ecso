<script setup lang="ts">
import { computed, ref } from 'vue';
import { AlertTriangle, Clock, KeyRound, PlugZap, RefreshCw, Unplug } from 'lucide-vue-next';
import Alert from '@/shared/ui/Alert.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import KpiCard from '@/shared/ui/KpiCard.vue';
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
</script>

<template>
    <div class="space-y-5">
        <Alert v-for="s in lost" :key="s.id" :icon="AlertTriangle" tone="red" data-testid="sync-center-lost">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                <p class="flex-1">
                    <b>Shop {{ s.name }} mất kết nối.</b> Đơn mới và tồn kho của shop này tạm dừng đồng bộ, dữ liệu không bị mất.
                    <span v-if="s.last_error" class="text-muted-foreground">({{ s.last_error }})</span>
                </p>
                <RouterLink to="/marketplaces" class="font-medium text-rose-700 hover:underline">Kết nối lại ở Shop &amp; tài khoản →</RouterLink>
            </div>
        </Alert>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <KpiCard :icon="PlugZap" label="Kênh đang kết nối" :value="`${formatNumber(data.accounts.active)} / ${formatNumber(data.accounts.total)}`" tone="blue">shop đang hoạt động</KpiCard>
            <KpiCard :icon="Unplug" label="Mất kết nối" :value="formatNumber(data.accounts.disconnected)" :tone="data.accounts.disconnected ? 'red' : 'slate'">
                {{ data.accounts.circuit_open ? `${data.accounts.circuit_open} shop đang tạm ngừng đẩy do lỗi liên tiếp` : 'cần kết nối lại' }}
            </KpiCard>
            <KpiCard :icon="KeyRound" label="Kết nối sắp hết hạn" :value="formatNumber(data.accounts.token_expiring)" :tone="data.accounts.token_expiring ? 'amber' : 'slate'">trong 7 ngày tới</KpiCard>
            <KpiCard :icon="Clock" label="Quá hạn đối soát đơn" :value="formatNumber(data.accounts.reconcile_overdue)" :tone="data.accounts.reconcile_overdue ? 'amber' : 'slate'">shop lâu chưa đọc lại đơn</KpiCard>
        </div>

        <div>
            <div class="mb-2 flex items-baseline justify-between">
                <h2 class="text-sm font-semibold">Tác vụ 24 giờ qua</h2>
                <span class="text-xs text-muted-foreground">Bấm vào ô để mở Hàng đợi đã lọc</span>
            </div>
            <div class="grid gap-3 sm:grid-cols-3 xl:grid-cols-5" data-testid="sync-center-task-tiles">
                <RouterLink v-for="t in tiles" :key="t.status" :to="{ query: { tab: 'queue', status: t.status, since: '24h' } }" class="block rounded-lg bg-card px-4 py-3.5 shadow-card transition hover:border-primary/40">
                    <Badge :tone="statusInfo[t.status].tone">{{ statusInfo[t.status].label }}</Badge>
                    <p :class="['mt-2 text-2xl font-bold tabular-nums', { slate: 'text-foreground', blue: 'text-blue-600', green: 'text-emerald-600', red: t.value ? 'text-rose-600' : 'text-slate-400' }[t.tone]]">{{ formatNumber(t.value) }}</p>
                    <p class="text-[11px] text-muted-foreground">{{ t.note }}</p>
                </RouterLink>
            </div>
        </div>

        <div>
            <h2 class="mb-2 text-sm font-semibold">Tình trạng từng shop</h2>
            <Card>
                <EmptyState v-if="!data.shops.length" :icon="PlugZap" title="Chưa kết nối shop nào" description="Kết nối website hoặc gian hàng ở Shop & tài khoản để bắt đầu đồng bộ." />
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="sync-center-shops">
                        <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-5 py-2.5 font-medium">Shop</th>
                                <th class="px-4 py-2.5 font-medium">Kết nối</th>
                                <th class="px-4 py-2.5 font-medium">Đơn hàng</th>
                                <th class="px-4 py-2.5 font-medium">Tồn kho</th>
                                <th class="px-4 py-2.5 text-right font-medium">Lỗi đơn mở</th>
                                <th class="px-4 py-2.5 text-right font-medium">Tồn lệch</th>
                                <th class="px-5 py-2.5"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="s in data.shops" :key="s.id">
                                <td class="px-5 py-3">
                                    <span class="inline-flex items-center gap-2.5">
                                        <MarketplaceLogo :marketplace="s.marketplace" :name="s.name" />
                                        <span><span class="block font-medium">{{ s.name }}</span><span class="text-xs capitalize text-muted-foreground">{{ s.marketplace }}</span></span>
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <Badge v-if="!s.sync_enabled && s.status === 'connected'" tone="muted">Tắt đẩy tồn</Badge>
                                    <Badge v-else-if="s.circuit_open" tone="warning">Tạm ngừng đẩy</Badge>
                                    <Badge v-else-if="s.token_expires_at && s.status === 'connected'" tone="warning">Kết nối hết hạn {{ formatDate(s.token_expires_at) }}</Badge>
                                    <Badge v-else :tone="shopStatus[s.status]?.tone ?? 'muted'">{{ shopStatus[s.status]?.label ?? s.status }}</Badge>
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    <span class="block">Nhận đơn {{ timeOnly(s.last_webhook_at) }}</span>
                                    <span class="text-muted-foreground">Đối soát {{ timeOnly(s.last_order_poll_at) }}</span>
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    <RouterLink v-if="s.pending_push" :to="{ query: { tab: 'queue', type: 'stock_push', account: s.id, status: 'pending' } }" class="text-primary hover:underline">{{ formatNumber(s.pending_push) }} SKU chờ đẩy</RouterLink>
                                    <span v-else class="text-muted-foreground">Không có SKU chờ đẩy</span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    <RouterLink v-if="s.open_errors" :to="{ query: { tab: 'log', account: s.id } }" class="font-medium text-rose-600 hover:underline">{{ formatNumber(s.open_errors) }}</RouterLink>
                                    <span v-else class="text-muted-foreground">0</span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums" :title="s.stock_drift ? 'Số SKU mà sàn báo khác số EzChat Bán hàng đã đẩy lên gần nhất' : ''">
                                    <span :class="s.stock_drift ? 'font-medium text-amber-600' : 'text-muted-foreground'">{{ formatNumber(s.stock_drift) }}</span>
                                    <button type="button" class="block w-full whitespace-nowrap text-right text-xs text-primary hover:underline" data-testid="stock-check-open" @click="openCheck(s)">
                                        {{ checkLine(s.stock_check) }}
                                    </button>
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <Button v-if="data.permissions.operate" size="sm" variant="outline" :disabled="!canSyncNow(s)" :loading="busy === s.id" data-testid="sync-center-sync-now" @click="syncNow(s)">
                                        <RefreshCw /> Đồng bộ ngay
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
            <p class="mt-2 text-xs text-muted-foreground">
                Mỗi đêm lúc 2 giờ, EzChat Bán hàng đọc lại tồn trên từng sàn, so với tồn đang bán trong phần mềm và đẩy lại số đúng cho SKU lệch. Bản tin 8 giờ sáng báo số SKU đã sửa.
                Tồn lệch là số SKU mà sàn báo khác số EzChat Bán hàng đã đẩy lên gần nhất, thường do ai đó sửa tồn trực tiếp trên sàn. EzChat Bán hàng không cộng hay ghi đè tồn kho nội bộ theo số này; lần đẩy tồn sau sẽ đặt lại số trên sàn.
            </p>
        </div>
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
