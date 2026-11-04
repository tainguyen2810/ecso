<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Banknote, Clock, CreditCard, History, Minus, Plus, Printer, QrCode, Trash2, UserRound, Wallet, X } from 'lucide-vue-next';
import { RouterLink } from 'vue-router';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import { api, ApiError, idempotent, newIdempotencyKey } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { paymentMethods } from '../orders/labels';
import { printInvoice } from '../orders/invoice';
import { printShopFrom } from '../orders/printShop';
import { useSettingsStore } from '@/shared/stores/settings';
import { addToCart, cartTotals, emptyCart, quickCash, splitPayments, type Cart } from './pos';
import ShiftPanel from './ShiftPanel.vue';
import type { CashShift, Customer, OrderDetail, SalesChannel, Variant, Warehouse } from '@/shared/types';

const STORAGE_KEY = 'ecso.pos.carts';
const toast = useToast();
const tenant = useTenantStore();
const settings = useSettingsStore();
// Cấu hình: methods offered at the counter, and whether staff may change prices.
const posMethods = computed(() => (['cash', 'bank_transfer', 'card', 'ewallet'] as const).filter((m) => settings.data?.payments.methods[m] ?? true));
const canEditPrice = computed(() => (settings.data?.pricing.staff_edit_price ?? true) || tenant.can('orders.price'));

const channels = ref<SalesChannel[]>([]);
const warehouses = ref<Warehouse[]>([]);
const channelId = ref('');
const warehouseId = ref('');
const carts = ref<Cart[]>([emptyCart(1)]);
const active = ref(0);
const cart = computed(() => carts.value[active.value]!);
const taxRule = computed(() => ({ rate: Number(settings.data?.tax.sale_rate) || 0, included: settings.data?.tax.prices_include_tax ?? true }));
const totals = computed(() => cartTotals(cart.value, taxRule.value));

const search = ref('');
const results = ref<Variant[]>([]);
const searchInput = ref<InstanceType<typeof Input> | null>(null);
const customerQuery = ref('');
const customerResults = ref<Customer[]>([]);
const paying = ref(false);
const error = ref<string | null>(null);
const autoPrint = ref(true);
const shift = ref<CashShift | null>(null);
const closedShift = ref<CashShift | null>(null);
const suggestedOpening = ref('0');
const shiftOpen = ref(false);
const shiftLoaded = ref(false);

function freshCart(no: number): Cart {
    const c = emptyCart(no);
    const m = settings.data?.payments.default_pos;
    if (m && posMethods.value.includes(m as never)) c.method = m as Cart['method'];
    return c;
}

const methodIcons = { cash: Banknote, bank_transfer: QrCode, card: CreditCard, ewallet: Wallet } as const;

function persist() {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(carts.value));
    } catch {
        /* storage unavailable: carts live in memory only */
    }
}
watch(carts, persist, { deep: true });

onMounted(async () => {
    try {
        const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? 'null');
        if (Array.isArray(saved) && saved.length) carts.value = saved;
    } catch {
        /* ignore */
    }
    const [c, w, s] = await Promise.all([api.get('/sales-channels'), api.get('/warehouses'), settings.load()]);
    autoPrint.value = s.sales.pos_auto_print;
    // Empty carts start on the counter's default method.
    carts.value.forEach((ct) => !ct.lines.length && posMethods.value.includes(s.payments.default_pos as never) && (ct.method = s.payments.default_pos as Cart['method']));
    channels.value = (c.data.data as SalesChannel[]).filter((ch) => ch.is_active);
    warehouses.value = w.data.data;
    const pos = channels.value.find((ch) => ch.type === 'pos') ?? channels.value[0];
    channelId.value = pos?.id ?? '';
    warehouseId.value = pos?.warehouse?.id ?? warehouses.value.find((x) => x.is_default)?.id ?? warehouses.value[0]?.id ?? '';
    await Promise.all([runSearch(), loadShift()]);
    if (!shift.value) shiftOpen.value = true;
    window.addEventListener('keydown', onKey);
    focusSearch();
});
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

function focusSearch() {
    nextTick(() => (searchInput.value?.$el as HTMLInputElement | undefined)?.focus?.() ?? document.querySelector<HTMLInputElement>('[data-testid="pos-search"]')?.focus());
}

watch(channelId, (id) => {
    const ch = channels.value.find((c) => c.id === id);
    if (ch?.warehouse) warehouseId.value = ch.warehouse.id;
});

let timer: ReturnType<typeof setTimeout> | undefined;
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(runSearch, 200);
});
watch(warehouseId, runSearch);

async function runSearch() {
    if (!warehouseId.value) return;
    const { data } = await api.get('/variants', { params: { q: search.value || undefined, warehouse: warehouseId.value } });
    results.value = data.data;
}

/** Scanner: types the code then Enter. An exact barcode/SKU match is added at once. */
async function onSearchEnter() {
    clearTimeout(timer);
    await runSearch();
    const term = search.value.trim().toLowerCase();
    const exact = results.value.find((v) => v.barcode?.toLowerCase() === term || v.sku.toLowerCase() === term) ?? (results.value.length === 1 ? results.value[0] : undefined);
    if (exact) {
        add(exact);
        search.value = '';
    }
}

function add(v: Variant) {
    addToCart(cart.value, v);
    error.value = null;
}

function onKey(e: KeyboardEvent) {
    if (e.key === 'F2') {
        e.preventDefault();
        focusSearch();
    } else if (e.key === 'F9') {
        e.preventDefault();
        pay();
    }
}

let customerTimer: ReturnType<typeof setTimeout> | undefined;
watch(customerQuery, (q) => {
    clearTimeout(customerTimer);
    if (!q.trim()) return void (customerResults.value = []);
    customerTimer = setTimeout(async () => {
        const { data } = await api.get('/customers', { params: { q, per_page: 6 } });
        customerResults.value = data.data;
    }, 250);
});

function pickCustomer(c: Customer) {
    cart.value.customer = { id: c.id, name: c.name, phone: c.phone, code: c.code };
    customerQuery.value = '';
    customerResults.value = [];
}

function newCart() {
    carts.value.push(freshCart(Math.max(...carts.value.map((c) => c.no)) + 1));
    active.value = carts.value.length - 1;
    focusSearch();
}

function closeCart(i: number) {
    carts.value.splice(i, 1);
    if (!carts.value.length) carts.value.push(freshCart(1));
    active.value = Math.min(active.value, carts.value.length - 1);
}

const split = computed(() => (cart.value.split ? splitPayments(cart.value.split, totals.value.total) : null));
const received = computed(() => (split.value ? split.value.paid : cart.value.received === '' ? totals.value.total : Number(cart.value.received) || 0));

/** "Chia nhiều hình thức": e.g. part cash, part bank transfer. Starts with the whole amount on the current method. */
function toggleSplit() {
    cart.value.split = cart.value.split ? null : [{ method: cart.value.method, amount: totals.value.total }, { method: posMethods.value.find((m) => m !== cart.value.method) ?? 'bank_transfer', amount: '' }];
}
const change = computed(() => Math.max(0, received.value - totals.value.total));
const shortfall = computed(() => Math.max(0, totals.value.total - received.value));

async function pay() {
    if (!cart.value.lines.length || paying.value) return;
    if (shiftLoaded.value && !shift.value) {
        error.value = 'Chưa mở ca. Mở ca và đếm tiền đầu ca trước khi bán.';
        closedShift.value = null;
        shiftOpen.value = true;
        return;
    }
    if (settings.data?.sales.pos_require_cash && cart.value.method === 'cash' && cart.value.received === '' && totals.value.total > 0) {
        error.value = 'Nhập số tiền khách đưa trước khi thanh toán.';
        return;
    }
    if (split.value?.error) {
        error.value = split.value.error;
        return;
    }
    if (shortfall.value > 0 && !cart.value.customer) {
        error.value = 'Khách lẻ phải thanh toán đủ. Chọn khách hàng để ghi nợ phần còn lại.';
        return;
    }
    paying.value = true;
    error.value = null;
    const collected = Math.min(received.value, totals.value.total);
    // Same key on every retry of this cart: a sale that went through but whose answer was lost is not sold again.
    const key = (cart.value.key ??= newIdempotencyKey());
    try {
        const { data } = await api.post('/sales-orders', {
            channel_id: channelId.value,
            warehouse_id: warehouseId.value,
            stage: 'completed',
            counter: true,
            customer_id: cart.value.customer?.id ?? null,
            discount: Number(cart.value.discount) || 0,
            lines: cart.value.lines.map((l) => ({ variant_id: l.variant_id, quantity: l.quantity, unit_price: l.unit_price, discount: l.discount })),
            payments: split.value ? split.value.payments : collected > 0 ? [{ method: cart.value.method, amount: collected }] : [],
        }, idempotent(key));
        toast.success(`Đã bán ${data.data.external_order_id}${change.value > 0 ? ` · trả lại khách ${formatMoney(change.value)}` : ''}`);
        if (autoPrint.value) {
            const detail = await api.get(`/orders/${data.data.id}`);
            printInvoice(detail.data.data as OrderDetail, printShopFrom(settings.data, tenant.current?.name ?? ''), 'k80');
        }
        carts.value[active.value] = freshCart(cart.value.no);
        await runSearch();
        focusSearch();
    } catch (e) {
        const err = e as ApiError;
        error.value = err.field('stock') ?? err.field('payments') ?? collectFirst(err) ?? err.message;
    } finally {
        paying.value = false;
    }
}

function collectFirst(err: ApiError): string | null {
    const errors = err.body.errors as Record<string, string[]> | undefined;
    return errors ? (Object.values(errors)[0]?.[0] ?? null) : null;
}

async function loadShift() {
    const { data } = await api.get('/pos/shifts/current', { params: { warehouse: warehouseId.value || undefined } });
    shift.value = data.data;
    suggestedOpening.value = data.meta.suggested_opening;
    shiftLoaded.value = true;
}

async function openShiftDialog() {
    closedShift.value = null;
    if (shift.value) await loadShift(); // fresh figures
    shiftOpen.value = true;
}

async function onShiftChanged(s: CashShift) {
    if (s.status === 'closed') {
        closedShift.value = s;
        shift.value = null;
        const { data } = await api.get('/pos/shifts/current', { params: { warehouse: warehouseId.value || undefined } });
        suggestedOpening.value = data.meta.suggested_opening;
    } else {
        const justOpened = !shift.value;
        shift.value = s;
        if (justOpened) {
            shiftOpen.value = false;
            focusSearch();
        }
    }
}

const stockOf = (variantId: string) => results.value.find((v) => v.id === variantId)?.stock?.available;
const quick = computed(() => quickCash(totals.value.total));
</script>

<template>
    <div class="flex h-[calc(100vh-4rem)] flex-col gap-3 lg:flex-row" data-testid="pos">
        <!-- Products -->
        <div class="flex min-h-0 flex-1 flex-col gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <Input ref="searchInput" v-model="search" class="min-w-64 flex-1 text-base" placeholder="Quét mã vạch hoặc tìm sản phẩm (F2)…" data-testid="pos-search" @keydown.enter.prevent="onSearchEnter" />
                <Select v-model="channelId" class="w-48" :options="channels.map((c) => ({ value: c.id, label: c.name }))" aria-label="Kênh bán" />
                <Select v-model="warehouseId" class="w-40" :options="warehouses.filter((w) => w.status === 'active').map((w) => ({ value: w.id, label: w.name }))" aria-label="Kho" />
                <Button :variant="shift ? 'outline' : 'default'" data-testid="pos-shift" @click="openShiftDialog"><Clock /> {{ shift ? `Ca ${shift.number}` : 'Mở ca' }}</Button>
                <RouterLink to="/pos/shifts" class="inline-flex size-9 items-center justify-center rounded-md border text-muted-foreground hover:bg-muted" title="Lịch sử ca" data-testid="pos-shift-history"><History class="size-4" /></RouterLink>
            </div>
            <div class="grid min-h-0 flex-1 auto-rows-min grid-cols-2 gap-2 overflow-y-auto sm:grid-cols-3 xl:grid-cols-4" data-testid="pos-products">
                <button
                    v-for="v in results"
                    :key="v.id"
                    type="button"
                    :disabled="(v.stock?.available ?? 0) <= 0"
                    :class="cn('flex flex-col rounded-lg border bg-card p-3 text-left shadow-xs transition hover:border-primary hover:shadow disabled:cursor-not-allowed disabled:opacity-50')"
                    @click="add(v)"
                >
                    <span class="line-clamp-2 text-sm font-medium">{{ v.product?.name }}</span>
                    <span class="text-xs text-muted-foreground">{{ v.name ?? '' }}</span>
                    <span class="mt-auto pt-2 font-mono text-[11px] text-muted-foreground">{{ v.sku }}</span>
                    <span class="flex items-end justify-between">
                        <span class="font-semibold tabular-nums text-primary">{{ formatMoney(v.ref_price) }}</span>
                        <span class="text-xs tabular-nums" :class="(v.stock?.available ?? 0) <= 0 ? 'text-destructive' : 'text-muted-foreground'">còn {{ formatNumber(v.stock?.available ?? 0) }}</span>
                    </span>
                </button>
            </div>
        </div>

        <!-- Cart -->
        <Card class="flex min-h-0 w-full flex-col lg:w-[26rem]">
            <div class="flex items-center gap-1 overflow-x-auto border-b px-2 py-1.5">
                <button
                    v-for="(c, i) in carts"
                    :key="c.no"
                    type="button"
                    :class="cn('flex items-center gap-1 rounded-md px-2.5 py-1 text-sm', i === active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted')"
                    @click="active = i"
                >
                    Đơn {{ c.no }}<span v-if="c.lines.length" class="text-xs opacity-80">({{ c.lines.length }})</span>
                    <X v-if="carts.length > 1" class="size-3.5 opacity-70 hover:opacity-100" @click.stop="closeCart(i)" />
                </button>
                <Button variant="ghost" size="icon" class="size-7" title="Thêm đơn chờ" data-testid="pos-new-cart" @click="newCart"><Plus /></Button>
            </div>

            <div class="relative border-b p-2">
                <div v-if="cart.customer" class="flex items-center justify-between rounded-md bg-muted/50 px-2 py-1.5 text-sm" data-testid="pos-customer">
                    <span class="flex items-center gap-1.5"><UserRound class="size-4" /> {{ cart.customer.name }} <span class="text-xs text-muted-foreground">{{ cart.customer.phone }}</span></span>
                    <Button variant="ghost" size="icon" class="size-6" @click="cart.customer = null"><X /></Button>
                </div>
                <template v-else>
                    <Input v-model="customerQuery" placeholder="Khách lẻ · tìm khách theo tên/SĐT để ghi nợ, tích lịch sử" class="h-8 text-sm" data-testid="pos-customer-search" />
                    <ul v-if="customerResults.length" class="absolute inset-x-2 z-20 mt-1 overflow-hidden rounded-md border bg-card shadow-lg">
                        <li v-for="c in customerResults" :key="c.id">
                            <button type="button" class="flex w-full justify-between px-3 py-2 text-left text-sm hover:bg-muted" @click="pickCustomer(c)">
                                <span>{{ c.name }}</span><span class="text-xs text-muted-foreground">{{ c.phone }}</span>
                            </button>
                        </li>
                    </ul>
                </template>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto" data-testid="pos-cart">
                <p v-if="!cart.lines.length" class="px-4 py-10 text-center text-sm text-muted-foreground">Chưa có sản phẩm. Quét mã vạch hoặc bấm vào sản phẩm.</p>
                <ul v-else class="divide-y">
                    <li v-for="(l, i) in cart.lines" :key="l.variant_id" class="px-3 py-2">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium">{{ l.name }}</p>
                                <p class="font-mono text-[11px] text-muted-foreground">{{ l.sku }}</p>
                            </div>
                            <Button variant="ghost" size="icon" class="size-7" title="Xóa" @click="cart.lines.splice(i, 1)"><Trash2 class="text-muted-foreground" /></Button>
                        </div>
                        <div class="mt-1 flex items-center gap-2">
                            <div class="flex items-center rounded-md border">
                                <button type="button" class="px-2 py-1" @click="l.quantity > 1 ? l.quantity-- : cart.lines.splice(i, 1)"><Minus class="size-3.5" /></button>
                                <input v-model.number="l.quantity" type="number" min="1" :data-testid="`pos-qty-${i}`" class="h-7 w-12 border-x bg-card text-center text-sm tabular-nums" />
                                <button type="button" class="px-2 py-1" @click="l.quantity++"><Plus class="size-3.5" /></button>
                            </div>
                            <span class="text-xs text-muted-foreground">×</span>
                            <input v-model.number="l.unit_price" type="number" min="0" :disabled="!canEditPrice" :title="canEditPrice ? undefined : 'Cấu hình không cho nhân viên sửa giá'" class="h-7 w-24 rounded-md border bg-card px-2 text-right text-sm tabular-nums disabled:bg-muted/50" />
                            <span class="ml-auto text-sm font-medium tabular-nums">{{ formatNumber(l.quantity * l.unit_price - l.discount) }}</span>
                        </div>
                        <p v-if="stockOf(l.variant_id) !== undefined && l.quantity > stockOf(l.variant_id)!" class="mt-1 text-xs text-destructive">Vượt tồn khả dụng ({{ stockOf(l.variant_id) }})</p>
                    </li>
                </ul>
            </div>

            <div class="space-y-2 border-t p-3 text-sm">
                <div class="flex justify-between"><span class="text-muted-foreground">Tiền hàng ({{ totals.quantity }} sp)</span><span class="tabular-nums">{{ formatMoney(totals.subtotal) }}</span></div>
                <div class="flex items-center justify-between"><span class="text-muted-foreground">Giảm giá</span><input v-model="cart.discount" type="number" min="0" :disabled="!canEditPrice" class="h-7 w-28 rounded-md border bg-card px-2 text-right tabular-nums disabled:bg-muted/50" data-testid="pos-discount" /></div>
                <div v-if="taxRule.rate > 0" class="flex justify-between text-muted-foreground" data-testid="pos-tax"><span>{{ taxRule.included ? 'Trong đó' : 'Cộng' }} thuế GTGT {{ taxRule.rate }}%</span><span class="tabular-nums">{{ formatMoney(totals.tax) }}</span></div>
                <div class="flex justify-between text-lg font-semibold"><span>Khách phải trả</span><span class="tabular-nums" data-testid="pos-total">{{ formatMoney(totals.total) }}</span></div>

                <div v-if="cart.split" class="space-y-1.5 rounded-md border p-2" data-testid="pos-split">
                    <div v-for="(r, i) in cart.split" :key="i" class="flex items-center gap-2">
                        <select v-model="r.method" class="h-8 flex-1 rounded-md border bg-card px-2 text-sm" :data-testid="`pos-split-method-${i}`">
                            <option v-for="m in posMethods" :key="m" :value="m">{{ paymentMethods[m] }}</option>
                        </select>
                        <input v-model="r.amount" type="number" min="0" class="h-8 w-32 rounded-md border bg-card px-2 text-right tabular-nums" :data-testid="`pos-split-amount-${i}`" />
                        <Button v-if="cart.split.length > 1" variant="ghost" size="icon" class="size-7" title="Bỏ dòng" @click="cart.split.splice(i, 1)"><X /></Button>
                    </div>
                    <div class="flex items-center justify-between">
                        <button type="button" class="text-xs font-medium text-primary hover:underline" @click="cart.split.push({ method: posMethods[0] ?? 'cash', amount: '' })">+ Thêm hình thức</button>
                        <button type="button" class="text-xs text-muted-foreground hover:underline" data-testid="pos-split-off" @click="toggleSplit">Một hình thức</button>
                    </div>
                </div>
                <div v-else class="grid gap-1" :style="{ gridTemplateColumns: `repeat(${Math.max(posMethods.length, 1)}, minmax(0, 1fr))` }">
                    <button
                        v-for="m in posMethods"
                        :key="m"
                        type="button"
                        :class="cn('flex flex-col items-center gap-0.5 rounded-md border px-1 py-1.5 text-xs', cart.method === m ? 'border-primary bg-primary/10 text-primary' : 'hover:bg-muted')"
                        :data-testid="`pos-method-${m}`"
                        @click="cart.method = m"
                    >
                        <component :is="methodIcons[m]" class="size-4" />{{ paymentMethods[m] }}
                    </button>
                </div>
                <button v-if="!cart.split && posMethods.length > 1" type="button" class="text-xs font-medium text-primary hover:underline" data-testid="pos-split-on" @click="toggleSplit">Chia nhiều hình thức thanh toán</button>
                <div v-if="!cart.split" class="flex items-center justify-between gap-2">
                    <span class="text-muted-foreground">Khách đưa</span>
                    <input v-model="cart.received" type="number" min="0" :placeholder="String(totals.total)" class="h-8 w-36 rounded-md border bg-card px-2 text-right text-base tabular-nums" data-testid="pos-received" />
                </div>
                <div v-if="!cart.split && cart.method === 'cash' && totals.total > 0" class="flex flex-wrap gap-1">
                    <button v-for="q in quick" :key="q" type="button" class="rounded border px-2 py-0.5 text-xs tabular-nums hover:bg-muted" @click="cart.received = q">{{ formatNumber(q) }}</button>
                </div>
                <div v-if="change > 0" class="flex justify-between font-medium text-success"><span>Trả lại khách</span><span class="tabular-nums" data-testid="pos-change">{{ formatMoney(change) }}</span></div>
                <div v-if="shortfall > 0" class="flex justify-between font-medium text-destructive"><span>{{ cart.customer ? 'Ghi nợ' : 'Còn thiếu' }}</span><span class="tabular-nums">{{ formatMoney(shortfall) }}</span></div>
                <p v-if="error" class="text-sm text-destructive" data-testid="pos-error">{{ error }}</p>
                <div class="flex items-center justify-between">
                    <label class="flex items-center gap-1.5 text-xs text-muted-foreground"><input v-model="autoPrint" type="checkbox" /> <Printer class="size-3.5" /> In hóa đơn K80</label>
                </div>
                <Button class="h-12 w-full text-base" :loading="paying" :disabled="!cart.lines.length" data-testid="pos-pay" @click="pay">Thanh toán (F9)</Button>
            </div>
        </Card>
    </div>

    <Dialog v-model:open="shiftOpen" :title="closedShift ? `Đã đóng ca ${closedShift.number}` : shift ? 'Ca bán hàng' : 'Mở ca bán hàng'" size="lg">
        <ShiftPanel :shift="closedShift ?? shift" :warehouse-id="warehouseId" :suggested-opening="suggestedOpening" @changed="onShiftChanged" />
        <template v-if="closedShift" #footer><Button variant="outline" @click="closedShift = null">Mở ca mới</Button><Button @click="shiftOpen = false">Xong</Button></template>
    </Dialog>
</template>
