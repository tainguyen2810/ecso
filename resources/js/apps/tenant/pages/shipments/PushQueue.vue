<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { PackageCheck, Plug, Search, TriangleAlert } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError, idempotent, newIdempotencyKey } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { cn, formatDateTime, formatMoney } from '@/shared/lib/utils';
import Pagination from '../../components/Pagination.vue';
import { marketplaceNames } from '../orders/labels';
import CarrierPushDialog from './CarrierPushDialog.vue';
import { carrierMarks, choiceOf, openLabels, shortDate, type CarrierAccountData, type CarrierChoice, type QueueRow, type QuoteResult } from './carriers';

/**
 * "Chờ đẩy": confirmed orders the shop ships itself that are not at a carrier yet. Each row shows every connected
 * account's price (cheapest service first, the cheapest overall picked); tick several and push them in one go.
 */
const props = defineProps<{ accounts: CarrierAccountData[] }>();
const emit = defineEmits<{ connect: []; pushed: [] }>();
const tenant = useTenantStore();
const toast = useToast();

const rows = ref<QueueRow[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(true);
const loadError = ref<string | null>(null);
const q = ref('');
const page = ref(1);
const quotes = ref<Record<string, QuoteResult | 'loading' | string>>({});
const picks = ref<Record<string, CarrierChoice>>({});
const selected = ref<Set<string>>(new Set());
const connected = computed(() => props.accounts.some((a) => a.status === 'connected'));

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get('/carrier-shipments/queue', { params: { q: q.value || undefined, page: page.value } });
        rows.value = data.data;
        meta.value = data.meta;
        selected.value = new Set([...selected.value].filter((id) => rows.value.some((r) => r.id === id)));
        void loadQuotes();
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}

/** Asks for prices a few orders at a time, so a page of 20 does not hit the carrier all at once. */
async function loadQuotes() {
    if (!connected.value) return;
    const todo = rows.value.filter((r) => r.has_address && !(r.id in quotes.value));
    todo.forEach((r) => (quotes.value[r.id] = 'loading'));
    const worker = async () => {
        for (let r = todo.shift(); r; r = todo.shift()) await quoteRow(r.id);
    };
    await Promise.all([worker(), worker(), worker()]);
}
async function quoteRow(id: string) {
    try {
        const { data } = await api.post('/shipping/quotes', { order_id: id });
        const res = data.data as QuoteResult;
        quotes.value[id] = res;
        if (res.cheapest && !picks.value[id]) picks.value[id] = choiceOf(res.cheapest);
    } catch (e) {
        quotes.value[id] = (e as ApiError).message;
    }
}
onMounted(load);
watch(() => props.accounts.map((a) => a.id + a.status).join(), () => ((quotes.value = {}), (picks.value = {}), load()));
let timer: ReturnType<typeof setTimeout> | undefined;
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(() => ((page.value = 1), load()), 300);
});

/** The cheapest service of each account: one chip per account, like the mockup. */
function chips(id: string) {
    const res = quotes.value[id];
    if (!res || typeof res === 'string') return [];
    return res.accounts.filter((a) => a.services.length).map((a) => ({ ...a.services[0]!, account_id: a.account.id, carrier: a.account.carrier, carrier_label: a.account.carrier_label }));
}
const cheapestId = (id: string) => {
    const res = quotes.value[id];
    return res && typeof res !== 'string' && res.cheapest ? res.cheapest.account_id + res.cheapest.code : null;
};
const quoteErrors = (id: string) => {
    const res = quotes.value[id];
    if (!res || typeof res === 'string') return typeof res === 'string' && res !== 'loading' ? res : null;
    return res.accounts.every((a) => !a.services.length) ? (res.accounts.find((a) => a.error)?.error ?? 'Không hãng nào nhận tuyến này.') : null;
};

function quickPick(kind: 'cheapest' | 'fastest') {
    for (const r of rows.value) {
        const res = quotes.value[r.id];
        if (res && typeof res !== 'string' && res[kind]) picks.value[r.id] = choiceOf(res[kind]!);
    }
}
function toggle(id: string) {
    const s = new Set(selected.value);
    if (s.has(id)) s.delete(id);
    else s.add(id);
    selected.value = s;
}
const pickable = computed(() => rows.value.filter((r) => picks.value[r.id]));
const allChecked = computed(() => pickable.value.length > 0 && pickable.value.every((r) => selected.value.has(r.id)));
function toggleAll() {
    selected.value = allChecked.value ? new Set() : new Set(pickable.value.map((r) => r.id));
}
const chosen = computed(() => rows.value.filter((r) => selected.value.has(r.id) && picks.value[r.id]));
const summary = computed(() => {
    const by: Record<string, number> = {};
    chosen.value.forEach((r) => (by[picks.value[r.id]!.carrier_label] = (by[picks.value[r.id]!.carrier_label] ?? 0) + 1));
    return {
        carriers: Object.entries(by).map(([k, n]) => `${k} ${n}`).join(' · '),
        fee: chosen.value.reduce((s, r) => s + Number(picks.value[r.id]!.fee), 0),
        cod: chosen.value.reduce((s, r) => s + Number(r.cod_amount), 0),
    };
});

const busy = ref(false);
const results = ref<Record<string, string>>({});
async function pushSelected(print: boolean) {
    busy.value = true;
    results.value = {};
    const list = chosen.value.map((r) => ({ id: r.id, ...picks.value[r.id]! }));
    const done: { id: string; account: string }[] = [];
    try {
        for (let i = 0; i < list.length; i += 20) {
            const part = list.slice(i, i + 20);
            const { data } = await api.post('/carrier-shipments/bulk', { orders: part.map((p) => ({ id: p.id, account_id: p.account_id, service: p.service, service_name: p.service_name })) }, idempotent(newIdempotencyKey()));
            for (const res of data.data.results as { id: string; ok: boolean; shipment_id: string | null; error: string | null }[]) {
                if (res.ok && res.shipment_id) done.push({ id: res.shipment_id, account: part.find((p) => p.id === res.id)!.account_id });
                else results.value[res.id] = res.error ?? 'Hãng không nhận đơn.';
            }
        }
        const failed = Object.keys(results.value).length;
        if (done.length) toast.success(`Đã đẩy ${done.length} đơn${failed ? `, ${failed} đơn bị từ chối (xem lý do trên dòng)` : ''}`);
        else if (failed) toast.error(`${failed} đơn bị từ chối. Xem lý do trên từng dòng.`);
        if (print && done.length) {
            // Labels come from each carrier account separately.
            const byAccount = new Map<string, string[]>();
            done.forEach((d) => byAccount.set(d.account, [...(byAccount.get(d.account) ?? []), d.id]));
            for (const ids of byAccount.values()) {
                try {
                    await openLabels(ids);
                } catch (e) {
                    toast.error((e as ApiError).field('ids') ?? (e as ApiError).message);
                }
            }
        }
        selected.value = new Set(Object.keys(results.value));
        if (done.length) emit('pushed');
        const keep = results.value;
        await load();
        results.value = keep;
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = false;
    }
}

const detail = ref<QueueRow | null>(null);
const detailOpen = ref(false);
function openDetail(r: QueueRow) {
    detail.value = r;
    detailOpen.value = true;
}
function afterSingle() {
    emit('pushed');
    void load();
}
const canPush = computed(() => tenant.can('shipments.manage'));
</script>

<template>
    <div data-testid="push-queue">
        <div v-if="!connected" class="border-b bg-blue-50/50 p-4">
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <Plug class="size-5 text-blue-500" />
                <div class="min-w-0 flex-1">
                    <b>Chưa kết nối hãng vận chuyển.</b> Kết nối tài khoản GHN của shop (miễn phí) để so sánh giá và đẩy đơn ngay tại đây.
                    <div class="text-xs text-muted-foreground">Viettel Post, J&T: sắp có. SPX: tạo trên app SPX rồi nhập mã (nút "Nhập mã vận đơn từ file").</div>
                </div>
                <Button size="sm" data-testid="queue-connect" @click="emit('connect')"><Plug /> Kết nối hãng</Button>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2 border-b p-4">
            <div class="relative min-w-60 flex-1">
                <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                <Input v-model="q" class="pl-8" placeholder="Tìm mã đơn, tên, SĐT" data-testid="queue-search" />
            </div>
            <span class="ml-auto text-xs text-muted-foreground">Chọn hãng nhanh:</span>
            <Button size="sm" variant="outline" :disabled="!connected" data-testid="quick-cheapest" @click="quickPick('cheapest')">Rẻ nhất</Button>
            <Button size="sm" variant="outline" :disabled="!connected" data-testid="quick-fastest" @click="quickPick('fastest')">Nhanh nhất</Button>
        </div>

        <div v-if="loading && !rows.length" class="space-y-3 p-4"><Skeleton v-for="i in 4" :key="i" class="h-12" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <EmptyState v-else-if="!rows.length" :icon="PackageCheck" title="Không có đơn chờ đẩy" description="Đơn bán riêng và đơn website đã xác nhận, chưa có vận đơn sẽ hiện ở đây." />
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm" data-testid="queue-table">
                <thead class="whitespace-nowrap border-b bg-slate-50 text-left text-xs font-semibold text-foreground/80">
                    <tr>
                        <th class="w-10 px-4 py-3"><input type="checkbox" :checked="allChecked" :disabled="!pickable.length" class="accent-blue-600" aria-label="Chọn tất cả" @change="toggleAll" /></th>
                        <th class="px-2 py-3">Đơn</th>
                        <th class="px-3 py-3">Người nhận · khu vực</th>
                        <th class="px-3 py-3 text-right">Khối lượng</th>
                        <th class="px-3 py-3 text-right">COD</th>
                        <th class="px-3 py-3">So sánh giá · bấm để chọn</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="r in rows" :key="r.id" :class="cn(selected.has(r.id) && 'bg-blue-50/40')" data-testid="queue-row">
                        <td class="px-4 py-3"><input type="checkbox" :checked="selected.has(r.id)" :disabled="!picks[r.id]" class="accent-blue-600" :aria-label="`Chọn ${r.external_order_id}`" @change="toggle(r.id)" /></td>
                        <td class="whitespace-nowrap px-2 py-3">
                            <RouterLink :to="`/orders/${r.id}`" class="inline-flex items-center gap-1.5 font-medium hover:underline">
                                <MarketplaceLogo :marketplace="r.marketplace" :name="marketplaceNames[r.marketplace]" size="sm" />{{ r.external_order_id }}
                            </RouterLink>
                            <div class="text-[11px] text-muted-foreground">{{ r.warehouse?.name ?? '' }}<template v-if="r.placed_at"> · {{ formatDateTime(r.placed_at) }}</template></div>
                        </td>
                        <td class="max-w-64 px-3 py-3">
                            <div class="truncate">{{ r.recipient ?? '—' }}</div>
                            <div v-if="r.has_address" class="truncate text-[11px] text-muted-foreground">{{ r.area ?? r.address }}</div>
                            <RouterLink v-else :to="`/orders/${r.id}`" class="inline-flex items-center gap-1 text-[11px] text-amber-700 hover:underline"><TriangleAlert class="size-3" /> Thiếu tỉnh/phường · Sửa</RouterLink>
                            <div v-if="results[r.id] ?? r.failed_shipment?.error" class="text-[11px] text-destructive" data-testid="queue-error">{{ results[r.id] ?? `${r.failed_shipment?.carrier_name ?? 'Hãng'} từ chối: ${r.failed_shipment?.error}` }}</div>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">{{ r.weight_g.toLocaleString('vi-VN') }} g</td>
                        <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">{{ Number(r.cod_amount) > 0 ? formatMoney(r.cod_amount) : '0' }}</td>
                        <td class="px-3 py-3">
                            <span v-if="!r.has_address" class="text-xs text-muted-foreground">Sửa địa chỉ để xem giá</span>
                            <span v-else-if="!connected" class="text-xs text-muted-foreground">Kết nối hãng để xem giá</span>
                            <span v-else-if="quotes[r.id] === 'loading'" class="text-xs text-muted-foreground">Đang hỏi giá…</span>
                            <span v-else-if="quoteErrors(r.id)" class="text-xs text-destructive">{{ quoteErrors(r.id) }}</span>
                            <div v-else class="flex flex-wrap gap-1.5">
                                <button
                                    v-for="c in chips(r.id)"
                                    :key="c.account_id + c.code"
                                    :class="cn('inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border px-2 py-1 text-xs', picks[r.id]?.account_id === c.account_id ? 'border-blue-500 ring-1 ring-blue-200' : 'hover:bg-muted')"
                                    :data-testid="`queue-chip-${c.carrier}`"
                                    @click="(picks[r.id] = choiceOf(c)), selected.add(r.id), (selected = new Set(selected))"
                                >
                                    <span :class="cn('rounded px-1 text-[9px] font-bold text-white', carrierMarks[c.carrier]?.bg ?? 'bg-slate-500')">{{ carrierMarks[c.carrier]?.short ?? c.carrier }}</span>
                                    <b class="tabular-nums">{{ formatMoney(c.fee).replace(' ₫', '') }}</b>
                                    <span class="text-muted-foreground">{{ shortDate(c.expected_at) }}</span>
                                    <span v-if="cheapestId(r.id) === c.account_id + c.code" class="font-semibold text-emerald-600">RẺ NHẤT</span>
                                </button>
                                <span v-if="picks[r.id] && !chips(r.id).some((c) => c.account_id === picks[r.id]!.account_id && c.code === picks[r.id]!.service)" class="self-center text-[11px] text-blue-700">{{ picks[r.id]!.carrier_label }} · {{ picks[r.id]!.service_name }} {{ formatMoney(picks[r.id]!.fee) }}</span>
                            </div>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <button v-if="canPush && connected && r.has_address" class="text-xs text-muted-foreground hover:text-primary hover:underline" data-testid="queue-detail" @click="openDetail(r)">Chi tiết</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="(p) => ((page = p), load())" />

        <div v-if="chosen.length && canPush" class="sticky bottom-3 z-10 mx-4 mb-4 flex flex-wrap items-center gap-3 rounded-xl bg-slate-900 px-4 py-3 text-sm text-white shadow-lg" data-testid="queue-bulk-bar">
            <b>Đã chọn {{ chosen.length }} đơn</b>
            <span class="text-slate-300">{{ summary.carriers }} · tổng phí {{ formatMoney(summary.fee) }} · tổng COD {{ formatMoney(summary.cod) }}</span>
            <span class="ml-auto flex gap-2">
                <Button size="sm" variant="ghost" class="text-white hover:bg-white/10" @click="selected = new Set()">Bỏ chọn</Button>
                <Button size="sm" variant="ghost" class="border border-white/20 text-white hover:bg-white/10" :disabled="busy" data-testid="queue-push" @click="pushSelected(false)">Đẩy đơn</Button>
                <Button size="sm" :disabled="busy" :loading="busy" data-testid="queue-push-print" @click="pushSelected(true)">Đẩy và in nhãn A5</Button>
            </span>
        </div>
    </div>
    <CarrierPushDialog v-if="detail" v-model:open="detailOpen" :order-id="detail.id" :order-code="detail.external_order_id" @pushed="afterSingle" />
</template>
