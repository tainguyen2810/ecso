<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { AlertTriangle, Check, ChevronDown, Copy, Crown, Gauge, Landmark, Minus, Receipt } from 'lucide-vue-next';
import SettingsShell from './settings/SettingsShell.vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import type { Entitlement } from '@/shared/types';
import { cycleLabels, featureLabels, invoiceKinds, invoiceStatus, subscriptionStatus, type Cycle } from './billing/labels';

interface PlanRow {
    key: string;
    name: string;
    price_monthly: number;
    price_yearly: number;
    trial_days: number;
    features: Record<string, Entitlement>;
}
interface PaymentInstructions {
    configured: boolean;
    bank_name: string | null;
    account_number: string | null;
    account_name: string | null;
    branch: string | null;
    amount: string;
    memo: string;
}
interface InvoiceRow {
    id: string;
    number: string;
    kind: string;
    plan: { key: string; name: string };
    billing_cycle: Cycle;
    subtotal: string;
    credit: string;
    amount: string;
    status: string;
    transfer_code: string;
    due_at: string | null;
    paid_at: string | null;
    period_start: string | null;
    period_end: string | null;
    created_at: string | null;
    payment: PaymentInstructions | null;
}
interface SubscriptionData {
    plan: { key: string; name: string } | null;
    status: string;
    billing_cycle: Cycle;
    trial_ends_at: string | null;
    trial_available: boolean;
    current_period_end: string | null;
    gift: { source: string; label: string | null } | null;
    past_due_since: string | null;
    grace_until: string | null;
    pending_plan: { key: string; name: string } | null;
    entitlements: Record<string, Entitlement>;
    usage: Record<string, number>;
    open_invoice: InvoiceRow | null;
    plans: PlanRow[];
}
interface Quote {
    subtotal: number;
    credit: number;
    amount: number;
    kind: string;
    downgrade_blockers: Record<string, { used: number; limit: number }>;
}

const tenant = useTenantStore();
const toast = useToast();
const data = ref<SubscriptionData | null>(null);
const invoices = ref<InvoiceRow[]>([]);
const error = ref<string | null>(null);
const cycle = ref<Cycle>('monthly');
const canManage = computed(() => tenant.can('billing.manage'));
const compare = ref(false);
const badge: Record<string, string> = { default: 'bg-blue-50 text-blue-700', success: 'bg-emerald-50 text-emerald-700', warning: 'bg-amber-50 text-amber-700', danger: 'bg-red-50 text-red-600', muted: 'bg-slate-100 text-slate-600' };
/** Days left in the paid period or the trial. */
const daysLeft = computed(() => {
    const end = data.value?.status === 'trialing' ? data.value.trial_ends_at : data.value?.current_period_end;
    return end ? Math.max(0, Math.ceil((new Date(end).getTime() - Date.now()) / 86_400_000)) : null;
});
const limitKeys = ['limit.users', 'limit.warehouses', 'limit.shops', 'limit.orders_per_month'] as const;
const flagKeys = computed(() => Object.keys(featureLabels).filter((k) => !k.startsWith('limit.')));

const target = ref<PlanRow | null>(null);
const quote = ref<Quote | null>(null);
const quoteOpen = ref(false);
const busy = ref(false);

async function load() {
    error.value = null;
    try {
        const [sub, inv] = await Promise.all([api.get('/subscription'), api.get('/invoices')]);
        data.value = sub.data.data;
        invoices.value = inv.data.data;
        if (data.value?.billing_cycle) cycle.value = data.value.billing_cycle;
    } catch (e) {
        error.value = (e as ApiError).message;
    }
}
onMounted(load);

const usageKeys = ['limit.users', 'limit.shops', 'limit.warehouses', 'limit.orders_per_month'] as const;

function limitText(e?: Entitlement): string {
    if (!e || !e.enabled) return '—';
    return e.limit === null ? 'Không giới hạn' : e.limit.toLocaleString('vi-VN');
}
function usagePercent(key: string): number {
    const limit = data.value?.entitlements[key]?.limit;
    const used = data.value?.usage[key] ?? 0;
    return limit ? Math.min(100, Math.round((used / limit) * 100)) : 0;
}
function price(p: PlanRow): number {
    return cycle.value === 'yearly' ? p.price_yearly : p.price_monthly;
}
function isCurrent(p: PlanRow): boolean {
    return p.key === data.value?.plan?.key;
}
const statusInfo = computed(() => subscriptionStatus[data.value?.status ?? ''] ?? { label: data.value?.status ?? '', tone: 'muted' as const });
const ordersWarning = computed(() => {
    const pct = usagePercent('limit.orders_per_month');
    if (pct >= 100) return 'Đã vượt số đơn của gói trong tháng này. Đơn vẫn được đồng bộ để không sai tồn kho; hãy nâng gói để tiếp tục đúng điều khoản.';
    if (pct >= 80) return `Đã dùng ${pct}% số đơn của gói trong tháng này.`;
    return null;
});

async function choose(p: PlanRow) {
    target.value = p;
    quote.value = null;
    quoteOpen.value = true;
    try {
        quote.value = (await api.post('/subscription/quote', { plan: p.key, cycle: cycle.value })).data.data;
    } catch (e) {
        toast.error((e as ApiError).message);
        quoteOpen.value = false;
    }
}

const blockers = computed(() => Object.entries(quote.value?.downgrade_blockers ?? {}));
const isDowngrade = computed(() => {
    if (!target.value || !data.value) return false;
    const current = data.value.plans.find((p) => p.key === data.value?.plan?.key);
    return price(target.value) === 0 || (current !== undefined && price(target.value) < (cycle.value === 'yearly' ? current.price_yearly : current.price_monthly));
});

async function confirmChange() {
    if (!target.value) return;
    busy.value = true;
    try {
        const res = (await api.post('/subscription/change', { plan: target.value.key, cycle: cycle.value })).data.data;
        quoteOpen.value = false;
        toast.success(
            res.action === 'invoice' ? 'Đã tạo hóa đơn. Gói sẽ được kích hoạt khi nhận được thanh toán.' : res.action === 'scheduled' ? 'Đã hẹn chuyển gói khi hết kỳ đã thanh toán.' : 'Đã chuyển gói.',
        );
        await load();
        await tenant.restore();
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = false;
    }
}

async function startTrial(p: PlanRow) {
    busy.value = true;
    try {
        await api.post('/subscription/trial', { plan: p.key });
        toast.success(`Đã bắt đầu dùng thử ${p.name} ${p.trial_days} ngày.`);
        await load();
        await tenant.restore();
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = false;
    }
}

async function cancelInvoice(inv: InvoiceRow) {
    try {
        await api.post(`/invoices/${inv.id}/cancel`);
        toast.success('Đã hủy hóa đơn.');
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}

async function copy(text: string) {
    try {
        await navigator.clipboard.writeText(text);
        toast.success('Đã sao chép.');
    } catch {
        toast.error('Không sao chép được, hãy chép tay.');
    }
}
</script>

<template>
    <SettingsShell title="Gói dịch vụ" description="Gói đang dùng, giới hạn, nâng hạ gói và hóa đơn.">
        <template #actions>
            <div class="flex rounded-[12px] bg-slate-100 p-1 text-[14px]" role="group" aria-label="Chu kỳ thanh toán">
                <button
                    v-for="c in (['monthly', 'yearly'] as const)"
                    :key="c"
                    type="button"
                    :class="cn('rounded-[9px] px-3.5 py-1.5', cycle === c ? 'bg-white font-semibold text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-900')"
                    :data-testid="`cycle-${c}`"
                    @click="cycle = c"
                >
                    {{ cycleLabels[c] }}
                </button>
            </div>
        </template>

        <ErrorState v-if="error" :message="error" @retry="load" />
        <div v-else-if="!data" class="grid gap-4 xl:grid-cols-[400px_minmax(0,1fr)]"><Skeleton v-for="i in 2" :key="i" class="h-52 rounded-[14px]" /></div>
        <template v-else>
            <div v-if="data.status === 'past_due'" class="mb-4 flex items-start gap-3 rounded-[12px] border border-red-200 bg-red-50 p-4 text-[14px] text-red-800" data-testid="past-due-banner">
                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                <p>
                    Kỳ thanh toán đã hết nhưng chưa nhận được tiền. Nếu chưa thanh toán trước <strong>{{ formatDateTime(data.grace_until) }}</strong>, doanh nghiệp sẽ chuyển về gói Free (dữ liệu vẫn được giữ
                    nguyên).
                </p>
            </div>

            <div class="mb-5 grid gap-4 xl:grid-cols-[400px_minmax(0,1fr)]">
                <div class="relative overflow-hidden rounded-[16px] bg-gradient-to-br from-blue-600 to-indigo-600 p-6 text-white shadow-[0_8px_24px_-8px_rgba(37,99,235,0.55)]" data-testid="current-plan">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-[14px] text-blue-100">Gói đang dùng</p>
                            <p class="mt-1 text-[30px] font-bold leading-tight">{{ data.plan?.name }}</p>
                            <span class="mt-2 inline-flex rounded-md bg-white/15 px-2 py-0.5 text-[12px] font-medium">{{ statusInfo.label }}</span>
                        </div>
                        <span class="grid size-12 shrink-0 place-items-center rounded-[12px] bg-white/15"><Crown class="size-6" /></span>
                    </div>
                    <div class="mt-6 text-[14px] text-blue-100">
                        <p v-if="data.status === 'trialing'">Dùng thử đến <b class="text-white">{{ formatDateTime(data.trial_ends_at) }}</b><template v-if="daysLeft !== null"> · còn {{ daysLeft }} ngày</template></p>
                        <p v-else-if="data.gift" data-testid="gift-plan">
                            Tặng kèm {{ data.gift.label ?? 'EzChat' }}<template v-if="data.current_period_end"> · đến {{ formatDateTime(data.current_period_end) }}</template>. Gia hạn gói EzChat thì gói này tự gia hạn
                            theo.
                        </p>
                        <p v-else-if="data.current_period_end">
                            {{ cycleLabels[data.billing_cycle] }} · hết kỳ <b class="text-white">{{ formatDateTime(data.current_period_end) }}</b><template v-if="daysLeft !== null"> · còn {{ daysLeft }} ngày</template>
                        </p>
                        <p v-else>Không thu phí.</p>
                        <p v-if="data.pending_plan" class="mt-2 rounded-[10px] bg-white/10 px-3 py-2 text-white" data-testid="pending-plan">
                            Sẽ chuyển sang <strong>{{ data.pending_plan.name }}</strong> khi hết kỳ.
                        </p>
                    </div>
                </div>

                <div class="rounded-[14px] border border-slate-200/80 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,0.04)]" data-testid="usage">
                    <h2 class="flex items-center gap-2 text-[16px] font-semibold text-slate-900"><Gauge class="size-5 text-blue-600" /> Mức sử dụng</h2>
                    <div class="mt-4 grid gap-x-8 gap-y-5 sm:grid-cols-2">
                        <div v-for="key in usageKeys" :key="key">
                            <div class="flex items-baseline justify-between text-[14px]">
                                <span class="text-slate-600">{{ featureLabels[key] }}</span>
                                <span class="tabular-nums">
                                    <b class="text-slate-900">{{ (data.usage[key] ?? 0).toLocaleString('vi-VN') }}</b><span class="text-slate-400">/{{ limitText(data.entitlements[key]) }}</span>
                                </span>
                            </div>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                                <div :class="cn('h-full rounded-full', usagePercent(key) >= 100 ? 'bg-red-500' : usagePercent(key) >= 80 ? 'bg-amber-500' : 'bg-blue-600')" :style="{ width: usagePercent(key) + '%' }" />
                            </div>
                        </div>
                    </div>
                    <p v-if="ordersWarning" class="mt-4 text-[13px] text-amber-700" data-testid="orders-warning">{{ ordersWarning }}</p>
                </div>
            </div>

            <section v-if="data.open_invoice" class="mb-5 overflow-hidden rounded-[14px] border border-amber-200 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]" data-testid="open-invoice">
                <header class="flex items-center gap-3 border-b border-slate-100 px-6 py-4">
                    <span class="grid size-9 place-items-center rounded-[10px] bg-amber-50 text-amber-600"><Receipt class="size-[18px]" /></span>
                    <h2 class="flex-1 text-[16px] font-semibold text-slate-900">Hóa đơn chờ thanh toán</h2>
                    <button v-if="canManage" type="button" class="rounded-[9px] px-3 py-1.5 text-[13px] font-medium text-red-600 hover:bg-red-50" @click="cancelInvoice(data.open_invoice)">Hủy hóa đơn</button>
                </header>
                <div class="grid gap-6 p-6 md:grid-cols-2">
                    <div class="space-y-1.5 text-[14px] text-slate-600">
                        <p class="text-slate-500">{{ data.open_invoice.number }} · {{ invoiceKinds[data.open_invoice.kind] ?? data.open_invoice.kind }}</p>
                        <p>
                            Gói <b class="text-slate-900">{{ data.open_invoice.plan.name }}</b> · {{ cycleLabels[data.open_invoice.billing_cycle] }}
                        </p>
                        <p v-if="Number(data.open_invoice.credit) > 0" class="text-slate-500">
                            Giá gói {{ formatMoney(data.open_invoice.subtotal) }} − trừ thời gian chưa dùng {{ formatMoney(data.open_invoice.credit) }}
                        </p>
                        <p class="text-[28px] font-bold text-slate-900">{{ formatMoney(data.open_invoice.amount) }}</p>
                        <p class="text-slate-500">Hạn thanh toán {{ formatDateTime(data.open_invoice.due_at) }}</p>
                        <p class="text-slate-500">
                            Mã thanh toán <span class="font-mono font-semibold text-slate-900" data-testid="transfer-code">{{ data.open_invoice.transfer_code }}</span>
                        </p>
                    </div>
                    <div v-if="data.open_invoice.payment" class="rounded-[12px] bg-slate-50 p-4 text-[14px]" data-testid="bank-instructions">
                        <p class="mb-3 flex items-center gap-2 font-semibold text-slate-900"><Landmark class="size-4" /> Chuyển khoản ngân hàng</p>
                        <template v-if="data.open_invoice.payment.configured">
                            <dl class="grid grid-cols-[auto_1fr_auto] items-center gap-x-3 gap-y-2">
                                <dt class="text-slate-500">Ngân hàng</dt>
                                <dd class="col-span-2">{{ data.open_invoice.payment.bank_name }}<span v-if="data.open_invoice.payment.branch"> – {{ data.open_invoice.payment.branch }}</span></dd>
                                <dt class="text-slate-500">Số tài khoản</dt>
                                <dd class="font-mono">{{ data.open_invoice.payment.account_number }}</dd>
                                <button type="button" class="text-slate-400 hover:text-slate-900" aria-label="Sao chép số tài khoản" @click="copy(data.open_invoice.payment.account_number ?? '')"><Copy class="size-4" /></button>
                                <dt class="text-slate-500">Chủ tài khoản</dt>
                                <dd class="col-span-2">{{ data.open_invoice.payment.account_name }}</dd>
                                <dt class="text-slate-500">Số tiền</dt>
                                <dd class="font-semibold">{{ formatMoney(data.open_invoice.payment.amount) }}</dd>
                                <span />
                                <dt class="text-slate-500">Nội dung</dt>
                                <dd class="font-mono font-semibold">{{ data.open_invoice.payment.memo }}</dd>
                                <button type="button" class="text-slate-400 hover:text-slate-900" aria-label="Sao chép nội dung" @click="copy(data.open_invoice.payment.memo)"><Copy class="size-4" /></button>
                            </dl>
                            <p class="mt-3 text-[13px] text-slate-500">Ghi đúng nội dung chuyển khoản. Gói được kích hoạt sau khi bộ phận kế toán đối chiếu sao kê (thường trong giờ làm việc).</p>
                        </template>
                        <p v-else class="text-slate-500">Thông tin tài khoản nhận tiền chưa được cấu hình. Vui lòng liên hệ hỗ trợ.</p>
                    </div>
                </div>
            </section>

            <section data-testid="plans">
                <h2 class="mb-3 text-[16px] font-semibold text-slate-900">Các gói dịch vụ</h2>
                <div class="grid gap-4 sm:grid-cols-2 2xl:grid-cols-4">
                    <div
                        v-for="p in data.plans"
                        :key="p.key"
                        :class="cn('relative flex flex-col rounded-[14px] border bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.04)]', isCurrent(p) ? 'border-blue-600 ring-1 ring-blue-600' : 'border-slate-200/80')"
                    >
                        <span v-if="isCurrent(p)" class="absolute -top-3 left-5 rounded-full bg-blue-600 px-2.5 py-0.5 text-[12px] font-semibold text-white">Đang dùng</span>
                        <p class="text-[16px] font-semibold text-slate-900">{{ p.name }}</p>
                        <p class="mt-2 text-[26px] font-bold tabular-nums text-slate-900">
                            {{ formatNumber(price(p)) }}<span class="text-[14px] font-medium text-slate-400"> {{ price(p) ? (cycle === 'yearly' ? 'đ/năm' : 'đ/tháng') : 'Miễn phí' }}</span>
                        </p>
                        <ul class="mt-4 flex-1 space-y-2 text-[14px] text-slate-600">
                            <li v-for="key in limitKeys" :key="key" class="flex gap-2">
                                <Check class="mt-0.5 size-4 shrink-0 text-emerald-500" /> {{ featureLabels[key] }}: <b class="font-semibold text-slate-900">{{ limitText(p.features[key]) }}</b>
                            </li>
                            <li v-for="key in flagKeys" :key="key" :class="cn('flex gap-2', !p.features[key]?.enabled && 'text-slate-400')">
                                <Check v-if="p.features[key]?.enabled" class="mt-0.5 size-4 shrink-0 text-emerald-500" />
                                <Minus v-else class="mt-0.5 size-4 shrink-0" />
                                {{ featureLabels[key] }}
                            </li>
                        </ul>
                        <div v-if="canManage" class="mt-5 space-y-2">
                            <span v-if="isCurrent(p) && (data.billing_cycle === cycle || price(p) === 0)" class="block rounded-[10px] bg-slate-100 py-2.5 text-center text-[14px] font-medium text-slate-500">Đang dùng</span>
                            <button
                                v-else
                                type="button"
                                :class="cn('block w-full rounded-[10px] py-2.5 text-center text-[14px] font-medium', price(p) === 0 ? 'border border-slate-200 text-slate-700 hover:border-blue-300' : 'bg-blue-600 text-white hover:bg-blue-700')"
                                :data-testid="`choose-${p.key}`"
                                @click="choose(p)"
                            >
                                {{ isCurrent(p) ? 'Đổi chu kỳ' : 'Chọn gói' }}
                            </button>
                            <button
                                v-if="data.trial_available && p.trial_days > 0 && !isCurrent(p) && data.status !== 'trialing'"
                                type="button"
                                class="block w-full text-center text-[13px] font-medium text-blue-700 hover:underline disabled:opacity-50"
                                :disabled="busy"
                                :data-testid="`trial-${p.key}`"
                                @click="startTrial(p)"
                            >
                                Dùng thử {{ p.trial_days }} ngày
                            </button>
                        </div>
                    </div>
                </div>
                <button type="button" class="mt-3 inline-flex items-center gap-1.5 text-[14px] font-medium text-blue-700" @click="compare = !compare">
                    <ChevronDown :class="cn('size-4 transition-transform', compare && 'rotate-180')" /> {{ compare ? 'Ẩn bảng so sánh' : 'So sánh đầy đủ các gói' }}
                </button>
                <div v-if="compare" class="mt-3 overflow-x-auto rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
                    <table class="w-full text-[14px]">
                        <thead class="border-b border-slate-100 text-left text-[12px] uppercase tracking-wider text-slate-400">
                            <tr>
                                <th class="px-5 py-3.5 font-semibold">Tính năng</th>
                                <th v-for="p in data.plans" :key="p.key" :class="cn('px-5 py-3.5 font-semibold', isCurrent(p) && 'bg-blue-50/60 text-blue-700')">{{ p.name }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <tr v-for="(label, key) in featureLabels" :key="key">
                                <td class="px-5 py-3 text-slate-500">{{ label }}</td>
                                <td v-for="p in data.plans" :key="p.key" :class="cn('px-5 py-3', isCurrent(p) && 'bg-blue-50/60')">
                                    <template v-if="String(key).startsWith('limit.')">{{ limitText(p.features[key]) }}</template>
                                    <Check v-else-if="p.features[key]?.enabled" class="size-4 text-emerald-500" />
                                    <Minus v-else class="size-4 text-slate-300" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="mt-5 overflow-hidden rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]" data-testid="invoices">
                <h2 class="border-b border-slate-100 px-6 py-4 text-[16px] font-semibold text-slate-900">Lịch sử hóa đơn</h2>
                <p v-if="!invoices.length" class="px-6 py-5 text-[14px] text-slate-500">Chưa có hóa đơn.</p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-[14px]">
                        <thead class="border-b border-slate-100 text-left text-[12px] uppercase tracking-wider text-slate-400">
                            <tr>
                                <th class="px-4 py-3.5 pl-6 font-semibold">Số</th>
                                <th class="px-4 py-3.5 font-semibold">Ngày tạo</th>
                                <th class="px-4 py-3.5 font-semibold">Nội dung</th>
                                <th class="px-4 py-3.5 text-right font-semibold">Số tiền</th>
                                <th class="px-4 py-3.5 pr-6 font-semibold">Trạng thái</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <tr v-for="inv in invoices" :key="inv.id">
                                <td class="px-4 py-3.5 pl-6 font-mono text-[13px] text-blue-700">{{ inv.number }}</td>
                                <td class="whitespace-nowrap px-4 py-3.5 text-slate-500">{{ formatDateTime(inv.created_at) }}</td>
                                <td class="px-4 py-3.5">{{ invoiceKinds[inv.kind] ?? inv.kind }} {{ inv.plan.name }} · {{ cycleLabels[inv.billing_cycle] }}</td>
                                <td class="px-4 py-3.5 text-right tabular-nums">{{ formatMoney(inv.amount) }}</td>
                                <td class="px-4 py-3.5 pr-6">
                                    <span :class="cn('rounded-md px-2 py-0.5 text-[12px] font-medium', badge[invoiceStatus[inv.status]?.tone ?? 'muted'])">{{ invoiceStatus[inv.status]?.label ?? inv.status }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>
    </SettingsShell>

    <Dialog v-model:open="quoteOpen" :title="target ? `Chuyển sang gói ${target.name}` : 'Chuyển gói'" :description="target ? cycleLabels[cycle] : ''">
        <div v-if="!quote" class="space-y-2"><Skeleton class="h-5" /><Skeleton class="h-5" /></div>
        <div v-else class="space-y-3 text-sm" data-testid="quote">
            <template v-if="blockers.length">
                <p class="text-destructive">Chưa thể chuyển: đang dùng vượt giới hạn của gói mới.</p>
                <ul class="list-disc pl-5">
                    <li v-for="[key, b] in blockers" :key="key">{{ featureLabels[key] ?? key }}: đang dùng {{ b.used }}, gói mới cho phép {{ b.limit }}</li>
                </ul>
            </template>
            <template v-else-if="isDowngrade">
                <p>Gói mới có giới hạn thấp hơn. Nếu đang trong kỳ đã thanh toán, việc chuyển sẽ diễn ra khi hết kỳ; thời gian đã trả không được hoàn tiền.</p>
            </template>
            <template v-else>
                <div class="flex justify-between"><span class="text-muted-foreground">Giá gói</span><span>{{ formatMoney(quote.subtotal) }}</span></div>
                <div v-if="quote.credit > 0" class="flex justify-between">
                    <span class="text-muted-foreground">Trừ thời gian chưa dùng của gói hiện tại</span><span>−{{ formatMoney(quote.credit) }}</span>
                </div>
                <div class="flex justify-between border-t pt-2 text-base font-semibold"><span>Cần thanh toán</span><span>{{ formatMoney(quote.amount) }}</span></div>
                <p class="text-xs text-muted-foreground">Hệ thống tạo hóa đơn kèm thông tin chuyển khoản. Gói được kích hoạt khi nhận được tiền.</p>
            </template>
        </div>
        <template #footer>
            <Button variant="ghost" @click="quoteOpen = false">Đóng</Button>
            <Button :disabled="!quote || blockers.length > 0" :loading="busy" data-testid="confirm-change" @click="confirmChange">
                {{ isDowngrade ? 'Xác nhận chuyển gói' : 'Tạo hóa đơn' }}
            </Button>
        </template>
    </Dialog>
</template>
