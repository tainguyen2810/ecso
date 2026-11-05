<script setup lang="ts">
import { computed, onMounted, reactive, ref, type Component } from 'vue';
import { ArrowLeftRight, Banknote, BookOpen, CircleDollarSign, ClipboardCheck, Landmark, Lock, Pencil, Plus, Truck, Wallet } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import CashNav from './CashNav.vue';
import ClosingDialog from './ClosingDialog.vue';
import CountDialog from './CountDialog.vue';
import TransferDialog from './TransferDialog.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import IconBubble from '@/shared/ui/IconBubble.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import type { Tone } from '@/shared/lib/tones';
import { useToast } from '@/shared/composables/useToast';
import { formatDate, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { paymentMethods } from '../orders/labels';
import { fundKinds, type Fund, type FundKind } from './voucher';

const tenant = useTenantStore();
const toast = useToast();
const funds = ref<Fund[] | null>(null);
const total = ref<number | null>(0);
const loadError = ref<string | null>(null);
const canManage = computed(() => tenant.can('finance.accounts'));
const transferOpen = ref(false);
const transferFrom = ref<string | null>(null);
const canClose = computed(() => tenant.can('finance.close'));
const closedUntil = ref<string | null>(null);
const closingOpen = ref(false);
const counting = ref<Fund | null>(null);
const countOpen = ref(false);
function count(f: Fund) {
    counting.value = f;
    countOpen.value = true;
}

const icons: Record<FundKind, [Component, Tone]> = { cash: [Banknote, 'green'], bank: [Landmark, 'blue'], ewallet: [Wallet, 'amber'], cod: [Truck, 'slate'], other: [CircleDollarSign, 'slate'] };

async function load() {
    loadError.value = null;
    try {
        const { data } = await api.get('/cash-accounts');
        funds.value = data.data;
        total.value = data.meta.total;
        closedUntil.value = data.meta.closed_until ?? null;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    }
}
onMounted(load);

const active = computed(() => (funds.value ?? []).filter((f) => f.is_active));
const inactive = computed(() => (funds.value ?? []).filter((f) => !f.is_active));

// Add / edit
const editing = ref<Fund | null>(null);
const formOpen = ref(false);
const blank = () => ({ kind: 'bank' as FundKind, name: '', bank_name: '', account_number: '', holder: '', methods: [] as string[], opening_balance: '', note: '', is_active: true });
const form = reactive(blank());
const saving = ref(false);
const error = ref<ApiError | null>(null);

function openForm(f: Fund | null) {
    editing.value = f;
    error.value = null;
    Object.assign(form, blank(), f ? { ...f, bank_name: f.bank_name ?? '', account_number: f.account_number ?? '', holder: f.holder ?? '', note: f.note ?? '', opening_balance: f.opening_balance ? String(f.opening_balance) : '' } : {});
    formOpen.value = true;
}

function toggleMethod(m: string) {
    form.methods = form.methods.includes(m) ? form.methods.filter((x) => x !== m) : [...form.methods, m];
}

/** The fund that receives a method today, other than the one being edited. */
const holderOf = (m: string) => (funds.value ?? []).find((f) => f.id !== editing.value?.id && f.methods.includes(m))?.name;

async function save() {
    saving.value = true;
    error.value = null;
    const body = {
        name: form.name,
        bank_name: form.bank_name || null,
        account_number: form.account_number || null,
        holder: form.holder || null,
        methods: form.methods,
        // Without finance.balances the opening balance is not shown, so it is not sent either (it would reset it).
        ...(tenant.can('finance.balances') ? { opening_balance: Number(form.opening_balance || 0) } : {}),
        note: form.note || null,
    };
    try {
        if (editing.value) {
            await api.patch(`/cash-accounts/${editing.value.id}`, body);
        } else {
            await api.post('/cash-accounts', { ...body, kind: form.kind });
        }
        toast.success(editing.value ? `Đã lưu ${form.name}.` : `Đã thêm quỹ ${form.name}.`);
        formOpen.value = false;
        await load();
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        saving.value = false;
    }
}

async function setActive(f: Fund, is_active: boolean) {
    try {
        await api.patch(`/cash-accounts/${f.id}`, { is_active });
        await load();
    } catch (e) {
        toast.error((e as ApiError).field('is_active') ?? (e as ApiError).message);
    }
}

function transfer(from: string | null) {
    transferFrom.value = from;
    transferOpen.value = true;
}
</script>

<template>
    <PageHeader title="Thu chi" description="Tiền đang có ở từng quỹ: két tiền mặt, tài khoản ngân hàng, ví, tiền COD hãng vận chuyển đang giữ.">
        <template v-if="canManage">
            <Button variant="outline" data-testid="new-fund" @click="openForm(null)"><Plus /> Thêm quỹ</Button>
            <Button data-testid="open-transfer" @click="transfer(null)"><ArrowLeftRight /> Chuyển quỹ</Button>
        </template>
    </PageHeader>
    <CashNav />

    <ErrorState v-if="loadError" :message="loadError" @retry="load" />
    <div v-else-if="!funds" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"><Skeleton v-for="i in 3" :key="i" class="h-40" /></div>
    <template v-else>
        <Card class="mb-4">
            <div class="flex flex-wrap items-center justify-between gap-3 p-4" data-testid="funds-total">
                <div>
                    <p class="text-xs text-muted-foreground">Tổng tiền các quỹ đang dùng</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">{{ total === null ? '•••' : formatMoney(total) }}</p>
                </div>
                <p class="max-w-md text-xs text-muted-foreground">Chuyển tiền giữa các quỹ không làm thay đổi tổng này và không tính là thu hay chi. Số dư = số dư đầu + thu − chi + chuyển đến − chuyển đi.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2 border-t px-4 py-2.5 text-sm" data-testid="closing-status">
                <Lock class="size-4 text-muted-foreground" />
                <span v-if="closedUntil">Sổ đã khóa đến hết ngày <b>{{ formatDate(closedUntil) }}</b>: phiếu từ ngày đó trở về trước không lập thêm, hủy hay lùi ngày được.</span>
                <span v-else class="text-muted-foreground">Sổ chưa khóa. Khóa sổ sau khi đối chiếu xong một kỳ để số dư đã báo cáo không bị đổi.</span>
                <Button size="sm" variant="outline" class="ml-auto" data-testid="open-closing" @click="closingOpen = true">{{ canClose ? 'Khóa sổ' : 'Lịch sử khóa sổ' }}</Button>
            </div>
        </Card>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" data-testid="funds">
            <Card v-for="f in active" :key="f.id" :data-testid="`fund-${f.kind}`">
                <div class="flex items-start gap-3 p-4">
                    <IconBubble :icon="icons[f.kind][0]" :tone="icons[f.kind][1]" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium">{{ f.name }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ fundKinds[f.kind] }}<template v-if="f.account_number"> · {{ f.account_number }}</template><template v-if="f.holder"> · {{ f.holder }}</template>
                        </p>
                    </div>
                    <Button v-if="canManage" size="icon" variant="ghost" class="size-8" :aria-label="`Sửa ${f.name}`" @click="openForm(f)"><Pencil /></Button>
                </div>
                <div class="px-4 pb-3">
                    <p class="text-2xl font-semibold tabular-nums" :class="(f.balance ?? 0) < 0 && 'text-destructive'" :title="f.balance === null ? 'Bạn không có quyền xem số dư quỹ' : undefined">{{ f.balance === null ? '•••' : formatMoney(f.balance) }}</p>
                    <p v-if="f.opening_balance" class="text-xs text-muted-foreground">Số dư đầu {{ formatMoney(f.opening_balance) }}</p>
                    <div class="mt-2 flex flex-wrap gap-1">
                        <Badge v-for="m in f.methods" :key="m" tone="info">{{ paymentMethods[m] ?? m }}</Badge>
                        <span v-if="!f.methods.length" class="text-xs text-muted-foreground">Không nhận tiền mặc định, chọn tay khi lập phiếu</span>
                    </div>
                </div>
                <div class="flex flex-wrap gap-1 border-t px-2 py-1.5">
                    <RouterLink :to="{ path: '/reports/cashbook', query: { account: f.id } }" class="inline-flex items-center gap-1.5 rounded px-2 py-1 text-xs text-primary hover:bg-muted"><BookOpen class="size-3.5" /> Sổ quỹ</RouterLink>
                    <button v-if="canManage" type="button" class="inline-flex items-center gap-1.5 rounded px-2 py-1 text-xs text-primary hover:bg-muted" @click="transfer(f.id)"><ArrowLeftRight class="size-3.5" /> Chuyển đi</button>
                    <button type="button" class="inline-flex items-center gap-1.5 rounded px-2 py-1 text-xs text-primary hover:bg-muted" :data-testid="`count-${f.kind}`" @click="count(f)"><ClipboardCheck class="size-3.5" /> Kiểm quỹ</button>
                </div>
            </Card>
        </div>

        <div v-if="inactive.length" class="mt-6">
            <p class="mb-2 text-sm font-medium text-muted-foreground">Ngừng dùng</p>
            <Card>
                <ul class="divide-y text-sm">
                    <li v-for="f in inactive" :key="f.id" class="flex items-center gap-3 px-4 py-2.5 text-muted-foreground">
                        <span class="flex-1">{{ f.name }}</span>
                        <span class="tabular-nums">{{ f.balance === null ? '•••' : formatMoney(f.balance) }}</span>
                        <Button v-if="canManage" size="sm" variant="ghost" @click="setActive(f, true)">Dùng lại</Button>
                    </li>
                </ul>
            </Card>
        </div>
        <p class="mt-4 text-xs text-muted-foreground">Mỗi hình thức thanh toán có một quỹ nhận mặc định, khi lập phiếu vẫn chọn được quỹ khác. Quỹ đã có giao dịch không xóa được, chỉ ngừng dùng.</p>
    </template>

    <Dialog v-model:open="formOpen" :title="editing ? `Sửa ${editing.name}` : 'Thêm quỹ'" size="lg">
        <form id="fund-form" class="grid gap-4 sm:grid-cols-2" data-testid="fund-form" @submit.prevent="save">
            <FormField v-if="!editing" label="Loại quỹ" for="f-kind">
                <Select id="f-kind" v-model="form.kind" :options="Object.entries(fundKinds).map(([value, label]) => ({ value, label }))" />
            </FormField>
            <FormField label="Tên quỹ" for="f-name" :class="editing && 'sm:col-span-2'" :error="error?.field('name')">
                <Input id="f-name" v-model="form.name" required maxlength="100" placeholder="VD: Vietcombank 6789, Két quầy 2" data-testid="f-name" />
            </FormField>
            <template v-if="form.kind === 'bank' || form.kind === 'ewallet'">
                <FormField label="Ngân hàng / ví" for="f-bank"><Input id="f-bank" v-model="form.bank_name" maxlength="50" placeholder="VD: VCB, MoMo" /></FormField>
                <FormField label="Số tài khoản" for="f-number" :error="error?.field('account_number')"><Input id="f-number" v-model="form.account_number" maxlength="30" inputmode="numeric" /></FormField>
                <FormField label="Chủ tài khoản" for="f-holder" class="sm:col-span-2"><Input id="f-holder" v-model="form.holder" maxlength="100" /></FormField>
            </template>
            <FormField label="Số dư đầu" for="f-opening" hint="Tiền đang có ở quỹ trước khi bắt đầu ghi trên phần mềm." :error="error?.field('opening_balance')">
                <Input id="f-opening" :model-value="form.opening_balance ? formatNumber(form.opening_balance) : ''" inputmode="numeric" class="text-right tabular-nums" placeholder="0" data-testid="f-opening" @input="form.opening_balance = ($event.target as HTMLInputElement).value.replace(/\D/g, '').slice(0, 10)" />
            </FormField>
            <FormField label="Ghi chú" for="f-note"><Input id="f-note" v-model="form.note" maxlength="255" /></FormField>
            <div class="space-y-1.5 sm:col-span-2">
                <p class="text-sm font-medium">Nhận mặc định tiền</p>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="(label, m) in paymentMethods"
                        :key="m"
                        type="button"
                        :class="['rounded-full border px-3 py-1 text-sm', form.methods.includes(m) ? 'border-primary bg-primary/10 font-medium text-primary' : 'text-muted-foreground hover:bg-muted']"
                        @click="toggleMethod(m)"
                    >
                        {{ label }}
                    </button>
                </div>
                <p v-for="m in form.methods.filter((x) => holderOf(x))" :key="m" class="text-xs text-muted-foreground">{{ paymentMethods[m] }} sẽ chuyển từ “{{ holderOf(m) }}” sang quỹ này.</p>
            </div>
            <p v-if="error && !error.body.errors" class="text-sm text-destructive sm:col-span-2">{{ error.message }}</p>
        </form>
        <template #footer>
            <Button v-if="editing" variant="outline" class="mr-auto text-destructive" @click="((formOpen = false), setActive(editing, false))">Ngừng dùng</Button>
            <Button variant="outline" @click="formOpen = false">Hủy</Button>
            <Button type="submit" form="fund-form" :loading="saving" data-testid="f-save">Lưu</Button>
        </template>
    </Dialog>
    <TransferDialog v-model:open="transferOpen" :funds="funds ?? []" :from="transferFrom" @saved="load" />
    <CountDialog v-model:open="countOpen" :fund="counting" :can-count="canClose" @saved="load" />
    <ClosingDialog v-model:open="closingOpen" :can-close="canClose" @changed="load" />
</template>
