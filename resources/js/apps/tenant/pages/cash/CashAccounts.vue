<script setup lang="ts">
import { computed, onMounted, provide, reactive, ref, type Component } from 'vue';
import { ArrowLeftRight, Banknote, BookOpen, Calculator, CircleCheck, CircleDollarSign, ClipboardCheck, Info, Landmark, Lock, Pencil, PiggyBank, Plus, Truck, Wallet } from 'lucide-vue-next';
import ReportChip from '../reports/ReportChip.vue';
import ReportKpi from '../reports/ReportKpi.vue';
import SideCard from '../reports/SideCard.vue';
import { BANKS } from '../settings/banks';
import CashHead from './CashHead.vue';
import ClosingDialog from './ClosingDialog.vue';
import CountDialog from './CountDialog.vue';
import TransferDialog from './TransferDialog.vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';
import { cn, formatDate, formatNumber } from '@/shared/lib/utils';
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

provide('soft-cards', true);
const icons: Record<FundKind, Component> = { cash: Banknote, bank: Landmark, ewallet: Wallet, cod: Truck, other: CircleDollarSign };
const tints: Record<FundKind, string> = { cash: 'bg-emerald-50 text-emerald-600', bank: 'bg-blue-50 text-blue-600', ewallet: 'bg-amber-50 text-amber-600', cod: 'bg-slate-100 text-slate-600', other: 'bg-violet-50 text-violet-600' };
const bars: Record<FundKind, string> = { cash: 'bg-emerald-500', bank: 'bg-blue-600', ewallet: 'bg-amber-500', cod: 'bg-slate-400', other: 'bg-violet-500' };
const monthChange = ref<number | null>(null);
const lastCod = ref<string | null>(null);

async function load() {
    loadError.value = null;
    try {
        const { data } = await api.get('/cash-accounts');
        funds.value = data.data;
        total.value = data.meta.total;
        closedUntil.value = data.meta.closed_until ?? null;
        monthChange.value = data.meta.month_change ?? null;
        lastCod.value = data.meta.last_cod_reconciliation ?? null;
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

const money = (n: number) => formatNumber(Math.round(n));
/** Real bank logo (VietQR, same as Cấu hình › Thanh toán) when the fund names a known bank; an icon otherwise. */
const broken = ref(new Set<string>());
function bankCode(f: Fund): string | null {
    if (f.kind !== 'bank') return null;
    const text = `${f.bank_name ?? ''} ${f.name}`.toLowerCase();
    const words = text.split(/[^a-z0-9]+/);
    const bank = BANKS.find((b) => words.includes(b.code.toLowerCase()) || text.includes(b.name.toLowerCase()));
    return bank && !broken.value.has(bank.code) ? bank.code : null;
}
const bankLogo = (code: string) => `https://api.vietqr.io/img/${encodeURIComponent(code)}.png`;

const canSee = computed(() => total.value !== null);
const sumOf = (kinds: FundKind[]) => active.value.filter((f) => kinds.includes(f.kind)).reduce((s, f) => s + (f.balance ?? 0), 0);
const countOf = (kinds: FundKind[]) => active.value.filter((f) => kinds.includes(f.kind)).length;
const share = (f: Fund) => (total.value && total.value > 0 && f.balance !== null ? Math.max(0, (f.balance / total.value) * 100) : 0);
/** Where the money is, by kind of fund. */
const mix = computed(() =>
    (Object.keys(fundKinds) as FundKind[])
        .filter((k) => countOf([k]))
        .map((k) => ({ kind: k, label: fundKinds[k], amount: sumOf([k]), pct: total.value && total.value > 0 ? Math.max(0, (sumOf([k]) / total.value) * 100) : 0 })),
);
const lastCashCount = computed(() => active.value.filter((f) => f.kind === 'cash' && f.last_count).map((f) => f.last_count!.at).sort().pop() ?? null);
/** "<1%" for a small but not empty share, so it does not read as nothing. */
const pct = (p: number) => (p > 0 && p < 1 ? '<1%' : `${Math.round(p)}%`);
const fundLine = (f: Fund) => [fundKinds[f.kind], f.account_number, f.holder].filter(Boolean).join(' · ');
const card = 'rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]';
const action = 'inline-flex items-center gap-1.5 rounded-[8px] px-2.5 py-1.5 text-[13px] font-medium text-slate-600 hover:bg-slate-100 hover:text-blue-700';
</script>

<template>
    <CashHead title="Quỹ" description="Tiền đang có ở từng quỹ: két tiền mặt, tài khoản ngân hàng, ví, tiền COD hãng vận chuyển đang giữ.">
        <template v-if="canManage">
            <button type="button" class="inline-flex h-11 items-center gap-2 whitespace-nowrap rounded-[12px] border border-slate-200 bg-white px-4 text-[15px] font-medium text-slate-700 hover:border-blue-300 hover:text-blue-700" data-testid="new-fund" @click="openForm(null)"><Plus class="size-4" /> Thêm quỹ</button>
            <button type="button" class="inline-flex h-11 items-center gap-2 whitespace-nowrap rounded-[12px] bg-blue-600 px-4 text-[15px] font-medium text-white shadow-sm hover:bg-blue-700" data-testid="open-transfer" @click="transfer(null)"><ArrowLeftRight class="size-4" /> Chuyển quỹ</button>
        </template>
    </CashHead>

    <ErrorState v-if="loadError" :message="loadError" @retry="load" />
    <div v-else-if="!funds" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><Skeleton v-for="i in 4" :key="i" class="h-[150px] rounded-[14px]" /></div>
    <template v-else>
        <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="funds-total">
            <ReportKpi label="Tổng tiền các quỹ đang dùng" tip="Cộng số dư mọi quỹ đang dùng. Chuyển tiền giữa các quỹ không làm đổi tổng này." :icon="PiggyBank" primary :value="total" :text="total === null ? '•••' : undefined">
                <template v-if="monthChange !== null" #chips>
                    <ReportChip tone="onblue">{{ monthChange >= 0 ? '+' : '−' }}{{ money(Math.abs(monthChange)) }} đ</ReportChip>
                    <span class="text-blue-100">tháng này</span>
                </template>
                {{ active.length }} quỹ đang dùng · chuyển quỹ không làm đổi tổng
            </ReportKpi>
            <ReportKpi label="Tiền mặt" tip="Tiền trong các két tiền mặt." :icon="Banknote" tone="green" plain :value="canSee ? sumOf(['cash']) : null" :text="canSee ? undefined : '•••'">
                <template #chips><ReportChip tone="up">{{ countOf(['cash']) }} két</ReportChip></template>
                {{ lastCashCount ? `Kiểm quỹ lần cuối ${formatDate(lastCashCount)}` : 'Chưa kiểm quỹ lần nào' }}
            </ReportKpi>
            <ReportKpi label="Ngân hàng, ví" tip="Tiền trong tài khoản ngân hàng và ví điện tử." :icon="Landmark" tone="blue" plain :value="canSee ? sumOf(['bank', 'ewallet']) : null" :text="canSee ? undefined : '•••'">
                <template #chips>
                    <ReportChip>{{ countOf(['bank']) }} tài khoản</ReportChip>
                    <ReportChip v-if="countOf(['ewallet'])" tone="amber">{{ countOf(['ewallet']) }} ví</ReportChip>
                </template>
                <span class="line-clamp-1">{{ active.filter((f) => f.kind === 'bank' || f.kind === 'ewallet').map((f) => f.name).join(', ') || 'Chưa có tài khoản nào' }}</span>
            </ReportKpi>
            <ReportKpi label="COD chờ đối soát" tip="Tiền thu hộ hãng vận chuyển đang giữ, chưa chuyển về tài khoản của bạn." :icon="Truck" tone="amber" :value="canSee ? sumOf(['cod']) : null" :text="canSee ? undefined : '•••'">
                <template #chips><RouterLink to="/shipping/cod" class="text-[12px] font-medium text-blue-700 hover:underline">Đối soát COD →</RouterLink></template>
                {{ lastCod ? `Đối soát gần nhất ${formatDate(lastCod)}` : 'Chưa đối soát lần nào' }}
            </ReportKpi>
        </div>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_380px]">
            <div class="min-w-0">
                <div class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3" data-testid="funds">
                    <div v-for="f in active" :key="f.id" :class="cn(card, 'flex flex-col')" :data-testid="`fund-${f.kind}`">
                        <div class="flex items-start gap-3 p-5 pb-3">
                            <span v-if="bankCode(f)" class="grid size-11 shrink-0 place-items-center overflow-hidden rounded-[12px] border border-slate-200 bg-white">
                                <img :src="bankLogo(bankCode(f)!)" :alt="f.bank_name ?? f.name" class="max-h-7 max-w-9 object-contain" @error="broken.add(bankCode(f)!)" />
                            </span>
                            <span v-else :class="cn('grid size-11 shrink-0 place-items-center rounded-[12px]', tints[f.kind])"><component :is="icons[f.kind]" class="size-5" /></span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-[16px] font-semibold text-slate-900">{{ f.name }}</p>
                                <p class="truncate text-[13px] text-slate-500">{{ fundLine(f) }}</p>
                            </div>
                            <button v-if="canManage" type="button" class="grid size-8 place-items-center rounded-[8px] text-slate-400 hover:bg-slate-100 hover:text-slate-700" :aria-label="`Sửa ${f.name}`" @click="openForm(f)"><Pencil class="size-4" /></button>
                        </div>
                        <div class="flex-1 px-5 pb-4">
                            <p :class="cn('text-[26px] font-bold tabular-nums tracking-tight', (f.balance ?? 0) < 0 ? 'text-red-600' : 'text-slate-900')" :title="f.balance === null ? 'Bạn không có quyền xem số dư quỹ' : undefined" data-testid="fund-balance">
                                {{ f.balance === null ? '•••' : money(f.balance) }}<span v-if="f.balance !== null" class="ml-1 text-[15px] font-semibold text-slate-400">đ</span>
                            </p>
                            <template v-if="f.balance !== null">
                                <p class="text-[12px] text-slate-500">{{ f.opening_balance ? `Số dư đầu ${money(f.opening_balance)} đ · ` : '' }}{{ pct(share(f)) }} tổng tiền</p>
                                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100"><div :class="cn('h-full rounded-full', bars[f.kind])" :style="{ width: `${share(f)}%` }" /></div>
                            </template>
                            <p class="mt-3 text-[12px] font-medium uppercase text-slate-400">Nhận mặc định</p>
                            <div class="mt-1 flex flex-wrap gap-1.5">
                                <span v-for="m in f.methods" :key="m" class="rounded-md bg-blue-50 px-2 py-0.5 text-[12px] font-medium text-blue-700">{{ paymentMethods[m] ?? m }}</span>
                                <span v-if="!f.methods.length" class="text-[12px] text-slate-400">Không nhận mặc định, chọn tay khi lập phiếu</span>
                            </div>
                            <p v-if="f.last_count" class="mt-2 flex items-center gap-1.5 text-[12px] text-slate-500">
                                <CircleCheck v-if="!f.last_count.difference" class="size-3.5 text-emerald-500" /><Info v-else class="size-3.5 text-amber-500" />
                                Kiểm quỹ lần cuối {{ formatDate(f.last_count.at) }}{{ f.last_count.difference === null ? '' : !f.last_count.difference ? ', khớp' : `, ${f.last_count.difference > 0 ? 'thừa' : 'thiếu'} ${money(Math.abs(f.last_count.difference))} đ` }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-1 border-t border-slate-100 px-3 py-2">
                            <RouterLink :to="{ path: '/reports/cashbook', query: { account: f.id } }" :class="action"><BookOpen class="size-3.5" /> Sổ quỹ</RouterLink>
                            <button v-if="canManage" type="button" :class="action" @click="transfer(f.id)"><ArrowLeftRight class="size-3.5" /> Chuyển đi</button>
                            <button type="button" :class="action" :data-testid="`count-${f.kind}`" @click="count(f)"><ClipboardCheck class="size-3.5" /> Kiểm quỹ</button>
                        </div>
                    </div>
                    <button
                        v-if="canManage"
                        type="button"
                        class="flex min-h-[240px] flex-col items-center justify-center gap-2 rounded-[14px] border-2 border-dashed border-slate-200 bg-white/60 text-slate-500 hover:border-blue-300 hover:text-blue-700"
                        @click="openForm(null)"
                    >
                        <span class="grid size-11 place-items-center rounded-[12px] bg-slate-100"><Plus class="size-5" /></span>
                        <span class="text-[15px] font-medium text-slate-700">Thêm quỹ</span>
                        <span class="max-w-[220px] text-center text-[13px]">Két quầy khác, tài khoản ngân hàng, ví điện tử</span>
                    </button>
                </div>

                <section v-if="inactive.length" :class="cn(card, 'mt-5')">
                    <header class="border-b border-slate-100 px-5 py-4">
                        <h3 class="text-[16px] font-semibold text-slate-900">Ngừng dùng</h3>
                        <p class="mt-0.5 text-[13px] text-slate-500">Quỹ đã có giao dịch không xóa được, chỉ ngừng dùng</p>
                    </header>
                    <ul class="divide-y divide-slate-100">
                        <li v-for="f in inactive" :key="f.id" class="flex items-center gap-3 px-5 py-3.5 text-[14px] text-slate-500">
                            <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-slate-100"><component :is="icons[f.kind]" class="size-4" /></span>
                            <span class="min-w-0 flex-1 truncate">{{ f.name }}</span>
                            <span class="tabular-nums">{{ f.balance === null ? '•••' : `${money(f.balance)} đ` }}</span>
                            <button v-if="canManage" type="button" class="rounded-[8px] border border-slate-200 px-3 py-1.5 text-[13px] font-medium text-slate-700 hover:border-blue-300 hover:text-blue-700" @click="setActive(f, true)">Dùng lại</button>
                        </li>
                    </ul>
                </section>
            </div>

            <div class="space-y-5">
                <SideCard title="Khóa sổ" description="Giữ nguyên số dư đã báo cáo">
                    <template #actions><span class="grid size-10 shrink-0 place-items-center rounded-[11px] bg-violet-50 text-violet-600"><Lock class="size-5" /></span></template>
                    <div class="px-5 py-4" data-testid="closing-status">
                        <template v-if="closedUntil">
                            <p class="text-[14px] text-slate-700">Sổ đã khóa đến hết ngày <b>{{ formatDate(closedUntil) }}</b>.</p>
                            <p class="mt-1 text-[13px] text-slate-500">Phiếu từ ngày đó trở về trước không lập thêm, hủy hay lùi ngày được.</p>
                        </template>
                        <p v-else class="text-[13px] text-slate-500">Sổ chưa khóa. Khóa sổ sau khi đối chiếu xong một kỳ để số dư đã báo cáo không bị đổi.</p>
                        <button type="button" :class="cn('mt-3 inline-flex h-9 items-center rounded-[10px] px-3.5 text-[13px] font-medium', canClose ? 'bg-violet-600 text-white hover:bg-violet-700' : 'border border-slate-200 text-slate-700 hover:border-blue-300')" data-testid="open-closing" @click="closingOpen = true">
                            {{ canClose ? 'Khóa sổ, lịch sử' : 'Lịch sử khóa sổ' }}
                        </button>
                    </div>
                </SideCard>
                <SideCard v-if="canSee && mix.length" title="Tiền đang nằm ở đâu" description="Theo loại quỹ, số dư hiện tại">
                    <div class="px-5 py-4">
                        <div class="flex h-3 overflow-hidden rounded-full bg-slate-100">
                            <div v-for="m in mix" :key="m.kind" :class="cn('h-full', bars[m.kind])" :style="{ width: `${m.pct}%` }" />
                        </div>
                        <ul class="mt-3 divide-y divide-slate-100">
                            <li v-for="m in mix" :key="m.kind" class="flex items-center gap-2.5 py-2 text-[14px]">
                                <span :class="cn('size-2.5 rounded-full', bars[m.kind])" />
                                <span class="flex-1 text-slate-600">{{ m.label }}</span>
                                <b class="tabular-nums text-slate-900">{{ money(m.amount) }}</b>
                                <span class="w-10 text-right text-[12px] text-slate-400">{{ pct(m.pct) }}</span>
                            </li>
                        </ul>
                    </div>
                </SideCard>
                <section :class="cn(card, 'p-5')">
                    <p class="flex items-center gap-2 text-[14px] font-semibold text-slate-900"><Calculator class="size-4 text-slate-400" /> Cách tính số dư</p>
                    <p class="mt-2 rounded-[10px] bg-slate-50 px-3 py-2 text-[13px] text-slate-600">Số dư đầu + thu − chi + chuyển đến − chuyển đi</p>
                    <p class="mt-2 text-[13px] text-slate-500">Mỗi hình thức thanh toán có một quỹ nhận mặc định, khi lập phiếu vẫn chọn được quỹ khác. Quỹ đã có giao dịch không xóa được, chỉ ngừng dùng.</p>
                </section>
            </div>
        </div>
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
