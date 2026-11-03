<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { AlertTriangle, ArrowLeft, Keyboard, PackageOpen, Repeat, Trash2, UserRound, X } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import SkuPicker from '../../components/SkuPicker.vue';
import TagInput from '../../components/TagInput.vue';
import Alert from '@/shared/ui/Alert.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { channelTypes, paymentMethods } from './labels';
import type { Customer, OrderDetail, OrderReturnData, SalesChannel, Variant, Warehouse } from '@/shared/types';

interface Line {
    variant_id: string;
    sku: string;
    name: string;
    available: number | null;
    quantity: number | string;
    unit_price: number | string;
    discount: number | string;
}

const route = useRoute();
const router = useRouter();
const toast = useToast();
const tenant = useTenantStore();
const editId = computed(() => (route.name === 'order-edit' ? String(route.params.id) : null));
const copyId = computed(() => (editId.value ? null : ((route.query.copy as string | undefined) ?? null)));
const copiedFrom = ref<string | null>(null);
// Exchange: a new order paid (partly) by the goods of a return (?exchange=<return id>).
const exchangeId = computed(() => (editId.value ? null : ((route.query.exchange as string | undefined) ?? null)));
const exchange = ref<OrderReturnData | null>(null);
const tagSuggestions = ref<string[]>([]);

const loading = ref(true);
const channels = ref<SalesChannel[]>([]);
const warehouses = ref<Warehouse[]>([]);
const lines = ref<Line[]>([]);
const customer = ref<Pick<Customer, 'id' | 'code' | 'name' | 'phone' | 'address'> | null>(null);
const newCustomer = reactive({ enabled: false, name: '', phone: '', address: '' });
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
    carrier: '',
    is_cod: false,
    shipping_fee: 0 as number | string,
    discount: 0 as number | string,
    note: '',
    tags: [] as string[],
    deliver_on: '',
    pay_method: 'cash',
    pay_amount: '' as number | string,
});
const saving = ref(false);
const error = ref<ApiError | null>(null);

const num = (v: number | string) => Number(v) || 0;
const lineTotal = (l: Line) => Math.max(0, num(l.quantity) * num(l.unit_price) - num(l.discount));
const subtotal = computed(() => lines.value.reduce((s, l) => s + lineTotal(l), 0));
const total = computed(() => Math.max(0, subtotal.value + num(form.shipping_fee) - num(form.discount)));
/** Estimate shown while typing; the server caps it at what the buyer actually paid on the original order. */
const exchangeCredit = computed(() => (exchange.value ? Math.min(Number(exchange.value.goods_value ?? 0), total.value) : 0));
const activeChannels = computed(() => channels.value.filter((c) => c.is_active || c.id === form.channel_id).map((c) => ({ value: c.id, label: `${c.name} · ${channelTypes[c.type] ?? c.type}` })));
const activeWarehouses = computed(() => warehouses.value.filter((w) => w.status === 'active').map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` })));

onMounted(async () => {
    try {
        api.get('/orders/tags').then(({ data }) => (tagSuggestions.value = data.data)).catch(() => {});
        const [c, w] = await Promise.all([api.get('/sales-channels'), api.get('/warehouses')]);
        channels.value = c.data.data;
        warehouses.value = w.data.data;
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
            if (exchangeId.value) await loadExchange(exchangeId.value);
        }
    } finally {
        loading.value = false;
    }
});

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
    // A marketplace line without an internal SKU cannot be sold again from here.
    const skipped = o.items.filter((i) => !i.variant).length;
    if (asCopy && skipped) toast.info(`Bỏ qua ${skipped} dòng chưa liên kết SKU nội bộ.`);
    o.items = o.items.filter((i) => i.variant);
    form.buyer_name = o.buyer_name ?? '';
    form.buyer_phone = o.buyer_phone ?? '';
    form.shipping_address = o.shipping_address ?? '';
    form.carrier = o.carrier ?? '';
    form.is_cod = o.is_cod;
    form.shipping_fee = Number(o.shipping_fee);
    form.discount = Number(o.discount);
    form.note = o.buyer_note ?? '';
    form.tags = [...(o.tags ?? [])];
    form.deliver_on = asCopy ? '' : (o.deliver_on ?? '');
    customer.value = o.customer ? { ...o.customer, phone: null, address: null } : null;
    lines.value = o.items.map((i) => ({
        variant_id: i.variant!.id,
        sku: i.variant!.sku,
        name: i.name + (i.variant_name ? ` · ${i.variant_name}` : ''),
        available: null,
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

function add(v: Variant) {
    const existing = lines.value.find((l) => l.variant_id === v.id);
    if (existing) {
        existing.quantity = num(existing.quantity) + 1;
        return;
    }
    lines.value.push({
        variant_id: v.id!,
        sku: v.sku,
        name: (v.product?.name ?? '') + (v.name ? ` · ${v.name}` : ''),
        available: v.stock?.available ?? null,
        quantity: 1,
        unit_price: Number(v.ref_price) || 0,
        discount: 0,
    });
}

let customerTimer: ReturnType<typeof setTimeout> | undefined;
watch(customerQuery, (q) => {
    clearTimeout(customerTimer);
    if (!q.trim()) {
        customerResults.value = [];
        return;
    }
    customerTimer = setTimeout(async () => {
        const { data } = await api.get('/customers', { params: { q, per_page: 8 } });
        customerResults.value = data.data;
    }, 250);
});

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

function chooseCustomer(c: Customer) {
    customer.value = c;
    newCustomer.enabled = false;
    customerQuery.value = '';
    customerResults.value = [];
    form.buyer_name = c.name;
    form.buyer_phone = c.phone ?? '';
    form.shipping_address = c.address ?? form.shipping_address;
}

function clearCustomer() {
    customer.value = null;
}

watch(
    () => [newCustomer.name, newCustomer.phone, newCustomer.address],
    () => {
        if (!newCustomer.enabled) return;
        form.buyer_name = newCustomer.name;
        form.buyer_phone = newCustomer.phone;
        form.shipping_address = newCustomer.address;
    },
);

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
    form.shipping_address = original.shipping_address ?? '';
}

async function submit() {
    saving.value = true;
    error.value = null;
    const body: Record<string, unknown> = {
        warehouse_id: form.warehouse_id || null,
        customer_id: customer.value?.id ?? null,
        ...(newCustomer.enabled && newCustomer.name ? { customer: { name: newCustomer.name, phone: newCustomer.phone || null, address: newCustomer.address || null } } : {}),
        buyer_name: form.buyer_name || null,
        buyer_phone: form.buyer_phone || null,
        shipping_address: form.shipping_address || null,
        carrier: form.carrier || null,
        is_cod: form.is_cod,
        shipping_fee: num(form.shipping_fee),
        discount: num(form.discount),
        note: form.note || null,
        tags: form.tags,
        deliver_on: form.deliver_on || null,
        lines: lines.value.map((l) => ({ variant_id: l.variant_id, quantity: num(l.quantity), unit_price: num(l.unit_price), discount: num(l.discount) })),
    };
    try {
        if (editId.value) {
            await api.put(`/sales-orders/${editId.value}`, body);
            toast.success('Đã lưu đơn.');
            await router.push(`/orders/${editId.value}`);
        } else {
            const payments = num(form.pay_amount) > 0 ? [{ method: form.pay_method, amount: num(form.pay_amount) }] : [];
            const { data } = await api.post('/sales-orders', { ...body, channel_id: form.channel_id, stage: form.stage, payments, exchange_return_id: exchange.value?.id ?? null });
            toast.success(`Đã tạo đơn ${data.data.external_order_id}`);
            await router.push(`/orders/${data.data.id}`);
        }
    } catch (e) {
        error.value = e as ApiError;
        if (!error.value.body.errors || error.value.field('stock') || error.value.field('order')) toast.error(error.value.field('stock') ?? error.value.message);
    } finally {
        saving.value = false;
    }
}

const stages = [
    { value: 'pending', label: 'Chờ xác nhận', hint: 'Giữ hàng trong kho, xử lý sau.' },
    { value: 'confirmed', label: 'Đã xác nhận', hint: 'Chuyển thẳng sang chờ đóng gói.' },
    { value: 'completed', label: 'Hoàn tất ngay', hint: 'Khách nhận hàng tại chỗ: trừ kho ngay.' },
];

// Counter shortcuts: F1 save, F3 product search, F4 customer search, F6 order discount, F7 shipping fee.
const shortcuts: Record<string, string> = { F3: '[data-shortcut="sku"] input', F4: '[data-testid="customer-search"]', F6: '[data-testid="order-discount"]', F7: '[data-testid="shipping-fee"]' };
function onShortcut(e: KeyboardEvent) {
    if (loading.value) return;
    if (e.key === 'F1') {
        e.preventDefault();
        if (!saving.value && lines.value.length && form.channel_id) submit();
    } else if (shortcuts[e.key]) {
        e.preventDefault();
        const el = document.querySelector<HTMLInputElement>(shortcuts[e.key]!);
        el?.focus();
        el?.select?.();
    }
}
onMounted(() => window.addEventListener('keydown', onShortcut));
onBeforeUnmount(() => window.removeEventListener('keydown', onShortcut));
</script>

<template>
    <RouterLink :to="editId ? `/orders/${editId}` : '/orders'" class="mb-3 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft class="size-4" /> {{ editId ? 'Chi tiết đơn' : 'Đơn hàng' }}</RouterLink>
    <PageHeader
        :title="editId ? 'Sửa đơn bán' : copiedFrom ? `Tạo đơn bán (sao chép từ ${copiedFrom})` : 'Tạo đơn bán'"
        description="Đơn của kênh bán riêng (cửa hàng, Facebook, Zalo, website…). Hàng được giữ trong kho ngay khi lưu đơn."
    >
        <span class="hidden items-center gap-1.5 rounded-md border bg-card px-2.5 py-1.5 text-xs text-muted-foreground lg:inline-flex">
            <Keyboard class="size-3.5" />
            <template v-for="(k, i) in [['F1', 'lưu'], ['F3', 'sản phẩm'], ['F4', 'khách'], ['F6', 'giảm giá'], ['F7', 'phí giao']]" :key="k[0]"><span v-if="i" class="text-border">·</span><kbd class="rounded border bg-muted px-1 font-sans text-[10px] font-semibold text-foreground">{{ k[0] }}</kbd>{{ k[1] }}</template>
        </span>
    </PageHeader>

    <div v-if="loading" class="space-y-4"><Skeleton class="h-40" /><Skeleton class="h-64" /></div>
    <Alert v-if="!loading && exchange" :icon="Repeat" tone="blue" class="mb-5" data-testid="exchange-banner">
        <span>
            Đơn đổi hàng cho phiếu trả <b class="font-mono">{{ exchange.number }}</b> (đơn {{ exchange.order?.external_order_id }}). Hàng trả trị giá
            <b>{{ formatMoney(exchange.goods_value) }}</b> được trừ vào đơn này, tối đa bằng số khách đã trả ở đơn cũ. Chọn sản phẩm khách lấy đổi.
        </span>
    </Alert>
    <form v-if="!loading" class="grid gap-5 lg:grid-cols-3" data-testid="order-form" @submit.prevent="submit">
        <div class="space-y-5 lg:col-span-2">
            <Card caps title="Sản phẩm" description="Quét mã vạch hoặc tìm SKU để thêm. Giá mặc định là giá bán tham khảo của SKU.">
                <div class="border-b p-4">
                    <SkuPicker :warehouse="form.warehouse_id" :exclude="[]" placeholder="Tìm SKU, tên sản phẩm hoặc quét mã vạch (F3)" data-shortcut="sku" @pick="add" />
                    <p v-if="error?.field('lines')" class="mt-2 text-xs text-destructive">{{ error.field('lines') }}</p>
                </div>
                <EmptyState v-if="!lines.length" :icon="PackageOpen" title="Chưa có sản phẩm" description="Tìm SKU, tên sản phẩm hoặc quét mã vạch ở ô phía trên." />
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="order-lines">
                        <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-4 py-2 font-medium">Sản phẩm</th>
                                <th class="whitespace-nowrap px-3 py-2 text-right font-medium">Khả dụng</th>
                                <th class="px-2 py-2 text-right font-medium">SL</th>
                                <th class="px-2 py-2 text-right font-medium">Đơn giá</th>
                                <th class="px-2 py-2 text-right font-medium">Giảm</th>
                                <th class="whitespace-nowrap px-3 py-2 text-right font-medium">Thành tiền</th>
                                <th class="px-2 py-2" />
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="(l, i) in lines" :key="l.variant_id" class="align-top">
                                <td class="px-4 py-2.5">
                                    <p class="max-w-56 truncate font-medium" :title="l.name">{{ l.name }}</p>
                                    <p class="font-mono text-[11px] text-muted-foreground">{{ l.sku }}</p>
                                </td>
                                <td class="px-2 py-2.5 text-right tabular-nums">
                                    <span v-if="l.available !== null" :class="cn(num(l.quantity) > l.available && 'rounded-full bg-rose-50 px-2 font-medium text-rose-600')">{{ formatNumber(l.available) }}</span>
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                                <td class="px-2 py-2.5 text-right">
                                    <input v-model="l.quantity" type="number" min="1" required :data-testid="`line-qty-${i}`" :class="cn('h-8 w-16 rounded-md border border-input bg-card px-2 text-right text-sm tabular-nums', error?.field(`lines.${i}.quantity`) && 'border-destructive')" />
                                </td>
                                <td class="px-2 py-2.5 text-right">
                                    <input v-model="l.unit_price" type="number" min="0" step="any" required :data-testid="`line-price-${i}`" class="h-8 w-24 rounded-md border border-input bg-card px-2 text-right text-sm tabular-nums" />
                                </td>
                                <td class="px-2 py-2.5 text-right">
                                    <input v-model="l.discount" type="number" min="0" step="any" class="h-8 w-20 rounded-md border border-input bg-card px-2 text-right text-sm tabular-nums" />
                                    <p v-if="error?.field(`lines.${i}.discount`)" class="text-xs text-destructive">{{ error.field(`lines.${i}.discount`) }}</p>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-right font-semibold tabular-nums">{{ formatMoney(lineTotal(l)) }}</td>
                                <td class="px-2 py-2.5 text-right">
                                    <Button variant="ghost" size="icon" class="size-8" title="Xóa dòng" @click="lines.splice(i, 1)"><Trash2 class="text-muted-foreground" /></Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <Alert v-if="lines.some((l) => l.available !== null && num(l.quantity) > l.available)" :icon="AlertTriangle" tone="amber" class="m-4 text-xs">
                        Có sản phẩm vượt số khả dụng: đơn vẫn lưu được (giữ phần còn lại, thiếu sẽ được giữ khi nhập hàng) nhưng không xuất kho được cho đến khi đủ hàng.
                    </Alert>
                </div>
            </Card>

            <Card caps title="Khách hàng">
                <div class="space-y-3 p-4">
                    <div v-if="customer" class="flex items-center justify-between rounded-lg border border-blue-100 bg-blue-50/70 px-3 py-2.5" data-testid="selected-customer">
                        <div class="flex items-center gap-2 text-sm">
                            <span class="grid size-7 place-items-center rounded-full bg-white text-blue-500"><UserRound class="size-4" /></span>
                            <span class="font-medium">{{ customer.name }}</span>
                            <span class="font-mono text-xs text-muted-foreground">{{ customer.code }}</span>
                            <span v-if="customer.phone" class="text-muted-foreground">{{ customer.phone }}</span>
                        </div>
                        <Button variant="ghost" size="icon" class="size-7" title="Bỏ chọn" @click="clearCustomer"><X /></Button>
                    </div>
                    <template v-else-if="!newCustomer.enabled">
                        <div class="relative">
                            <Input v-model="customerQuery" placeholder="Tìm khách theo tên, mã, số điện thoại… (F4)" data-testid="customer-search" />
                            <ul v-if="customerResults.length" class="absolute z-20 mt-1 w-full overflow-hidden rounded-md border bg-card shadow-lg">
                                <li v-for="c in customerResults" :key="c.id">
                                    <button type="button" class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-muted" @click="chooseCustomer(c)">
                                        <span><span class="font-medium">{{ c.name }}</span> <span class="text-xs text-muted-foreground">{{ c.code }}</span></span>
                                        <span class="text-xs text-muted-foreground">{{ c.phone }}</span>
                                    </button>
                                </li>
                            </ul>
                        </div>
                        <div class="flex items-center gap-3 text-sm">
                            <Button v-if="tenant.can('customers.manage')" size="sm" variant="outline" data-testid="new-customer" @click="newCustomer.enabled = true">+ Khách mới</Button>
                            <span class="text-xs text-muted-foreground">Không chọn khách = khách lẻ.</span>
                        </div>
                    </template>
                    <div v-else class="grid gap-3 sm:grid-cols-2">
                        <FormField label="Tên khách" for="nc-name" :error="error?.field('customer.name')"><Input id="nc-name" v-model="newCustomer.name" required data-testid="nc-name" /></FormField>
                        <FormField label="Số điện thoại" for="nc-phone" :error="error?.field('phone') ?? error?.field('customer.phone')"><Input id="nc-phone" v-model="newCustomer.phone" inputmode="tel" data-testid="nc-phone" /></FormField>
                        <FormField label="Địa chỉ" for="nc-address" class="sm:col-span-2"><Input id="nc-address" v-model="newCustomer.address" /></FormField>
                        <Alert v-if="phoneMatch" :icon="UserRound" tone="amber" class="sm:col-span-2">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span>Số này đã có: <b>{{ phoneMatch.name }}</b> ({{ phoneMatch.code }})</span>
                                <Button size="sm" variant="outline" @click="chooseCustomer(phoneMatch)">Dùng khách này</Button>
                            </div>
                        </Alert>
                        <div class="sm:col-span-2"><Button size="sm" variant="ghost" @click="newCustomer.enabled = false">Hủy thêm khách</Button></div>
                    </div>
                </div>
            </Card>

            <Card caps title="Giao hàng">
                <div class="grid gap-3 p-4 sm:grid-cols-2">
                    <FormField label="Người nhận" for="o-name"><Input id="o-name" v-model="form.buyer_name" /></FormField>
                    <FormField label="Điện thoại" for="o-phone"><Input id="o-phone" v-model="form.buyer_phone" inputmode="tel" /></FormField>
                    <FormField label="Địa chỉ giao" for="o-address" class="sm:col-span-2"><Input id="o-address" v-model="form.shipping_address" /></FormField>
                    <FormField label="Đơn vị vận chuyển" for="o-carrier"><Input id="o-carrier" v-model="form.carrier" placeholder="GHN, GHTK, tự giao…" /></FormField>
                    <label class="flex items-center gap-2 self-end pb-2 text-sm"><input v-model="form.is_cod" type="checkbox" class="size-4" /> Thu hộ (COD) khi giao</label>
                    <FormField label="Ghi chú" for="o-note" class="sm:col-span-2"><Input id="o-note" v-model="form.note" maxlength="500" /></FormField>
                    <FormField label="Ngày hẹn giao" for="o-deliver-on"><Input id="o-deliver-on" v-model="form.deliver_on" type="date" data-testid="form-deliver-on" /></FormField>
                    <div>
                        <span class="mb-1.5 block text-sm font-medium">Tags</span>
                        <TagInput v-model="form.tags" :suggestions="tagSuggestions" />
                    </div>
                </div>
            </Card>
        </div>

        <div class="space-y-5">
            <Card caps title="Đơn hàng" class="lg:sticky lg:top-4">
                <div class="space-y-4 p-5">
                    <FormField label="Kênh bán" for="o-channel" :error="error?.field('channel_id')">
                        <Select id="o-channel" v-model="form.channel_id" :options="activeChannels" :disabled="!!editId" data-testid="order-channel" />
                    </FormField>
                    <FormField label="Kho xuất" for="o-warehouse" :error="error?.field('warehouse_id')">
                        <Select id="o-warehouse" v-model="form.warehouse_id" :options="activeWarehouses" data-testid="order-warehouse" />
                    </FormField>
                    <div v-if="!editId" class="space-y-1.5">
                        <p class="text-xs font-medium text-muted-foreground">Trạng thái khi lưu</p>
                        <label v-for="s in stages" :key="s.value" :class="cn('flex cursor-pointer items-start gap-2 rounded-lg border px-3 py-2 text-sm hover:bg-muted/40', form.stage === s.value && 'border-blue-300 bg-blue-50/70 hover:bg-blue-50/70')">
                            <input v-model="form.stage" type="radio" :value="s.value" class="mt-1" :data-testid="`stage-${s.value}`" />
                            <span><span class="font-medium">{{ s.label }}</span><span class="block text-xs text-muted-foreground">{{ s.hint }}</span></span>
                        </label>
                    </div>

                    <dl class="space-y-2 rounded-lg border bg-slate-50 p-3 text-sm">
                        <div class="flex justify-between"><dt class="text-muted-foreground">Tiền hàng</dt><dd class="tabular-nums">{{ formatMoney(subtotal) }}</dd></div>
                        <div class="flex items-center justify-between gap-2"><dt class="text-muted-foreground">Phí giao hàng <kbd class="text-[10px]">F7</kbd></dt><dd><input v-model="form.shipping_fee" type="number" min="0" class="h-7 w-28 rounded border border-input bg-card px-2 text-right text-sm tabular-nums" data-testid="shipping-fee" /></dd></div>
                        <div class="flex items-center justify-between gap-2"><dt class="text-muted-foreground">Giảm giá đơn <kbd class="text-[10px]">F6</kbd></dt><dd><input v-model="form.discount" type="number" min="0" class="h-7 w-28 rounded border border-input bg-card px-2 text-right text-sm tabular-nums" data-testid="order-discount" /></dd></div>
                        <p v-if="error?.field('discount')" class="text-xs text-destructive">{{ error.field('discount') }}</p>
                        <div class="flex items-baseline justify-between border-t pt-2 font-semibold"><dt>Khách phải trả</dt><dd class="text-xl font-bold tabular-nums text-blue-600" data-testid="order-total">{{ formatMoney(total) }}</dd></div>
                        <template v-if="exchange">
                            <div class="flex justify-between text-emerald-600"><dt>Trừ hàng đổi trả</dt><dd class="tabular-nums" data-testid="exchange-credit">−{{ formatMoney(exchangeCredit) }}</dd></div>
                            <div class="flex justify-between font-semibold"><dt>Còn phải trả</dt><dd class="tabular-nums">{{ formatMoney(total - exchangeCredit) }}</dd></div>
                        </template>
                    </dl>

                    <div v-if="!editId && tenant.can('orders.payment')" class="space-y-2">
                        <p class="text-xs font-medium text-muted-foreground">Thu tiền ngay (tùy chọn)</p>
                        <div class="flex gap-2">
                            <Select v-model="form.pay_method" class="w-36" :options="Object.entries(paymentMethods).filter(([k]) => k !== 'cod').map(([value, label]) => ({ value, label }))" aria-label="Hình thức" />
                            <Input v-model="form.pay_amount" type="number" min="0" placeholder="0" class="text-right" data-testid="pay-amount" />
                        </div>
                        <button type="button" class="text-xs font-medium text-primary hover:underline" @click="payFull">Thu đủ {{ formatMoney(total - exchangeCredit) }}</button>
                        <p v-if="error?.field('amount')" class="text-xs text-destructive">{{ error.field('amount') }}</p>
                    </div>

                    <p v-if="error && error.field('stock')" class="text-sm text-destructive" data-testid="stock-error">{{ error.field('stock') }}</p>
                    <Button type="submit" size="lg" class="w-full" :loading="saving" :disabled="!lines.length || !form.channel_id" data-testid="submit-order">{{ editId ? 'Lưu thay đổi' : 'Tạo đơn' }} <kbd class="ml-1 text-[10px] opacity-70">F1</kbd></Button>
                </div>
            </Card>
        </div>
    </form>
</template>
