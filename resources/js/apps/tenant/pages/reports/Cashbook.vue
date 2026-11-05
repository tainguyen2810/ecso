<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { CircleHelp, Download, HandCoins, Info, Search, Wallet } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import RangePicker from './RangePicker.vue';
import CashNav from '../cash/CashNav.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import KpiCard from '@/shared/ui/KpiCard.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { paymentMethods } from '../orders/labels';
import { downloadCsv, presetRange, toCsv } from './range';
import { useRoute, useRouter } from 'vue-router';
import type { Fund } from '../cash/voucher';

interface Entry {
    paid_at: string;
    type: 'payment' | 'refund' | 'supplier' | 'supplier_refund' | 'voucher' | 'voucher_reversal' | 'transfer_in' | 'transfer_out';
    label: string | null;
    method: string;
    amount: number;
    /** Null without finance.balances. */
    balance: number | null;
    document: { id: string; number: string; kind: 'order' | 'purchase' | 'voucher' | 'transfer' } | null;
    party: string | null;
    reference: string | null;
    created_by: string | null;
}
interface Book {
    opening: number | null;
    in: number;
    out: number;
    closing: number | null;
    by_method: { method: string; in: number; out: number }[];
    count: number;
    truncated: boolean;
    account: { id: string; name: string; kind: string } | null;
    rows: Entry[];
}

const types: Record<Entry['type'], string> = { payment: 'Thu tiền bán hàng', refund: 'Hoàn tiền khách', supplier: 'Chi trả nhà cung cấp', supplier_refund: 'Nhà cung cấp hoàn tiền', voucher: 'Phiếu thu chi', voucher_reversal: 'Hủy phiếu kỳ đã khóa (ghi đảo)', transfer_in: 'Chuyển quỹ đến', transfer_out: 'Chuyển quỹ đi' };
const label = (e: Entry) => ((e.type === 'voucher' || e.type === 'voucher_reversal') && e.label ? e.label : types[e.type]);
const methodName = (m: string) => (m === 'transfer' ? 'Chuyển quỹ' : (paymentMethods[m] ?? m));
const link = (d: NonNullable<Entry['document']>) => (d.kind === 'order' ? `/orders/${d.id}` : d.kind === 'purchase' ? `/purchasing/${d.id}` : `/cash?open=${d.kind}:${d.id}`);
const range = ref(presetRange('month'));
const route = useRoute();
const router = useRouter();
const method = ref('');
// The fund comes from the URL (?account=ULID), so a fund card links straight to its book.
const account = ref(typeof route.query.account === 'string' ? route.query.account : '');
const funds = ref<Fund[]>([]);
const fundOptions = computed(() => [{ value: '', label: 'Tất cả quỹ' }, ...funds.value.map((f) => ({ value: f.id, label: f.is_active ? f.name : `${f.name} (ngừng dùng)` }))]);
const data = ref<Book | null>(null);
const error = ref<string | null>(null);
const loading = ref(false);
const direction = ref<'all' | 'in' | 'out'>('all');
const search = ref('');
const glossary = ref(false);
const directions = { all: 'Tất cả', in: 'Thu', out: 'Chi' } as const;
const terms = [
    ['Tồn đầu kỳ', 'Tiền trong quỹ tính đến hết ngày trước ngày bắt đầu kỳ đang xem.'],
    ['Tổng thu', 'Tiền thu vào trong kỳ: khách trả tiền đơn hàng, thu nợ và các phiếu thu.'],
    ['Tổng chi', 'Tiền chi ra trong kỳ: hoàn tiền cho khách, trả tiền nhà cung cấp và các phiếu chi.'],
    ['Tồn cuối kỳ', 'Tồn đầu kỳ + Tổng thu − Tổng chi, tức tiền trong quỹ tính đến hết ngày cuối kỳ.'],
    ['Tồn quỹ từng dòng', 'Số dư ngay sau khoản thu hoặc chi đó, cộng dồn từ tồn đầu kỳ.'],
    ['Quỹ', 'Nơi tiền đang nằm: két tiền mặt, từng tài khoản ngân hàng, ví điện tử. Chọn một quỹ để xem sổ của riêng quỹ đó, có cả các lần chuyển quỹ.'],
    ['Chuyển quỹ', 'Tiền đổi chỗ giữa các quỹ, ví dụ nộp tiền mặt vào ngân hàng. Không phải thu hay chi nên sổ tất cả quỹ không tính.'],
    ['Hình thức', 'Cách khách trả hoặc mình trả. Lọc theo hình thức thì không tính số dư đầu của quỹ và các lần chuyển quỹ.'],
] as const;

// Tabs and search only narrow the list; the totals and running balance stay those of the whole book.
const rows = computed(() => {
    const q = search.value.trim().toLowerCase();
    return (data.value?.rows ?? []).filter(
        (e) =>
            (direction.value === 'all' || (direction.value === 'in' ? e.amount > 0 : e.amount < 0)) &&
            (!q || [e.document?.number, e.party, e.reference, e.created_by].some((v) => v?.toLowerCase().includes(q))),
    );
});

async function load() {
    loading.value = true;
    error.value = null;
    try {
        router.replace({ query: { ...route.query, account: account.value || undefined } });
        data.value = (await api.get('/reports/cashbook', { params: { ...range.value, method: method.value || undefined, account: account.value || undefined } })).data.data;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(() => {
    load();
    api.get('/cash-accounts').then(({ data }) => (funds.value = data.data)).catch(() => {});
});
watch([range, method, account], load, { deep: true });

function exportCsv() {
    if (!data.value) return;
    downloadCsv(
        `so-quy-${data.value.account?.name || method.value || 'tat-ca'}-${range.value.from}-${range.value.to}.csv`,
        toCsv(
            ['Thời gian', 'Loại', 'Hình thức', 'Chứng từ', 'Đối tượng', 'Tham chiếu', 'Thu', 'Chi', 'Tồn quỹ', 'Người lập'],
            [...data.value.rows].reverse().map((e) => [formatDateTime(e.paid_at), label(e), methodName(e.method), e.document?.number ?? '', e.party, e.reference, e.amount > 0 ? e.amount : null, e.amount < 0 ? -e.amount : null, e.balance, e.created_by]),
        ),
    );
}
</script>

<template>
    <PageHeader title="Thu chi" description="Sổ quỹ: tiền thu từ khách, hoàn tiền, chi trả nhà cung cấp và các phiếu thu chi lập tay.">
        <RangePicker v-model:from="range.from" v-model:to="range.to" />
        <Button variant="outline" size="sm" @click="$router.push('/cash')"><HandCoins /> Lập phiếu thu chi</Button>
        <Button variant="ghost" size="sm" data-testid="cashbook-glossary" @click="glossary = true"><CircleHelp /> Giải thích thuật ngữ</Button>
    </PageHeader>
    <CashNav />
    <ErrorState v-if="error" :message="error" @retry="load" />
    <div v-else-if="!data" class="grid gap-4 md:grid-cols-4"><Skeleton v-for="i in 4" :key="i" class="h-24" /></div>
    <template v-else>
        <div class="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-testid="cashbook-kpis">
            <KpiCard label="Tồn đầu kỳ" :value="data.opening === null ? '•••' : formatMoney(data.opening)" />
            <KpiCard label="Tổng thu" tone="green" :value="'+' + formatMoney(data.in)" />
            <KpiCard label="Tổng chi" tone="red" :value="'−' + formatMoney(data.out)" />
            <KpiCard label="Tồn cuối kỳ" tone="blue" :value="data.closing === null ? '•••' : formatMoney(data.closing)"><template #value><span data-testid="cashbook-closing">{{ data.closing === null ? '•••' : formatMoney(data.closing) }}</span></template></KpiCard>
        </div>
        <Card>
            <div class="flex gap-1 border-b px-3 pt-2" role="tablist">
                <button
                    v-for="(label, key) in directions"
                    :key="key"
                    type="button"
                    role="tab"
                    :aria-selected="direction === key"
                    :data-testid="`cashbook-tab-${key}`"
                    class="-mb-px border-b-2 px-3 py-2 text-sm"
                    :class="direction === key ? 'border-primary font-medium text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'"
                    @click="direction = key"
                >
                    {{ label }}
                </button>
            </div>
            <div class="flex flex-wrap items-center gap-2 border-b p-3">
                <div class="relative w-full sm:w-64">
                    <Search class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input v-model="search" class="pl-8" placeholder="Tìm chứng từ, đối tượng, tham chiếu" aria-label="Tìm trong sổ quỹ" data-testid="cashbook-search" />
                </div>
                <Select v-model="account" class="w-52" :options="fundOptions" aria-label="Quỹ" data-testid="cashbook-account" />
                <Select v-model="method" class="w-52" :options="[{ value: '', label: 'Tất cả hình thức' }, ...Object.entries(paymentMethods).map(([value, label]) => ({ value, label: value === 'cash' ? 'Tiền mặt (sổ quỹ tiền mặt)' : label }))]" aria-label="Hình thức" data-testid="cashbook-method" />
                <div class="flex flex-wrap gap-3 text-xs text-muted-foreground">
                    <span v-for="m in data.by_method" :key="m.method">{{ methodName(m.method) }}: <span class="text-success">+{{ formatMoney(m.in) }}</span> / <span class="text-destructive">−{{ formatMoney(m.out) }}</span></span>
                </div>
                <Button variant="outline" size="sm" class="ml-auto" @click="exportCsv"><Download /> Xuất CSV</Button>
            </div>
            <p v-if="data.truncated" class="border-b bg-amber-50 px-4 py-2 text-xs text-amber-800" data-testid="cashbook-truncated">
                Kỳ này có {{ formatNumber(data.count) }} dòng, chỉ hiện {{ formatNumber(data.rows.length) }} dòng mới nhất. Tồn đầu kỳ, tổng thu chi và tồn cuối kỳ vẫn tính đủ cả kỳ. Chọn khoảng ngày ngắn hơn để xem hết.
            </p>
            <EmptyState v-if="!data.rows.length" :icon="Wallet" title="Không có phiếu thu chi" description="Chưa có khoản thu, hoàn hay chi nào trong kỳ này." />
            <EmptyState v-else-if="!rows.length" :icon="Search" title="Không có dòng phù hợp" description="Thử đổi tab hoặc từ khóa tìm kiếm." />
            <div v-else class="overflow-x-auto" :class="loading && 'opacity-60'">
                <table class="w-full text-sm" data-testid="cashbook-table">
                    <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">Thời gian</th>
                            <th class="px-4 py-2.5 font-medium">Nội dung</th>
                            <th class="px-4 py-2.5 font-medium">Chứng từ</th>
                            <th class="px-4 py-2.5 text-right font-medium">Thu</th>
                            <th class="px-4 py-2.5 text-right font-medium">Chi</th>
                            <th class="px-4 py-2.5 text-right font-medium">Tồn quỹ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="(e, i) in rows" :key="i">
                            <td class="whitespace-nowrap px-4 py-2 text-muted-foreground">{{ formatDateTime(e.paid_at) }}<div class="text-xs">{{ e.created_by }}</div></td>
                            <td class="px-4 py-2">{{ label(e) }}<template v-if="e.type.startsWith('transfer')"> · {{ e.type === 'transfer_in' ? 'từ' : 'sang' }} {{ e.party }}</template><template v-else> · {{ methodName(e.method) }}</template><div class="text-xs text-muted-foreground">{{ e.party }}<template v-if="e.reference"> · {{ e.reference }}</template></div></td>
                            <td class="px-4 py-2"><RouterLink v-if="e.document" :to="link(e.document)" class="font-mono text-xs hover:underline">{{ e.document.number }}</RouterLink><span v-else class="text-xs text-muted-foreground">Thanh toán chung</span></td>
                            <td class="px-4 py-2 text-right tabular-nums text-success">{{ e.amount > 0 ? formatMoney(e.amount) : '' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-destructive">{{ e.amount < 0 ? formatMoney(-e.amount) : '' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ e.balance === null ? '' : formatMoney(e.balance) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="flex items-start gap-2 border-t px-4 py-2.5 text-xs text-muted-foreground"><Info class="mt-0.5 size-3.5 shrink-0" /> Tiền sàn chuyển về (payout) chưa có ở đây vì cần dữ liệu đối soát từ API sàn. Phiếu đã hủy không được tính.</p>
        </Card>
    </template>
    <Dialog v-model:open="glossary" title="Giải thích thuật ngữ" size="lg">
        <dl class="divide-y text-sm">
            <div v-for="[term, meaning] in terms" :key="term" class="grid gap-1 py-2.5 sm:grid-cols-[10rem_1fr]">
                <dt class="font-medium">{{ term }}</dt>
                <dd class="text-muted-foreground">{{ meaning }}</dd>
            </div>
        </dl>
    </Dialog>
</template>
