<script setup lang="ts">
import DateTimeInput from '@/shared/ui/DateTimeInput.vue';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { AlertTriangle, ArrowDown, ArrowUp, Check, CirclePlus, Clock, Contact, ImageIcon, PackageOpen, Pencil, Repeat, Search, Send, Settings2, Store, Truck, UserPlus, UserRound, Wrench, X } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import SkuPicker from '../../components/SkuPicker.vue';
import AddressPicker from '../../components/AddressPicker.vue';
import TagInput from '../../components/TagInput.vue';
import Alert from '@/shared/ui/Alert.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError, idempotent, newIdempotencyKey } from '@/shared/lib/api';
import { saleTax, type TaxRule } from '@/shared/lib/tax';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useAuthStore } from '@/shared/stores/auth';
import { useTenantStore } from '@/shared/stores/tenant';
import { useSettingsStore } from '@/shared/stores/settings';
import { enabledMethods } from '@/shared/lib/paymentMethods';
import { CARRIERS, emptyAddress, type AddressParts } from '@/shared/lib/geo';
import { channelTypes, paymentMethods } from './labels';
import CarrierQuotePicker from '../shipments/CarrierQuotePicker.vue';
import ParcelOptionsPanel from '../shipments/ParcelOptionsPanel.vue';
import { choiceOf, loadCarrierAccounts, type CarrierAccountData, type CarrierChoice, type ParcelOptions, type QuoteResult } from '../shipments/carriers';
import type { Customer, CustomerSummary, OrderDetail, OrderReturnData, SalesChannel, Variant, Warehouse } from '@/shared/types';
import DateInput from '@/shared/ui/DateInput.vue';
import { previewPromotions, type PromotionPreview } from '../promotions/promotions';

/** A SKU line (stock is held) or a service line (own name and price, no stock). */
interface Line {
    uid: number;
    kind: 'sku' | 'service';
    variant_id: string;
    sku: string;
    name: string;
    image: string | null;
    available: number | null;
    retail: number;
    wholesale: number;
    quantity: number | string;
    unit_price: number | string;
    discount: number | string;
}
let nextUid = 1;

const route = useRoute();
const router = useRouter();
const toast = useToast();
const tenant = useTenantStore();
const auth = useAuthStore();
const editId = computed(() => (route.name === 'order-edit' ? String(route.params.id) : null));
const copyId = computed(() => (editId.value ? null : ((route.query.copy as string | undefined) ?? null)));
const copiedFrom = ref<string | null>(null);
// Exchange: a new order paid (partly) by the goods of a return (?exchange=<return id>).
const exchangeId = computed(() => (editId.value ? null : ((route.query.exchange as string | undefined) ?? null)));
const exchange = ref<OrderReturnData | null>(null);
const tagSuggestions = ref<string[]>([]);
// Đơn nháp (?draft=<id>): the form saved without creating an order; creating the order deletes the draft.
const draftId = ref<string | null>(editId.value ? null : ((route.query.draft as string | undefined) ?? null));
const savingDraft = ref(false);

const loading = ref(true);
const channels = ref<SalesChannel[]>([]);
const warehouses = ref<Warehouse[]>([]);
const lines = ref<Line[]>([]);
const customer = ref<(Pick<Customer, 'id' | 'code' | 'name' | 'phone' | 'address'> & { group?: Customer['group'] }) | null>(null);
const newCustomer = reactive({ enabled: false, name: '', phone: '', address: '', addr: emptyAddress() });
// Group terms of the chosen customer (loaded with its detail).
const customerTerms = ref<{ group: Customer['group']; debt: number; summary: CustomerSummary | null } | null>(null);
const groupDiscountAuto = ref(false);
const customerQuery = ref('');
const customerResults = ref<Customer[]>([]);
const phoneMatch = ref<Customer | null>(null);
const form = reactive({
    channel_id: '',
    warehouse_id: '',
    stage: 'pending',
    buyer_name: '',
    buyer_phone: '',
    shipping_address: '',
    // Standard parts of the delivery address (province → ward → street); shipping_address is the text composed from them.
    address: emptyAddress(),
    carrier: '',
    is_cod: false,
    shipping_fee: 0 as number | string,
    discount: 0 as number | string,
    coupon_code: '',
    promotions_off: false,
    note: '',
    tags: [] as string[],
    deliver_on: '',
    pay_method: 'cash',
    pay_amount: '' as number | string,
    placed_at: '',
});
// Cấu hình: default price list and payment method, who may change prices, back-dating a sale.
const settings = useSettingsStore();
const canEditPrice = computed(() => (settings.data?.pricing.staff_edit_price ?? true) || tenant.can('orders.price'));
const canBackdate = computed(() => !editId.value && !!settings.data?.sales.allow_change_sale_time);
const payOptions = computed(() => enabledMethods(Object.entries(paymentMethods).filter(([k]) => k !== 'cod').map(([value, label]) => ({ value, label })), settings.data));
const saving = ref(false);
// One key per new order on this screen: a retry after a timeout or a double click cannot create a second order.
const createKey = newIdempotencyKey();
const error = ref<ApiError | null>(null);

const num = (v: number | string) => Number(v) || 0;
const lineTotal = (l: Line) => Math.max(0, num(l.quantity) * num(l.unit_price) - num(l.discount));
const subtotal = computed(() => lines.value.reduce((s, l) => s + lineTotal(l), 0));
// VAT: the rate of Cấu hình for a new order, the order's own rate when editing (changing Cấu hình never re-taxes it).
const orderTax = ref<TaxRule | null>(null);
const taxRule = computed<TaxRule>(() => orderTax.value ?? { rate: Number(settings.data?.tax.sale_rate) || 0, included: settings.data?.tax.prices_include_tax ?? true });
// Promotions (C4): what the server will apply on save, shown while typing. The seller's own discount stays apart.
const promo = ref<PromotionPreview | null>(null);
const promoDiscount = computed(() => Number(promo.value?.discount ?? 0));
const allDiscount = computed(() => num(form.discount) + promoDiscount.value);
// What the running promotions give this order, asked again (debounced) whenever the lines, buyer or code change.
let promoTimer: ReturnType<typeof setTimeout> | undefined;
let promoSeq = 0;
function refreshPromo() {
    clearTimeout(promoTimer);
    promoTimer = setTimeout(async () => {
        const seq = ++promoSeq;
        const goods = lines.value.filter((l) => num(l.quantity) > 0);
        if (goods.length === 0) {
            promo.value = null;
            return;
        }
        try {
            const res = await previewPromotions({
                channel_id: form.channel_id || null,
                customer_id: customer.value?.id ?? null,
                new_customer: !customer.value && newCustomer.enabled && !!newCustomer.name,
                order_id: editId.value,
                coupon_code: form.coupon_code.trim() || null,
                promotions_off: form.promotions_off,
                lines: goods.map((l) => ({ variant_id: l.kind === 'sku' ? l.variant_id : null, quantity: num(l.quantity), unit_price: num(l.unit_price), discount: num(l.discount) })),
            });
            if (seq === promoSeq) promo.value = res;
        } catch {
            if (seq === promoSeq) promo.value = null;
        }
    }, 350);
}
watch(
    () => [lines.value.map((l) => [l.variant_id, l.quantity, l.unit_price, l.discount]), customer.value?.id, newCustomer.enabled && !!newCustomer.name, form.channel_id, form.coupon_code, form.promotions_off],
    refreshPromo,
    { deep: true },
);
const promoGifts = computed(() => (promo.value?.applied ?? []).flatMap((a) => a.gifts.map((g) => ({ ...g, promotion: a.name }))));
const promoNames = computed(() => (promo.value?.applied ?? []).filter((a) => Number(a.amount) > 0 && a.type !== 'gift').map((a) => a.name).join(', '));
const taxAmount = computed(() => saleTax(Math.max(0, subtotal.value - allDiscount.value), taxRule.value));
const total = computed(() => Math.max(0, subtotal.value + num(form.shipping_fee) - allDiscount.value) + (taxRule.value.included ? 0 : taxAmount.value));
/** Estimate shown while typing; the server caps it at what the buyer actually paid on the original order. */
const totalQty = computed(() => lines.value.reduce((s, l) => s + num(l.quantity), 0));
const exchangeCredit = computed(() => (exchange.value ? Math.min(Number(exchange.value.goods_value ?? 0), total.value) : 0));
const activeChannels = computed(() => channels.value.filter((c) => c.is_active || c.id === form.channel_id).map((c) => ({ value: c.id, label: `${c.name} · ${channelTypes[c.type] ?? c.type}` })));
const activeWarehouses = computed(() => warehouses.value.filter((w) => w.status === 'active').map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` })));

onMounted(async () => {
    try {
        api.get('/orders/tags').then(({ data }) => (tagSuggestions.value = data.data)).catch(() => {});
        const [c, w, s] = await Promise.all([api.get('/sales-channels'), api.get('/warehouses'), settings.load()]);
        if (!editId.value) {
            priceTier.value = s.pricing.sale_price;
            if (payOptions.value.some((o) => o.value === s.payments.default_online)) form.pay_method = s.payments.default_online;
        }
        channels.value = c.data.data;
        warehouses.value = (w.data.data as Warehouse[]).filter((x) => x.purpose !== 'defective');
        if (editId.value) {
            await loadOrder(editId.value);
        } else {
            const first = channels.value.find((ch) => ch.is_active);
            form.channel_id = first?.id ?? '';
            form.warehouse_id = first?.warehouse?.id ?? warehouses.value.find((x) => x.is_default && x.status === 'active')?.id ?? warehouses.value[0]?.id ?? '';
            if (route.query.customer) {
                const { data } = await api.get(`/customers/${route.query.customer}`);
                chooseCustomer(data.data);
            }
            if (copyId.value) await loadOrder(copyId.value, true);
            if (draftId.value) await loadDraft(draftId.value);
            if (exchangeId.value) await loadExchange(exchangeId.value);
        }
    } finally {
        loading.value = false;
    }
});

// The version of the order the form was filled from: the server refuses the save if someone changed it since.
const loadedVersion = ref<number | null>(null);

/** Fills the form from an order: to edit it, or ("Sao chép") as the start of a new order. */
async function loadOrder(id: string, asCopy = false) {
    const { data } = await api.get(`/orders/${id}`);
    const o = data.data as OrderDetail;
    if (!asCopy && !o.editable) {
        toast.error('Đơn này không còn sửa được.');
        await router.replace(`/orders/${id}`);
        return;
    }
    if (!asCopy || o.channel) form.channel_id = o.channel?.id ?? '';
    if (!asCopy || o.warehouse) form.warehouse_id = o.warehouse?.id ?? form.warehouse_id;
    copiedFrom.value = asCopy ? o.external_order_id : null;
    loadedVersion.value = asCopy ? null : (o.version ?? null);
    orderTax.value = asCopy || o.tax_rate === undefined ? null : { rate: Number(o.tax_rate) || 0, included: o.prices_include_tax ?? true };
    // A marketplace line without an internal SKU cannot be sold again from here.
    const skipped = o.items.filter((i) => !i.variant && i.mapping_status !== 'service').length;
    if (asCopy && skipped) toast.info(`Bỏ qua ${skipped} dòng chưa liên kết SKU nội bộ.`);
    // Gift lines come back from the promotion on save.
    o.items = o.items.filter((i) => (i.variant || i.mapping_status === 'service') && !i.is_gift);
    form.buyer_name = o.buyer_name ?? '';
    form.buyer_phone = o.buyer_phone ?? '';
    setAddress({ street: o.shipping_street, province_code: o.shipping_province_code, ward_code: o.shipping_ward_code, address: o.shipping_address });
    form.carrier = o.carrier ?? '';
    form.is_cod = o.is_cod;
    form.shipping_fee = Number(o.shipping_fee);
    form.discount = Math.max(0, Number(o.discount) - Number(o.promotion_discount ?? 0));
    form.coupon_code = asCopy ? '' : (o.coupon_code ?? '');
    form.promotions_off = asCopy ? false : !!o.promotions_off;
    form.note = o.buyer_note ?? '';
    form.tags = [...(o.tags ?? [])];
    form.deliver_on = asCopy ? '' : (o.deliver_on ?? '');
    customer.value = o.customer ? { ...o.customer, phone: null, address: null } : null;
    lines.value = o.items.map((i) => ({
        uid: nextUid++,
        kind: i.variant ? 'sku' : 'service',
        variant_id: i.variant?.id ?? '',
        sku: i.variant?.sku ?? '',
        name: i.name + (i.variant_name ? ` · ${i.variant_name}` : ''),
        image: null,
        available: null,
        retail: Number(i.unit_price),
        wholesale: 0,
        quantity: i.quantity,
        unit_price: Number(i.unit_price),
        discount: Number(i.discount),
    }));
}

watch(
    () => form.channel_id,
    (id, old) => {
        if (!old || editId.value || loading.value) return;
        const ch = channels.value.find((c) => c.id === id);
        if (ch?.warehouse) form.warehouse_id = ch.warehouse.id;
    },
);

// Price policy of the order ("Giá bán lẻ" / "Giá bán buôn"): new lines start from it, switching re-prices SKU lines.
const priceTier = ref<'retail' | 'wholesale'>('retail');
const tierPrice = (l: { retail: number; wholesale: number }) => (priceTier.value === 'wholesale' && l.wholesale > 0 ? l.wholesale : l.retail);
watch(priceTier, () => lines.value.filter((l) => l.kind === 'sku').forEach((l) => (l.unit_price = tierPrice(l))));

// Display options of the product table and of "Thông tin bổ sung", kept in this browser per business.
const viewKey = `ecso.orderform.view.${tenant.current?.id ?? 'default'}`;
const defaultView = {
    columns: { image: true, sku: true, available: true, discount: true },
    newOnTop: false,
    split: false,
    extras: [
        { key: 'warehouse', label: 'Kho xuất', show: true },
        { key: 'seller', label: 'Bán bởi', show: true },
        { key: 'deliver_on', label: 'Hẹn giao', show: true },
    ],
};
function readView(): typeof defaultView {
    try {
        const saved = JSON.parse(localStorage.getItem(viewKey) ?? 'null');
        return saved ? { ...defaultView, ...saved, columns: { ...defaultView.columns, ...saved.columns } } : structuredClone(defaultView);
    } catch {
        return structuredClone(defaultView);
    }
}
const view = reactive(readView());
const viewDraft = ref(structuredClone(defaultView));
const viewOpen = ref(false);
const extrasOpen = ref(false);
function openView(which: 'columns' | 'extras') {
    viewDraft.value = JSON.parse(JSON.stringify(view));
    if (which === 'columns') viewOpen.value = true;
    else extrasOpen.value = true;
}
function saveView() {
    Object.assign(view, JSON.parse(JSON.stringify(viewDraft.value)));
    try {
        localStorage.setItem(viewKey, JSON.stringify(view));
    } catch {
        // Private mode: kept for this visit only.
    }
    viewOpen.value = false;
    extrasOpen.value = false;
}
function moveExtra(i: number, step: number) {
    const list = viewDraft.value.extras;
    const j = i + step;
    if (j < 0 || j >= list.length) return;
    [list[i], list[j]] = [list[j]!, list[i]!];
}
const shownExtras = computed(() => view.extras.filter((e) => e.show));

function place(line: Line) {
    if (view.newOnTop) lines.value.unshift(line);
    else lines.value.push(line);
}

function add(v: Variant) {
    const existing = view.split ? undefined : lines.value.find((l) => l.kind === 'sku' && l.variant_id === v.id);
    if (existing) {
        existing.quantity = num(existing.quantity) + 1;
        return;
    }
    const prices = { retail: Number(v.ref_price) || 0, wholesale: Number(v.wholesale_price) || 0 };
    place({
        uid: nextUid++,
        kind: 'sku',
        variant_id: v.id!,
        sku: v.sku,
        name: (v.product?.name ?? '') + (v.name ? ` · ${v.name}` : ''),
        image: v.product?.image ?? null,
        available: v.stock?.available ?? null,
        ...prices,
        quantity: 1,
        unit_price: tierPrice(prices),
        discount: 0,
    });
}

/** "Thêm dịch vụ khác (F9)": a line with its own name and price (repair, installation, delivery…), no stock. */
async function addService() {
    const line: Line = { uid: nextUid++, kind: 'service', variant_id: '', sku: '', name: '', image: null, available: null, retail: 0, wholesale: 0, quantity: 1, unit_price: 0, discount: 0 };
    place(line);
    await nextTick();
    document.querySelector<HTMLInputElement>(`[data-service="${line.uid}"]`)?.focus();
}

// "Chọn nhanh": browse SKUs with their stock and add several at once.
const pickOpen = ref(false);
const pickQ = ref('');
const pickRows = ref<Variant[]>([]);
const picked = ref<Map<string, Variant>>(new Map());
async function loadPick() {
    const { data } = await api.get('/variants', { params: { q: pickQ.value || undefined, warehouse: form.warehouse_id || undefined, sellable: 1 } });
    pickRows.value = data.data;
}
let pickTimer: ReturnType<typeof setTimeout> | undefined;
watch(pickQ, () => {
    clearTimeout(pickTimer);
    pickTimer = setTimeout(loadPick, 250);
});
watch(pickOpen, (v) => v && ((picked.value = new Map()), loadPick()));
function togglePick(v: Variant) {
    const next = new Map(picked.value);
    if (next.has(v.id!)) next.delete(v.id!);
    else next.set(v.id!, v);
    picked.value = next;
}
function confirmPick() {
    picked.value.forEach((v) => add(v));
    pickOpen.value = false;
}

// "Kiểm tra tồn kho": stock of the order's SKUs in every warehouse.
const stockOpen = ref(false);
const stockRows = ref<{ line: Line; balances: { warehouse: { id: string; name: string }; on_hand: number; reserved: number; available: number }[] }[]>([]);
async function openStock() {
    stockOpen.value = true;
    const skuLines = lines.value.filter((l) => l.kind === 'sku');
    stockRows.value = await Promise.all(
        skuLines.map((line) => api.get(`/inventory/variants/${line.variant_id}/card`).then(({ data }) => ({ line, balances: data.balances })).catch(() => ({ line, balances: [] }))),
    );
}
const stockWarehouses = computed(() => {
    const seen = new Map<string, string>();
    stockRows.value.forEach((r) => r.balances.forEach((b) => seen.set(b.warehouse.id, b.warehouse.name)));
    return [...seen].map(([id, name]) => ({ id, name }));
});

// Customer search (F4): on focus the customers who bought last show at once, like Sapo; typing searches.
let customerTimer: ReturnType<typeof setTimeout> | undefined;
const customerFocus = ref(false);
const customerActive = ref(0);
async function searchCustomers(q: string) {
    const { data } = await api.get('/customers', { params: q ? { q, per_page: 8 } : { sort: 'recent', per_page: 8, status: 'active' } });
    if (customerQuery.value.trim() === q) {
        customerResults.value = data.data;
        customerActive.value = 0;
    }
}
watch(customerQuery, (q) => {
    clearTimeout(customerTimer);
    customerTimer = setTimeout(() => searchCustomers(q.trim()).catch(() => {}), q.trim() ? 250 : 0);
});
function onCustomerFocus() {
    customerFocus.value = true;
    if (!customerQuery.value.trim()) searchCustomers('').catch(() => {});
}
function onCustomerBlur() {
    setTimeout(() => (customerFocus.value = false), 150);
}
function onCustomerKey(e: KeyboardEvent) {
    const n = customerResults.value.length;
    if (e.key === 'ArrowDown') customerActive.value = Math.min(n - 1, customerActive.value + 1);
    else if (e.key === 'ArrowUp') customerActive.value = Math.max(0, customerActive.value - 1);
    else if (e.key === 'Enter') {
        const c = customerResults.value[customerActive.value];
        if (c) chooseCustomer(c);
        else if (customerQuery.value.trim() && tenant.can('customers.manage')) startNewCustomer();
    } else if (e.key === 'Escape') customerFocus.value = false;
    else return;
    e.preventDefault();
}

let phoneTimer: ReturnType<typeof setTimeout> | undefined;
watch(
    () => newCustomer.phone,
    (phone) => {
        clearTimeout(phoneTimer);
        phoneMatch.value = null;
        if (phone.replace(/\D/g, '').length < 9) return;
        phoneTimer = setTimeout(async () => {
            const { data } = await api.get('/customers', { params: { q: phone, per_page: 1 } });
            phoneMatch.value = data.data[0] ?? null;
        }, 300);
    },
);

// Saved delivery addresses of the chosen customer (Khách hàng › Sổ địa chỉ), offered under "Địa chỉ giao".
interface SavedAddress { id: string; label: string | null; recipient: string | null; phone: string | null; address: string; street?: string | null; province_code?: string | null; ward_code?: string | null }
const savedAddresses = ref<SavedAddress[]>([]);
function useAddress(a: SavedAddress) {
    setAddress(a);
    if (a.recipient) form.buyer_name = a.recipient;
    if (a.phone) form.buyer_phone = a.phone;
    addressMenu.value = false;
}

/**
 * The delivery address from a customer, a saved address or an order: its standard parts when it has them,
 * otherwise the old free text, kept as is and shown with a suggestion to standardise it.
 */
const legacyAddress = ref<string | null>(null);
const customerMain = ref<SavedAddress | null>(null);
function focusAddress() {
    const el = document.getElementById('o-addr-province');
    el?.scrollIntoView({ block: 'center', behavior: 'smooth' });
    el?.focus();
}
const addressMenu = ref(false);
function setAddress(a: { street?: string | null; province_code?: string | null; ward_code?: string | null; address?: string | null }) {
    form.shipping_address = a.address ?? '';
    if (a.province_code) {
        form.address = { street: a.street ?? '', province_code: a.province_code, ward_code: a.ward_code ?? '' };
        legacyAddress.value = null;
    } else {
        form.address = emptyAddress();
        legacyAddress.value = a.address || null;
    }
}
/** The picker's composed text replaces the old text once a province is chosen. */
function onAddressText(text: string) {
    if (form.address.province_code) form.shipping_address = text;
}
const addressStandard = computed(() => !!form.address.ward_code);
const isCarrier = computed(() => delivery.value === 'api' || (CARRIERS as readonly string[]).includes(form.carrier));
const otherCarrier = ref(false);
function pickCarrier(c: string) {
    otherCarrier.value = false;
    form.carrier = form.carrier === c ? '' : c;
}
watch(
    () => customer.value?.id,
    (id) => (id ? loadTerms(id) : ((savedAddresses.value = []), (customerTerms.value = null))),
);

function chooseCustomer(c: Customer) {
    customer.value = c;
    newCustomer.enabled = false;
    customerQuery.value = '';
    customerResults.value = [];
    customerFocus.value = false;
    form.buyer_name = c.name;
    form.buyer_phone = c.phone ?? '';
    if (c.address || c.province_code) setAddress(c);
    // The customer's group decides the starting price policy (retail / wholesale), discount and payment method.
    if (c.group?.price_tier) priceTier.value = c.group.price_tier;
    applyGroup(c.group);
}

function applyGroup(group: Customer['group']) {
    if (editId.value) return;
    groupDiscountAuto.value = Number(group?.discount_percent ?? 0) > 0;
    if (groupDiscountAuto.value) form.discount = groupDiscount.value;
    if (group?.payment_method && payOptions.value.some((o) => o.value === group.payment_method)) form.pay_method = group.payment_method;
}
const groupDiscount = computed(() => Math.round((subtotal.value * Number(customerTerms.value?.group?.discount_percent ?? customer.value?.group?.discount_percent ?? 0)) / 100));
// The group discount follows the subtotal until someone types another discount.
watch(groupDiscount, (d) => groupDiscountAuto.value && (form.discount = d));

/** Group, debt, terms and saved addresses of the chosen customer (one request). */
async function loadTerms(id: string) {
    customerTerms.value = null;
    savedAddresses.value = [];
    customerMain.value = null;
    try {
        const { data } = await api.get(`/customers/${id}`);
        if (customer.value?.id !== id) return;
        customerTerms.value = { group: data.data.group, debt: Number(data.data.debt ?? 0), summary: data.data.summary ?? null };
        savedAddresses.value = data.data.addresses ?? [];
        const c = data.data as Customer;
        customerMain.value = c.address ? { id: 'main', label: 'Địa chỉ chính', recipient: c.name, phone: c.phone, address: c.address, street: c.street, province_code: c.province_code, ward_code: c.ward_code } : null;
    } catch {
        // No access to customer details: the order can still be made, the address stays a plain input.
    }
}
/** Credit limit warning: what the customer would owe after this order, against the limit of the customer or group. */
const creditWarning = computed(() => {
    const limit = Number(customerTerms.value?.summary?.credit_limit ?? 0);
    if (!limit || editId.value || form.is_cod) return null;
    const after = customerTerms.value!.debt + remaining.value;
    return after > limit ? `Khách đang nợ ${formatMoney(customerTerms.value!.debt)}, thêm đơn này sẽ là ${formatMoney(after)}, vượt hạn mức ${formatMoney(limit)}.` : null;
});

function clearCustomer() {
    customer.value = null;
    customerTerms.value = null;
    if (groupDiscountAuto.value) form.discount = 0;
    groupDiscountAuto.value = false;
}

watch(
    () => [newCustomer.name, newCustomer.phone, newCustomer.address, newCustomer.addr],
    () => {
        if (!newCustomer.enabled) return;
        form.buyer_name = newCustomer.name;
        form.buyer_phone = newCustomer.phone;
        if (newCustomer.addr.province_code) setAddress({ ...newCustomer.addr, address: newCustomer.address });
    },
    { deep: true },
);

/** What the buyer still owes after the exchange credit and the payment typed in now. */
const remaining = computed(() => Math.max(0, total.value - exchangeCredit.value - num(form.pay_amount)));

function focusSku() {
    document.querySelector<HTMLInputElement>('[data-shortcut="sku"] input')?.focus();
}

/** "Thêm mới khách hàng" from the search box: what was typed becomes the phone or the name. */
const customerDialog = ref(false);
function startNewCustomer() {
    const q = customerQuery.value.trim();
    newCustomer.enabled = true;
    customerDialog.value = true;
    if (q && /^[\d\s+.]+$/.test(q)) newCustomer.phone = q;
    else if (q) newCustomer.name = q;
    customerQuery.value = '';
}
// Closing the dialog (×, Esc) without a name drops the new customer.
watch(customerDialog, (isOpen) => {
    if (!isOpen && !newCustomer.name.trim()) cancelNewCustomer();
});
function cancelNewCustomer() {
    Object.assign(newCustomer, { enabled: false, name: '', phone: '', address: '', addr: emptyAddress() });
    customerDialog.value = false;
}
/** The new customer is created together with the order; the dialog only collects the details. */
function saveNewCustomer() {
    if (!newCustomer.name.trim()) return;
    customerDialog.value = false;
}

function payFull() {
    form.pay_amount = total.value - exchangeCredit.value;
}

/** Starts an exchange order for the buyer of a return, on the same channel and warehouse. */
async function loadExchange(returnId: string) {
    const { data } = await api.get(`/returns/${returnId}`);
    exchange.value = data.data;
    if (exchange.value?.exchange_order) {
        toast.error(`Phiếu ${exchange.value.number} đã đổi sang đơn ${exchange.value.exchange_order.external_order_id}.`);
        exchange.value = null;
        return;
    }
    const original = (await api.get(`/orders/${exchange.value!.order!.id}`)).data.data as OrderDetail;
    if (original.channel) form.channel_id = original.channel.id;
    if (original.warehouse) form.warehouse_id = original.warehouse.id;
    customer.value = original.customer ? { ...original.customer, phone: null, address: null } : null;
    form.buyer_name = original.buyer_name ?? '';
    form.buyer_phone = original.buyer_phone ?? '';
    setAddress({ street: original.shipping_street, province_code: original.shipping_province_code, ward_code: original.shipping_ward_code, address: original.shipping_address });
}

function addressBody(a: AddressParts, text: string) {
    return a.province_code ? { street: a.street || null, province_code: a.province_code, ward_code: a.ward_code || null } : { address: text || null };
}

/** The order is saved; now the parcel goes to the chosen carrier. A refusal leaves the order to push again from its page. */
async function pushNew(orderId: string) {
    const c = carrierChoice.value!;
    try {
        const { data } = await api.post(`/orders/${orderId}/carrier-shipments`, {
            account_id: c.account_id,
            service: c.service,
            service_name: c.service_name,
            ...Object.fromEntries(Object.entries(parcel.value).filter(([, v]) => v !== null && v !== undefined && v !== '')),
        }, idempotent(newIdempotencyKey()));
        toast.success(`Đã đẩy qua ${c.carrier_label}: ${data.data.tracking_number}`);
    } catch (e) {
        const err = e as ApiError;
        toast.error(`Đơn đã tạo nhưng ${c.carrier_label} chưa nhận: ${err.field('carrier') ?? err.field('order') ?? err.field('account_id') ?? err.message}. Bấm "Đẩy qua hãng" ở trang đơn để thử lại.`);
    }
}

async function submit() {
    if (!editId.value && delivery.value === 'api' && !carrierChoice.value) {
        pushError.value = hasCarrierAccount.value ? 'Chọn hãng và dịch vụ trong bảng giá trước khi lưu.' : 'Chưa kết nối hãng vận chuyển nào. Chọn "Đẩy vận chuyển ngoài" hoặc kết nối hãng ở trang Đẩy qua vận chuyển.';
        return;
    }
    pushError.value = null;
    saving.value = true;
    error.value = null;
    const body: Record<string, unknown> = {
        warehouse_id: form.warehouse_id || null,
        customer_id: customer.value?.id ?? null,
        ...(newCustomer.enabled && newCustomer.name ? { customer: { name: newCustomer.name, phone: newCustomer.phone || null, ...addressBody(newCustomer.addr, newCustomer.address) } } : {}),
        buyer_name: form.buyer_name || null,
        buyer_phone: form.buyer_phone || null,
        // Standard parts when a province is chosen (the server composes the text), else the old text as it was.
        ...(form.address.province_code
            ? { shipping_street: form.address.street || null, shipping_province_code: form.address.province_code, shipping_ward_code: form.address.ward_code || null }
            : { shipping_address: form.shipping_address || null }),
        carrier: form.carrier || null,
        is_cod: form.is_cod,
        shipping_fee: num(form.shipping_fee),
        discount: num(form.discount),
        coupon_code: form.coupon_code.trim() || null,
        promotions_off: form.promotions_off,
        note: form.note || null,
        tags: form.tags,
        deliver_on: form.deliver_on || null,
        lines: lines.value.map((l) => ({
            ...(l.kind === 'service' ? { name: l.name.trim() } : { variant_id: l.variant_id }),
            quantity: num(l.quantity),
            unit_price: num(l.unit_price),
            discount: num(l.discount),
        })),
    };
    try {
        if (editId.value) {
            await api.put(`/sales-orders/${editId.value}`, { ...body, ...(loadedVersion.value !== null ? { version: loadedVersion.value } : {}) });
            toast.success('Đã lưu đơn.');
            await router.push(`/orders/${editId.value}`);
        } else {
            const payments = num(form.pay_amount) > 0 ? [{ method: form.pay_method, amount: num(form.pay_amount) }] : [];
            const placedAt = canBackdate.value && form.placed_at ? form.placed_at.replace('T', ' ') : null;
            const { data } = await api.post('/sales-orders', { ...body, channel_id: form.channel_id, stage: form.stage, payments, exchange_return_id: exchange.value?.id ?? null, ...(placedAt ? { placed_at: placedAt } : {}) }, idempotent(createKey));
            toast.success(`Đã tạo đơn ${data.data.external_order_id}`);
            if (draftId.value) await api.delete(`/order-drafts/${draftId.value}`).catch(() => {});
            if (delivery.value === 'api' && carrierChoice.value) await pushNew(data.data.id);
            await router.push(`/orders/${data.data.id}`);
        }
    } catch (e) {
        error.value = e as ApiError;
        if (!error.value.body.errors || error.value.field('stock') || error.value.field('order') || error.value.field('version') || error.value.field('customer_id')) toast.error(error.value.field('stock') ?? error.value.field('version') ?? error.value.message);
        else if (error.value.field('shipping_ward_code') || error.value.field('shipping_street')) focusAddress();
    } finally {
        saving.value = false;
    }
}

// How the goods leave (Đóng gói và giao hàng). The choice also sets the status the order is saved in.
const deliveries = [
    { value: 'api', label: 'Đẩy qua hãng vận chuyển', icon: Truck, hint: 'So sánh giá các hãng đã kết nối; lưu đơn là đẩy sang hãng, hãng cấp mã và đến lấy hàng.' },
    { value: 'ext', label: 'Đẩy vận chuyển ngoài', icon: Send, hint: 'Hãng chưa kết nối hoặc shipper riêng: chọn hãng, nhập mã vận đơn ở trang đơn sau.' },
    { value: 'completed', label: 'Khách nhận tại cửa hàng', icon: Store, hint: 'Khách lấy hàng ngay: trừ kho và hoàn tất đơn khi lưu.' },
    { value: 'later', label: 'Giao hàng sau', icon: Clock, hint: 'Đơn giữ hàng trong kho, chọn cách giao sau (ở trang đơn hoặc "Đẩy qua vận chuyển").' },
] as const;
type Delivery = (typeof deliveries)[number]['value'];
const delivery = ref<Delivery>('later');
// "Đã xác nhận, chờ đóng gói": skips the confirm step (pushing to a carrier confirms by itself).
const confirmedNow = ref(false);
watch([delivery, confirmedNow], () => {
    form.stage = delivery.value === 'completed' ? 'completed' : confirmedNow.value && delivery.value !== 'api' ? 'confirmed' : 'pending';
    if (delivery.value === 'api') form.carrier = '';
});

// Đẩy qua hãng: the shop's connected accounts, the parcel and the prices for this order as it is being typed.
const carrierAccounts = ref<CarrierAccountData[]>([]);
const parcel = ref<ParcelOptions>({ required_note: 'view_no_try', payer: 'shop' });
const quote = ref<QuoteResult | null>(null);
const quoteLoading = ref(false);
const quoteError = ref<string | null>(null);
const carrierChoice = ref<CarrierChoice | null>(null);
const pushError = ref<string | null>(null);
const codAmount = computed(() => (form.is_cod ? remaining.value : 0));
const quoteMissing = computed(() => {
    if (!form.address.province_code || !form.address.ward_code) return 'Chọn tỉnh/thành và phường/xã của người nhận để xem giá.';
    if (!lines.value.some((l) => l.kind === 'sku' && l.variant_id)) return 'Thêm sản phẩm để tính khối lượng.';
    return null;
});
let quoteSeq = 0;
async function loadQuote() {
    if (delivery.value !== 'api' || quoteMissing.value) return;
    const mine = ++quoteSeq;
    quoteLoading.value = true;
    quoteError.value = null;
    try {
        const { data } = await api.post('/shipping/quotes', {
            warehouse_id: form.warehouse_id || null,
            province_code: form.address.province_code,
            ward_code: form.address.ward_code,
            items: lines.value.filter((l) => l.kind === 'sku' && l.variant_id).map((l) => ({ variant_id: l.variant_id, quantity: Math.max(1, num(l.quantity)) })),
            cod_amount: codAmount.value,
            ...Object.fromEntries(Object.entries(parcel.value).filter(([, v]) => v !== null && v !== undefined && v !== '')),
        });
        if (mine !== quoteSeq) return;
        const res = data.data as QuoteResult;
        quote.value = res;
        const row = res.accounts.find((a) => a.account.id === carrierChoice.value?.account_id)?.services.find((x) => x.code === carrierChoice.value?.service);
        carrierChoice.value = row ? { ...carrierChoice.value!, fee: row.fee } : res.cheapest ? choiceOf(res.cheapest) : null;
    } catch (e) {
        if (mine === quoteSeq) quoteError.value = (e as ApiError).message;
    } finally {
        if (mine === quoteSeq) quoteLoading.value = false;
    }
}
let quoteTimer: ReturnType<typeof setTimeout> | undefined;
watch(
    () => [delivery.value, form.warehouse_id, form.address.province_code, form.address.ward_code, codAmount.value, JSON.stringify(parcel.value), lines.value.map((l) => `${l.variant_id}x${l.quantity}`).join()],
    () => {
        clearTimeout(quoteTimer);
        quoteTimer = setTimeout(loadQuote, 500);
    },
);
watch(delivery, async (d) => {
    if (d === 'api' && !carrierAccounts.value.length) {
        try {
            carrierAccounts.value = await loadCarrierAccounts();
        } catch {
            // Shown as "no connection" below.
        }
    }
});
const hasCarrierAccount = computed(() => carrierAccounts.value.some((a) => a.status === 'connected'));

// Counter shortcuts: F1 save, F3 product search, F4 customer search, F6 order discount, F7 shipping fee.
// F9 adds a service line.
const shortcuts: Record<string, string> = { F3: '[data-shortcut="sku"] input', F4: '[data-testid="customer-search"]', F6: '[data-testid="order-discount"]', F7: '[data-testid="shipping-fee"]' };
function onShortcut(e: KeyboardEvent) {
    if (loading.value) return;
    if (e.key === 'F1') {
        e.preventDefault();
        if (!saving.value && lines.value.length && form.channel_id) submit();
    } else if (e.key === 'F9') {
        e.preventDefault();
        addService();
    } else if (shortcuts[e.key]) {
        e.preventDefault();
        const el = document.querySelector<HTMLInputElement>(shortcuts[e.key]!);
        el?.focus();
        el?.select?.();
    }
}
onMounted(() => window.addEventListener('keydown', onShortcut));
onBeforeUnmount(() => window.removeEventListener('keydown', onShortcut));

async function loadDraft(id: string) {
    try {
        const { data } = await api.get(`/order-drafts/${id}`);
        const p = data.data.payload as { form?: Partial<typeof form>; lines?: Line[]; customer?: typeof customer.value; priceTier?: 'retail' | 'wholesale' };
        Object.assign(form, p.form ?? {});
        // Drafts saved before standard addresses: keep their text as the old address.
        if (!form.address?.province_code) {
            form.address = emptyAddress();
            legacyAddress.value = form.shipping_address || null;
        }
        lines.value = (p.lines ?? []).map((l) => ({ ...l, uid: nextUid++ }));
        customer.value = p.customer ?? null;
        if (p.priceTier) priceTier.value = p.priceTier;
    } catch {
        toast.error('Không mở được đơn nháp (có thể đã bị xóa).');
        draftId.value = null;
    }
}

/** "Lưu nháp": keeps the form for later without creating an order; no stock is held and no number is used. */
async function saveDraft() {
    savingDraft.value = true;
    try {
        const title = form.buyer_name.trim() || customer.value?.name || lines.value[0]?.name || 'Đơn nháp';
        const body = { title: title.slice(0, 191), total: total.value, lines_count: lines.value.length, payload: { form: { ...form }, lines: lines.value, customer: customer.value, priceTier: priceTier.value } };
        if (draftId.value) {
            await api.put(`/order-drafts/${draftId.value}`, body);
        } else {
            const { data } = await api.post('/order-drafts', body);
            draftId.value = data.data.id;
            await router.replace({ query: { ...route.query, draft: draftId.value } });
        }
        toast.success('Đã lưu nháp. Mở lại ở Danh sách đơn hàng › Đơn nháp.');
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        savingDraft.value = false;
    }
}
</script>

<template>
    <PageHeader :title="editId ? 'Sửa đơn bán' : copiedFrom ? `Tạo đơn bán (sao chép từ ${copiedFrom})` : 'Tạo đơn hàng'" description="Đơn của kênh bán riêng: cửa hàng, Facebook, Zalo, website… Hàng được giữ trong kho ngay khi lưu đơn.">
        <Button variant="outline" @click="$router.push(editId ? `/orders/${editId}` : '/orders')">Thoát</Button>
        <Button v-if="!editId && !exchange" variant="outline" :loading="savingDraft" :disabled="loading || (!lines.length && !form.buyer_name)" data-testid="save-draft" @click="saveDraft">{{ draftId ? 'Cập nhật nháp' : 'Lưu nháp' }}</Button>
        <Button form="order-form" type="submit" :loading="saving" :disabled="loading || !lines.length || !form.channel_id" data-testid="submit-order">
            {{ editId ? 'Lưu thay đổi' : 'Tạo đơn hàng' }} <kbd class="ml-1 text-[10px] opacity-70">F1</kbd>
        </Button>
    </PageHeader>

    <div v-if="loading" class="space-y-4"><Skeleton class="h-40" /><Skeleton class="h-64" /></div>
    <Alert v-if="!loading && exchange" :icon="Repeat" tone="blue" class="mb-5" data-testid="exchange-banner">
        <span>
            Đơn đổi hàng cho phiếu trả <b class="font-mono">{{ exchange.number }}</b> (đơn {{ exchange.order?.external_order_id }}). Hàng trả trị giá
            <b>{{ formatMoney(exchange.goods_value) }}</b> được trừ vào đơn này, tối đa bằng số khách đã trả ở đơn cũ. Chọn sản phẩm khách lấy đổi.
        </span>
    </Alert>

    <form v-if="!loading" id="order-form" class="space-y-5" data-testid="order-form" @submit.prevent="submit">
        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
            <Card title="Thông tin khách hàng" :caps="false">
                <div class="space-y-3 p-4">
                    <div v-if="customer" class="flex items-center justify-between rounded-lg border border-blue-100 bg-blue-50/70 px-3 py-2.5" data-testid="selected-customer">
                        <div class="flex items-center gap-3 text-sm">
                            <span class="grid size-9 place-items-center rounded-full bg-white text-blue-500"><UserRound class="size-5" /></span>
                            <div>
                                <p><span class="font-medium text-primary">{{ customer.name }}</span> <span class="font-mono text-xs text-muted-foreground">{{ customer.code }}</span></p>
                                <p class="text-xs text-muted-foreground">{{ customer.phone ?? form.buyer_phone }}<template v-if="customer.address ?? form.shipping_address"> · {{ customer.address ?? form.shipping_address }}</template></p>
                                <p v-if="customerTerms?.group" class="text-xs text-muted-foreground" data-testid="customer-group-terms">
                                    Nhóm {{ customerTerms.group.name }}<template v-if="Number(customerTerms.group.discount_percent)"> · chiết khấu {{ Number(customerTerms.group.discount_percent) }}%</template><template v-if="customerTerms.debt > 0"> · đang nợ {{ formatMoney(customerTerms.debt) }}</template>
                                </p>
                                <p v-else-if="customerTerms && customerTerms.debt > 0" class="text-xs text-muted-foreground">Đang nợ {{ formatMoney(customerTerms.debt) }}</p>
                            </div>
                        </div>
                        <Button variant="ghost" size="icon" class="size-7" title="Bỏ chọn" @click="clearCustomer"><X /></Button>
                    </div>
                    <p v-if="customer && creditWarning" class="flex items-start gap-1.5 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800" data-testid="credit-warning"><AlertTriangle class="mt-px size-3.5 shrink-0" /> {{ creditWarning }}</p>
                    <template v-if="!customer && !newCustomer.enabled">
                        <div class="relative">
                            <Search class="pointer-events-none absolute left-3 top-2.5 size-4 text-muted-foreground" />
                            <Input
                                v-model="customerQuery"
                                class="pl-9"
                                placeholder="Tìm theo tên, SĐT, mã khách hàng… (F4)"
                                autocomplete="off"
                                data-testid="customer-search"
                                @focus="onCustomerFocus"
                                @blur="onCustomerBlur"
                                @keydown="onCustomerKey"
                            />
                            <ul v-if="customerFocus || customerQuery.trim()" class="absolute z-20 mt-1 max-h-96 w-full overflow-y-auto rounded-md border bg-card shadow-lg" data-testid="customer-results">
                                <li v-if="tenant.can('customers.manage')">
                                    <button type="button" class="flex w-full items-center gap-2 border-b px-4 py-2.5 text-left text-sm font-medium text-primary hover:bg-muted" @mousedown.prevent="startNewCustomer"><CirclePlus class="size-4" /> Thêm mới khách hàng</button>
                                </li>
                                <li v-if="!customerQuery.trim() && customerResults.length" class="px-4 pt-2 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Khách mua gần đây</li>
                                <li v-for="(c, i) in customerResults" :key="c.id">
                                    <button
                                        type="button"
                                        :class="cn('flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm', i === customerActive ? 'bg-blue-50/70' : 'hover:bg-muted')"
                                        @mousedown.prevent="chooseCustomer(c)"
                                        @mousemove="customerActive = i"
                                    >
                                        <span class="grid size-8 shrink-0 place-items-center rounded-full bg-blue-50 text-blue-500"><UserRound class="size-4" /></span>
                                        <span class="min-w-0 flex-1"><span class="block truncate">{{ c.name }} <span class="text-xs text-muted-foreground">{{ c.code }}</span></span><span v-if="c.phone" class="block text-xs font-medium">{{ c.phone }}</span></span>
                                        <span v-if="c.ward_code" class="hidden max-w-[45%] truncate text-xs text-muted-foreground sm:block" :title="c.address ?? ''">{{ c.address }}</span>
                                        <span v-else-if="c.address" class="hidden shrink-0 items-center gap-1 text-xs text-amber-700 sm:flex" :title="c.address"><span class="size-1.5 rounded-full bg-amber-500" />Địa chỉ chưa chuẩn hóa</span>
                                    </button>
                                </li>
                                <li v-if="customerQuery.trim() && !customerResults.length" class="px-4 py-2.5 text-sm text-muted-foreground">Không tìm thấy khách. Enter để thêm khách mới.</li>
                            </ul>
                        </div>
                        <div class="flex flex-col items-center py-6 text-center text-sm text-muted-foreground">
                            <Contact class="mb-2 size-12 text-slate-300" :stroke-width="1.5" />
                            Chưa có thông tin khách hàng
                            <div class="mt-3 flex items-center gap-3">
                                <Button v-if="tenant.can('customers.manage')" size="sm" variant="outline" data-testid="new-customer" @click="startNewCustomer">+ Khách mới</Button>
                                <span class="text-xs">Không chọn khách = khách lẻ.</span>
                            </div>
                        </div>
                    </template>
                    <div v-else-if="newCustomer.enabled" class="flex items-center justify-between rounded-lg border border-emerald-100 bg-emerald-50/70 px-3 py-2.5" data-testid="pending-customer">
                        <div class="flex items-center gap-3 text-sm">
                            <span class="grid size-9 place-items-center rounded-full bg-white text-emerald-500"><UserPlus class="size-5" /></span>
                            <div>
                                <p><span class="font-medium">{{ newCustomer.name || 'Khách mới' }}</span> <span class="rounded-full bg-emerald-100 px-1.5 text-[11px] text-emerald-700">Khách mới</span></p>
                                <p class="text-xs text-muted-foreground">{{ newCustomer.phone }}<template v-if="newCustomer.address"> · {{ newCustomer.address }}</template> · tạo cùng đơn hàng</p>
                            </div>
                        </div>
                        <div class="flex gap-1">
                            <Button variant="ghost" size="icon" class="size-7" title="Sửa" @click="customerDialog = true"><Pencil /></Button>
                            <Button variant="ghost" size="icon" class="size-7" title="Bỏ" @click="cancelNewCustomer"><X /></Button>
                        </div>
                    </div>
                    <div v-if="(customer || newCustomer.enabled) && form.stage !== 'completed'" class="relative rounded-lg border px-3 py-2.5 text-sm" data-testid="customer-delivery">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Địa chỉ giao hàng</p>
                            <button type="button" class="text-xs font-medium text-primary hover:underline" data-testid="change-address" @click="addressMenu = !addressMenu">Thay đổi</button>
                        </div>
                        <p class="mt-1 font-medium">{{ [form.buyer_name, form.buyer_phone].filter(Boolean).join(' · ') || 'Chưa có người nhận' }}</p>
                        <p :class="form.shipping_address ? '' : 'text-muted-foreground'">{{ form.shipping_address || 'Chưa có địa chỉ' }}</p>
                        <span v-if="addressStandard" class="mt-1.5 inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] text-emerald-700"><Check class="size-3" /> Đã chuẩn hóa</span>
                        <span v-else-if="form.shipping_address" class="mt-1.5 inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] text-amber-700"><AlertTriangle class="size-3" /> Chưa chuẩn hóa</span>
                        <ul v-if="addressMenu" class="absolute inset-x-0 top-full z-20 mt-1 max-h-72 overflow-y-auto rounded-md border bg-card py-1 shadow-lg" data-testid="address-menu">
                            <li v-if="customerMain">
                                <button type="button" class="block w-full px-3 py-2 text-left text-sm hover:bg-muted" @click="useAddress(customerMain)">
                                    <span class="font-medium">Địa chỉ chính</span> <span class="block text-xs text-muted-foreground">{{ customerMain.address }}</span>
                                </button>
                            </li>
                            <li v-for="a in savedAddresses" :key="a.id">
                                <button type="button" class="block w-full px-3 py-2 text-left text-sm hover:bg-muted" @click="useAddress(a)">
                                    <span class="font-medium">{{ a.label || a.recipient || 'Địa chỉ' }}</span><template v-if="a.phone"> · {{ a.phone }}</template>
                                    <span class="block text-xs text-muted-foreground">{{ a.address }}</span>
                                </button>
                            </li>
                            <li>
                                <button type="button" class="block w-full px-3 py-2 text-left text-sm font-medium text-primary hover:bg-muted" @click="((addressMenu = false), focusAddress())">+ Nhập địa chỉ khác</button>
                            </li>
                        </ul>
                    </div>
                </div>
            </Card>

            <Card title="Thông tin bổ sung" :caps="false">
                <template #actions>
                    <button type="button" class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground" title="Sắp xếp thông tin bổ sung" data-testid="extras-settings" @click="openView('extras')"><Settings2 class="size-4" /></button>
                </template>
                <div class="space-y-3 p-4 text-sm">
                    <div class="grid grid-cols-[88px_1fr] items-center gap-2">
                        <label for="o-channel" class="text-muted-foreground">Kênh bán</label>
                        <Select id="o-channel" v-model="form.channel_id" :options="activeChannels" :disabled="!!editId" data-testid="order-channel" />
                    </div>
                    <p v-if="error?.field('channel_id')" class="text-xs text-destructive">{{ error.field('channel_id') }}</p>
                    <template v-for="e in shownExtras" :key="e.key">
                        <div v-if="e.key === 'warehouse'" class="grid grid-cols-[88px_1fr] items-center gap-2">
                            <label for="o-warehouse" class="text-muted-foreground">Kho xuất</label>
                            <Select id="o-warehouse" v-model="form.warehouse_id" :options="activeWarehouses" data-testid="order-warehouse" />
                        </div>
                        <div v-else-if="e.key === 'seller'" class="grid grid-cols-[88px_1fr] items-center gap-2">
                            <span class="text-muted-foreground">Bán bởi</span>
                            <span class="flex h-9 items-center rounded-md border bg-muted/40 px-3">{{ auth.user?.name }}</span>
                        </div>
                        <div v-else-if="e.key === 'deliver_on'" class="grid grid-cols-[88px_1fr] items-center gap-2">
                            <label for="o-deliver-on" class="text-muted-foreground">Hẹn giao</label>
                            <DateInput weekday quick="future" id="o-deliver-on" v-model="form.deliver_on" data-testid="form-deliver-on" />
                        </div>
                    </template>
                    <div v-if="canBackdate" class="grid grid-cols-[88px_1fr] items-center gap-2">
                        <label for="o-placed-at" class="text-muted-foreground">Ngày bán</label>
                        <DateTimeInput id="o-placed-at" v-model="form.placed_at" :max="new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16)" data-testid="form-placed-at" />
                    </div>
                    <p v-if="error?.field('placed_at')" class="text-xs text-destructive">{{ error.field('placed_at') }}</p>
                    <p v-if="error?.field('warehouse_id')" class="text-xs text-destructive">{{ error.field('warehouse_id') }}</p>
                </div>
            </Card>
        </div>

        <Card title="Thông tin sản phẩm" :caps="false">
            <template #actions>
                <div class="flex items-center gap-3 text-sm">
                    <label class="flex items-center gap-1.5" title="Quét/chọn lại cùng SKU sẽ thêm dòng mới thay vì cộng số lượng"><input v-model="view.split" type="checkbox" class="size-4" /> Tách dòng</label>
                    <span class="h-4 w-px bg-border" />
                    <button v-if="tenant.can('inventory.view')" type="button" class="font-medium text-primary hover:underline disabled:opacity-50" :disabled="!lines.some((l) => l.kind === 'sku')" data-testid="check-stock" @click="openStock">Kiểm tra tồn kho</button>
                    <span class="h-4 w-px bg-border" />
                    <button type="button" class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground" title="Tùy chỉnh hiển thị" data-testid="lines-settings" @click="openView('columns')"><Settings2 class="size-4" /></button>
                </div>
            </template>
            <div class="flex flex-wrap gap-2 border-b p-4">
                <SkuPicker class="min-w-64 flex-1" :warehouse="form.warehouse_id" :exclude="[]" placeholder="Tìm theo tên, mã SKU, hoặc quét mã barcode… (F3)" data-shortcut="sku" @pick="add" />
                <Button type="button" variant="outline" data-testid="quick-pick" @click="pickOpen = true">Chọn nhanh</Button>
                <Select v-model="priceTier" class="w-40" :options="[{ value: 'retail', label: 'Giá bán lẻ' }, { value: 'wholesale', label: 'Giá bán buôn' }]" aria-label="Chính sách giá" data-testid="price-tier" />
                <p v-if="error?.field('lines')" class="basis-full text-xs text-destructive">{{ error.field('lines') }}</p>
            </div>
            <div v-if="!lines.length" class="flex flex-col items-center py-10 text-center text-sm text-muted-foreground">
                <PackageOpen class="mb-2 size-14 text-slate-300" :stroke-width="1.5" />
                Chưa có thông tin sản phẩm
                <Button size="sm" variant="outline" class="mt-3 text-primary" @click="focusSku">Thêm sản phẩm</Button>
            </div>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm" data-testid="order-lines">
                    <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="w-12 px-4 py-2 font-medium">STT</th>
                            <th v-if="view.columns.image" class="w-12 px-1 py-2 font-medium">Ảnh</th>
                            <th class="px-2 py-2 font-medium">Tên sản phẩm</th>
                            <th v-if="view.columns.available" class="whitespace-nowrap px-3 py-2 text-right font-medium">Khả dụng</th>
                            <th class="w-24 px-2 py-2 text-right font-medium">Số lượng</th>
                            <th class="w-32 px-2 py-2 text-right font-medium">Đơn giá</th>
                            <th v-if="view.columns.discount" class="w-28 px-2 py-2 text-right font-medium">Chiết khấu</th>
                            <th class="w-32 whitespace-nowrap px-3 py-2 text-right font-medium">Thành tiền</th>
                            <th class="w-10 px-2 py-2" />
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="(l, i) in lines" :key="l.uid" class="align-middle">
                            <td class="px-4 py-2.5 text-muted-foreground">{{ i + 1 }}</td>
                            <td v-if="view.columns.image" class="px-1 py-2.5">
                                <img v-if="l.image" :src="l.image" alt="" class="size-9 rounded-md border object-cover" />
                                <span v-else class="grid size-9 place-items-center rounded-md border bg-slate-50 text-slate-400"><Wrench v-if="l.kind === 'service'" class="size-4" /><ImageIcon v-else class="size-4" /></span>
                            </td>
                            <td class="px-2 py-2.5">
                                <template v-if="l.kind === 'service'">
                                    <input v-model="l.name" :data-service="l.uid" required maxlength="255" placeholder="Tên dịch vụ (VD: Công lắp đặt)" class="h-8 w-full max-w-md rounded-md border border-input bg-card px-2 text-sm" :data-testid="`line-name-${i}`" />
                                    <p class="mt-0.5 text-[11px] text-muted-foreground">Dịch vụ · không trừ kho</p>
                                </template>
                                <template v-else>
                                    <p class="max-w-80 truncate font-medium" :title="l.name">{{ l.name }}</p>
                                    <p v-if="view.columns.sku" class="font-mono text-[11px] text-primary">{{ l.sku }}</p>
                                </template>
                            </td>
                            <td v-if="view.columns.available" class="px-3 py-2.5 text-right tabular-nums">
                                <span v-if="l.available !== null" :class="cn(num(l.quantity) > l.available && 'rounded-full bg-rose-50 px-2 font-medium text-rose-600')">{{ formatNumber(l.available) }}</span>
                                <span v-else class="text-muted-foreground">—</span>
                            </td>
                            <td class="px-2 py-2.5 text-right">
                                <input v-model="l.quantity" type="number" min="1" required :data-testid="`line-qty-${i}`" :class="cn('h-8 w-20 rounded-md border border-input bg-card px-2 text-right text-sm tabular-nums', error?.field(`lines.${i}.quantity`) && 'border-destructive')" />
                            </td>
                            <td class="px-2 py-2.5 text-right">
                                <input v-model="l.unit_price" type="number" min="0" step="any" required :disabled="!canEditPrice && l.kind === 'sku'" :title="canEditPrice ? undefined : 'Cấu hình không cho nhân viên sửa giá'" :data-testid="`line-price-${i}`" class="h-8 w-28 rounded-md border border-input bg-card px-2 text-right text-sm tabular-nums disabled:bg-muted/50" />
                            </td>
                            <td v-if="view.columns.discount" class="px-2 py-2.5 text-right">
                                <input v-model="l.discount" type="number" min="0" step="any" :disabled="!canEditPrice" class="h-8 w-24 rounded-md border border-input bg-card px-2 text-right text-sm tabular-nums disabled:bg-muted/50" />
                                <p v-if="error?.field(`lines.${i}.discount`)" class="text-xs text-destructive">{{ error.field(`lines.${i}.discount`) }}</p>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right font-semibold tabular-nums">{{ formatMoney(lineTotal(l)) }}</td>
                            <td class="px-2 py-2.5 text-right">
                                <Button variant="ghost" size="icon" class="size-8" title="Xóa dòng" @click="lines.splice(i, 1)"><X class="text-muted-foreground" /></Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <Alert v-if="lines.some((l) => l.available !== null && num(l.quantity) > l.available)" :icon="AlertTriangle" tone="amber" class="m-4 text-xs">
                    Có sản phẩm vượt số khả dụng: đơn vẫn lưu được (giữ phần còn lại, thiếu sẽ được giữ khi nhập hàng) nhưng không xuất kho được cho đến khi đủ hàng.
                </Alert>
            </div>
            <div class="flex items-center border-t px-4 py-2.5">
                <button type="button" class="flex items-center gap-1.5 text-sm font-medium text-primary hover:underline" data-testid="add-service" @click="addService"><CirclePlus class="size-4" /> Thêm dịch vụ khác (F9)</button>
            </div>

            <div class="grid gap-6 border-t p-4 md:grid-cols-[minmax(0,1fr)_380px]">
                <div class="max-w-md space-y-4">
                    <div>
                        <span class="mb-1.5 block text-sm text-muted-foreground">Tags</span>
                        <TagInput v-model="form.tags" :suggestions="tagSuggestions" />
                    </div>
                    <FormField label="Ghi chú đơn hàng" for="o-note">
                        <textarea id="o-note" v-model="form.note" maxlength="500" rows="3" placeholder="VD: Hàng tặng gói riêng" class="w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" />
                    </FormField>
                </div>
                <!-- One value column (w-36, right-aligned) for every figure and input, so the numbers line up. -->
                <dl class="grid grid-cols-[1fr_9rem] items-center gap-x-3 gap-y-2.5 text-sm">
                    <dt>Tổng tiền ({{ totalQty }} sản phẩm)</dt>
                    <dd class="text-right tabular-nums">{{ formatMoney(subtotal) }}</dd>
                    <dt>Chiết khấu <kbd class="text-[10px] text-muted-foreground">F6</kbd></dt>
                    <dd><input v-model="form.discount" type="number" min="0" :disabled="!canEditPrice" class="h-8 w-full rounded-md border border-input bg-card px-2 text-right text-sm tabular-nums disabled:bg-muted/50" data-testid="order-discount" @input="groupDiscountAuto = false" /></dd>
                    <p v-if="groupDiscountAuto" class="col-span-2 -mt-1.5 text-right text-xs text-muted-foreground">Chiết khấu nhóm {{ Number(customerTerms?.group?.discount_percent ?? customer?.group?.discount_percent ?? 0) }}%</p>
                    <p v-if="error?.field('discount')" class="col-span-2 text-right text-xs text-destructive">{{ error.field('discount') }}</p>
                    <template v-if="promoDiscount > 0">
                        <dt class="text-emerald-700">Khuyến mãi <span class="block text-xs font-normal text-muted-foreground">{{ promoNames }}</span></dt>
                        <dd class="text-right tabular-nums text-emerald-700" data-testid="order-promotion">−{{ formatMoney(promoDiscount) }}</dd>
                    </template>
                    <template v-for="g in promoGifts" :key="`${g.promotion}-${g.sku}`">
                        <dt class="text-emerald-700">Tặng {{ g.quantity }} × {{ g.name ?? g.sku }} <span class="block text-xs font-normal text-muted-foreground">{{ g.promotion }}</span></dt>
                        <dd class="text-right tabular-nums text-emerald-700" data-testid="order-gift">0 ₫</dd>
                    </template>
                    <dt>Mã giảm giá</dt>
                    <dd><input v-model="form.coupon_code" placeholder="Nhập mã" maxlength="40" class="h-8 w-full rounded-md border border-input bg-card px-2 text-right text-sm uppercase" data-testid="order-coupon" /></dd>
                    <p v-if="form.coupon_code.trim() && promo?.code_error" class="col-span-2 -mt-1.5 text-right text-xs text-destructive" data-testid="coupon-error">{{ promo.code_error }}</p>
                    <p v-if="error?.field('coupon_code')" class="col-span-2 -mt-1.5 text-right text-xs text-destructive">{{ error.field('coupon_code') }}</p>
                    <label v-if="canEditPrice" class="col-span-2 -mt-1 flex items-center justify-end gap-1.5 text-xs text-muted-foreground">
                        <input v-model="form.promotions_off" type="checkbox" class="size-3.5" data-testid="promotions-off" /> Bỏ khuyến mãi tự động trên đơn này
                    </label>
                    <dt class="text-primary">Phí giao hàng <kbd class="text-[10px] text-muted-foreground">F7</kbd></dt>
                    <dd><input v-model="form.shipping_fee" type="number" min="0" class="h-8 w-full rounded-md border border-input bg-card px-2 text-right text-sm tabular-nums" data-testid="shipping-fee" /></dd>
                    <template v-if="taxRule.rate > 0">
                        <dt class="text-muted-foreground">{{ taxRule.included ? 'Trong đó' : 'Cộng' }} thuế GTGT {{ taxRule.rate }}%</dt>
                        <dd class="text-right tabular-nums text-muted-foreground" data-testid="order-tax">{{ formatMoney(taxAmount) }}</dd>
                    </template>
                    <div class="col-span-2 border-t" />
                    <dt class="font-semibold">Khách phải trả</dt>
                    <dd class="text-right text-xl font-bold tabular-nums text-blue-600" data-testid="order-total">{{ formatMoney(total) }}</dd>
                    <template v-if="exchange">
                        <dt class="text-emerald-600">Trừ hàng đổi trả</dt>
                        <dd class="text-right tabular-nums text-emerald-600" data-testid="exchange-credit">−{{ formatMoney(exchangeCredit) }}</dd>
                    </template>
                    <template v-if="!editId && tenant.can('orders.payment')">
                        <div class="col-span-2 border-t" />
                        <dt class="flex items-center gap-2 font-semibold">
                            Khách đã trả
                            <Select v-model="form.pay_method" class="h-8 w-32 text-xs font-normal" :options="payOptions" aria-label="Hình thức" />
                        </dt>
                        <dd><input v-model="form.pay_amount" type="number" min="0" placeholder="0" class="h-8 w-full rounded-md border border-input bg-card px-2 text-right text-sm tabular-nums" data-testid="pay-amount" /></dd>
                        <dd class="col-span-2 -mt-1">
                            <button type="button" class="flex items-center gap-1.5 text-xs font-medium text-primary hover:underline" @click="payFull"><CirclePlus class="size-3.5" /> Thu đủ {{ formatMoney(total - exchangeCredit) }}</button>
                            <p v-if="error?.field('amount')" class="text-xs text-destructive">{{ error.field('amount') }}</p>
                        </dd>
                    </template>
                    <div class="col-span-2 border-t" />
                    <dt class="font-semibold">Còn phải trả</dt>
                    <dd :class="cn('text-right font-semibold tabular-nums', remaining > 0 && 'text-rose-600')">{{ formatMoney(remaining) }}</dd>
                </dl>
            </div>
        </Card>

        <Card title="Đóng gói và giao hàng" :caps="false">
            <div class="space-y-4 p-4">
                <div v-if="!editId" class="flex flex-wrap gap-3" data-testid="delivery-options">
                    <label
                        v-for="s in deliveries"
                        :key="s.value"
                        :class="cn('relative flex cursor-pointer items-center gap-2 rounded-lg border px-4 py-2.5 text-sm font-medium hover:bg-muted/40', delivery === s.value && 'border-primary bg-blue-50/70 text-primary hover:bg-blue-50/70')"
                        :title="s.hint"
                    >
                        <input v-model="delivery" type="radio" :value="s.value" class="absolute inset-0 cursor-pointer opacity-0" :data-testid="s.value === 'completed' ? 'stage-completed' : `delivery-${s.value}`" />
                        <component :is="s.icon" class="size-4" />
                        {{ s.label }}
                        <Check v-if="delivery === s.value" class="absolute -bottom-px -right-px size-4 rounded-tl-md rounded-br-lg bg-primary p-0.5 text-white" />
                    </label>
                </div>
                <div class="flex flex-wrap items-center gap-x-5 gap-y-1">
                    <p class="text-xs text-muted-foreground">{{ editId ? 'Thông tin giao hàng của đơn.' : deliveries.find((s) => s.value === delivery)?.hint }}</p>
                    <label v-if="!editId && (delivery === 'ext' || delivery === 'later')" class="flex items-center gap-2 text-sm">
                        <input v-model="confirmedNow" type="checkbox" class="size-4" data-testid="stage-confirmed" /> Đã xác nhận, chờ đóng gói
                    </label>
                </div>
                <div v-if="editId || delivery !== 'completed'" :class="cn('grid gap-5', (editId || delivery === 'ext') && 'lg:grid-cols-[minmax(0,1fr)_340px]')">
                    <div class="space-y-3">
                        <p class="font-medium">Địa chỉ người nhận</p>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <FormField :label="`Tên người nhận${isCarrier ? ' *' : ''}`" for="o-name" :error="error?.field('buyer_name')"><Input id="o-name" v-model="form.buyer_name" data-testid="buyer-name" /></FormField>
                            <FormField :label="`Số điện thoại${isCarrier ? ' *' : ''}`" for="o-phone" :error="error?.field('buyer_phone')"><Input id="o-phone" v-model="form.buyer_phone" inputmode="tel" data-testid="buyer-phone" /></FormField>
                        </div>
                        <AddressPicker
                            v-model="form.address"
                            id-prefix="o-addr"
                            :required="isCarrier"
                            :legacy="legacyAddress"
                            :errors="{ street: error?.field('shipping_street'), province_code: error?.field('shipping_province_code'), ward_code: error?.field('shipping_ward_code') }"
                            @update:text="onAddressText"
                        />
                        <div v-if="savedAddresses.length" class="flex flex-wrap gap-1.5" data-testid="saved-addresses">
                            <span class="self-center text-xs text-muted-foreground">Sổ địa chỉ:</span>
                            <button
                                v-for="a in savedAddresses"
                                :key="a.id"
                                type="button"
                                :class="['max-w-full truncate rounded-full border px-2.5 py-1 text-xs', form.shipping_address === a.address ? 'border-blue-300 bg-blue-50 text-blue-700' : 'hover:bg-muted']"
                                :title="a.address"
                                @click="useAddress(a)"
                            >
                                {{ a.label || a.recipient || 'Địa chỉ' }} · {{ a.address }}
                            </button>
                        </div>
                    </div>
                    <div v-if="!editId && delivery === 'later'" class="flex items-center gap-4">
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.is_cod" type="checkbox" class="size-4" /> Thu hộ (COD) khi giao</label>
                    </div>
                    <div v-if="!editId && delivery === 'api'" class="space-y-3" data-testid="delivery-api">
                        <div class="flex flex-wrap items-center gap-4">
                            <p class="font-medium">Đẩy qua hãng vận chuyển</p>
                            <label class="flex items-center gap-2 text-sm"><input v-model="form.is_cod" type="checkbox" class="size-4" data-testid="api-cod" /> Thu hộ (COD) {{ form.is_cod ? formatMoney(codAmount) : '' }}</label>
                        </div>
                        <Alert v-if="!hasCarrierAccount" tone="amber">
                            Chưa kết nối hãng vận chuyển nào. <RouterLink to="/shipments" class="font-medium text-primary hover:underline">Kết nối hãng</RouterLink> (miễn phí, dùng tài khoản của shop) hoặc chọn "Đẩy vận chuyển ngoài".
                        </Alert>
                        <div v-else class="grid gap-4 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
                            <ParcelOptionsPanel v-model="parcel" hide-cod :weight-hint="quote?.weight_g" />
                            <div>
                                <p v-if="quoteMissing" class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">{{ quoteMissing }}</p>
                                <CarrierQuotePicker v-else v-model="carrierChoice" :quote="quote" :loading="quoteLoading" :error="quoteError" />
                            </div>
                        </div>
                        <p v-if="pushError" class="text-sm text-destructive" data-testid="push-choice-error">{{ pushError }}</p>
                    </div>
                    <div v-if="editId || delivery === 'ext'" class="space-y-3 rounded-lg bg-slate-50 p-4">
                        <p class="font-medium">Vận chuyển</p>
                        <div class="grid grid-cols-2 gap-2" data-testid="carrier-options">
                            <button
                                v-for="c in CARRIERS"
                                :key="c"
                                type="button"
                                :class="cn('rounded-md border bg-card px-2.5 py-2 text-left text-sm', form.carrier === c ? 'border-primary font-medium text-primary ring-1 ring-primary' : 'hover:border-slate-300')"
                                :data-testid="`carrier-${c}`"
                                @click="pickCarrier(c)"
                            >
                                {{ c }}
                            </button>
                            <button
                                type="button"
                                :class="cn('col-span-2 rounded-md border bg-card px-2.5 py-2 text-left text-sm', (otherCarrier || (form.carrier && !isCarrier)) ? 'border-primary font-medium text-primary ring-1 ring-primary' : 'hover:border-slate-300')"
                                @click="((otherCarrier = true), isCarrier && (form.carrier = ''))"
                            >
                                Tự giao / đơn vị khác
                            </button>
                        </div>
                        <Input v-if="otherCarrier || (form.carrier && !isCarrier)" id="o-carrier" v-model="form.carrier" placeholder="Shipper riêng, Ahamove, GHTK…" aria-label="Đơn vị vận chuyển khác" />
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.is_cod" type="checkbox" class="size-4" /> Thu hộ (COD) khi giao</label>
                        <p class="rounded-md bg-blue-50 px-3 py-2 text-xs text-blue-800">
                            Nhập mã vận đơn của hãng ở trang đơn sau khi tạo. Hãng đã kết nối (GHN): chọn "Đẩy qua hãng vận chuyển" để đẩy đơn ngay.
                        </p>
                    </div>
                </div>
                <p v-if="error && error.field('stock')" class="text-sm text-destructive" data-testid="stock-error">{{ error.field('stock') }}</p>
            </div>
        </Card>
    </form>

    <Dialog v-model:open="customerDialog" title="Thêm mới khách hàng" description="Khách được lưu vào danh bạ khi tạo đơn." size="lg">
        <form id="new-customer-form" class="grid gap-3 sm:grid-cols-2" @submit.prevent="saveNewCustomer">
            <FormField label="Tên khách hàng *" for="nc-name" :error="error?.field('customer.name')"><Input id="nc-name" v-model="newCustomer.name" required data-testid="nc-name" /></FormField>
            <FormField label="Số điện thoại" for="nc-phone" :error="error?.field('phone') ?? error?.field('customer.phone')"><Input id="nc-phone" v-model="newCustomer.phone" inputmode="tel" data-testid="nc-phone" /></FormField>
            <div class="sm:col-span-2">
                <AddressPicker
                    v-model="newCustomer.addr"
                    id-prefix="nc-addr"
                    :errors="{ street: error?.field('customer.street'), province_code: error?.field('customer.province_code'), ward_code: error?.field('customer.ward_code') }"
                    @update:text="(t: string) => (newCustomer.address = t)"
                />
            </div>
            <Alert v-if="phoneMatch" :icon="UserRound" tone="amber" class="sm:col-span-2">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span>Số này đã có: <b>{{ phoneMatch.name }}</b> ({{ phoneMatch.code }})</span>
                    <Button size="sm" variant="outline" @click="(chooseCustomer(phoneMatch), (customerDialog = false))">Dùng khách này</Button>
                </div>
            </Alert>
        </form>
        <template #footer>
            <Button variant="outline" @click="cancelNewCustomer">Thoát</Button>
            <Button form="new-customer-form" type="submit" :disabled="!newCustomer.name.trim()" data-testid="nc-save">Thêm</Button>
        </template>
    </Dialog>
    <Dialog v-model:open="pickOpen" title="Chọn sản phẩm để bán hàng" size="lg">
        <div class="relative mb-3">
            <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
            <Input v-model="pickQ" class="pl-8" placeholder="Tìm kiếm sản phẩm" data-testid="quick-pick-search" />
        </div>
        <ul class="divide-y rounded-md border" data-testid="quick-pick-list">
            <li v-for="v in pickRows" :key="v.id">
                <button type="button" :class="cn('flex w-full items-center gap-3 px-3 py-2.5 text-left hover:bg-muted/40', picked.has(v.id!) && 'bg-blue-50/70 hover:bg-blue-50/70')" @click="togglePick(v)">
                    <img v-if="v.product?.image" :src="v.product.image" alt="" class="size-11 shrink-0 rounded-md border object-cover" />
                    <span v-else class="grid size-11 shrink-0 place-items-center rounded-md border bg-slate-50 text-slate-400"><ImageIcon class="size-5" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm">{{ v.product?.name }}<template v-if="v.name"> ({{ v.name }})</template></span>
                        <span class="font-mono text-[11px] text-muted-foreground">{{ v.sku }}</span>
                    </span>
                    <span class="shrink-0 text-right">
                        <span class="block text-sm font-semibold tabular-nums">{{ formatNumber(tierPrice({ retail: Number(v.ref_price) || 0, wholesale: Number(v.wholesale_price) || 0 })) }}</span>
                        <span v-if="v.stock" class="text-[11px] text-muted-foreground">Tồn: <b :class="v.stock.on_hand > 0 ? 'text-primary' : 'text-rose-600'">{{ formatNumber(v.stock.on_hand) }}</b> | Có thể bán: <b :class="v.stock.available > 0 ? 'text-primary' : 'text-rose-600'">{{ formatNumber(v.stock.available) }}</b></span>
                    </span>
                    <span :class="cn('grid size-6 shrink-0 place-items-center rounded-full border-2', picked.has(v.id!) ? 'border-primary bg-primary text-white' : 'border-primary/40 text-primary')">
                        <Check v-if="picked.has(v.id!)" class="size-3.5" /><CirclePlus v-else class="size-3.5" />
                    </span>
                </button>
            </li>
            <li v-if="!pickRows.length" class="px-3 py-8 text-center text-sm text-muted-foreground">Không có sản phẩm khớp tìm kiếm.</li>
        </ul>
        <template #footer>
            <span class="mr-auto self-center text-sm font-medium text-primary">Bạn đã chọn {{ picked.size }} sản phẩm</span>
            <Button variant="outline" @click="pickOpen = false">Thoát</Button>
            <Button :disabled="!picked.size" data-testid="quick-pick-done" @click="confirmPick">Chọn xong</Button>
        </template>
    </Dialog>

    <Dialog v-model:open="stockOpen" title="Kiểm tra tồn kho" size="xl">
        <div class="overflow-x-auto rounded-md border">
            <table class="w-full text-sm" data-testid="stock-check">
                <thead class="whitespace-nowrap bg-slate-50 text-xs font-semibold text-foreground/80">
                    <tr class="border-b">
                        <th rowspan="2" class="border-r px-3 py-2 text-left">Mã SKU</th>
                        <th rowspan="2" class="border-r px-3 py-2 text-left">Tên sản phẩm</th>
                        <th rowspan="2" class="border-r px-3 py-2 text-right">Số lượng</th>
                        <th v-for="w in stockWarehouses" :key="w.id" colspan="3" class="border-r px-3 py-2 text-center">{{ w.name }}</th>
                    </tr>
                    <tr class="border-b">
                        <template v-for="w in stockWarehouses" :key="w.id">
                            <th class="px-3 py-1.5 text-right font-medium">Tồn kho</th>
                            <th class="px-3 py-1.5 text-right font-medium">Đang giữ</th>
                            <th class="border-r px-3 py-1.5 text-right font-medium">Có thể bán</th>
                        </template>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="r in stockRows" :key="r.line.uid">
                        <td class="border-r px-3 py-2 font-mono text-xs text-primary">{{ r.line.sku }}</td>
                        <td class="border-r px-3 py-2">{{ r.line.name }}</td>
                        <td class="border-r px-3 py-2 text-right tabular-nums">{{ formatNumber(num(r.line.quantity)) }}</td>
                        <template v-for="w in stockWarehouses" :key="w.id">
                            <td class="px-3 py-2 text-right tabular-nums">{{ formatNumber(r.balances.find((b) => b.warehouse.id === w.id)?.on_hand ?? 0) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-muted-foreground">{{ formatNumber(r.balances.find((b) => b.warehouse.id === w.id)?.reserved ?? 0) }}</td>
                            <td :class="cn('border-r px-3 py-2 text-right font-medium tabular-nums', (r.balances.find((b) => b.warehouse.id === w.id)?.available ?? 0) < num(r.line.quantity) && 'text-rose-600')">{{ formatNumber(r.balances.find((b) => b.warehouse.id === w.id)?.available ?? 0) }}</td>
                        </template>
                    </tr>
                    <tr v-if="!stockRows.length"><td :colspan="3 + stockWarehouses.length * 3" class="px-3 py-8 text-center text-muted-foreground">Đang tải…</td></tr>
                </tbody>
            </table>
        </div>
        <template #footer><Button variant="outline" @click="stockOpen = false">Thoát</Button></template>
    </Dialog>

    <Dialog v-model:open="viewOpen" title="Tùy chỉnh hiển thị" size="lg">
        <div class="space-y-5 text-sm">
            <div>
                <p class="mb-2 font-medium">Cột hiển thị</p>
                <div class="grid grid-cols-2 gap-2">
                    <label v-for="(label, key) in { image: 'Ảnh', sku: 'Mã SKU', available: 'Khả dụng', discount: 'Chiết khấu' }" :key="key" class="flex items-center gap-2 rounded-md border px-3 py-2 hover:bg-muted/40">
                        <input v-model="viewDraft.columns[key]" type="checkbox" class="size-4" /> {{ label }}
                    </label>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-4">
                <span class="font-medium">Thứ tự hiển thị hàng hóa:</span>
                <label class="flex items-center gap-1.5"><input v-model="viewDraft.newOnTop" type="radio" :value="true" /> Thêm sau lên trên</label>
                <label class="flex items-center gap-1.5"><input v-model="viewDraft.newOnTop" type="radio" :value="false" /> Thêm sau xuống dưới</label>
            </div>
            <label class="flex items-center gap-2"><input v-model="viewDraft.split" type="checkbox" class="size-4" /> Tách dòng khi thêm lại cùng sản phẩm</label>
        </div>
        <template #footer>
            <Button variant="outline" @click="viewOpen = false">Thoát</Button>
            <Button data-testid="lines-settings-save" @click="saveView">Lưu</Button>
        </template>
    </Dialog>

    <Dialog v-model:open="extrasOpen" title="Sắp xếp thông tin bổ sung" description="Chọn thông tin hiển thị và thứ tự (Kênh bán luôn ở đầu)." size="md">
        <ul class="divide-y rounded-md border text-sm">
            <li v-for="(e, i) in viewDraft.extras" :key="e.key" class="flex items-center gap-3 px-3 py-2.5">
                <input v-model="e.show" type="checkbox" class="size-4" :aria-label="`Hiện ${e.label}`" />
                <span class="flex-1">{{ e.label }}</span>
                <button type="button" class="rounded p-1 text-muted-foreground hover:bg-muted disabled:opacity-30" :disabled="i === 0" :aria-label="`Đưa ${e.label} lên`" @click="moveExtra(i, -1)"><ArrowUp class="size-4" /></button>
                <button type="button" class="rounded p-1 text-muted-foreground hover:bg-muted disabled:opacity-30" :disabled="i === viewDraft.extras.length - 1" :aria-label="`Đưa ${e.label} xuống`" @click="moveExtra(i, 1)"><ArrowDown class="size-4" /></button>
            </li>
        </ul>
        <template #footer>
            <Button variant="outline" @click="extrasOpen = false">Thoát</Button>
            <Button @click="saveView">Lưu</Button>
        </template>
    </Dialog>
</template>
