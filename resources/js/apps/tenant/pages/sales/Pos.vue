<script setup lang="ts">
import { orderCode } from '@/shared/orderCode';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { ArrowLeft, Banknote, Clock, CloudOff, CreditCard, History, Minus, NotebookPen, Plus, Printer, QrCode, RefreshCw, ScanBarcode, Store, Trash2, UserRound, Wallet, X } from 'lucide-vue-next';
import { RouterLink, useRouter } from 'vue-router';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import Input from '@/shared/ui/Input.vue';
import { api, ApiError, getApiTenant, idempotent, newIdempotencyKey } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { useAuthStore } from '@/shared/stores/auth';
import { paymentMethods } from '../orders/labels';
import { printInvoice } from '../orders/invoice';
import { printShopFrom } from '../orders/printShop';
import { useSettingsStore } from '@/shared/stores/settings';
import { addToCart, applyGroup, cartTotals, emptyCart, quickCash, splitPayments, syncGroupDiscount, type Cart } from './pos';
import ShiftPanel from './ShiftPanel.vue';
import OfflineQueue from './OfflineQueue.vue';
import { findByCode, isNetworkError, loadCatalog, loadQueue, loadSetup, offlineNumber, pendingCount, failedCount, pruneSent, saveCatalog, saveQueue, saveSetup, searchCatalog, takeStock, type CatalogItem, type OfflineSale } from './offline';
import { previewPromotions, type PromotionPreview } from '../promotions/promotions';
import type { CashShift, Customer, OrderDetail, SalesChannel, Variant, Warehouse } from '@/shared/types';

const STORAGE_KEY = 'ecso.pos.carts';
const tenantKey = getApiTenant() ?? 'default';
const toast = useToast();
const tenant = useTenantStore();
const auth = useAuthStore();
const router = useRouter();
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
// Khuyến mãi (C4): what the running promotions give this cart; the server applies the same when the sale is saved.
const promo = ref<PromotionPreview | null>(null);
const promoDiscount = computed(() => Number(promo.value?.discount ?? 0));
const promoGifts = computed(() => (promo.value?.applied ?? []).flatMap((a) => a.gifts.map((g) => ({ ...g, promotion: a.name }))));
const promoNames = computed(() => (promo.value?.applied ?? []).filter((a) => a.type !== 'gift' && Number(a.amount) > 0).map((a) => a.name).join(', '));
const loyalty = computed(() => (cart.value.customer ? (promo.value?.loyalty ?? null) : null));
const pointsDiscount = computed(() => Math.min(Number(cart.value.points) || 0, loyalty.value?.max ?? 0) * (loyalty.value?.point_value ?? 0));
const totals = computed(() => cartTotals({ ...cart.value, discount: (Number(cart.value.discount) || 0) + promoDiscount.value + pointsDiscount.value }, taxRule.value));
let promoSeq = 0;
async function loadPromo() {
    const seq = ++promoSeq;
    const c = cart.value;
    if (!c.lines.length || !online.value) {
        promo.value = null; // offline: no promotions, the server is told so when the sale is sent
        return;
    }
    try {
        const res = await previewPromotions({
            channel_id: channelId.value || null,
            customer_id: c.customer?.id ?? null,
            coupon_code: c.coupon?.trim() || null,
            discount: Number(c.discount) || 0,
            lines: c.lines.map((l) => ({ variant_id: l.variant_id, quantity: l.quantity, unit_price: l.unit_price, discount: l.discount })),
        });
        if (seq === promoSeq) promo.value = res;
    } catch {
        if (seq === promoSeq) promo.value = null;
    }
}
let promoTimer: ReturnType<typeof setTimeout> | undefined;
watch(
    () => [active.value, channelId.value, cart.value.customer?.id, cart.value.coupon, cart.value.discount, cart.value.lines.map((l) => [l.variant_id, l.quantity, l.unit_price, l.discount])],
    () => {
        clearTimeout(promoTimer);
        promoTimer = setTimeout(loadPromo, 300);
    },
    { deep: true },
);

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

// Bán khi mất mạng: the till keeps selling from the catalog on this device and sends the sales once back online.
const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine);
const catalog = ref<CatalogItem[]>([]);
const catalogAt = ref<string | null>(null);
const queue = ref<OfflineSale[]>(pruneSent(loadQueue(tenantKey)));
const queueOpen = ref(false);
const syncing = ref(false);
const pending = computed(() => pendingCount(queue.value));
const failed = computed(() => failedCount(queue.value));
watch(queue, (q) => saveQueue(tenantKey, q), { deep: true });

function goOffline() {
    if (online.value) toast.error('Mất kết nối mạng. Vẫn bán bình thường, đơn sẽ tự gửi khi có mạng lại.');
    online.value = false;
}
async function goOnline() {
    online.value = true;
    await syncQueue();
    refreshCatalog();
    if (!shiftLoaded.value) loadShift().catch(() => {});
}

/** Downloads the catalog of the counter's warehouse for offline selling (in the background, every 15 minutes). */
async function refreshCatalog() {
    if (!warehouseId.value || !online.value) return;
    const wh = warehouseId.value;
    try {
        const { data } = await api.get('/pos/catalog', { params: { warehouse: wh } });
        if (wh !== warehouseId.value) return;
        catalog.value = data.data;
        catalogAt.value = data.meta.at;
        saveCatalog(tenantKey, wh, data.data, data.meta.at);
    } catch (e) {
        if (isNetworkError(e)) goOffline();
    }
}
function useCachedCatalog() {
    const cached = warehouseId.value ? loadCatalog(tenantKey, warehouseId.value) : null;
    catalog.value = cached?.items ?? [];
    catalogAt.value = cached?.at ?? null;
}
let catalogTimer: ReturnType<typeof setInterval> | undefined;

/** Sends the sales kept offline, oldest first; stops at the first connection problem and tries again later. */
async function syncQueue() {
    if (syncing.value || !online.value) return;
    syncing.value = true;
    let sent = 0;
    try {
        for (const sale of queue.value.filter((s) => s.status === 'pending')) {
            try {
                const { data } = await api.post('/sales-orders', sale.payload, idempotent(`${sale.key}-off`));
                sale.status = 'sent';
                sale.order_number = orderCode(data.data);
                sale.error = null;
                sent++;
            } catch (e) {
                if (isNetworkError(e)) {
                    goOffline();
                    break;
                }
                const err = e as ApiError;
                sale.status = 'failed';
                sale.error = err.field('stock') ?? err.field('payments') ?? collectFirst(err) ?? err.message;
            }
        }
    } finally {
        syncing.value = false;
    }
    if (sent) toast.success(`Đã gửi ${sent} đơn bán lúc mất mạng.`);
    if (failed.value) toast.error(`${failed.value} đơn bán lúc mất mạng chưa gửi được. Bấm vào thanh báo để xem.`);
}

/** Sends one kept sale again; with `asPending` it goes in as an order waiting to be handled (e.g. stock was short). */
async function retrySale(sale: OfflineSale, asPending = false) {
    if (asPending) sale.payload = { ...sale.payload, stage: 'pending' };
    sale.status = 'pending';
    sale.error = null;
    await syncQueue();
}
function dropSale(sale: OfflineSale) {
    queue.value = queue.value.filter((s) => s.key !== sale.key);
}

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
    window.addEventListener('online', goOnline);
    window.addEventListener('offline', goOffline);
    window.addEventListener('keydown', onKey);
    try {
        const [c, w, s] = await Promise.all([api.get('/sales-channels'), api.get('/warehouses'), settings.load()]);
        autoPrint.value = s.sales.pos_auto_print;
        // Empty carts start on the counter's default method.
        carts.value.forEach((ct) => !ct.lines.length && posMethods.value.includes(s.payments.default_pos as never) && (ct.method = s.payments.default_pos as Cart['method']));
        channels.value = (c.data.data as SalesChannel[]).filter((ch) => ch.is_active);
        warehouses.value = (w.data.data as Warehouse[]).filter((x) => x.purpose !== 'defective');
        saveSetup(tenantKey, { channels: channels.value, warehouses: warehouses.value });
    } catch (e) {
        if (!isNetworkError(e)) throw e;
        // Opened without network: the counter setup this device kept last time.
        goOffline();
        const saved = loadSetup<{ channels: SalesChannel[]; warehouses: Warehouse[] }>(tenantKey);
        channels.value = saved?.channels ?? [];
        warehouses.value = saved?.warehouses ?? [];
    }
    const pos = channels.value.find((ch) => ch.type === 'pos') ?? channels.value[0];
    channelId.value = pos?.id ?? '';
    warehouseId.value = pos?.warehouse?.id ?? warehouses.value.find((x) => x.is_default)?.id ?? warehouses.value[0]?.id ?? '';
    useCachedCatalog();
    await Promise.all([runSearch(), loadShift()]);
    if (shiftLoaded.value && !shift.value) shiftOpen.value = true;
    focusSearch();
    if (online.value) {
        refreshCatalog();
        syncQueue();
    }
    catalogTimer = setInterval(refreshCatalog, 15 * 60 * 1000);
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    window.removeEventListener('online', goOnline);
    window.removeEventListener('offline', goOffline);
    clearInterval(catalogTimer);
});

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
watch(warehouseId, (wh, old) => {
    if (old === undefined || old === '') return;
    useCachedCatalog();
    refreshCatalog();
    runSearch();
});

async function runSearch() {
    if (!warehouseId.value) return;
    if (!online.value) {
        results.value = searchCatalog(catalog.value, search.value);
        return;
    }
    try {
        const { data } = await api.get('/variants', { params: { q: search.value || undefined, warehouse: warehouseId.value, sellable: 1 } });
        results.value = data.data;
    } catch (e) {
        if (!isNetworkError(e)) throw e;
        goOffline();
        results.value = searchCatalog(catalog.value, search.value);
    }
}

/** Scanner: types the code then Enter. An exact barcode/SKU match is added at once. */
async function onSearchEnter() {
    clearTimeout(timer);
    await runSearch();
    const term = search.value.trim().toLowerCase();
    const exact = (!online.value ? findByCode(catalog.value, term) : undefined) ?? results.value.find((v) => v.barcode?.toLowerCase() === term || v.sku.toLowerCase() === term) ?? (results.value.length === 1 ? results.value[0] : undefined);
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
    } else if (e.key === 'F4') {
        e.preventDefault();
        if (cart.value.customer) dropCustomer();
        nextTick(() => document.querySelector<HTMLInputElement>('[data-testid="pos-customer-search"]')?.focus());
    } else if (e.key === 'F6') {
        e.preventDefault();
        document.querySelector<HTMLInputElement>('[data-testid="pos-discount"]')?.focus();
    } else if (e.key === 'F9') {
        e.preventDefault();
        pay();
    }
}

let customerTimer: ReturnType<typeof setTimeout> | undefined;
watch(customerQuery, (q) => {
    clearTimeout(customerTimer);
    if (!q.trim()) return void (customerResults.value = []);
    if (!online.value) return void (customerResults.value = []);
    customerTimer = setTimeout(async () => {
        try {
            const { data } = await api.get('/customers', { params: { q, per_page: 6 } });
            customerResults.value = data.data;
        } catch (e) {
            if (!isNetworkError(e)) throw e;
            goOffline();
        }
    }, 250);
});

function pickCustomer(c: Customer) {
    cart.value.customer = { id: c.id, name: c.name, phone: c.phone, code: c.code };
    applyGroup(cart.value, c.group);
    if (c.group?.payment_method && posMethods.value.includes(c.group.payment_method as never)) cart.value.method = c.group.payment_method as Cart['method'];
    customerQuery.value = '';
    customerResults.value = [];
}

function dropCustomer() {
    cart.value.customer = null;
    if (cart.value.discountAuto) cart.value.discount = 0;
    applyGroup(cart.value, null);
}
watch(() => cartTotals({ ...cart.value, discount: 0 }).subtotal, () => syncGroupDiscount(cart.value));

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
    clearTimeout(promoTimer);
    if (!online.value) return keepOffline();
    await loadPromo(); // the amount taken must match the promotions the server applies
    if (!online.value) return keepOffline(); // the connection went while checking promotions
    const collected = Math.min(received.value, totals.value.total);
    // Same key on every retry of this cart: a sale that went through but whose answer was lost is not sold again.
    const key = (cart.value.key ??= newIdempotencyKey());
    try {
        const { data } = await api.post('/sales-orders', {
            channel_id: channelId.value,
            warehouse_id: warehouseId.value,
            stage: 'completed',
            counter: true,
            offline_key: key,
            customer_id: cart.value.customer?.id ?? null,
            discount: Number(cart.value.discount) || 0,
            coupon_code: cart.value.coupon?.trim() || null,
            points_used: cart.value.customer ? Math.min(Number(cart.value.points) || 0, loyalty.value?.max ?? 0) : 0,
            lines: cart.value.lines.map((l) => ({ variant_id: l.variant_id, quantity: l.quantity, unit_price: l.unit_price, discount: l.discount })),
            payments: split.value ? split.value.payments : collected > 0 ? [{ method: cart.value.method, amount: collected }] : [],
        }, idempotent(key));
        toast.success(`Đã bán ${orderCode(data.data)}${change.value > 0 ? ` · trả lại khách ${formatMoney(change.value)}` : ''}`);
        if (autoPrint.value) {
            const detail = await api.get(`/orders/${data.data.id}`);
            printInvoice(detail.data.data as OrderDetail, printShopFrom(settings.data, tenant.current?.name ?? ''), 'k80');
        }
        carts.value[active.value] = freshCart(cart.value.no);
        await runSearch();
        focusSearch();
    } catch (e) {
        if (isNetworkError(e)) {
            // No answer: keep the sale on this device; the server finds it by its key if it did get through.
            goOffline();
            return keepOffline();
        }
        const err = e as ApiError;
        error.value = err.field('stock') ?? err.field('payments') ?? collectFirst(err) ?? err.message;
    } finally {
        paying.value = false;
    }
}

/**
 * Sale while offline: kept on this device with the time it was made, at the prices on screen and without
 * promotions, coupon or points (they need the server). The receipt prints from what the till knows.
 */
function keepOffline() {
    try {
        const c = cart.value;
        const t = cartTotals({ ...c, discount: Number(c.discount) || 0 }, taxRule.value);
        const pay = split.value && !split.value.error ? split.value : null;
        const got = pay ? pay.paid : c.received === '' ? t.total : Number(c.received) || 0;
        if (got < t.total && !c.customer) {
            error.value = 'Khách lẻ phải thanh toán đủ. Chọn khách hàng để ghi nợ phần còn lại.';
            return;
        }
        const collected = Math.min(got, t.total);
        const key = (c.key ??= newIdempotencyKey());
        const lines = c.lines.map((l) => ({ variant_id: l.variant_id, quantity: l.quantity, unit_price: l.unit_price, discount: l.discount }));
        const payments = pay ? pay.payments : collected > 0 ? [{ method: c.method, amount: collected }] : [];
        const number = offlineNumber(queue.value);
        const now = new Date();
        queue.value.push({
            key,
            number,
            created_at: now.toISOString(),
            status: 'pending',
            summary: { items: t.quantity, total: t.total, customer: c.customer?.name ?? null, method: c.method },
            payload: {
                channel_id: channelId.value, warehouse_id: warehouseId.value, stage: 'completed', counter: true, offline: true, offline_key: key,
                placed_at: now.toISOString(), promotions_off: true, coupon_code: null, points_used: 0,
                customer_id: c.customer?.id ?? null, discount: Number(c.discount) || 0, lines, payments,
            },
        });
        takeStock(catalog.value, lines);
        saveCatalog(tenantKey, warehouseId.value, catalog.value, catalogAt.value ?? now.toISOString());
        const back = Math.max(0, got - t.total);
        toast.success(`Đã lưu đơn ${number} trên máy${back > 0 ? ` · trả lại khách ${formatMoney(back)}` : ''}. Sẽ tự gửi khi có mạng.`);
        if (autoPrint.value) printInvoice(offlineReceipt(number, now, t, payments, c), printShopFrom(settings.data, tenant.current?.name ?? ''), 'k80');
        carts.value[active.value] = freshCart(c.no);
        results.value = searchCatalog(catalog.value, search.value);
        focusSearch();
    } finally {
        paying.value = false;
    }
}

/** What the K80 receipt needs, from the cart (the real order code comes once the sale is sent). */
function offlineReceipt(number: string, at: Date, t: ReturnType<typeof cartTotals>, payments: { method: string; amount: number }[], c: Cart): OrderDetail {
    const paid = payments.reduce((s, p) => s + p.amount, 0);
    return {
        external_order_id: number,
        placed_at: at.toISOString(),
        channel: channels.value.find((ch) => ch.id === channelId.value) ?? null,
        buyer_name: c.customer?.name ?? null,
        buyer_phone: c.customer?.phone ?? null,
        shipping_address: null,
        buyer_note: 'Bán khi mất mạng, mã đơn chính thức có khi gửi lên hệ thống.',
        items: c.lines.map((l) => ({ name: l.name, variant_name: null, seller_sku: l.sku, variant: { sku: l.sku }, quantity: l.quantity, unit_price: String(l.unit_price), discount: String(l.discount) })),
        subtotal: String(t.subtotal),
        shipping_fee: '0',
        discount: String(Number(c.discount) || 0),
        tax_amount: String(t.tax),
        tax_rate: String(taxRule.value.rate),
        prices_include_tax: taxRule.value.included,
        total: String(t.total),
        paid_amount: String(paid),
        payments: payments.map((p) => ({ kind: 'payment', method: p.method, amount: String(p.amount), voided_at: null })),
    } as unknown as OrderDetail;
}

function collectFirst(err: ApiError): string | null {
    const errors = err.body.errors as Record<string, string[]> | undefined;
    return errors ? (Object.values(errors)[0]?.[0] ?? null) : null;
}

async function loadShift(): Promise<void> {
    try {
        const { data } = await api.get('/pos/shifts/current', { params: { warehouse: warehouseId.value || undefined } });
        shift.value = data.data;
        suggestedOpening.value = data.meta.suggested_opening;
        shiftLoaded.value = true;
        saveSetup(`${tenantKey}.shift`, { channels: [], warehouses: [], shift: data.data });
    } catch (e) {
        if (!isNetworkError(e)) throw e;
        // Offline: show the shift this device saw last; the server puts offline sales in the open shift when sent.
        shift.value = loadSetup<{ shift: CashShift | null }>(`${tenantKey}.shift`)?.shift ?? null;
        goOffline();
    }
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

// Dịch vụ, thuê bao: sold without stock.
const noStock = (v: { product?: { type?: string } | null } | undefined) => ['service', 'subscription'].includes(v?.product?.type ?? '');
const stockOf = (variantId: string) => {
    const v = results.value.find((r) => r.id === variantId);
    return noStock(v) ? undefined : v?.stock?.available;
};
const quick = computed(() => quickCash(totals.value.total).slice(0, 4));

/** Thoát: back to where the cashier came from, or the order list. */
function leave() {
    if (window.history.state?.back) router.back();
    else router.push('/orders');
}
const shiftLabel = computed(() => {
    if (!shift.value) return 'Chưa mở ca';
    const at = new Date(shift.value.opened_at).toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });
    return `Ca ${shift.value.number} · mở ${at} · ${shift.value.totals?.orders ?? 0} đơn`;
});
/** Two letters for a product without a photo: first letters of the first two words of the product name. */
const mono = (name: string) =>
    (name.split(' · ')[0] ?? name)
        .split(/\s+/)
        .filter((w) => /\p{L}/u.test(w))
        .slice(0, 2)
        .map((w) => w.match(/\p{L}/u)![0])
        .join('')
        .toUpperCase();
/** Typed prices may carry dots ("159.000"). */
const parseMoney = (e: Event) => Number((e.target as HTMLInputElement).value.replace(/\D/g, '')) || 0;
const warehouseOptions = computed(() => warehouses.value.filter((w) => w.status === 'active'));
</script>

<template>
    <div class="flex min-h-screen flex-col bg-slate-50 lg:h-screen" data-testid="pos">
        <!-- Dark top bar: leave, the open sales (đơn chờ), counter and warehouse, shift, cashier. -->
        <header class="flex shrink-0 flex-wrap items-center gap-2 bg-slate-900 px-3 py-2 text-sm text-slate-200 lg:flex-nowrap lg:px-4">
            <button type="button" class="flex h-9 items-center gap-1.5 rounded-lg px-2.5 hover:bg-white/10" data-testid="pos-leave" @click="leave"><ArrowLeft class="size-4" /> Thoát</button>
            <div class="order-last flex w-full min-w-0 items-center gap-1.5 overflow-x-auto lg:order-none lg:w-auto lg:flex-1">
                <button
                    v-for="(c, i) in carts"
                    :key="c.no"
                    type="button"
                    :class="cn('flex h-9 shrink-0 items-center gap-2 rounded-lg px-3 font-medium transition', i === active ? 'bg-white text-slate-900' : 'bg-white/10 text-slate-200 hover:bg-white/15')"
                    @click="active = i"
                >
                    Đơn {{ c.no }}<span v-if="c.lines.length && i !== active" class="text-xs font-normal opacity-75">· {{ c.lines.length }} món</span>
                    <X v-if="carts.length > 1" class="size-3.5 opacity-60 hover:opacity-100" @click.stop="closeCart(i)" />
                </button>
                <button type="button" class="grid size-9 shrink-0 place-items-center rounded-lg bg-white/10 hover:bg-white/15" title="Thêm đơn chờ" data-testid="pos-new-cart" @click="newCart"><Plus class="size-4" /></button>
            </div>
            <div class="ml-auto flex flex-wrap items-center gap-1">
                <button
                    v-if="!online || pending || failed"
                    type="button"
                    :class="cn('flex h-9 items-center gap-1.5 rounded-lg px-3 font-medium', !online ? 'bg-amber-400 text-slate-900 hover:bg-amber-300' : failed ? 'bg-rose-500 text-white hover:bg-rose-400' : 'bg-white/10 hover:bg-white/15')"
                    data-testid="pos-offline"
                    @click="queueOpen = true"
                >
                    <CloudOff v-if="!online" class="size-4" /><RefreshCw v-else :class="cn('size-4', syncing && 'animate-spin')" />
                    <span v-if="!online">Mất mạng · bán trên máy<template v-if="pending"> · {{ pending }} đơn chờ gửi</template></span>
                    <span v-else-if="failed">{{ failed }} đơn chưa gửi được</span>
                    <span v-else>Đang gửi {{ pending }} đơn</span>
                </button>
                <label class="flex h-9 items-center gap-1.5 rounded-lg px-2 hover:bg-white/10">
                    <Store class="size-4 shrink-0 text-slate-400" />
                    <select v-model="channelId" class="max-w-36 cursor-pointer appearance-none bg-transparent outline-none" aria-label="Kênh bán">
                        <option v-for="c in channels" :key="c.id" :value="c.id" class="text-slate-900">{{ c.name }}</option>
                    </select>
                    <span class="text-slate-500">·</span>
                    <select v-model="warehouseId" class="max-w-32 cursor-pointer appearance-none bg-transparent outline-none" aria-label="Kho">
                        <option v-for="w in warehouseOptions" :key="w.id" :value="w.id" class="text-slate-900">{{ w.name }}</option>
                    </select>
                </label>
                <span class="hidden h-9 items-center gap-1.5 px-2 xl:flex"><UserRound class="size-4 text-slate-400" /> Thu ngân: {{ auth.user?.name }}</span>
                <RouterLink to="/pos/shifts" class="grid size-9 place-items-center rounded-lg text-slate-300 hover:bg-white/10" title="Lịch sử ca" data-testid="pos-shift-history"><History class="size-4" /></RouterLink>
                <button
                    type="button"
                    :class="cn('flex h-9 items-center gap-1.5 rounded-lg px-3 font-medium', shift ? 'bg-emerald-500/15 text-emerald-300 hover:bg-emerald-500/25' : 'bg-amber-400 text-slate-900 hover:bg-amber-300')"
                    :title="shift ? shiftLabel : undefined"
                    data-testid="pos-shift"
                    @click="openShiftDialog"
                >
                    <span v-if="shift" class="size-2 rounded-full bg-emerald-400" /><Clock v-else class="size-4" /> <span class="max-w-64 truncate">{{ shift ? shiftLabel : 'Mở ca' }}</span>
                </button>
            </div>
        </header>

        <div class="flex flex-1 flex-col lg:min-h-0 lg:flex-row">
            <!-- Left: scan box, the cart as a table, quick-add products. -->
            <section class="flex flex-1 flex-col gap-3 p-3 lg:min-h-0 lg:p-4">
                <div class="relative">
                    <ScanBarcode class="pointer-events-none absolute left-4 top-1/2 size-6 -translate-y-1/2 text-primary" :stroke-width="1.75" />
                    <Input
                        ref="searchInput"
                        v-model="search"
                        class="h-14 rounded-xl border-2 border-primary/60 bg-white pl-14 pr-14 text-lg shadow-sm focus-visible:border-primary"
                        placeholder="Quét mã vạch hoặc gõ tên, SKU (F2)"
                        data-testid="pos-search"
                        @keydown.enter.prevent="onSearchEnter"
                    />
                    <kbd class="absolute right-4 top-1/2 -translate-y-1/2 rounded border bg-slate-50 px-1.5 text-xs text-muted-foreground">F2</kbd>
                </div>

                <div class="flex flex-col overflow-hidden rounded-xl border bg-white lg:min-h-64 lg:flex-1">
                    <div class="hidden grid-cols-[2.5rem_minmax(0,1fr)_8.5rem_7rem_7rem_2.5rem] items-center gap-3 border-b border-slate-100 bg-slate-50/70 px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-slate-400 md:grid">
                        <span>#</span><span>Sản phẩm</span><span class="text-center">Số lượng</span><span class="text-right">Đơn giá</span><span class="text-right">Thành tiền</span><span />
                    </div>
                    <div class="lg:min-h-0 lg:flex-1 lg:overflow-y-auto" data-testid="pos-cart">
                        <div v-if="!cart.lines.length" class="flex h-full min-h-48 flex-col items-center justify-center gap-2 px-4 text-center text-sm text-muted-foreground">
                            <ScanBarcode class="size-10 text-slate-300" :stroke-width="1.5" />
                            Chưa có sản phẩm. Quét mã vạch hoặc bấm vào sản phẩm.
                        </div>
                        <ul v-else class="divide-y">
                            <li
                                v-for="(l, i) in cart.lines"
                                :key="l.variant_id"
                                :class="cn('grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-2 px-4 py-3 md:grid-cols-[2.5rem_minmax(0,1fr)_8.5rem_7rem_7rem_2.5rem]', i === cart.lines.length - 1 && 'bg-primary/[0.03]')"
                            >
                                <span class="hidden text-sm text-muted-foreground md:block">{{ i + 1 }}</span>
                                <div class="flex min-w-0 items-center gap-3">
                                    <img v-if="l.image" :src="l.image" alt="" class="size-11 shrink-0 rounded-lg border object-cover" />
                                    <span v-else class="grid size-11 shrink-0 place-items-center rounded-lg bg-slate-100 text-xs font-semibold text-slate-500">{{ mono(l.name) }}</span>
                                    <div class="min-w-0">
                                        <p class="truncate font-medium">{{ l.name }}</p>
                                        <p class="text-xs text-muted-foreground">
                                            <span class="font-mono">{{ l.sku }}</span><template v-if="stockOf(l.variant_id) !== undefined"> · tồn {{ formatNumber(stockOf(l.variant_id)!) }}</template>
                                        </p>
                                        <p v-if="stockOf(l.variant_id) !== undefined && l.quantity > stockOf(l.variant_id)!" class="text-xs text-destructive">Vượt tồn khả dụng ({{ stockOf(l.variant_id) }})</p>
                                    </div>
                                </div>
                                <button type="button" class="grid size-8 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-destructive md:hidden" title="Xóa" @click="cart.lines.splice(i, 1)"><Trash2 class="size-4" /></button>
                                <div class="flex items-center justify-center gap-1 max-md:col-start-1 max-md:justify-start">
                                    <button type="button" class="grid size-8 place-items-center rounded-lg border hover:bg-slate-50" aria-label="Bớt" @click="l.quantity > 1 ? l.quantity-- : cart.lines.splice(i, 1)"><Minus class="size-3.5" /></button>
                                    <input v-model.number="l.quantity" type="number" min="1" :data-testid="`pos-qty-${i}`" class="h-8 w-12 rounded-lg border bg-white text-center font-medium tabular-nums [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none" />
                                    <button type="button" class="grid size-8 place-items-center rounded-lg border hover:bg-slate-50" aria-label="Thêm" @click="l.quantity++"><Plus class="size-3.5" /></button>
                                </div>
                                <input
                                    :value="formatNumber(l.unit_price)"
                                    inputmode="numeric"
                                    :disabled="!canEditPrice"
                                    :title="canEditPrice ? 'Sửa đơn giá' : 'Cấu hình không cho nhân viên sửa giá'"
                                    class="hidden h-8 w-full rounded-lg border border-transparent bg-transparent px-2 text-right tabular-nums hover:border-slate-200 focus:border-primary focus:bg-white disabled:hover:border-transparent md:block"
                                    @change="l.unit_price = parseMoney($event)"
                                />
                                <span class="text-right font-semibold tabular-nums max-md:row-start-2 max-md:col-start-2">{{ formatNumber(l.quantity * l.unit_price - l.discount) }}</span>
                                <button type="button" class="hidden size-8 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-destructive md:grid" title="Xóa" @click="cart.lines.splice(i, 1)"><Trash2 class="size-4" /></button>
                            </li>
                        </ul>
                    </div>
                </div>

                <div>
                    <p class="mb-2 flex items-center justify-between text-[12px] font-semibold uppercase tracking-wider text-slate-400"><span>{{ search.trim() ? `Kết quả cho “${search.trim()}”` : 'Sản phẩm bán tại quầy' }}</span><span class="font-normal normal-case tracking-normal">Bấm để thêm</span></p>
                    <div class="grid max-h-[8.5rem] grid-cols-2 gap-2 overflow-y-auto sm:grid-cols-3 xl:grid-cols-5 2xl:grid-cols-6" data-testid="pos-products">
                        <button
                            v-for="v in results"
                            :key="v.id"
                            type="button"
                            :disabled="!noStock(v) && (v.stock?.available ?? 0) <= 0"
                            class="flex min-w-0 items-center gap-2.5 rounded-xl border bg-white p-2 text-left transition hover:border-primary hover:shadow-sm disabled:cursor-not-allowed disabled:opacity-50"
                            :title="`${v.product?.name ?? ''}${v.name ? ' · ' + v.name : ''} · còn ${formatNumber(v.stock?.available ?? 0)}`"
                            @click="add(v)"
                        >
                            <img v-if="v.product?.image" :src="v.product.image" alt="" class="size-10 shrink-0 rounded-lg object-cover" />
                            <span v-else class="grid size-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-xs font-semibold text-primary">{{ mono(v.product?.name ?? v.sku) }}</span>
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium">{{ v.product?.name }}<template v-if="v.name"> · {{ v.name }}</template></span>
                                <span class="flex gap-1 whitespace-nowrap text-xs tabular-nums text-muted-foreground">
                                    {{ formatNumber(Number(v.ref_price)) }}
                                    <span v-if="noStock(v)">· {{ v.product?.type === 'subscription' ? 'thuê bao' : 'dịch vụ' }}</span>
                                    <span v-else :class="(v.stock?.available ?? 0) <= 0 ? 'text-destructive' : ''">· còn {{ formatNumber(v.stock?.available ?? 0) }}</span>
                                </span>
                            </span>
                        </button>
                    </div>
                </div>
            </section>

            <!-- Right: buyer, totals, payment. -->
            <aside class="flex w-full shrink-0 flex-col border-t bg-white lg:w-[26rem] lg:border-l lg:border-t-0 xl:w-[28rem]" data-testid="pos-cart-panel">
                <div class="flex-1 space-y-4 p-4 lg:min-h-0 lg:overflow-y-auto">
                    <div class="relative">
                        <div v-if="cart.customer" class="flex items-center gap-3 rounded-xl border p-3" data-testid="pos-customer">
                            <span class="grid size-11 shrink-0 place-items-center rounded-full bg-primary/10 text-sm font-semibold text-primary">{{ mono(cart.customer.name) }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="flex items-center gap-2 truncate font-medium">
                                    {{ cart.customer.name }}
                                    <span v-if="cart.tier === 'wholesale'" class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">Giá buôn</span>
                                </p>
                                <p class="text-xs text-muted-foreground">{{ cart.customer.phone ?? cart.customer.code }}<template v-if="loyalty?.enabled"> · {{ formatNumber(loyalty.balance) }} điểm</template></p>
                            </div>
                            <button type="button" class="grid size-8 place-items-center rounded-lg text-slate-400 hover:bg-slate-100" title="Bỏ khách (F4)" @click="dropCustomer"><X class="size-4" /></button>
                        </div>
                        <template v-else>
                            <div class="relative">
                                <UserRound class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                                <Input v-model="customerQuery" placeholder="Tìm khách theo tên hoặc SĐT (F4)" class="h-11 rounded-xl pl-9" data-testid="pos-customer-search" />
                            </div>
                            <ul v-if="customerResults.length" class="absolute inset-x-0 z-20 mt-1 overflow-hidden rounded-xl border bg-white shadow-lg">
                                <li v-for="c in customerResults" :key="c.id">
                                    <button type="button" class="flex w-full justify-between px-3 py-2.5 text-left text-sm hover:bg-slate-50" @click="pickCustomer(c)">
                                        <span>{{ c.name }}</span><span class="text-xs text-muted-foreground">{{ c.phone }}</span>
                                    </button>
                                </li>
                            </ul>
                        </template>
                        <p class="mt-1.5 text-xs text-muted-foreground">F4 đổi khách · bỏ trống = khách lẻ (chọn khách để ghi nợ, tích điểm)</p>
                    </div>

                    <div class="space-y-2.5 border-t pt-4 text-sm">
                        <div class="flex justify-between"><span class="text-muted-foreground">Tạm tính · {{ totals.quantity }} sản phẩm</span><span class="tabular-nums">{{ formatMoney(totals.subtotal) }}</span></div>
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-muted-foreground">Giảm giá (F6)<template v-if="cart.discountAuto"> · nhóm {{ cart.groupPercent }}%</template></span>
                            <input v-model="cart.discount" type="number" min="0" :disabled="!canEditPrice" placeholder="0" class="h-9 w-32 rounded-lg border bg-white px-2.5 text-right tabular-nums text-destructive [appearance:textfield] disabled:bg-slate-50 [&::-webkit-inner-spin-button]:appearance-none" data-testid="pos-discount" @input="cart.discountAuto = false" />
                        </div>
                        <div v-if="promoDiscount > 0" class="flex justify-between text-emerald-700" data-testid="pos-promotion"><span>Khuyến mãi <span class="text-xs">{{ promoNames }}</span></span><span class="tabular-nums">−{{ formatMoney(promoDiscount) }}</span></div>
                        <div v-for="g in promoGifts" :key="`${g.promotion}-${g.sku}`" class="flex justify-between text-emerald-700" data-testid="pos-gift"><span>Tặng {{ g.quantity }} × {{ g.name ?? g.sku }}</span><span class="tabular-nums">0 ₫</span></div>
                        <div v-if="loyalty?.enabled && loyalty.balance > 0" class="flex items-center justify-between gap-2">
                            <span class="text-muted-foreground">Dùng điểm <span class="text-xs">(tối đa {{ formatNumber(loyalty.max) }})</span></span>
                            <span class="flex items-center gap-1.5">
                                <button type="button" class="text-xs font-medium text-primary hover:underline" @click="cart.points = loyalty.max">Dùng hết</button>
                                <input v-model="cart.points" type="number" min="0" :max="loyalty.max" placeholder="0" class="h-9 w-24 rounded-lg border bg-white px-2.5 text-right tabular-nums" data-testid="pos-points" />
                            </span>
                        </div>
                        <div v-if="pointsDiscount > 0" class="flex justify-between text-emerald-700"><span>Trừ điểm</span><span class="tabular-nums">−{{ formatMoney(pointsDiscount) }}</span></div>
                        <div class="flex items-center justify-between gap-2"><span class="text-muted-foreground">Mã giảm giá</span><input v-model="cart.coupon" :disabled="!online" :placeholder="online ? 'Nhập mã' : 'Cần có mạng'" maxlength="40" class="h-9 w-32 rounded-lg border bg-white px-2.5 text-right uppercase" data-testid="pos-coupon" /></div>
                        <p v-if="cart.coupon?.trim() && promo?.code_error" class="text-right text-xs text-destructive">{{ promo.code_error }}</p>
                        <div v-if="taxRule.rate > 0" class="flex justify-between text-muted-foreground" data-testid="pos-tax"><span>{{ taxRule.included ? 'Trong đó' : 'Cộng' }} thuế GTGT {{ taxRule.rate }}%</span><span class="tabular-nums">{{ formatMoney(totals.tax) }}</span></div>
                        <div class="!mt-4 rounded-[14px] bg-gradient-to-br from-blue-600 to-indigo-600 px-5 py-4 text-white shadow-sm">
                            <p class="text-[13px] text-blue-100">Khách phải trả</p>
                            <p class="mt-1 text-[34px] font-bold leading-none tabular-nums" data-testid="pos-total">{{ formatMoney(totals.total) }}</p>
                        </div>
                    </div>

                    <div class="space-y-3 border-t pt-4 text-sm">
                        <div v-if="cart.split" class="space-y-2 rounded-xl border p-3" data-testid="pos-split">
                            <div v-for="(r, i) in cart.split" :key="i" class="flex items-center gap-2">
                                <select v-model="r.method" class="h-9 flex-1 rounded-lg border bg-white px-2" :data-testid="`pos-split-method-${i}`">
                                    <option v-for="m in posMethods" :key="m" :value="m">{{ paymentMethods[m] }}</option>
                                </select>
                                <input v-model="r.amount" type="number" min="0" class="h-9 w-32 rounded-lg border bg-white px-2.5 text-right tabular-nums" :data-testid="`pos-split-amount-${i}`" />
                                <Button v-if="cart.split.length > 1" variant="ghost" size="icon" class="size-8" title="Bỏ dòng" @click="cart.split.splice(i, 1)"><X /></Button>
                            </div>
                            <div class="flex items-center justify-between">
                                <button type="button" class="text-xs font-medium text-primary hover:underline" @click="cart.split.push({ method: posMethods[0] ?? 'cash', amount: '' })">+ Thêm hình thức</button>
                                <button type="button" class="text-xs text-muted-foreground hover:underline" data-testid="pos-split-off" @click="toggleSplit">Một hình thức</button>
                            </div>
                        </div>
                        <template v-else>
                            <div class="grid gap-2" :style="{ gridTemplateColumns: `repeat(${Math.max(posMethods.length, 1)}, minmax(0, 1fr))` }">
                                <button
                                    v-for="m in posMethods"
                                    :key="m"
                                    type="button"
                                    :class="cn('flex h-14 flex-col items-center justify-center gap-1 rounded-xl border px-1 text-xs font-medium', cart.method === m ? 'border-2 border-primary bg-primary/5 text-primary' : 'hover:bg-slate-50')"
                                    :data-testid="`pos-method-${m}`"
                                    @click="cart.method = m"
                                >
                                    <component :is="methodIcons[m]" class="size-[18px] shrink-0" /><span class="max-w-full truncate">{{ paymentMethods[m] }}</span>
                                </button>
                            </div>
                            <div>
                                <div class="mb-1.5 flex items-center justify-between">
                                    <span class="text-muted-foreground">Khách đưa</span>
                                    <button v-if="posMethods.length > 1" type="button" class="text-xs font-medium text-primary hover:underline" data-testid="pos-split-on" @click="toggleSplit">Chia nhiều hình thức</button>
                                </div>
                                <input v-model="cart.received" type="number" min="0" :placeholder="formatNumber(totals.total)" class="h-14 w-full rounded-xl border-2 bg-white px-4 text-right text-2xl font-semibold tabular-nums [appearance:textfield] focus:border-primary focus:outline-none [&::-webkit-inner-spin-button]:appearance-none" data-testid="pos-received" />
                            </div>
                            <div v-if="cart.method === 'cash' && totals.total > 0" class="grid grid-cols-4 gap-2">
                                <button
                                    v-for="q in quick"
                                    :key="q"
                                    type="button"
                                    :class="cn('h-10 rounded-lg text-sm font-medium tabular-nums', Number(cart.received) === q ? 'bg-primary text-white' : 'bg-slate-100 hover:bg-slate-200')"
                                    @click="cart.received = q"
                                >
                                    {{ formatNumber(q) }}
                                </button>
                            </div>
                        </template>
                        <div v-if="change > 0" class="flex items-center justify-between rounded-xl bg-emerald-50 px-4 py-3 text-emerald-800">
                            <span>Tiền thừa trả khách</span><span class="text-2xl font-bold tabular-nums" data-testid="pos-change">{{ formatMoney(change) }}</span>
                        </div>
                        <div v-if="shortfall > 0" class="flex items-center justify-between rounded-xl bg-red-50 px-4 py-3 text-red-700">
                            <span>{{ cart.customer ? 'Ghi nợ' : 'Còn thiếu' }}</span><span class="text-xl font-bold tabular-nums">{{ formatMoney(shortfall) }}</span>
                        </div>
                        <label class="flex cursor-pointer items-center gap-2.5">
                            <input v-model="autoPrint" type="checkbox" class="peer sr-only" />
                            <span class="relative h-6 w-11 shrink-0 rounded-full bg-slate-200 transition after:absolute after:left-0.5 after:top-0.5 after:size-5 after:rounded-full after:bg-white after:shadow after:transition peer-checked:bg-primary peer-checked:after:translate-x-5" />
                            <Printer class="size-4 text-muted-foreground" /><span>In hóa đơn K80 sau khi thanh toán</span>
                        </label>
                    </div>
                </div>

                <div class="sticky bottom-0 space-y-2 border-t bg-white p-4">
                    <p v-if="error" class="text-sm text-destructive" data-testid="pos-error">{{ error }}</p>
                    <div class="flex gap-2">
                        <Button variant="outline" class="h-14 w-32 shrink-0 text-base" :disabled="!cart.lines.length" title="Giữ đơn này, mở đơn mới cho khách sau" data-testid="pos-park" @click="newCart"><NotebookPen /> Lưu tạm</Button>
                        <Button class="h-14 flex-1 text-lg font-semibold" :loading="paying" :disabled="!cart.lines.length" data-testid="pos-pay" @click="pay">Thanh toán · F9</Button>
                    </div>
                </div>
            </aside>
        </div>
    </div>

    <Dialog v-model:open="queueOpen" title="Đơn bán lúc mất mạng" description="Lưu trên máy này và tự gửi lên hệ thống khi có mạng. Không xoá dữ liệu trình duyệt khi còn đơn chờ gửi." size="lg">
        <OfflineQueue :queue="queue" :online="online" :syncing="syncing" :catalog-at="catalogAt" :catalog-count="catalog.length" @sync="syncQueue" @retry="retrySale" @drop="dropSale" />
    </Dialog>

    <Dialog v-model:open="shiftOpen" :title="closedShift ? `Đã đóng ca ${closedShift.number}` : shift ? 'Ca bán hàng' : 'Mở ca bán hàng'" size="lg">
        <ShiftPanel :shift="closedShift ?? shift" :warehouse-id="warehouseId" :suggested-opening="suggestedOpening" @changed="onShiftChanged" />
        <template v-if="closedShift" #footer><Button variant="outline" @click="closedShift = null">Mở ca mới</Button><Button @click="shiftOpen = false">Xong</Button></template>
    </Dialog>
</template>
