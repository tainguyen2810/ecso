<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { PackageOpen, Plus, RefreshCw, ScanLine, Search, Undo2 } from 'lucide-vue-next';
import { useRouter } from 'vue-router';
import { useToast } from '@/shared/composables/useToast';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import PageHeader from '../../PageHeader.vue';
import Pagination from '../../components/Pagination.vue';
import Tabs from '../../components/Tabs.vue';
import Badge from '@/shared/ui/Badge.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDateTime, formatMoney } from '@/shared/lib/utils';
import { marketplaceNames, returnStatus, returnTypes } from '../orders/labels';
import type { OrderReturnData, OrderSummary } from '@/shared/types';
import { useTenantStore } from '@/shared/stores/tenant';

const tenant = useTenantStore();
const router = useRouter();
const toast = useToast();
const source = ref('');

const rows = ref<OrderReturnData[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(true);
const loadError = ref<string | null>(null);
const status = ref('all');
const type = ref('');
const q = ref('');
const page = ref(1);

const tabs = computed(() => [{ value: 'all', label: 'Tất cả đơn trả' }, { value: 'pending_receipt', label: 'Chưa nhận hàng' }, ...['received', 'in_transit', 'requested', 'approved', 'inspected', 'closed', 'rejected'].map((s) => ({ value: s, label: returnStatus[s]!.label }))]);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get('/returns', { params: { status: status.value === 'all' ? undefined : status.value, type: type.value || undefined, source: source.value || undefined, q: q.value || undefined, page: page.value } });
        rows.value = data.data;
        meta.value = data.meta;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
let timer: ReturnType<typeof setTimeout> | undefined;
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(() => ((page.value = 1), load()), 300);
});
watch([status, type, source], () => ((page.value = 1), load()));

// Đợt 6 "Quét hàng hoàn": a scanner types the parcel's tracking number and Enter; the return opens, marked received.
const scanCode = ref('');
const scanning = ref(false);
async function scan() {
    const code = scanCode.value.trim();
    if (!code) return;
    scanning.value = true;
    try {
        const { data } = await api.post('/returns/scan', { code });
        scanCode.value = '';
        toast.success(`Đã nhận hàng hoàn ${data.data.number}. Kiểm hàng để nhập lại kho.`);
        router.push(`/returns/${data.data.id}`);
    } catch (e) {
        toast.error((e as ApiError).message);
        scanCode.value = '';
    } finally {
        scanning.value = false;
    }
}

// "Lấy yêu cầu từ sàn": reads Shopee / TikTok return requests now (they also come in by themselves every 30 minutes).
const syncing = ref(false);
async function syncSan() {
    syncing.value = true;
    try {
        const { data } = await api.post('/returns/sync');
        const d = data.data as { read: number; created: number; failed: string[] };
        if (d.failed.length) toast.error(d.failed.join(' · '));
        else toast.success(d.created ? `Có ${d.created} yêu cầu trả hàng mới từ sàn.` : 'Không có yêu cầu trả hàng mới trên sàn.');
        load();
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        syncing.value = false;
    }
}

// "Tạo đơn trả hàng": pick a delivered or completed order, then the return form opens on that order.
const pickOpen = ref(false);
const pickQ = ref('');
const pickRows = ref<OrderSummary[]>([]);
const pickLoading = ref(false);
async function loadPick() {
    pickLoading.value = true;
    try {
        const { data } = await api.get('/orders', { params: { tab: 'done', q: pickQ.value || undefined, per_page: 20 } });
        pickRows.value = data.data;
    } finally {
        pickLoading.value = false;
    }
}
let pickTimer: ReturnType<typeof setTimeout> | undefined;
watch(pickQ, () => {
    clearTimeout(pickTimer);
    pickTimer = setTimeout(loadPick, 300);
});
watch(pickOpen, (v) => v && loadPick());
</script>

<template>
    <PageHeader title="Danh sách đơn trả hàng" description="Hàng hoàn từ khách, đơn hủy sau khi gửi, giao thất bại. Kho chỉ được cộng lại sau khi kiểm hàng, theo số lượng còn bán được.">
        <Button variant="outline" :loading="syncing" data-testid="returns-sync" @click="syncSan"><RefreshCw /> Lấy yêu cầu từ sàn</Button>
        <Button v-if="tenant.can('returns.inspect')" data-testid="new-return" @click="pickOpen = true"><Plus /> Tạo đơn trả hàng</Button>
    </PageHeader>
    <Card v-if="tenant.can('returns.inspect')" class="mb-4 p-4" data-testid="return-scan-card">
        <div class="flex flex-wrap items-center gap-3">
            <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-blue-50 text-blue-600"><PackageOpen class="size-5" /></span>
            <div class="min-w-48 flex-1">
                <p class="font-medium">Nhận hàng hoàn</p>
                <p class="text-xs text-muted-foreground">Quét mã vận đơn trên gói hàng hoàn (hoặc gõ mã đơn), phiếu hoàn mở ra để kiểm hàng: còn bán được thì cộng lại kho.</p>
            </div>
            <form class="relative w-full sm:w-80" @submit.prevent="scan">
                <ScanLine class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                <Input v-model="scanCode" class="pl-8" placeholder="Quét mã vận đơn hàng hoàn…" :disabled="scanning" data-testid="return-scan" aria-label="Mã vận đơn hàng hoàn" />
            </form>
        </div>
    </Card>
    <Card>
        <div class="border-b px-3"><Tabs v-model="status" :tabs="tabs" variant="underline" /></div>
        <div class="space-y-3 border-b p-4">
            <div class="flex flex-wrap gap-2">
                <div class="relative min-w-60 flex-1">
                    <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                    <Input v-model="q" class="pl-8" placeholder="Tìm theo mã đơn trả, mã đơn hàng…" />
                </div>
                <Select v-model="source" class="w-40" :options="[{ value: '', label: 'Mọi nguồn' }, { value: 'marketplace', label: 'Yêu cầu từ sàn' }]" aria-label="Nguồn" data-testid="return-source" />
                <Select v-model="type" class="w-48" :options="[{ value: '', label: 'Mọi loại' }, ...Object.entries(returnTypes).map(([value, label]) => ({ value, label }))]" aria-label="Loại" />
            </div>
        </div>
        <div v-if="loading && !rows.length" class="space-y-3 p-4"><Skeleton v-for="i in 5" :key="i" class="h-10" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <EmptyState v-else-if="!rows.length" :icon="Undo2" title="Chưa có phiếu trả hàng" description="Phiếu được tạo tự động khi đơn bị hủy sau khi gửi hoặc giao thất bại, hoặc tạo thủ công từ trang chi tiết đơn." />
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm" data-testid="return-table">
                <thead class="whitespace-nowrap border-b bg-slate-50 text-left text-xs font-semibold text-foreground/80">
                    <tr>
                        <th class="px-4 py-3">Mã đơn trả hàng</th>
                        <th class="px-3 py-3">Mã đơn hàng</th>
                        <th class="px-3 py-3">Mã đơn đổi hàng</th>
                        <th class="px-3 py-3">Khách hàng</th>
                        <th class="px-3 py-3">Trạng thái</th>
                        <th class="px-3 py-3">Hoàn tiền</th>
                        <th class="px-3 py-3 text-right">Tiền hoàn</th>
                        <th class="whitespace-nowrap px-3 py-3">Ngày nhận hàng</th>
                        <th class="px-4 py-3">Lý do trả hàng</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="r in rows" :key="r.id" class="cursor-pointer hover:bg-blue-50/40" @click="$router.push(`/returns/${r.id}`)">
                        <td class="px-4 py-3">
                            <span class="font-medium text-primary">{{ r.number }}</span>
                            <div class="text-[11px] text-muted-foreground">{{ returnTypes[r.type] }} · {{ r.lines_count }} dòng<span v-if="r.external_return_id" class="ml-1 rounded bg-orange-50 px-1 font-medium text-orange-700">từ sàn</span></div>
                        </td>
                        <td class="px-3 py-3" @click.stop>
                            <RouterLink v-if="r.order" :to="`/orders/${r.order.id}`" class="inline-flex items-center gap-1.5 text-primary hover:underline">
                                <MarketplaceLogo :marketplace="r.order.marketplace" :name="marketplaceNames[r.order.marketplace]" size="sm" />{{ r.order.external_order_id }}
                            </RouterLink>
                        </td>
                        <td class="px-3 py-3" @click.stop>
                            <RouterLink v-if="r.exchange_order" :to="`/orders/${r.exchange_order.id}`" class="text-primary hover:underline">{{ r.exchange_order.external_order_id }}</RouterLink>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="max-w-48 truncate px-3 py-3">{{ r.order?.customer ?? 'Khách lẻ' }}</td>
                        <td class="whitespace-nowrap px-3 py-3">
                            <Badge :tone="returnStatus[r.status]?.tone">{{ returnStatus[r.status]?.label }}</Badge>
                            <div v-if="r.respond_by && r.status === 'requested'" class="mt-1 text-[11px] font-medium text-amber-700">Trả lời trên sàn trước {{ formatDateTime(r.respond_by) }}</div>
                        </td>
                        <td class="px-3 py-3 text-xs">
                            <span class="inline-flex items-center gap-1.5" :title="Number(r.refund_amount ?? 0) > 0 ? 'Có hoàn tiền' : 'Không hoàn tiền'">
                                <span :class="cn('size-2.5 rounded-full border-2', Number(r.refund_amount ?? 0) > 0 ? 'border-emerald-500 bg-emerald-500' : 'border-slate-400')" />
                                <span class="text-muted-foreground">{{ Number(r.refund_amount ?? 0) > 0 ? 'Có' : 'Không' }}</span>
                            </span>
                        </td>
                        <td class="px-3 py-3 text-right font-semibold tabular-nums">{{ formatMoney(r.refund_amount ?? 0) }}</td>
                        <td class="whitespace-nowrap px-3 py-3 tabular-nums text-muted-foreground">{{ r.received_at ? formatDateTime(r.received_at) : '—' }}</td>
                        <td class="max-w-56 truncate px-4 py-3 text-muted-foreground" :title="r.reason ?? ''">{{ r.reason ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="(p) => ((page = p), load())" />
    </Card>

    <Dialog v-model:open="pickOpen" title="Chọn đơn hàng để trả" description="Đơn đã giao hoặc hoàn tất." size="xl">
        <div class="relative mb-3">
            <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
            <Input v-model="pickQ" class="pl-8" placeholder="Tìm kiếm theo mã đơn hàng, tên, SĐT khách hàng" data-testid="return-order-search" />
        </div>
        <div class="overflow-x-auto rounded-md border">
            <table class="w-full text-sm" data-testid="return-order-pick">
                <thead class="whitespace-nowrap border-b bg-slate-50 text-left text-xs font-semibold text-foreground/80">
                    <tr><th class="px-3 py-2.5">Mã đơn hàng</th><th class="px-3 py-2.5">Ngày tạo</th><th class="px-3 py-2.5">Nhân viên</th><th class="px-3 py-2.5">Khách hàng</th><th class="px-3 py-2.5 text-right">Tổng tiền</th><th class="px-3 py-2.5 text-center">Thao tác</th></tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="o in pickRows" :key="o.id" class="hover:bg-blue-50/40">
                        <td class="px-3 py-2.5"><span class="inline-flex items-center gap-1.5"><MarketplaceLogo :marketplace="o.marketplace" :name="marketplaceNames[o.marketplace]" size="sm" />{{ o.external_order_id }}</span></td>
                        <td class="whitespace-nowrap px-3 py-2.5 tabular-nums">{{ formatDateTime(o.placed_at) }}</td>
                        <td class="px-3 py-2.5 text-muted-foreground">{{ o.assignee?.name ?? '—' }}</td>
                        <td class="max-w-48 truncate px-3 py-2.5">{{ o.customer?.name ?? o.buyer_name ?? 'Khách lẻ' }}</td>
                        <td class="px-3 py-2.5 text-right tabular-nums">{{ formatMoney(o.total) }}</td>
                        <td class="px-3 py-2.5 text-center"><RouterLink :to="{ path: `/orders/${o.id}`, query: { return: '1' } }" class="font-medium text-primary hover:underline">Đổi trả</RouterLink></td>
                    </tr>
                    <tr v-if="!pickRows.length"><td colspan="6" class="px-3 py-8 text-center text-muted-foreground">{{ pickLoading ? 'Đang tải…' : 'Không có đơn đã giao hoặc hoàn tất khớp tìm kiếm.' }}</td></tr>
                </tbody>
            </table>
        </div>
    </Dialog>
</template>
