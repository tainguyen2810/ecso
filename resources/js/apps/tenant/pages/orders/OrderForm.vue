<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, PackageOpen, Trash2, UserRound, X } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import SkuPicker from '../../components/SkuPicker.vue';
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
import type { Customer, OrderDetail, SalesChannel, Variant, Warehouse } from '@/shared/types';

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
    pay_method: 'cash',
    pay_amount: '' as number | string,
});
const saving = ref(false);
const error = ref<ApiError | null>(null);

const num = (v: number | string) => Number(v) || 0;
const lineTotal = (l: Line) => Math.max(0, num(l.quantity) * num(l.unit_price) - num(l.discount));
const subtotal = computed(() => lines.value.reduce((s, l) => s + lineTotal(l), 0));
const total = computed(() => Math.max(0, subtotal.value + num(form.shipping_fee) - num(form.discount)));
const activeChannels = computed(() => channels.value.filter((c) => c.is_active || c.id === form.channel_id).map((c) => ({ value: c.id, label: `${c.name} · ${channelTypes[c.type] ?? c.type}` })));
const activeWarehouses = computed(() => warehouses.value.filter((w) => w.status === 'active').map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` })));

onMounted(async () => {
    try {
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
    form.pay_amount = total.value;
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
        lines: lines.value.map((l) => ({ variant_id: l.variant_id, quantity: num(l.quantity), unit_price: num(l.unit_price), discount: num(l.discount) })),
    };
    try {
        if (editId.value) {
            await api.put(`/sales-orders/${editId.value}`, body);
            toast.success('Đã lưu đơn.');
            await router.push(`/orders/${editId.value}`);
        } else {
            const payments = num(form.pay_amount) > 0 ? [{ method: form.pay_method, amount: num(form.pay_amount) }] : [];
            const { data } = await api.post('/sales-orders', { ...body, channel_id: form.channel_id, stage: form.stage, payments });
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
        description="Đơn của kênh bán riêng (cửa hàng, Facebook, Zalo, website…). Hàng được giữ trong kho ngay khi lưu đơn. Phím tắt: F1 lưu đơn · F3 tìm sản phẩm · F4 tìm khách · F6 giảm giá · F7 phí giao."
    />

    <div v-if="loading" class="space-y-4"><Skeleton class="h-40" /><Skeleton class="h-64" /></div>
    <form v-else class="grid gap-5 lg:grid-cols-3" data-testid="order-form" @submit.prevent="submit">
        <div class="space-y-5 lg:col-span-2">
            <Card title="Sản phẩm" description="Quét mã vạch hoặc tìm SKU để thêm. Giá mặc định là giá bán tham khảo của SKU.">
                <div class="border-b p-4">
                    <SkuPicker :warehouse="form.warehouse_id" :exclude="[]" placeholder="Tìm SKU, tên sản phẩm hoặc quét mã vạch (F3)" data-shortcut="sku" @pick="add" />
                    <p v-if="error?.field('lines')" class="mt-2 text-xs text-destructive">{{ error.field('lines') }}</p>
                </div>
                <EmptyState v-if="!lines.length" :icon="PackageOpen" title="Chưa có sản phẩm" description="Tìm SKU, tên sản phẩm hoặc quét mã vạch ở ô phía trên." />
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="order-lines">
                        <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
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
                                    <p class="font-mono text-xs font-medium">{{ l.sku }}</p>
                                    <p class="max-w-44 truncate text-xs text-muted-foreground" :title="l.name">{{ l.name }}</p>
                                </td>
                                <td class="px-2 py-2.5 text-right tabular-nums">
                                    <span v-if="l.available !== null" :class="cn(num(l.quantity) > l.available && 'font-medium text-destructive')">{{ formatNumber(l.available) }}</span>
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
                                <td class="whitespace-nowrap px-3 py-2.5 text-right font-medium tabular-nums">{{ formatMoney(lineTotal(l)) }}</td>
                                <td class="px-2 py-2.5 text-right">
                                    <Button variant="ghost" size="icon" class="size-8" title="Xóa dòng" @click="lines.splice(i, 1)"><Trash2 class="text-muted-foreground" /></Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-if="lines.some((l) => l.available !== null && num(l.quantity) > l.available)" class="border-t px-4 py-2 text-xs text-destructive">
                        Có sản phẩm vượt số khả dụng: đơn vẫn lưu được (giữ phần còn lại, thiếu sẽ được giữ khi nhập hàng) nhưng không xuất kho được cho đến khi đủ hàng.
                    </p>
                </div>
            </Card>

            <Card title="Khách hàng">
                <div class="space-y-3 p-4">
                    <div v-if="customer" class="flex items-center justify-between rounded-md border bg-muted/30 px-3 py-2" data-testid="selected-customer">
                        <div class="flex items-center gap-2 text-sm">
                            <UserRound class="size-4 text-muted-foreground" />
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
                        <div v-if="phoneMatch" class="flex items-center justify-between rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-sm sm:col-span-2">
                            <span>Số này đã có: <b>{{ phoneMatch.name }}</b> ({{ phoneMatch.code }})</span>
                            <Button size="sm" variant="outline" @click="chooseCustomer(phoneMatch)">Dùng khách này</Button>
                        </div>
                        <div class="sm:col-span-2"><Button size="sm" variant="ghost" @click="newCustomer.enabled = false">Hủy thêm khách</Button></div>
                    </div>
                </div>
            </Card>

            <Card title="Giao hàng">
                <div class="grid gap-3 p-4 sm:grid-cols-2">
                    <FormField label="Người nhận" for="o-name"><Input id="o-name" v-model="form.buyer_name" /></FormField>
                    <FormField label="Điện thoại" for="o-phone"><Input id="o-phone" v-model="form.buyer_phone" inputmode="tel" /></FormField>
                    <FormField label="Địa chỉ giao" for="o-address" class="sm:col-span-2"><Input id="o-address" v-model="form.shipping_address" /></FormField>
                    <FormField label="Đơn vị vận chuyển" for="o-carrier"><Input id="o-carrier" v-model="form.carrier" placeholder="GHN, GHTK, tự giao…" /></FormField>
                    <label class="flex items-center gap-2 self-end pb-2 text-sm"><input v-model="form.is_cod" type="checkbox" class="size-4" /> Thu hộ (COD) khi giao</label>
                    <FormField label="Ghi chú" for="o-note" class="sm:col-span-2"><Input id="o-note" v-model="form.note" maxlength="500" /></FormField>
                </div>
            </Card>
        </div>

        <div class="space-y-5">
            <Card title="Đơn hàng" class="lg:sticky lg:top-4">
                <div class="space-y-4 p-5">
                    <FormField label="Kênh bán" for="o-channel" :error="error?.field('channel_id')">
                        <Select id="o-channel" v-model="form.channel_id" :options="activeChannels" :disabled="!!editId" data-testid="order-channel" />
                    </FormField>
                    <FormField label="Kho xuất" for="o-warehouse" :error="error?.field('warehouse_id')">
                        <Select id="o-warehouse" v-model="form.warehouse_id" :options="activeWarehouses" data-testid="order-warehouse" />
                    </FormField>
                    <div v-if="!editId" class="space-y-1.5">
                        <p class="text-xs font-medium text-muted-foreground">Trạng thái khi lưu</p>
                        <label v-for="s in stages" :key="s.value" :class="cn('flex cursor-pointer items-start gap-2 rounded-md border px-3 py-2 text-sm', form.stage === s.value && 'border-primary bg-primary/5')">
                            <input v-model="form.stage" type="radio" :value="s.value" class="mt-1" :data-testid="`stage-${s.value}`" />
                            <span><span class="font-medium">{{ s.label }}</span><span class="block text-xs text-muted-foreground">{{ s.hint }}</span></span>
                        </label>
                    </div>

                    <dl class="space-y-2 rounded-md bg-muted/50 p-3 text-sm">
                        <div class="flex justify-between"><dt class="text-muted-foreground">Tiền hàng</dt><dd class="tabular-nums">{{ formatMoney(subtotal) }}</dd></div>
                        <div class="flex items-center justify-between gap-2"><dt class="text-muted-foreground">Phí giao hàng <kbd class="text-[10px]">F7</kbd></dt><dd><input v-model="form.shipping_fee" type="number" min="0" class="h-7 w-28 rounded border border-input bg-card px-2 text-right text-sm tabular-nums" data-testid="shipping-fee" /></dd></div>
                        <div class="flex items-center justify-between gap-2"><dt class="text-muted-foreground">Giảm giá đơn <kbd class="text-[10px]">F6</kbd></dt><dd><input v-model="form.discount" type="number" min="0" class="h-7 w-28 rounded border border-input bg-card px-2 text-right text-sm tabular-nums" data-testid="order-discount" /></dd></div>
                        <p v-if="error?.field('discount')" class="text-xs text-destructive">{{ error.field('discount') }}</p>
                        <div class="flex justify-between border-t pt-2 text-base font-semibold"><dt>Khách phải trả</dt><dd class="tabular-nums" data-testid="order-total">{{ formatMoney(total) }}</dd></div>
                    </dl>

                    <div v-if="!editId && tenant.can('orders.payment')" class="space-y-2">
                        <p class="text-xs font-medium text-muted-foreground">Thu tiền ngay (tùy chọn)</p>
                        <div class="flex gap-2">
                            <Select v-model="form.pay_method" class="w-36" :options="Object.entries(paymentMethods).filter(([k]) => k !== 'cod').map(([value, label]) => ({ value, label }))" aria-label="Hình thức" />
                            <Input v-model="form.pay_amount" type="number" min="0" placeholder="0" class="text-right" data-testid="pay-amount" />
                        </div>
                        <button type="button" class="text-xs font-medium text-primary hover:underline" @click="payFull">Thu đủ {{ formatMoney(total) }}</button>
                        <p v-if="error?.field('amount')" class="text-xs text-destructive">{{ error.field('amount') }}</p>
                    </div>

                    <p v-if="error && error.field('stock')" class="text-sm text-destructive" data-testid="stock-error">{{ error.field('stock') }}</p>
                    <Button type="submit" class="w-full" :loading="saving" :disabled="!lines.length || !form.channel_id" data-testid="submit-order">{{ editId ? 'Lưu thay đổi' : 'Tạo đơn' }} <kbd class="ml-1 text-[10px] opacity-70">F1</kbd></Button>
                </div>
            </Card>
        </div>
    </form>
</template>
