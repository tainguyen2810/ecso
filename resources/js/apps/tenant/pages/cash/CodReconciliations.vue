<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowRight, Banknote, FileUp, Landmark, Smartphone, Truck, Wallet } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import CodImportDialog from './CodImportDialog.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDate, formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import { codStatuses, type CodReconciliation } from './cod';

/** Vận chuyển › Đối soát COD và phí: statements imported from carriers' files, each booking COD collections and the carrier's fees. */
const tenant = useTenantStore();
const rows = ref<CodReconciliation[]>([]);
const loading = ref(true);
const loadError = ref<string | null>(null);
const importing = ref(false);
const detail = ref<CodReconciliation | null>(null);
const detailOpen = computed({ get: () => detail.value !== null, set: (v) => !v && (detail.value = null) });

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        rows.value = (await api.get('/cod-reconciliations')).data.data;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(() => {
    load();
    loadHeld();
    loadFunds();
    loadGap();
});

/** "Chờ hãng trả": delivered COD parcels whose money the carrier still holds (needs orders.view). */
interface HeldParcel {
    id: string;
    carrier_name: string | null;
    tracking_number: string | null;
    cod_amount: string;
    fee_actual: string | null;
    fee_estimated: string | null;
    delivered_at: string | null;
    order?: { id: string; external_order_id: string; channel: string | null };
}
const route = useRoute();
const tab = ref<'waiting' | 'sessions' | 'fee'>(route.query.tab === 'fee' ? 'fee' : 'waiting');
const held = ref<HeldParcel[]>([]);
const heldTotal = ref(0);
const heldByCarrier = ref<{ carrier: string; carrier_name: string; amount: string; count: number }[]>([]);
const carrier = ref('');
const canSeeParcels = computed(() => tenant.can('orders.view'));
async function loadHeld() {
    if (!canSeeParcels.value) return void (tab.value = 'sessions');
    const keep = tab.value === 'fee';
    try {
        const [list, sum] = await Promise.all([api.get('/shipments', { params: { cod: 'collected', carrier: carrier.value || undefined } }), api.get('/shipments/summary')]);
        held.value = list.data.data;
        heldTotal.value = list.data.meta?.total ?? held.value.length;
        heldByCarrier.value = sum.data.data.cod_held ?? [];
        if (!heldByCarrier.value.length && !carrier.value && !keep) tab.value = 'sessions';
    } catch {
        if (!keep) tab.value = 'sessions';
    }
}

/** "Phí ship lệch": parcels the carrier charged more for than it quoted when the order was pushed. */
const gap = ref<HeldParcel[]>([]);
const gapTotal = ref(0);
async function loadGap() {
    if (!canSeeParcels.value) return;
    try {
        const { data } = await api.get('/shipments', { params: { fee: 'gap', per_page: 100 } });
        gap.value = data.data;
        gapTotal.value = data.meta?.total ?? gap.value.length;
    } catch {
        gap.value = [];
    }
}
const gapOf = (p: HeldParcel) => Number(p.fee_actual ?? 0) - Number(p.fee_estimated ?? 0);
const gapSum = computed(() => gap.value.reduce((s, p) => s + gapOf(p), 0));
function pickCarrier(c: string) {
    carrier.value = carrier.value === c ? '' : c;
    loadHeld();
}
const heldAmount = computed(() => heldByCarrier.value.reduce((s, c) => s + Number(c.amount), 0));
const heldCount = computed(() => heldByCarrier.value.reduce((s, c) => s + c.count, 0));
const feeOf = (p: HeldParcel) => Number(p.fee_actual ?? p.fee_estimated ?? 0);
const daysSince = (iso: string | null) => (iso ? Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 86_400_000)) : null);

/** This month's statements: money that reached the account, and the carriers' fees. */
const month = computed(() => {
    const start = new Date();
    start.setDate(1);
    start.setHours(0, 0, 0, 0);
    const mine = rows.value.filter((r) => !r.voided_at && new Date(r.remitted_at) >= start);
    const cod = mine.reduce((s, r) => s + Number(r.total_cod), 0);
    const fee = mine.reduce((s, r) => s + Number(r.total_fee), 0);
    return { transferred: mine.reduce((s, r) => s + Number(r.transferred), 0), times: mine.length, fee, feeRate: cod > 0 ? (fee / cod) * 100 : null };
});

/** Quỹ hiện có: the funds and what is in them (when the member may see balances). */
interface Fund { id: string; kind: string; name: string; account_number?: string | null; balance: number | null; is_active: boolean }
const funds = ref<Fund[]>([]);
async function loadFunds() {
    try {
        funds.value = ((await api.get('/cash-accounts')).data.data as Fund[]).filter((f) => f.is_active);
    } catch {
        funds.value = [];
    }
}
const fundIcon = (kind: string) => (kind === 'cash' ? Banknote : kind === 'bank' ? Landmark : kind === 'ewallet' ? Smartphone : Wallet);
const fundsTotal = computed(() => (funds.value.some((f) => f.balance === null) ? null : funds.value.reduce((s, f) => s + (f.balance ?? 0), 0)));

async function open(r: CodReconciliation) {
    detail.value = (await api.get(`/cod-reconciliations/${r.id}`)).data.data;
}
// Void the whole statement: its order payments and fee voucher are voided with it.
const toast = useToast();
const voiding = ref(false);
const voidReason = ref('');
const voidError = ref<ApiError | null>(null);
const busy = ref(false);
function startVoid() {
    voidReason.value = '';
    voidError.value = null;
    voiding.value = true;
}
async function confirmVoid() {
    busy.value = true;
    voidError.value = null;
    try {
        await api.post(`/cod-reconciliations/${detail.value!.id}/void`, { reason: voidReason.value });
        toast.success(`Đã hủy phiên đối soát ${detail.value!.number}.`);
        voiding.value = false;
        detail.value = (await api.get(`/cod-reconciliations/${detail.value!.id}`)).data.data;
        load();
    } catch (e) {
        voidError.value = e as ApiError;
    } finally {
        busy.value = false;
    }
}
function saved(r: CodReconciliation) {
    load();
    loadHeld();
    loadFunds();
    tab.value = 'sessions';
    detail.value = r;
}
</script>

<template>
    <PageHeader title="Đối soát COD và phí" description="Tiền thu hộ hãng vận chuyển đang giữ, tiền đã về tài khoản và phí ship hãng tính lệch.">
        <Button v-if="tenant.can('finance.vouchers')" data-testid="cod-import" @click="importing = true"><FileUp /> Nhập file đối soát của hãng</Button>
    </PageHeader>

    <div class="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" data-testid="cod-kpis">
        <div class="rounded-xl border bg-card p-4">
            <p class="text-sm text-muted-foreground">Hãng đang giữ</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">{{ canSeeParcels ? formatMoney(heldAmount) : '—' }}</p>
            <p class="mt-0.5 text-xs text-muted-foreground">{{ canSeeParcels ? `${formatNumber(heldCount)} đơn đã giao, chưa trả tiền` : 'cần quyền xem đơn hàng' }}</p>
        </div>
        <div class="rounded-xl border bg-card p-4">
            <p class="text-sm text-muted-foreground">Đã về tài khoản tháng này</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">{{ formatMoney(month.transferred) }}</p>
            <p class="mt-0.5 text-xs text-muted-foreground">{{ formatNumber(month.times) }} lần chuyển</p>
        </div>
        <div class="rounded-xl border bg-card p-4">
            <p class="text-sm text-muted-foreground">Phí hãng bị trừ tháng này</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">{{ formatMoney(month.fee) }}</p>
            <p class="mt-0.5 text-xs text-muted-foreground">{{ month.feeRate === null ? 'chưa có đối soát' : `${month.feeRate.toFixed(1).replace('.', ',')}% tiền thu hộ` }}</p>
        </div>
        <div class="rounded-xl border bg-card p-4">
            <p class="text-sm text-muted-foreground">Phiên đối soát</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">{{ formatNumber(rows.filter((r) => !r.voided_at).length) }}</p>
            <p class="mt-0.5 text-xs text-muted-foreground">gần đây nhất {{ rows[0] ? formatDate(rows[0].remitted_at) : '—' }}</p>
        </div>
    </div>

    <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
    <ErrorState v-if="loadError" :message="loadError" @retry="load" />
    <Card v-else class="min-w-0">
        <div class="flex flex-wrap items-center gap-2 border-b px-4 py-3">
            <button
                v-if="canSeeParcels"
                type="button"
                :class="cn('rounded-full px-3.5 py-1.5 text-sm font-medium', tab === 'waiting' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200')"
                data-testid="cod-tab-waiting"
                @click="tab = 'waiting'"
            >
                Chờ hãng trả ({{ formatNumber(heldCount) }})
            </button>
            <button
                type="button"
                :class="cn('rounded-full px-3.5 py-1.5 text-sm font-medium', tab === 'sessions' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200')"
                data-testid="cod-tab-sessions"
                @click="tab = 'sessions'"
            >
                Phiên đối soát ({{ formatNumber(rows.length) }})
            </button>
            <button
                v-if="canSeeParcels"
                type="button"
                :class="cn('rounded-full px-3.5 py-1.5 text-sm font-medium', tab === 'fee' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200')"
                data-testid="cod-tab-fee"
                @click="tab = 'fee'"
            >
                Phí ship lệch ({{ formatNumber(gapTotal) }})
            </button>
            <div v-if="tab === 'waiting' && heldByCarrier.length > 1" class="ml-auto flex flex-wrap gap-1.5">
                <button
                    v-for="c in heldByCarrier"
                    :key="c.carrier"
                    type="button"
                    :class="cn('rounded-full border px-3 py-1 text-xs font-medium', carrier === c.carrier ? 'border-primary bg-primary/5 text-primary' : 'hover:bg-slate-50')"
                    @click="pickCarrier(c.carrier)"
                >
                    {{ c.carrier_name }} · {{ formatNumber(c.count) }}
                </button>
            </div>
        </div>

        <template v-if="tab === 'waiting'">
            <div v-if="!held.length" class="flex flex-col items-center gap-2 px-6 py-12 text-center text-sm text-muted-foreground">
                <Truck class="size-8" />
                <p>Hãng không còn giữ tiền thu hộ nào của shop.</p>
            </div>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm" data-testid="cod-held">
                    <thead class="border-b bg-slate-50 text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">Đơn · vận đơn</th>
                            <th class="px-4 py-2.5 font-medium">Giao xong</th>
                            <th class="px-4 py-2.5 text-right font-medium">Thu hộ</th>
                            <th class="px-4 py-2.5 text-right font-medium">Phí ship</th>
                            <th class="px-4 py-2.5 text-right font-medium">Sẽ nhận</th>
                            <th class="px-4 py-2.5 font-medium">Tình trạng</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="p in held" :key="p.id" class="hover:bg-muted/30">
                            <td class="px-4 py-3">
                                <RouterLink v-if="p.order" :to="`/orders/${p.order.id}`" class="font-medium text-primary hover:underline">{{ p.order.external_order_id }}</RouterLink>
                                <p class="text-xs text-muted-foreground">{{ p.carrier_name }}<template v-if="p.tracking_number"> · <span class="font-mono">{{ p.tracking_number }}</span></template></p>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-muted-foreground">{{ p.delivered_at ? formatDate(p.delivered_at) : '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ formatMoney(p.cod_amount) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-muted-foreground">{{ feeOf(p) ? `−${formatMoney(feeOf(p))}` : '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums">{{ formatMoney(Number(p.cod_amount) - feeOf(p)) }}</td>
                            <td class="px-4 py-3">
                                <span v-if="(daysSince(p.delivered_at) ?? 0) > 7" class="rounded-full bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700">Quá 7 ngày chưa trả</span>
                                <span v-else class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700">Chờ hãng trả<template v-if="daysSince(p.delivered_at) !== null"> · {{ daysSince(p.delivered_at) }} ngày</template></span>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="heldTotal > held.length" class="border-t px-4 py-2.5 text-xs text-muted-foreground">Đang hiện {{ held.length }} trên {{ formatNumber(heldTotal) }} đơn. Xem đủ ở <RouterLink to="/shipments?cod=collected" class="text-primary hover:underline">Quản lý vận đơn</RouterLink>.</p>
            </div>
        </template>

        <template v-else-if="tab === 'fee'">
            <div v-if="!gap.length" class="flex flex-col items-center gap-2 px-6 py-12 text-center text-sm text-muted-foreground">
                <Truck class="size-8" />
                <p>Không có đơn nào hãng tính phí cao hơn lúc báo giá.</p>
                <p class="max-w-md text-xs">Phí thật của hãng lấy từ hành trình hoặc file đối soát. Đơn đẩy qua hãng mới có phí báo giá để so.</p>
            </div>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm" data-testid="cod-fee-gap">
                    <thead class="border-b bg-slate-50 text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">Đơn · vận đơn</th>
                            <th class="px-4 py-2.5 text-right font-medium">Phí lúc báo giá</th>
                            <th class="px-4 py-2.5 text-right font-medium">Hãng tính</th>
                            <th class="px-4 py-2.5 text-right font-medium">Lệch</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="p in gap" :key="p.id" class="hover:bg-muted/30">
                            <td class="px-4 py-3">
                                <RouterLink v-if="p.order" :to="`/orders/${p.order.id}`" class="font-medium text-primary hover:underline">{{ p.order.external_order_id }}</RouterLink>
                                <p class="text-xs text-muted-foreground">{{ p.carrier_name }}<template v-if="p.tracking_number"> · <span class="font-mono">{{ p.tracking_number }}</span></template></p>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-muted-foreground">{{ formatMoney(p.fee_estimated) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ formatMoney(p.fee_actual) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums text-rose-600">+{{ formatMoney(gapOf(p)) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t bg-slate-50 font-semibold">
                        <tr><td class="px-4 py-2.5" colspan="3">Tổng lệch<template v-if="gapTotal > gap.length"> ({{ gap.length }} trên {{ formatNumber(gapTotal) }} đơn)</template></td><td class="px-4 py-2.5 text-right tabular-nums text-rose-600">+{{ formatMoney(gapSum) }}</td></tr>
                    </tfoot>
                </table>
                <p class="border-t px-4 py-2.5 text-xs text-muted-foreground">Gửi danh sách này cho hãng để khiếu nại nếu khối lượng hoặc kích thước hãng đo khác với gói hàng.</p>
            </div>
        </template>

        <template v-else>
        <div v-if="loading && !rows.length" class="space-y-3 p-4"><Skeleton v-for="i in 4" :key="i" class="h-10" /></div>
        <div v-else-if="!rows.length" class="flex flex-col items-center gap-2 px-6 py-12 text-center text-sm text-muted-foreground">
            <Truck class="size-8" />
            <p>Chưa có phiên đối soát nào.</p>
            <p class="max-w-md text-xs">Tải file đối soát của GHN, Viettel Post, SPX hoặc J&amp;T. Hệ thống tự tìm cột mã vận đơn, tiền thu hộ và phí, khớp với đơn COD theo mã vận đơn, cho xem trước rồi mới ghi.</p>
        </div>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm" data-testid="cod-table">
                <thead class="border-b bg-slate-50 text-left text-xs text-muted-foreground">
                    <tr>
                        <th class="whitespace-nowrap px-4 py-2 font-medium">Phiên</th>
                        <th class="whitespace-nowrap px-4 py-2 font-medium">Hãng</th>
                        <th class="whitespace-nowrap px-4 py-2 font-medium">Ngày tiền về</th>
                        <th class="whitespace-nowrap px-4 py-2 font-medium">Vào quỹ</th>
                        <th class="whitespace-nowrap px-4 py-2 text-right font-medium">Thu hộ</th>
                        <th class="whitespace-nowrap px-4 py-2 text-right font-medium">Phí hãng</th>
                        <th class="whitespace-nowrap px-4 py-2 text-right font-medium">Hãng chuyển</th>
                        <th class="whitespace-nowrap px-4 py-2 text-right font-medium">Khớp</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="r in rows" :key="r.id" :class="['cursor-pointer hover:bg-muted/30', r.voided_at && 'opacity-60']" @click="open(r)">
                        <td class="px-4 py-2.5"><div class="font-mono text-xs font-medium">{{ r.number }} <Badge v-if="r.voided_at" tone="danger">Đã hủy</Badge></div><div class="max-w-48 truncate text-xs text-muted-foreground">{{ r.file_name }}</div></td>
                        <td class="px-4 py-2.5">{{ r.carrier_name }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5">{{ formatDateTime(r.remitted_at) }}<div class="text-xs text-muted-foreground">{{ r.created_by }}</div></td>
                        <td class="px-4 py-2.5">{{ r.account?.name ?? '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums">{{ formatMoney(r.total_cod) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-destructive">−{{ formatMoney(r.total_fee) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-medium tabular-nums">{{ formatMoney(r.transferred) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums">{{ r.matched_count }}/{{ r.lines_count }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        </template>
    </Card>

    <!-- Right: what is in each fund now, and the way to the cashbook. -->
    <div class="space-y-4">
        <Card v-if="funds.length" :caps="false" title="Quỹ hiện có" data-testid="cod-funds">
            <ul class="divide-y px-5 text-sm">
                <li v-for="f in funds" :key="f.id" class="flex items-center gap-3 py-3">
                    <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-600"><component :is="fundIcon(f.kind)" class="size-4" /></span>
                    <span class="min-w-0 flex-1 truncate">{{ f.name }}<span v-if="f.account_number" class="block text-xs text-muted-foreground">{{ f.account_number }}</span></span>
                    <span class="font-medium tabular-nums">{{ f.balance === null ? '—' : formatMoney(f.balance) }}</span>
                </li>
            </ul>
            <div v-if="fundsTotal !== null" class="flex justify-between border-t px-5 py-3 font-semibold"><span>Tổng</span><span class="tabular-nums">{{ formatMoney(fundsTotal) }}</span></div>
        </Card>
        <div class="rounded-xl border bg-card p-5 text-sm">
            <p class="font-medium">Đối soát thế nào?</p>
            <p class="mt-1 text-muted-foreground">Tải file đối soát của GHN, Viettel Post, SPX hoặc J&amp;T. Hệ thống khớp theo mã vận đơn, ghi tiền thu hộ vào đơn và phí của hãng vào sổ quỹ, cho xem trước rồi mới ghi.</p>
            <RouterLink to="/reports/cashbook" class="mt-3 inline-flex items-center gap-1 font-medium text-primary hover:underline">Xem sổ quỹ <ArrowRight class="size-4" /></RouterLink>
        </div>
    </div>
    </div>

    <CodImportDialog v-model:open="importing" @saved="saved" />

    <Dialog v-model:open="detailOpen" :title="detail ? `Đối soát ${detail.number} · ${detail.carrier_name}` : ''" size="xl">
        <div v-if="detail" class="space-y-3 text-sm" data-testid="cod-detail">
            <p v-if="detail.voided_at" class="rounded-md bg-rose-50 px-3 py-2 text-rose-700" data-testid="cod-voided">
                Đã hủy {{ formatDateTime(detail.voided_at) }}<template v-if="detail.voided_by"> bởi {{ detail.voided_by }}</template>: {{ detail.void_reason }}. Các khoản thu trên đơn và phiếu phí đã được hủy theo.
            </p>
            <p class="text-muted-foreground">
                Tiền về {{ formatDateTime(detail.remitted_at) }}, vào quỹ {{ detail.account?.name ?? '—' }}. Đã ghi {{ formatMoney(detail.total_booked) }} vào đơn,
                phí {{ formatMoney(detail.total_fee) }}<template v-if="detail.fee_voucher"> (phiếu {{ detail.fee_voucher.number }})</template>.
            </p>
            <div class="max-h-[60vh] overflow-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                        <tr><th class="px-3 py-2">Mã vận đơn</th><th class="px-3 py-2">Đơn</th><th class="px-3 py-2 text-right">Thu hộ</th><th class="px-3 py-2 text-right">Phí</th><th class="px-3 py-2 text-right">Đã ghi</th><th class="px-3 py-2">Kết quả</th></tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="(l, i) in detail.lines" :key="i">
                            <td class="px-3 py-2 font-mono text-xs">{{ l.tracking }}</td>
                            <td class="px-3 py-2"><RouterLink v-if="l.order" :to="`/orders/${l.order.id}`" class="font-mono text-xs text-primary hover:underline">{{ l.order.number }}</RouterLink><span v-else>—</span></td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ formatMoney(l.cod) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ formatMoney(l.fee) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ formatMoney(l.booked) }}</td>
                            <td class="px-3 py-2"><Badge :tone="codStatuses[l.status].tone">{{ codStatuses[l.status].label }}</Badge><div v-if="l.note" class="text-xs text-muted-foreground">{{ l.note }}</div></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <template v-if="detail && !detail.voided_at && tenant.can('finance.vouchers.void')" #footer>
            <Button variant="outline" class="text-destructive" data-testid="cod-void" @click="startVoid">Hủy phiên đối soát</Button>
        </template>
    </Dialog>

    <Dialog v-model:open="voiding" title="Hủy phiên đối soát" description="Mọi khoản thu COD đã ghi vào đơn và phiếu phí của phiên này sẽ bị hủy theo. Quỹ và đơn trở về như trước khi đối soát.">
        <FormField label="Lý do" :error="voidError?.field('reason')"><Input v-model="voidReason" data-testid="cod-void-reason" placeholder="Ví dụ: nhập nhầm file" /></FormField>
        <p v-if="voidError && !voidError.field('reason')" class="mt-2 text-sm text-destructive">{{ voidError.message }}</p>
        <template #footer>
            <Button variant="outline" @click="voiding = false">Đóng</Button>
            <Button variant="destructive" :loading="busy" :disabled="!voidReason.trim()" data-testid="cod-void-confirm" @click="confirmVoid">Hủy phiên</Button>
        </template>
    </Dialog>
</template>
