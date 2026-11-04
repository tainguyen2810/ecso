<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { Info, Search } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import { api, ApiError, idempotent, newIdempotencyKey } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';
import { cn, formatDate, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { paymentMethods } from '../orders/labels';
import { amountInWords, directionLabels, partyKinds, type Direction, type PartyKind, type VoucherRow, type VoucherType } from './voucher';

interface Party {
    id: string;
    name: string;
    code?: string;
    debt?: string | number | null;
}
interface DebtOrder {
    id: string;
    number: string;
    placed_at: string | null;
    due: number;
}

const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{ direction: Direction }>();
const emit = defineEmits<{ saved: [voucher: VoucherRow | null] }>();
const toast = useToast();
const tenant = useTenantStore();

const blank = () => ({ party_kind: 'other' as PartyKind, party_name: '', type: '', method: 'cash', amount: '', counts_in_pnl: true, reference: '', note: '', paid_at: '' });
const form = reactive(blank());
const party = ref<Party | null>(null);
const types = ref<VoucherType[]>([]);
const staff = ref<Party[]>([]);
const query = ref('');
const results = ref<Party[]>([]);
const searching = ref(false);
const debtMode = ref(false);
const debtOrders = ref<DebtOrder[]>([]);
const saving = ref(false);
// One key per voucher being entered: a retry sends the same key, so the money is recorded once.
let key = newIdempotencyKey();
const error = ref<ApiError | null>(null);

const receipt = computed(() => props.direction === 'receipt');
const activeTypes = computed(() => types.value.filter((t) => t.is_active && t.direction === props.direction));
const debt = computed(() => Number(party.value?.debt ?? 0));
// A customer's debt is collected from their unpaid orders; a supplier's is paid on account. Both keep debts right.
const debtAllowed = computed(() =>
    !!party.value && debt.value > 0 &&
    ((receipt.value && form.party_kind === 'customer' && tenant.can('orders.payment')) || (!receipt.value && form.party_kind === 'supplier' && tenant.can('purchasing.payment'))),
);
const kinds = computed(() => (Object.keys(partyKinds) as PartyKind[]).filter((k) => (k === 'customer' ? tenant.can('customers.view') : k === 'supplier' ? tenant.can('purchasing.view') : true)));
const amount = computed(() => Number(form.amount || 0));
const supplierMethods = ['cash', 'bank_transfer', 'card', 'other'];
const methodOptions = computed(() =>
    Object.entries(paymentMethods)
        .filter(([v]) => (debtMode.value && form.party_kind === 'supplier' ? supplierMethods.includes(v) : receipt.value || v !== 'cod'))
        .map(([value, label]) => ({ value, label })),
);
const allocation = computed(() => {
    let left = amount.value;
    return debtOrders.value.map((o) => {
        const take = Math.min(left, o.due);
        left -= take;
        return { ...o, take };
    });
});

function nowLocal(): string {
    const d = new Date();
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0, 16);
}

watch(open, async (v) => {
    if (!v) return;
    Object.assign(form, blank(), { paid_at: nowLocal() });
    party.value = null;
    query.value = '';
    results.value = [];
    error.value = null;
    debtMode.value = false;
    key = newIdempotencyKey();
    try {
        types.value = (await api.get('/cash-voucher-types', { params: { direction: props.direction } })).data.data;
        form.type = activeTypes.value[0]?.id ?? '';
    } catch (e) {
        error.value = e as ApiError;
    }
});
watch(() => form.type, (id) => {
    const t = types.value.find((x) => x.id === id);
    if (t) form.counts_in_pnl = t.counts_in_pnl;
});
watch(() => form.party_kind, async (k) => {
    party.value = null;
    query.value = '';
    results.value = [];
    debtMode.value = false;
    if (k === 'staff' && !staff.value.length) staff.value = (await api.get('/cash-vouchers/staff')).data.data;
});

let timer: ReturnType<typeof setTimeout> | undefined;
watch(query, (q) => {
    clearTimeout(timer);
    if (party.value && q === party.value.name) return;
    party.value = null;
    if (!q.trim() || !['customer', 'supplier'].includes(form.party_kind)) return (results.value = []);
    timer = setTimeout(async () => {
        searching.value = true;
        try {
            const url = form.party_kind === 'customer' ? '/customers' : '/suppliers';
            results.value = (await api.get(url, { params: { q, per_page: 8 } })).data.data;
        } finally {
            searching.value = false;
        }
    }, 250);
});

async function pick(p: Party) {
    party.value = p;
    query.value = p.name;
    results.value = [];
    debtMode.value = debtAllowed.value;
    debtOrders.value = [];
    if (debtMode.value && form.party_kind === 'customer') {
        debtOrders.value = (await api.get(`/customers/${p.id}/debt`)).data.data.orders;
    }
}

function pickStaff(id: string | undefined) {
    party.value = staff.value.find((s) => s.id === id) ?? null;
}

function onAmount(e: Event) {
    form.amount = (e.target as HTMLInputElement).value.replace(/\D/g, '').slice(0, 10);
}

async function save(again = false) {
    saving.value = true;
    error.value = null;
    const common = { method: form.method, amount: form.amount, reference: form.reference || null, note: form.note || null, paid_at: form.paid_at ? new Date(form.paid_at).toISOString() : null };
    try {
        let saved: VoucherRow | null = null;
        if (debtMode.value && party.value && form.party_kind === 'customer') {
            const { data } = await api.post(`/customers/${party.value.id}/debt-receipts`, common, idempotent(key));
            toast.success(`Đã thu nợ ${formatMoney(amount.value)} của ${party.value.name}, ghi vào ${data.data.length} đơn.`);
        } else if (debtMode.value && party.value && form.party_kind === 'supplier') {
            await api.post(`/suppliers/${party.value.id}/payments`, common, idempotent(key));
            toast.success(`Đã trả nợ ${formatMoney(amount.value)} cho ${party.value.name}.`);
        } else {
            const { data } = await api.post('/cash-vouchers', {
                ...common,
                direction: props.direction,
                type: form.type,
                party_kind: form.party_kind,
                party_id: party.value?.id ?? null,
                party_name: party.value ? null : form.party_name || query.value || null,
                counts_in_pnl: form.counts_in_pnl,
            }, idempotent(key));
            saved = data.data;
            toast.success(`Đã lập ${directionLabels[props.direction].toLowerCase()} ${data.data.number}.`);
        }
        key = newIdempotencyKey();
        emit('saved', saved);
        if (again) {
            Object.assign(form, { amount: '', reference: '', note: '' });
            document.getElementById('v-amount')?.focus();
        } else {
            open.value = false;
        }
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open" :title="`Lập ${directionLabels[direction].toLowerCase()}`" :description="receipt ? 'Tiền vào quỹ: thu nợ khách, thu nhập khác…' : 'Tiền ra khỏi quỹ: quảng cáo, vận chuyển, lương, trả nợ NCC…'" size="lg">
        <form id="voucher-form" class="grid gap-4 sm:grid-cols-2" data-testid="voucher-form" @submit.prevent="save(false)">
            <div class="space-y-1.5 sm:col-span-2">
                <p class="text-sm font-medium">{{ receipt ? 'Người nộp là' : 'Người nhận là' }}</p>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="k in kinds"
                        :key="k"
                        type="button"
                        :data-testid="`party-kind-${k}`"
                        :class="cn('rounded-full border px-3 py-1 text-sm', form.party_kind === k ? 'border-primary bg-primary/10 font-medium text-primary' : 'text-muted-foreground hover:bg-muted')"
                        @click="form.party_kind = k"
                    >
                        {{ partyKinds[k] }}
                    </button>
                </div>
            </div>

            <FormField :label="receipt ? 'Tên người nộp' : 'Tên người nhận'" for="v-party" class="relative sm:col-span-2" :error="error?.field('party_name') ?? error?.field('party_id')">
                <Select v-if="form.party_kind === 'staff'" id="v-party" :model-value="party?.id ?? ''" :options="[{ value: '', label: 'Chọn nhân viên' }, ...staff.map((s) => ({ value: s.id, label: s.name }))]" @update:model-value="pickStaff" />
                <div v-else-if="form.party_kind === 'customer' || form.party_kind === 'supplier'" class="relative">
                    <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                    <Input id="v-party" v-model="query" class="pl-8" autocomplete="off" :placeholder="form.party_kind === 'customer' ? 'Tìm tên, mã, số điện thoại khách' : 'Tìm tên, mã nhà cung cấp'" data-testid="v-party" />
                    <ul v-if="results.length" class="absolute z-10 mt-1 max-h-60 w-full overflow-y-auto rounded-md border bg-card shadow-lg" data-testid="v-party-results">
                        <li v-for="r in results" :key="r.id">
                            <button type="button" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-muted" @click="pick(r)">
                                <span>{{ r.name }} <span class="font-mono text-xs text-muted-foreground">{{ r.code }}</span></span>
                                <span v-if="Number(r.debt) > 0" class="text-xs text-destructive">Nợ {{ formatMoney(r.debt) }}</span>
                            </button>
                        </li>
                    </ul>
                    <p v-else-if="query && !party && !searching" class="mt-1 text-xs text-muted-foreground">Không thấy trong danh bạ? Phiếu sẽ ghi theo tên đã nhập.</p>
                </div>
                <Input v-else id="v-party" v-model="form.party_name" :placeholder="form.party_kind === 'carrier' ? 'VD: Giao Hàng Nhanh, Be' : 'VD: Chủ nhà, Điện lực'" data-testid="v-party" />
            </FormField>

            <div v-if="debtAllowed" class="rounded-lg border border-blue-100 bg-blue-50/70 p-3 text-sm sm:col-span-2" data-testid="v-debt">
                <label class="flex items-center gap-2 font-medium">
                    <input v-model="debtMode" type="checkbox" class="size-4" data-testid="v-debt-mode" />
                    {{ receipt ? 'Thu nợ khách' : 'Trả nợ nhà cung cấp' }}, đang nợ {{ formatMoney(debt) }}
                </label>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ receipt ? 'Số tiền được trừ vào các đơn còn nợ, đơn cũ trước.' : 'Số tiền được ghi là trả trước cho nhà cung cấp và trừ vào công nợ.' }}
                    <button v-if="debtMode" type="button" class="font-medium text-primary hover:underline" data-testid="v-debt-all" @click="form.amount = String(Math.round(debt))">{{ receipt ? 'Thu hết' : 'Trả hết' }}</button>
                </p>
                <ul v-if="debtMode && receipt && allocation.length" class="mt-2 space-y-0.5 text-xs">
                    <li v-for="o in allocation" :key="o.id" class="flex justify-between gap-2" :class="o.take ? '' : 'text-muted-foreground'">
                        <span class="font-mono">{{ o.number }} · {{ formatDate(o.placed_at) }}</span>
                        <span class="tabular-nums">{{ o.take ? `${formatMoney(o.take)} / ` : '' }}{{ formatMoney(o.due) }}</span>
                    </li>
                </ul>
            </div>

            <FormField label="Số tiền" for="v-amount" :error="error?.field('amount')" :hint="amount ? amountInWords(amount) : undefined">
                <Input id="v-amount" :model-value="form.amount ? formatNumber(form.amount) : ''" inputmode="numeric" required class="text-right text-base font-semibold tabular-nums" placeholder="0" data-testid="v-amount" @input="onAmount" />
            </FormField>
            <FormField label="Hình thức" for="v-method" :error="error?.field('method')">
                <Select id="v-method" v-model="form.method" :options="methodOptions" data-testid="v-method" />
            </FormField>

            <FormField v-if="!debtMode" :label="receipt ? 'Loại phiếu thu' : 'Loại phiếu chi'" for="v-type" :error="error?.field('type')">
                <Select id="v-type" v-model="form.type" :options="activeTypes.map((t) => ({ value: t.id, label: t.name }))" data-testid="v-type" />
            </FormField>
            <FormField label="Ngày ghi nhận" for="v-paid-at" :error="error?.field('paid_at')">
                <Input id="v-paid-at" v-model="form.paid_at" type="datetime-local" :max="nowLocal()" />
            </FormField>
            <FormField label="Tham chiếu" for="v-ref" hint="Mã giao dịch ngân hàng, số hóa đơn…"><Input id="v-ref" v-model="form.reference" maxlength="100" data-testid="v-ref" /></FormField>
            <FormField label="Diễn giải" for="v-note"><Input id="v-note" v-model="form.note" maxlength="500" :placeholder="receipt ? 'VD: Thu tiền bán thùng carton' : 'VD: Quảng cáo Shopee tháng 11'" data-testid="v-note" /></FormField>

            <label v-if="!debtMode" class="flex items-start gap-2 text-sm sm:col-span-2">
                <input v-model="form.counts_in_pnl" type="checkbox" class="mt-0.5 size-4" data-testid="v-pnl" />
                <span>Tính vào kết quả kinh doanh <span class="block text-xs text-muted-foreground">Bỏ chọn với tiền góp vốn, rút vốn, vay mượn: có vào ra quỹ nhưng không phải lãi lỗ.</span></span>
            </label>
            <p v-if="!activeTypes.length && !debtMode" class="flex items-center gap-2 text-sm text-muted-foreground sm:col-span-2"><Info class="size-4" /> Chưa có loại phiếu nào đang dùng. Thêm ở mục Loại phiếu.</p>
            <p v-if="error && !error.body.errors" class="text-sm text-destructive sm:col-span-2">{{ error.message }}</p>
        </form>
        <template #footer>
            <Button variant="outline" @click="open = false">Hủy</Button>
            <Button variant="secondary" :loading="saving" data-testid="v-save-again" @click="save(true)">Lưu và lập tiếp</Button>
            <Button type="submit" form="voucher-form" :loading="saving" data-testid="v-save">Lưu {{ directionLabels[direction].toLowerCase() }}</Button>
        </template>
    </Dialog>
</template>
