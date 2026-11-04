<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Download, Minus, Plus, ReceiptText, Search } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Pagination from '../../components/Pagination.vue';
import Tabs from '../../components/Tabs.vue';
import RangePicker from '../reports/RangePicker.vue';
import { downloadCsv, presetRange, toCsv } from '../reports/range';
import CashNav from './CashNav.vue';
import VoucherDetail from './VoucherDetail.vue';
import VoucherDialog from './VoucherDialog.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime, formatMoney } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { paymentMethods } from '../orders/labels';
import { directionLabels, partyKinds, sourceLabels, voucherCode, type Direction, type VoucherRow, type VoucherType } from './voucher';

const tenant = useTenantStore();
const route = useRoute();
const router = useRouter();

// Filters live in the URL so a filtered list can be bookmarked or shared.
const qs = (k: string) => (typeof route.query[k] === 'string' ? (route.query[k] as string) : '');
const initial = presetRange('month');
const direction = ref(qs('direction') || 'all');
const status = ref(qs('status') || 'all');
const range = ref({ from: qs('from') || initial.from, to: qs('to') || initial.to });
const q = ref(qs('q'));
const type = ref(qs('type'));
const method = ref(qs('method'));
const partyKind = ref(qs('party_kind'));
const page = ref(Number(qs('page')) || 1);

const rows = ref<VoucherRow[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const totals = ref({ count: 0, in: 0, out: 0 });
const types = ref<VoucherType[]>([]);
const loading = ref(true);
const loadError = ref<string | null>(null);
const creating = ref<Direction | null>(null);
const creatingOpen = computed({ get: () => creating.value !== null, set: (v) => !v && (creating.value = null) });
const selected = ref<VoucherRow | null>(null);
const detailOpen = ref(false);
const exporting = ref(false);

const params = () => ({
    direction: direction.value === 'all' ? undefined : direction.value,
    status: status.value === 'all' ? undefined : status.value,
    from: range.value.from || undefined,
    to: range.value.to || undefined,
    q: q.value || undefined,
    type: type.value || undefined,
    method: method.value || undefined,
    party_kind: partyKind.value || undefined,
});

async function load() {
    loading.value = true;
    loadError.value = null;
    router.replace({ query: { ...params(), page: page.value > 1 ? page.value : undefined } });
    try {
        const { data } = await api.get('/cash-vouchers', { params: { ...params(), page: page.value } });
        rows.value = data.data;
        meta.value = data.meta;
        totals.value = data.totals;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    load();
    api.get('/cash-voucher-types').then(({ data }) => (types.value = data.data)).catch(() => {});
    // Links from the cashbook open a voucher directly: /cash?open=voucher:ULID
    const [source, id] = qs('open').split(':');
    if (source && id) {
        try {
            selected.value = (await api.get(`/cash-vouchers/${source}/${id}`)).data.data;
            detailOpen.value = true;
        } catch {
            /* the list is still shown */
        }
    }
});
let timer: ReturnType<typeof setTimeout> | undefined;
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(() => ((page.value = 1), load()), 300);
});
watch([direction, status, type, method, partyKind, () => range.value.from, () => range.value.to], () => ((page.value = 1), load()));

const typeOptions = computed(() => [
    { value: '', label: 'Mọi loại phiếu' },
    { value: 'order_payment', label: 'Tự động: thu tiền đơn, hoàn tiền' },
    { value: 'supplier_payment', label: 'Tự động: trả nhà cung cấp' },
    { value: 'shift_movement', label: 'Tự động: thu, chi tiền mặt trong ca' },
    ...types.value
        .filter((t) => direction.value === 'all' || t.direction === direction.value)
        .map((t) => ({ value: t.id, label: `${directionLabels[t.direction]}: ${t.name}${t.is_active ? '' : ' (ngừng dùng)'}` })),
]);

function openRow(v: VoucherRow) {
    selected.value = v;
    detailOpen.value = true;
}

function onChanged(v: VoucherRow) {
    selected.value = v;
    load();
}

async function exportCsv() {
    exporting.value = true;
    try {
        const all: VoucherRow[] = [];
        for (let p = 1; p <= 50; p++) {
            const { data } = await api.get('/cash-vouchers', { params: { ...params(), page: p, per_page: 100 } });
            all.push(...data.data);
            if (p >= data.meta.last_page) break;
        }
        downloadCsv(
            `phieu-thu-chi-${range.value.from}-${range.value.to}.csv`,
            toCsv(
                ['Mã phiếu', 'Loại', 'Loại phiếu', 'Ngày ghi nhận', 'Đối tượng', 'Tên', 'Hình thức', 'Thu', 'Chi', 'Chứng từ gốc', 'Tham chiếu', 'Diễn giải', 'Tính KQKD', 'Trạng thái', 'Người lập'],
                all.map((v) => [
                    voucherCode(v),
                    directionLabels[v.direction],
                    v.type.name,
                    formatDateTime(v.paid_at),
                    partyKinds[v.party.kind],
                    v.party.name,
                    paymentMethods[v.method] ?? v.method,
                    v.direction === 'receipt' ? v.amount : null,
                    v.direction === 'payment' ? v.amount : null,
                    v.document?.number ?? '',
                    v.reference,
                    v.note,
                    v.source === 'voucher' ? (v.counts_in_pnl ? 'Có' : 'Không') : '',
                    v.voided_at ? `Đã hủy: ${v.void_reason}` : 'Hoàn thành',
                    v.created_by,
                ]),
            ),
        );
    } finally {
        exporting.value = false;
    }
}
</script>

<template>
    <PageHeader title="Thu chi" description="Mọi khoản tiền vào, ra: phiếu lập tay và phiếu tự động từ đơn hàng, trả nhà cung cấp.">
        <template v-if="tenant.can('finance.vouchers')">
            <Button variant="outline" class="border-success/40 text-success hover:bg-success/10" data-testid="new-receipt" @click="creating = 'receipt'"><Plus /> Lập phiếu thu</Button>
            <Button variant="outline" class="border-destructive/40 text-destructive hover:bg-destructive/10" data-testid="new-payment" @click="creating = 'payment'"><Minus /> Lập phiếu chi</Button>
        </template>
    </PageHeader>
    <CashNav />

    <div class="mb-4 grid gap-3 sm:grid-cols-3" data-testid="voucher-totals">
        <Card><div class="p-4"><p class="text-xs text-muted-foreground">Tổng thu (đang lọc)</p><p class="mt-1 text-xl font-semibold tabular-nums text-success">+{{ formatMoney(totals.in) }}</p></div></Card>
        <Card><div class="p-4"><p class="text-xs text-muted-foreground">Tổng chi (đang lọc)</p><p class="mt-1 text-xl font-semibold tabular-nums text-destructive">−{{ formatMoney(totals.out) }}</p></div></Card>
        <Card><div class="p-4"><p class="text-xs text-muted-foreground">Chênh lệch, {{ totals.count }} phiếu hoàn thành</p><p class="mt-1 text-xl font-semibold tabular-nums">{{ formatMoney(totals.in - totals.out) }}</p></div></Card>
    </div>

    <Card>
        <div class="flex flex-wrap items-center gap-2 border-b p-3">
            <Tabs v-model="direction" :tabs="[{ value: 'all', label: 'Tất cả' }, { value: 'receipt', label: 'Phiếu thu' }, { value: 'payment', label: 'Phiếu chi' }]" data-testid="voucher-direction" />
            <Tabs v-model="status" :tabs="[{ value: 'all', label: 'Mọi trạng thái' }, { value: 'active', label: 'Hoàn thành' }, { value: 'voided', label: 'Đã hủy' }]" data-testid="voucher-status" />
            <div class="ml-auto"><RangePicker v-model:from="range.from" v-model:to="range.to" /></div>
        </div>
        <div class="flex flex-wrap gap-2 border-b p-3">
            <div class="relative min-w-60 flex-1">
                <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                <Input v-model="q" class="pl-8" placeholder="Mã phiếu, tên người nộp/nhận, mã đơn, tham chiếu" data-testid="voucher-q" />
            </div>
            <Select v-model="type" class="w-56" :options="typeOptions" aria-label="Loại phiếu" data-testid="voucher-type-filter" />
            <Select v-model="partyKind" class="w-44" :options="[{ value: '', label: 'Mọi đối tượng' }, ...Object.entries(partyKinds).map(([value, label]) => ({ value, label }))]" aria-label="Đối tượng" />
            <Select v-model="method" class="w-40" :options="[{ value: '', label: 'Mọi hình thức' }, ...Object.entries(paymentMethods).map(([value, label]) => ({ value, label }))]" aria-label="Hình thức" />
            <Button variant="outline" :loading="exporting" :disabled="!meta.total" @click="exportCsv"><Download /> Xuất CSV</Button>
        </div>

        <div v-if="loading && !rows.length" class="space-y-3 p-4"><Skeleton v-for="i in 6" :key="i" class="h-10" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <EmptyState v-else-if="!rows.length" :icon="ReceiptText" title="Không có phiếu nào" description="Đổi khoảng ngày hoặc bộ lọc, hoặc lập phiếu thu, phiếu chi mới." />
        <div v-else class="overflow-x-auto" :class="loading && 'opacity-60'">
            <table class="w-full text-sm" data-testid="voucher-table">
                <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Mã phiếu</th>
                        <th class="px-4 py-2.5 font-medium">Ngày ghi nhận</th>
                        <th class="px-4 py-2.5 font-medium">Loại phiếu</th>
                        <th class="px-4 py-2.5 font-medium">Người nộp / nhận</th>
                        <th class="px-4 py-2.5 font-medium">Hình thức</th>
                        <th class="px-4 py-2.5 text-right font-medium">Thu</th>
                        <th class="px-4 py-2.5 text-right font-medium">Chi</th>
                        <th class="px-4 py-2.5 font-medium">Trạng thái</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="v in rows" :key="`${v.source}-${v.id}`" class="cursor-pointer hover:bg-muted/30" :class="v.voided_at && 'text-muted-foreground'" @click="openRow(v)">
                        <td class="px-4 py-2.5">
                            <div class="font-mono text-xs font-medium" :class="v.voided_at && 'line-through'">{{ v.number ?? v.document?.number ?? 'Tự động' }}</div>
                            <div v-if="v.source !== 'voucher'" class="text-xs text-muted-foreground">{{ sourceLabels[v.source] }}</div>
                        </td>
                        <td class="whitespace-nowrap px-4 py-2.5">{{ formatDateTime(v.paid_at) }}<div class="text-xs text-muted-foreground">{{ v.created_by }}</div></td>
                        <td class="px-4 py-2.5">{{ v.type.name }}<div v-if="v.note" class="max-w-56 truncate text-xs text-muted-foreground">{{ v.note }}</div></td>
                        <td class="px-4 py-2.5">{{ v.party.name }}<div class="text-xs text-muted-foreground">{{ partyKinds[v.party.kind] }}</div></td>
                        <td class="px-4 py-2.5">{{ paymentMethods[v.method] ?? v.method }}<div v-if="v.reference" class="max-w-40 truncate text-xs text-muted-foreground">{{ v.reference }}</div></td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-success">{{ v.direction === 'receipt' ? formatMoney(v.amount) : '' }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-destructive">{{ v.direction === 'payment' ? formatMoney(v.amount) : '' }}</td>
                        <td class="px-4 py-2.5"><Badge :tone="v.voided_at ? 'danger' : 'success'">{{ v.voided_at ? 'Đã hủy' : 'Hoàn thành' }}</Badge></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="(p) => ((page = p), load())" />
    </Card>

    <VoucherDialog v-model:open="creatingOpen" :direction="creating ?? 'receipt'" @saved="load" />
    <VoucherDetail v-model:open="detailOpen" :voucher="selected" @changed="onChanged" />
</template>
