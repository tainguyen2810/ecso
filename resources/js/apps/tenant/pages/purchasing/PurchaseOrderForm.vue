<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ImageIcon, PackageOpen, X } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import SkuPicker from '../../components/SkuPicker.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import { useIdempotencyKey } from '@/shared/composables/useIdempotencyKey';
import { REORDER_DRAFT_KEY, type ReorderDraft } from './reorder';
import { purchaseCosts, VAT_RATES } from './costs';
import { supplierPaymentMethods } from './labels';
import { useSettingsStore } from '@/shared/stores/settings';
import type { PurchaseOrderData, Supplier, Variant, Warehouse } from '@/shared/types';
import DateInput from '@/shared/ui/DateInput.vue';

/**
 * "Tạo đơn nhập hàng" like Sapo: supplier card with debt, invoice details, lines, order discount (F6) and purchase
 * costs (F7), an optional payment to the supplier, then "Tạo & nhập hàng" (receive everything now) or
 * "Tạo & chưa nhập". With ?mode=order it is "Đặt hàng nhập": save as draft or place the order, receive later.
 */
interface Line {
    id: string;
    sku: string;
    name: string;
    image: string | null;
    stock: number | null;
    quantity: string;
    unit_cost: string;
    tax_rate: string;
}

const route = useRoute();
const router = useRouter();
const toast = useToast();
const idempotency = useIdempotencyKey();
const tenant = useTenantStore();
const editId = computed(() => (route.params.id ? String(route.params.id) : null));
const orderMode = computed(() => route.query.mode === 'order');
const canReceive = computed(() => tenant.can('inventory.receive'));
const canPay = computed(() => tenant.can('purchasing.payment'));

const suppliers = ref<Supplier[]>([]);
const warehouses = ref<Warehouse[]>([]);
const form = reactive({
    supplier_id: '',
    warehouse_id: '',
    expected_at: '',
    invoice_date: '',
    due_date: '',
    reference: '',
    note: '',
    tags: '',
    discount: '',
    extra_cost: '',
    extra_cost_note: '',
    extra_cost_to_supplier: false,
    vat_in_cost: false,
    pay_amount: '',
    pay_method: 'bank_transfer',
});
const defaultVat = ref('0');
const lines = ref<Line[]>([]);
const saving = ref<string | null>(null);
const error = ref<ApiError | null>(null);

const costs = computed(() => purchaseCosts(lines.value, form));
const vatOptions = VAT_RATES.map((r) => ({ value: String(r), label: `${r}%` }));
const supplier = computed(() => suppliers.value.find((s) => s.id === form.supplier_id) ?? null);
const showPayment = computed(() => !editId.value && !orderMode.value && canPay.value);
const remaining = computed(() => Math.max(0, costs.value.total - (Number(form.pay_amount) || 0)));
const backTo = computed(() => (orderMode.value ? '/purchasing/orders' : '/purchasing'));

onMounted(async () => {
    window.addEventListener('keydown', onKey);
    const [s, w, cfg] = await Promise.all([api.get('/suppliers', { params: { active: 1, per_page: 200 } }), api.get('/warehouses'), useSettingsStore().load().catch(() => null)]);
    // New lines take the purchase tax set in Cấu hình → Thuế.
    if (cfg) defaultVat.value = String(cfg.tax.purchase_rate);
    suppliers.value = s.data.data;
    warehouses.value = (w.data.data as Warehouse[]).filter((x) => x.status === 'active' && x.purpose !== 'defective');
    form.warehouse_id = (warehouses.value.find((x) => x.is_default) ?? warehouses.value[0])?.id ?? '';
    if (route.query.supplier) form.supplier_id = String(route.query.supplier);

    if (editId.value) {
        const po: PurchaseOrderData = (await api.get(`/purchase-orders/${editId.value}`)).data.data;
        Object.assign(form, {
            supplier_id: po.supplier?.id ?? '',
            warehouse_id: po.warehouse?.id ?? '',
            expected_at: po.expected_at ?? '',
            invoice_date: po.invoice_date ?? '',
            due_date: po.due_date ?? '',
            reference: po.reference ?? '',
            note: po.note ?? '',
            tags: (po.tags ?? []).join(', '),
            discount: Number(po.discount) ? String(Math.round(Number(po.discount))) : '',
            extra_cost: Number(po.extra_cost) ? String(Math.round(Number(po.extra_cost))) : '',
            extra_cost_note: po.extra_cost_note ?? '',
            extra_cost_to_supplier: po.extra_cost_to_supplier,
            vat_in_cost: po.vat_in_cost,
        });
        lines.value = (po.lines ?? []).map((l) => ({ id: l.variant!.id, sku: l.variant!.sku, name: l.variant!.name, image: null, stock: null, quantity: String(l.quantity), unit_cost: String(Math.round(Number(l.unit_cost))), tax_rate: String(Number(l.tax_rate)) }));
    } else if (route.query.from === 'reorder') {
        try {
            const draft: ReorderDraft = JSON.parse(sessionStorage.getItem(REORDER_DRAFT_KEY) ?? 'null');
            if (draft) {
                form.warehouse_id = draft.warehouse_id || form.warehouse_id;
                form.supplier_id = draft.supplier_id || form.supplier_id;
                lines.value = draft.lines.map((l) => ({ ...l, image: null, quantity: String(l.quantity), unit_cost: String(Math.round(Number(l.unit_cost))), tax_rate: defaultVat.value }));
            }
        } catch {
            /* storage unavailable: start empty */
        }
    }
});
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

function add(v: Variant) {
    const existing = lines.value.find((l) => l.id === v.id);
    if (existing) {
        existing.quantity = String((Number(existing.quantity) || 0) + 1);
        return;
    }
    lines.value.push({
        id: v.id!,
        sku: v.sku,
        name: `${v.product?.name ?? ''}${v.name ? ' · ' + v.name : ''}`,
        image: v.product?.image ?? null,
        stock: v.stock?.on_hand ?? null,
        quantity: '1',
        unit_cost: v.cost_price && Number(v.cost_price) > 0 ? String(Math.round(Number(v.cost_price))) : '',
        tax_rate: defaultVat.value,
    });
}

function lineError(i: number, field: string): string | undefined {
    return error.value?.field(`lines.${i}.${field}`);
}

/** action: draft = save only; place = order placed, nothing received; receive = place and receive everything. */
async function submit(action: 'draft' | 'place' | 'receive') {
    if (saving.value) return;
    saving.value = action;
    error.value = null;
    const body = {
        supplier_id: form.supplier_id,
        warehouse_id: form.warehouse_id,
        expected_at: form.expected_at || null,
        invoice_date: form.invoice_date || null,
        due_date: form.due_date || null,
        reference: form.reference || null,
        note: form.note || null,
        tags: form.tags.split(',').map((t) => t.trim()).filter(Boolean),
        discount: Number(form.discount) || 0,
        extra_cost: Number(form.extra_cost) || 0,
        extra_cost_note: form.extra_cost_note || null,
        extra_cost_to_supplier: form.extra_cost_to_supplier,
        vat_in_cost: form.vat_in_cost,
        lines: lines.value.map((l) => ({ variant_id: l.id, quantity: Number(l.quantity), unit_cost: Number(l.unit_cost || 0), tax_rate: Number(l.tax_rate) || 0 })),
    };
    const payment = showPayment.value && Number(form.pay_amount) > 0 ? { amount: Number(form.pay_amount), method: form.pay_method } : null;
    try {
        const { data } = editId.value
            ? await api.put(`/purchase-orders/${editId.value}`, body)
            : await api.post('/purchase-orders', { ...body, place: action !== 'draft', receive: action === 'receive', payment }, idempotency.config());
        idempotency.renew();
        try {
            sessionStorage.removeItem(REORDER_DRAFT_KEY);
        } catch {
            /* ignore */
        }
        const n = data.data.number;
        toast.success(editId.value ? 'Đã lưu đơn nhập.' : action === 'receive' ? `Đã tạo ${n} và nhập kho.` : action === 'place' ? `Đã tạo ${n}, chưa nhập kho.` : `Đã lưu nháp ${n}.`);
        await router.push(`/purchasing/${data.data.id}`);
    } catch (e) {
        error.value = e as ApiError;
        if (!error.value.body.errors) toast.error(error.value.message);
    } finally {
        saving.value = null;
    }
}

const ready = computed(() => lines.value.length > 0 && !!form.supplier_id);
// Sapo keys: F1 main action, F3 product search, F6 order discount, F7 purchase costs.
function onKey(e: KeyboardEvent) {
    const focus = (sel: string) => (e.preventDefault(), document.querySelector<HTMLInputElement>(sel)?.focus());
    if (e.key === 'F1') {
        e.preventDefault();
        if (ready.value) submit(editId.value || orderMode.value || !canReceive.value ? 'place' : 'receive');
    } else if (e.key === 'F3') focus('[data-testid="sku-picker"]');
    else if (e.key === 'F6') focus('[data-testid="po-discount"]');
    else if (e.key === 'F7') focus('[data-testid="po-extra-cost"]');
}
</script>

<template>
    <PageHeader :title="editId ? 'Sửa đơn nhập' : orderMode ? 'Tạo đơn đặt hàng nhập' : 'Tạo đơn nhập hàng'">
        <RouterLink :to="backTo"><Button variant="outline">Thoát</Button></RouterLink>
        <template v-if="editId">
            <Button :loading="saving === 'place'" :disabled="!ready" data-testid="po-place" @click="submit('place')">Lưu thay đổi (F1)</Button>
        </template>
        <template v-else-if="orderMode">
            <Button variant="outline" :loading="saving === 'draft'" :disabled="!ready" data-testid="po-draft" @click="submit('draft')">Lưu nháp</Button>
            <Button :loading="saving === 'place'" :disabled="!ready" data-testid="po-place" @click="submit('place')">Đặt hàng (F1)</Button>
        </template>
        <template v-else>
            <Button variant="outline" :loading="saving === 'draft'" :disabled="!ready" data-testid="po-draft" @click="submit('draft')">Lưu nháp</Button>
            <Button :variant="canReceive ? 'outline' : 'default'" :loading="saving === 'place'" :disabled="!ready" data-testid="po-place" @click="submit('place')">Tạo & chưa nhập</Button>
            <Button v-if="canReceive" :loading="saving === 'receive'" :disabled="!ready" data-testid="po-create-receive" @click="submit('receive')">Tạo & nhập hàng (F1)</Button>
        </template>
    </PageHeader>

    <div class="grid gap-5 lg:grid-cols-[1fr_22rem]">
        <Card :caps="false" title="Thông tin nhà cung cấp">
            <div class="space-y-3 p-5">
                <FormField label="Nhà cung cấp" :error="error?.field('supplier_id')">
                    <Select v-model="form.supplier_id" :options="[{ value: '', label: 'Tìm hoặc chọn nhà cung cấp…' }, ...suppliers.map((s) => ({ value: s.id, label: `${s.code} · ${s.name}` }))]" data-testid="po-supplier" />
                </FormField>
                <p v-if="!suppliers.length" class="text-xs text-muted-foreground">Chưa có nhà cung cấp. <RouterLink to="/purchasing/suppliers" class="text-primary underline">Thêm nhà cung cấp</RouterLink>.</p>
                <div v-if="supplier" class="flex flex-wrap items-start justify-between gap-4 rounded-md bg-muted/40 p-3 text-sm" data-testid="po-supplier-card">
                    <div class="min-w-0">
                        <RouterLink :to="`/purchasing/suppliers/${supplier.id}`" class="font-medium text-primary hover:underline">{{ supplier.name }}</RouterLink>
                        <span class="ml-1.5 font-mono text-xs text-muted-foreground">{{ supplier.code }}</span>
                        <p class="text-xs text-muted-foreground">{{ [supplier.phone, supplier.address].filter(Boolean).join(' · ') || 'Chưa có liên hệ' }}</p>
                    </div>
                    <div class="flex gap-6 text-right text-xs">
                        <div>
                            <p class="text-muted-foreground">Công nợ</p>
                            <p :class="Number(supplier.debt) > 0 ? 'font-semibold text-destructive' : 'font-semibold'">{{ formatMoney(supplier.debt ?? 0) }}</p>
                        </div>
                        <div>
                            <p class="text-muted-foreground">Tổng đơn nhập ({{ supplier.orders_count ?? 0 }})</p>
                            <p class="font-semibold">{{ formatMoney(supplier.purchased ?? 0) }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </Card>
        <Card :caps="false" title="Thông tin đơn nhập hàng">
            <div class="space-y-3 p-5">
                <FormField label="Kho nhập" :error="error?.field('warehouse_id')">
                    <Select v-model="form.warehouse_id" :options="warehouses.map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` }))" data-testid="po-warehouse" />
                </FormField>
                <FormField label="Ngày hẹn giao" for="po-expected"><DateInput id="po-expected" v-model="form.expected_at" /></FormField>
                <FormField label="Ngày hóa đơn" for="po-invoice-date" :error="error?.field('invoice_date')"><DateInput id="po-invoice-date" v-model="form.invoice_date" data-testid="po-invoice-date" /></FormField>
                <FormField label="Hạn thanh toán" for="po-due-date" :error="error?.field('due_date')"><DateInput id="po-due-date" v-model="form.due_date" data-testid="po-due-date" /></FormField>
                <FormField label="Tham chiếu" for="po-reference" :error="error?.field('reference')">
                    <Input id="po-reference" v-model="form.reference" placeholder="Số hóa đơn, mã đơn của NCC" data-testid="po-reference" />
                </FormField>
            </div>
        </Card>
    </div>

    <Card :caps="false" title="Thông tin sản phẩm" class="mt-5">
        <div class="border-b p-4">
            <SkuPicker stocked :warehouse="form.warehouse_id" :exclude="[]" placeholder="Tìm theo tên, mã SKU, hoặc quét mã barcode… (F3)" @pick="add" />
            <p v-if="error?.field('lines')" class="mt-2 text-xs text-destructive">{{ error.field('lines') }}</p>
        </div>
        <EmptyState v-if="!lines.length" :icon="PackageOpen" title="Chưa có sản phẩm" description="Tìm SKU ở ô phía trên, hoặc dùng Gợi ý nhập hàng để tạo đơn từ tốc độ bán." />
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm" data-testid="po-lines">
                <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="w-10 px-4 py-2 font-medium">STT</th>
                        <th class="w-12 py-2 font-medium">Ảnh</th>
                        <th class="px-3 py-2 font-medium">Tên sản phẩm</th>
                        <th class="px-3 py-2 text-right font-medium">Tồn</th>
                        <th class="px-3 py-2 text-right font-medium">SL nhập</th>
                        <th class="px-3 py-2 text-right font-medium">Đơn giá</th>
                        <th class="px-3 py-2 text-right font-medium">VAT</th>
                        <th class="px-3 py-2 text-right font-medium">Thành tiền</th>
                        <th class="px-3 py-2 text-right font-medium" title="Giá vốn mỗi đơn vị khi nhập kho: đơn giá + phần chi phí nhập − phần chiết khấu (+ VAT nếu tính vào giá vốn)">Giá vốn</th>
                        <th class="px-2 py-2" />
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="(l, i) in lines" :key="l.id" class="align-top">
                        <td class="px-4 py-2.5 text-muted-foreground">{{ i + 1 }}</td>
                        <td class="py-2">
                            <img v-if="l.image" :src="l.image" alt="" class="size-9 rounded border object-cover" />
                            <span v-else class="flex size-9 items-center justify-center rounded border bg-muted/50 text-muted-foreground"><ImageIcon class="size-4" /></span>
                        </td>
                        <td class="px-3 py-2.5">
                            <p class="max-w-72 truncate font-medium text-primary">{{ l.name }}</p>
                            <p class="font-mono text-xs text-muted-foreground">{{ l.sku }}</p>
                            <p v-if="lineError(i, 'variant_id')" class="text-xs text-destructive">{{ lineError(i, 'variant_id') }}</p>
                        </td>
                        <td class="px-3 py-2.5 text-right tabular-nums text-muted-foreground">{{ l.stock === null ? '—' : formatNumber(l.stock) }}</td>
                        <td class="px-3 py-2.5 text-right">
                            <input v-model="l.quantity" type="number" min="1" class="h-8 w-20 rounded-md border border-input bg-card px-2 text-right text-sm tabular-nums" :data-testid="`po-qty-${i}`" />
                            <p v-if="lineError(i, 'quantity')" class="text-xs text-destructive">{{ lineError(i, 'quantity') }}</p>
                        </td>
                        <td class="px-3 py-2.5 text-right">
                            <input v-model="l.unit_cost" type="number" min="0" class="h-8 w-28 rounded-md border border-input bg-card px-2 text-right text-sm tabular-nums" :data-testid="`po-cost-${i}`" />
                            <p v-if="lineError(i, 'unit_cost')" class="text-xs text-destructive">{{ lineError(i, 'unit_cost') }}</p>
                        </td>
                        <td class="px-3 py-2.5 text-right">
                            <select v-model="l.tax_rate" class="h-8 rounded-md border border-input bg-card px-1 text-sm" :data-testid="`po-vat-${i}`" aria-label="Thuế GTGT">
                                <option v-for="o in vatOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </select>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2.5 text-right font-semibold tabular-nums">{{ formatMoney((Number(l.quantity) || 0) * (Number(l.unit_cost) || 0)) }}</td>
                        <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums text-muted-foreground" :data-testid="`po-landed-${i}`">{{ formatNumber(costs.landed[i]) }}</td>
                        <td class="px-2 py-2.5"><button type="button" class="rounded p-1 text-muted-foreground hover:text-destructive" title="Xóa dòng" @click="lines.splice(i, 1)"><X class="size-4" /></button></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="grid gap-6 border-t p-5 lg:grid-cols-[1fr_24rem]">
            <div class="space-y-3 text-sm">
                <FormField label="Ghi chú đơn" for="po-note"><textarea id="po-note" v-model="form.note" rows="3" class="w-full rounded-md border border-input bg-card px-3 py-2 text-sm" /></FormField>
                <FormField label="Tags" for="po-tags"><Input id="po-tags" v-model="form.tags" placeholder="Cách nhau bằng dấu phẩy" /></FormField>
                <div class="grid gap-3 sm:grid-cols-2">
                    <FormField label="VAT mặc định cho dòng mới" for="po-default-vat"><Select id="po-default-vat" v-model="defaultVat" :options="vatOptions" /></FormField>
                    <FormField label="Diễn giải chi phí nhập" for="po-extra-note"><Input id="po-extra-note" v-model="form.extra_cost_note" placeholder="Phí vận chuyển, bốc xếp…" /></FormField>
                </div>
                <label class="flex items-start gap-2"><input v-model="form.vat_in_cost" type="checkbox" class="mt-0.5" data-testid="po-vat-in-cost" /> <span>Tính VAT vào giá vốn <span class="block text-xs text-muted-foreground">Chọn khi không được khấu trừ thuế đầu vào (hộ kinh doanh, phương pháp trực tiếp).</span></span></label>
                <label class="flex items-start gap-2"><input v-model="form.extra_cost_to_supplier" type="checkbox" class="mt-0.5" data-testid="po-extra-to-supplier" /> <span>Chi phí nhập trả cho nhà cung cấp <span class="block text-xs text-muted-foreground">Có trên hóa đơn NCC, cộng vào công nợ. Bỏ chọn nếu trả cho đơn vị vận chuyển.</span></span></label>
            </div>
            <dl class="grid grid-cols-[1fr_9rem] items-center gap-x-3 gap-y-2.5 text-sm">
                <dt class="text-muted-foreground">Số lượng</dt>
                <dd class="text-right tabular-nums">{{ formatNumber(costs.quantity) }}</dd>
                <dt class="text-muted-foreground">Tổng tiền hàng</dt>
                <dd class="text-right tabular-nums">{{ formatMoney(costs.subtotal) }}</dd>
                <dt class="text-muted-foreground">Thuế GTGT</dt>
                <dd class="text-right tabular-nums" data-testid="po-tax">{{ formatMoney(costs.tax) }}</dd>
                <dt class="text-muted-foreground"><label for="po-discount">Chiết khấu đơn (F6)</label></dt>
                <dd><input id="po-discount" v-model="form.discount" type="number" min="0" class="h-8 w-full rounded-md border border-input bg-card px-2 text-right text-sm tabular-nums" data-testid="po-discount" /></dd>
                <dt class="text-muted-foreground"><label for="po-extra">Chi phí nhập hàng (F7)</label></dt>
                <dd><input id="po-extra" v-model="form.extra_cost" type="number" min="0" class="h-8 w-full rounded-md border border-input bg-card px-2 text-right text-sm tabular-nums" data-testid="po-extra-cost" /></dd>
                <p v-if="error?.field('discount')" class="col-span-2 text-xs text-destructive">{{ error.field('discount') }}</p>
                <div class="col-span-2 border-t" />
                <dt class="font-semibold">Tiền cần trả NCC</dt>
                <dd class="text-right text-lg font-semibold tabular-nums text-primary" data-testid="po-total">{{ formatMoney(costs.total) }}</dd>
                <template v-if="showPayment">
                    <div class="col-span-2 border-t" />
                    <dt class="font-medium">
                        Thanh toán cho NCC
                        <select v-model="form.pay_method" class="mt-1 block h-8 rounded-md border border-input bg-card px-1 text-sm font-normal" aria-label="Hình thức thanh toán" data-testid="po-pay-method">
                            <option v-for="(label, value) in supplierPaymentMethods" :key="value" :value="value">{{ label }}</option>
                        </select>
                    </dt>
                    <dd><input v-model="form.pay_amount" type="number" min="0" class="h-8 w-full rounded-md border border-input bg-card px-2 text-right text-sm tabular-nums" aria-label="Số tiền trả NCC" data-testid="po-pay-amount" /></dd>
                    <p v-if="error?.field('amount')" class="col-span-2 text-xs text-destructive">{{ error.field('amount') }}</p>
                    <div class="col-span-2 border-t" />
                    <dt class="font-semibold">Còn phải trả</dt>
                    <dd class="text-right font-semibold tabular-nums" :class="remaining > 0 && 'text-destructive'" data-testid="po-remaining">{{ formatMoney(remaining) }}</dd>
                </template>
            </dl>
        </div>
    </Card>
</template>
