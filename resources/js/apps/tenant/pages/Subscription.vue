<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { AlertTriangle, Check, Copy, Landmark, Minus } from 'lucide-vue-next';
import PageHeader from '../PageHeader.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDateTime, formatMoney } from '@/shared/lib/utils';
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
    <PageHeader title="Gói dịch vụ" description="Gói hiện tại, mức sử dụng, nâng/hạ gói và hóa đơn." />
    <ErrorState v-if="error" :message="error" @retry="load" />
    <div v-else-if="!data" class="grid gap-4 md:grid-cols-3"><Skeleton v-for="i in 3" :key="i" class="h-32" /></div>
    <template v-else>
        <div
            v-if="data.status === 'past_due'"
            class="mb-4 flex items-start gap-3 rounded-lg border border-destructive/30 bg-destructive/5 p-4 text-sm"
            data-testid="past-due-banner"
        >
            <AlertTriangle class="mt-0.5 size-4 text-destructive" />
            <p>
                Kỳ thanh toán đã hết nhưng chưa nhận được tiền. Nếu chưa thanh toán trước
                <strong>{{ formatDateTime(data.grace_until) }}</strong>, doanh nghiệp sẽ chuyển về gói Free (dữ liệu vẫn được giữ nguyên).
            </p>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <Card class="p-5" data-testid="current-plan">
                <p class="text-sm text-muted-foreground">Gói hiện tại</p>
                <p class="mt-2 flex items-center gap-2 text-2xl font-semibold">
                    {{ data.plan?.name }} <Badge :tone="statusInfo.tone">{{ statusInfo.label }}</Badge>
                </p>
                <p v-if="data.status === 'trialing'" class="mt-1 text-sm text-muted-foreground">Dùng thử đến {{ formatDateTime(data.trial_ends_at) }}</p>
                <p v-else-if="data.current_period_end" class="mt-1 text-sm text-muted-foreground">
                    {{ cycleLabels[data.billing_cycle] }} · hết kỳ {{ formatDateTime(data.current_period_end) }}
                </p>
                <p v-else class="mt-1 text-sm text-muted-foreground">Không thu phí.</p>
                <p v-if="data.pending_plan" class="mt-2 text-sm" data-testid="pending-plan">
                    Sẽ chuyển sang <strong>{{ data.pending_plan.name }}</strong> khi hết kỳ.
                </p>
            </Card>

            <Card class="p-5 lg:col-span-2" data-testid="usage">
                <p class="text-sm text-muted-foreground">Mức sử dụng</p>
                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <div v-for="key in usageKeys" :key="key">
                        <div class="flex items-baseline justify-between text-sm">
                            <span>{{ featureLabels[key] }}</span>
                            <span class="tabular-nums">
                                <strong>{{ (data.usage[key] ?? 0).toLocaleString('vi-VN') }}</strong>
                                <span class="text-muted-foreground"> / {{ limitText(data.entitlements[key]) }}</span>
                            </span>
                        </div>
                        <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-muted">
                            <div
                                :class="cn('h-full rounded-full', usagePercent(key) >= 100 ? 'bg-destructive' : usagePercent(key) >= 80 ? 'bg-warning' : 'bg-primary')"
                                :style="{ width: usagePercent(key) + '%' }"
                            />
                        </div>
                    </div>
                </div>
                <p v-if="ordersWarning" class="mt-3 text-xs text-[oklch(0.5_0.13_70)]" data-testid="orders-warning">{{ ordersWarning }}</p>
            </Card>
        </div>

        <Card v-if="data.open_invoice" class="mt-6" title="Hóa đơn chờ thanh toán" data-testid="open-invoice">
            <template #actions>
                <Button v-if="canManage" variant="ghost" size="sm" @click="cancelInvoice(data.open_invoice)">Hủy hóa đơn</Button>
            </template>
            <div class="grid gap-6 p-5 md:grid-cols-2">
                <div class="space-y-1.5 text-sm">
                    <p class="text-muted-foreground">{{ data.open_invoice.number }} · {{ invoiceKinds[data.open_invoice.kind] ?? data.open_invoice.kind }}</p>
                    <p>
                        Gói <strong>{{ data.open_invoice.plan.name }}</strong> · {{ cycleLabels[data.open_invoice.billing_cycle] }}
                    </p>
                    <p v-if="Number(data.open_invoice.credit) > 0" class="text-muted-foreground">
                        Giá gói {{ formatMoney(data.open_invoice.subtotal) }} − trừ thời gian chưa dùng {{ formatMoney(data.open_invoice.credit) }}
                    </p>
                    <p class="text-2xl font-semibold">{{ formatMoney(data.open_invoice.amount) }}</p>
                    <p class="text-muted-foreground">Hạn thanh toán {{ formatDateTime(data.open_invoice.due_at) }}</p>
                </div>
                <div v-if="data.open_invoice.payment" class="rounded-lg border bg-muted/40 p-4 text-sm" data-testid="bank-instructions">
                    <p class="mb-3 flex items-center gap-2 font-medium"><Landmark class="size-4" /> Chuyển khoản ngân hàng</p>
                    <template v-if="data.open_invoice.payment.configured">
                        <dl class="grid grid-cols-[auto_1fr_auto] items-center gap-x-3 gap-y-1.5">
                            <dt class="text-muted-foreground">Ngân hàng</dt>
                            <dd class="col-span-2">{{ data.open_invoice.payment.bank_name }}<span v-if="data.open_invoice.payment.branch"> – {{ data.open_invoice.payment.branch }}</span></dd>
                            <dt class="text-muted-foreground">Số tài khoản</dt>
                            <dd class="font-mono">{{ data.open_invoice.payment.account_number }}</dd>
                            <button class="text-muted-foreground hover:text-foreground" aria-label="Sao chép số tài khoản" @click="copy(data.open_invoice.payment.account_number ?? '')"><Copy class="size-4" /></button>
                            <dt class="text-muted-foreground">Chủ tài khoản</dt>
                            <dd class="col-span-2">{{ data.open_invoice.payment.account_name }}</dd>
                            <dt class="text-muted-foreground">Số tiền</dt>
                            <dd class="font-semibold">{{ formatMoney(data.open_invoice.payment.amount) }}</dd>
                            <span />
                            <dt class="text-muted-foreground">Nội dung</dt>
                            <dd class="font-mono font-semibold" data-testid="transfer-code">{{ data.open_invoice.payment.memo }}</dd>
                            <button class="text-muted-foreground hover:text-foreground" aria-label="Sao chép nội dung" @click="copy(data.open_invoice.payment.memo)"><Copy class="size-4" /></button>
                        </dl>
                        <p class="mt-3 text-xs text-muted-foreground">
                            Ghi đúng nội dung chuyển khoản. Gói được kích hoạt sau khi bộ phận kế toán đối chiếu sao kê (thường trong giờ làm việc).
                        </p>
                    </template>
                    <p v-else class="text-muted-foreground">Thông tin tài khoản nhận tiền chưa được cấu hình. Vui lòng liên hệ hỗ trợ.</p>
                </div>
            </div>
        </Card>

        <Card class="mt-6 overflow-x-auto" title="Các gói dịch vụ" data-testid="plans">
            <template #actions>
                <div class="inline-flex rounded-md border p-0.5 text-sm" role="group" aria-label="Chu kỳ thanh toán">
                    <button
                        v-for="c in (['monthly', 'yearly'] as const)"
                        :key="c"
                        :class="cn('rounded px-3 py-1', cycle === c ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted')"
                        :data-testid="`cycle-${c}`"
                        @click="cycle = c"
                    >
                        {{ cycleLabels[c] }}
                    </button>
                </div>
            </template>
            <table class="w-full min-w-[720px] text-sm">
                <thead>
                    <tr class="border-b">
                        <th class="px-5 py-3 text-left font-medium text-muted-foreground">Tính năng</th>
                        <th v-for="p in data.plans" :key="p.key" :class="cn('px-5 py-3 text-left align-top', isCurrent(p) && 'bg-secondary/60')">
                            <p class="font-semibold">{{ p.name }}</p>
                            <p class="text-xs font-normal text-muted-foreground">
                                {{ price(p) ? formatMoney(price(p)) + (cycle === 'yearly' ? '/năm' : '/tháng') : 'Miễn phí' }}
                            </p>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="(label, key) in featureLabels" :key="key">
                        <td class="px-5 py-2.5 text-muted-foreground">{{ label }}</td>
                        <td v-for="p in data.plans" :key="p.key" :class="cn('px-5 py-2.5', isCurrent(p) && 'bg-secondary/60')">
                            <template v-if="String(key).startsWith('limit.')">{{ limitText(p.features[key]) }}</template>
                            <Check v-else-if="p.features[key]?.enabled" class="size-4 text-success" />
                            <Minus v-else class="size-4 text-muted-foreground" />
                        </td>
                    </tr>
                    <tr v-if="canManage">
                        <td />
                        <td v-for="p in data.plans" :key="p.key" :class="cn('space-y-2 px-5 py-3', isCurrent(p) && 'bg-secondary/60')">
                            <Badge v-if="isCurrent(p) && (data.billing_cycle === cycle || price(p) === 0)" tone="success">Đang dùng</Badge>
                            <Button v-else size="sm" class="w-full" :variant="price(p) === 0 ? 'outline' : 'default'" :data-testid="`choose-${p.key}`" @click="choose(p)">
                                {{ isCurrent(p) ? 'Đổi chu kỳ' : 'Chọn gói' }}
                            </Button>
                            <Button
                                v-if="data.trial_available && p.trial_days > 0 && !isCurrent(p) && data.status !== 'trialing'"
                                size="sm"
                                variant="link"
                                class="h-auto w-full p-0"
                                :disabled="busy"
                                :data-testid="`trial-${p.key}`"
                                @click="startTrial(p)"
                            >
                                Dùng thử {{ p.trial_days }} ngày
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </Card>

        <Card class="mt-6" title="Lịch sử hóa đơn" data-testid="invoices">
            <p v-if="!invoices.length" class="p-5 text-sm text-muted-foreground">Chưa có hóa đơn.</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-sm">
                    <thead class="border-b text-left text-muted-foreground">
                        <tr>
                            <th class="px-5 py-2.5 font-medium">Số</th>
                            <th class="px-5 py-2.5 font-medium">Nội dung</th>
                            <th class="px-5 py-2.5 text-right font-medium">Số tiền</th>
                            <th class="px-5 py-2.5 font-medium">Trạng thái</th>
                            <th class="px-5 py-2.5 font-medium">Ngày tạo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="inv in invoices" :key="inv.id">
                            <td class="px-5 py-2.5 font-mono text-xs">{{ inv.number }}</td>
                            <td class="px-5 py-2.5">{{ invoiceKinds[inv.kind] ?? inv.kind }} {{ inv.plan.name }} · {{ cycleLabels[inv.billing_cycle] }}</td>
                            <td class="px-5 py-2.5 text-right tabular-nums">{{ formatMoney(inv.amount) }}</td>
                            <td class="px-5 py-2.5">
                                <Badge :tone="invoiceStatus[inv.status]?.tone ?? 'muted'">{{ invoiceStatus[inv.status]?.label ?? inv.status }}</Badge>
                            </td>
                            <td class="px-5 py-2.5 text-muted-foreground">{{ formatDateTime(inv.created_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </template>

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
