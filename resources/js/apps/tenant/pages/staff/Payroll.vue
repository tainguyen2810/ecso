<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { BadgePlus, Banknote, CalendarCheck, Check, CheckCircle2, ChevronRight, HandCoins, Lock, Pencil, ShieldCheck, Undo2, UserPlus, Wallet } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useIdempotencyKey } from '@/shared/composables/useIdempotencyKey';
import ReportChip from '../reports/ReportChip.vue';
import ReportKpi from '../reports/ReportKpi.vue';
import SideCard from '../reports/SideCard.vue';
import FrameButton from './FrameButton.vue';
import StaffFrame from './StaffFrame.vue';
import { monthLabel, thisMonth, type PayLine, type Payroll, type StaffProfile } from './staff';

/**
 * Nhân viên › Bảng lương: lương theo công (or by the hour) + hoa hồng + phụ cấp + thưởng, minus tạm ứng, bảo hiểm and
 * thuế TNCN. A draft follows attendance and sales live; Duyệt freezes it; Chi lương books one voucher per member in the
 * cash book. A member sees only their own payslip, once approved.
 */
const route = useRoute();
const router = useRouter();
const toast = useToast();
const month = ref(typeof route.query.month === 'string' && /^\d{4}-\d{2}$/.test(route.query.month) ? route.query.month : thisMonth());
const data = ref<Payroll | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const selectedId = ref<string | null>(null);
const busy = ref(false);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        data.value = (await api.get('/staff/payroll', { params: { month: month.value } })).data.data;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
watch(month, (m) => {
    router.replace({ query: { month: m } });
    selectedId.value = null;
    load();
});
onMounted(load);

const selected = computed<PayLine | null>(() => data.value?.lines.find((l) => l.id === selectedId.value) ?? data.value?.lines[0] ?? null);
const draft = computed(() => data.value?.status === 'draft');
const dateText = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('vi-VN') : '');
const workText = (l: PayLine) => (l.pay_type === 'hour' ? `${String(l.hours).replace('.', ',')} giờ` : `${l.days}/${l.standard_days}`);

async function run(action: 'approve' | 'reopen') {
    busy.value = true;
    try {
        data.value = { ...(await api.post(`/staff/payroll/${month.value}/${action}`)).data.data, manage: true };
        toast.success(action === 'approve' ? 'Đã duyệt bảng lương. Nhân viên xem được phiếu lương của mình.' : 'Đã mở lại bảng lương để sửa.');
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = false;
    }
}

// Chi lương: one payment voucher per member in the cash book.
const payOpen = ref(false);
const payForm = ref({ method: 'bank_transfer', account: '' });
async function pay() {
    busy.value = true;
    try {
        data.value = { ...(await api.post(`/staff/payroll/${month.value}/pay`, { method: payForm.value.method, account: payForm.value.account || null })).data.data, manage: true };
        payOpen.value = false;
        toast.success('Đã ghi phiếu chi lương vào sổ quỹ.');
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = false;
    }
}

// Thưởng / khấu trừ of one member this month.
const adjOpen = ref(false);
const adjForm = ref({ bonus: 0, deduction: 0, note: '' });
function openAdjust(l: PayLine) {
    selectedId.value = l.id;
    adjForm.value = { bonus: l.bonus, deduction: l.deduction, note: l.note ?? '' };
    adjOpen.value = true;
}
async function saveAdjust() {
    if (!selected.value) return;
    busy.value = true;
    try {
        data.value = { ...(await api.put(`/staff/payroll/${month.value}/adjust/${selected.value.id}`, { ...adjForm.value, note: adjForm.value.note || null })).data.data, manage: true };
        adjOpen.value = false;
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = false;
    }
}

// Tạm ứng lương: a payment voucher to the member, taken off this month's payslip.
const advOpen = ref(false);
const advForm = ref({ member: '', amount: 1000000, method: 'cash', note: '' });
const advKey = useIdempotencyKey();
function openAdvance(l?: PayLine) {
    ensureMembers();
    advForm.value = { member: l?.id ?? selected.value?.id ?? '', amount: 1000000, method: 'cash', note: '' };
    advOpen.value = true;
}
async function saveAdvance() {
    busy.value = true;
    try {
        const r = (await api.post('/staff/advances', { ...advForm.value, note: advForm.value.note || null }, advKey.config())).data.data;
        advKey.renew();
        advOpen.value = false;
        toast.success(`Đã ghi phiếu chi ${r.number} vào sổ quỹ.`);
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = false;
    }
}

// Hồ sơ lương: salary, insurance, dependants, shift and sales target of a member.
const members = ref<{ id: string; name: string; role: string; profile: StaffProfile }[]>([]);
async function ensureMembers() {
    if (!members.value.length) members.value = (await api.get('/staff/members').catch(() => null))?.data?.data ?? [];
}
const profOpen = ref(false);
const profMember = ref('');
const blank = (): StaffProfile => ({ title: '', pay_type: 'month', base_salary: 0, hourly_rate: 0, standard_days: 26, allowance: 0, insured_salary: 0, dependents: 0, sales_target: 0, shift_start: '08:00', shift_end: '17:00', set: false });
const prof = ref<StaffProfile>(blank());
async function openProfile(l?: PayLine) {
    await ensureMembers();
    const id = l?.id ?? members.value.find((m) => !m.profile.set)?.id ?? members.value[0]?.id ?? '';
    pickMember(id);
    profOpen.value = true;
}
function pickMember(id: string) {
    profMember.value = id;
    const m = members.value.find((x) => x.id === id);
    prof.value = m ? { ...m.profile, title: m.profile.title ?? '' } : blank();
}
async function saveProfile() {
    busy.value = true;
    try {
        const { set: _set, ...body } = prof.value;
        const saved = (await api.put(`/staff/members/${profMember.value}/profile`, { ...body, title: body.title || null })).data.data as StaffProfile;
        members.value = members.value.map((m) => (m.id === profMember.value ? { ...m, profile: saved } : m));
        profOpen.value = false;
        toast.success('Đã lưu hồ sơ lương.');
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = false;
    }
}

// Headline figures, the three steps and the totals row.
const sum = (k: keyof PayLine) => (data.value?.lines ?? []).reduce((s, l) => s + Number(l[k] ?? 0), 0);
const short = (n: number) => (Math.abs(n) >= 1e6 ? `${String(Math.round(n / 1e4) / 100).replace('.', ',')} tr` : formatNumber(n));
const initials = (name: string) => name.trim().split(/\s+/).slice(-2).map((w) => w[0]).join('').toUpperCase();
const steps = computed(() => {
    const at = { draft: 0, approved: 1, paid: 2 }[data.value?.status ?? 'draft'];
    return [
        { label: 'Tạm tính', hint: 'Tự cập nhật theo chấm công, hoa hồng' },
        { label: 'Đã duyệt', hint: 'Nhân viên thấy phiếu lương' },
        { label: 'Đã chi', hint: 'Ghi phiếu chi vào Sổ quỹ' },
    ].map((s, i) => ({ ...s, done: i < at || (i === 2 && at === 2), current: i === at && at < 2 }));
});
const methodText = (m: string | null) => (m === 'bank_transfer' ? 'Chuyển khoản' : m === 'cash' ? 'Tiền mặt' : m ?? '');
const dayText = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit' }) : '');
</script>

<template>
    <StaffFrame v-model:month="month" page="payroll" title="Bảng lương" description="Lương theo công + hoa hồng + phụ cấp, trừ tạm ứng, bảo hiểm và thuế TNCN. Số liệu lấy từ Chấm công và Hoa hồng.">
        <template v-if="data?.manage" #actions>
            <FrameButton data-testid="advance-open" @click="openAdvance()"><HandCoins class="text-slate-500" /> Tạm ứng</FrameButton>
            <FrameButton v-if="draft" primary :loading="busy" :disabled="!data.lines.length" data-testid="payroll-approve" @click="run('approve')"><CheckCircle2 /> Duyệt bảng lương</FrameButton>
            <template v-else-if="data.status === 'approved'">
                <FrameButton :loading="busy" @click="run('reopen')"><Undo2 class="text-slate-500" /> Mở lại</FrameButton>
                <FrameButton primary data-testid="payroll-pay" @click="payOpen = true"><Banknote /> Chi lương ra sổ quỹ</FrameButton>
            </template>
        </template>
    </StaffFrame>

    <div v-if="loading && !data" class="space-y-5"><div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4"><Skeleton v-for="i in 4" :key="i" class="h-36" /></div><Skeleton class="h-72" /></div>
    <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
    <template v-else-if="data">
        <!-- A member: their own payslip only. -->
        <template v-if="!data.manage">
            <section v-if="!data.lines.length" class="rounded-[14px] border border-slate-200/80 bg-white px-6 py-12 text-center text-[14px] text-slate-500 shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
                <Lock class="mx-auto mb-2 size-6" />Phiếu lương {{ monthLabel(month).toLowerCase() }} sẽ hiện ở đây khi chủ cửa hàng duyệt bảng lương.
            </section>
            <section v-for="l in data.lines" :key="l.id" class="mx-auto max-w-xl overflow-hidden rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]" data-testid="payslip">
                <header class="border-b border-slate-100 px-5 py-4">
                    <p class="text-[13px] text-slate-500">Phiếu lương {{ monthLabel(month).toLowerCase() }}<template v-if="data.status === 'paid'"> · đã chi ngày {{ dateText(data.paid_at) }}</template></p>
                    <p class="mt-0.5 text-[18px] font-semibold text-slate-900">{{ l.name }} <span class="text-[14px] font-normal text-slate-500">· {{ l.title }}</span></p>
                </header>
                <div class="px-5 py-1.5">
                    <div v-for="(d, i) in l.detail" :key="i" class="flex justify-between gap-3 border-b border-slate-100 py-2.5 text-[14px] last:border-0"><span class="text-slate-600">{{ d.label }}</span><span :class="cn('shrink-0 whitespace-nowrap font-medium tabular-nums', d.amount < 0 && 'text-rose-600')">{{ formatMoney(d.amount) }}</span></div>
                </div>
                <div class="flex items-baseline justify-between bg-emerald-50 px-5 py-4"><span class="text-[15px] font-semibold text-emerald-900">Thực nhận</span><span class="text-[24px] font-bold tabular-nums text-emerald-600">{{ formatMoney(l.net) }}</span></div>
            </section>
        </template>

        <template v-else>
            <div class="mb-5 grid gap-5 sm:grid-cols-2 xl:grid-cols-4" data-testid="payroll-kpis">
                <ReportKpi primary label="Tổng thực nhận" :icon="Wallet" :value="data.totals.net" tip="Cộng thực nhận của mọi nhân viên: lương theo công + hoa hồng + phụ cấp, thưởng, trừ tạm ứng, bảo hiểm, thuế TNCN và khấu trừ khác.">
                    <template #chips><ReportChip tone="onblue">{{ data.totals.count }} nhân viên</ReportChip></template>
                    Sau tạm ứng, bảo hiểm, thuế TNCN
                </ReportKpi>
                <ReportKpi label="Lương theo công" :icon="CalendarCheck" tone="green" :value="sum('base_pay')" tip="Lương tháng = lương cơ bản × số công / số công chuẩn. Lương giờ = số giờ × lương mỗi giờ.">
                    <template #chips><ReportChip>{{ formatNumber(sum('days')) }} công</ReportChip><ReportChip v-if="sum('hours')">{{ formatNumber(Math.round(sum('hours') * 10) / 10) }} giờ</ReportChip></template>
                    Lương cơ bản × công / công chuẩn
                </ReportKpi>
                <ReportKpi label="Hoa hồng, phụ cấp" :icon="HandCoins" tone="violet" plain :value="data.totals.commission + sum('allowance') + sum('bonus')" tip="Hoa hồng lấy từ trang Hoa hồng; phụ cấp từ Hồ sơ lương; thưởng nhập ở nút bút chì từng dòng.">
                    <template #chips><ReportChip>Hoa hồng {{ short(data.totals.commission) }}</ReportChip><ReportChip>Phụ cấp, thưởng {{ short(sum('allowance') + sum('bonus')) }}</ReportChip></template>
                    Hoa hồng lấy từ trang Hoa hồng
                </ReportKpi>
                <ReportKpi label="Bảo hiểm, thuế" :icon="ShieldCheck" tone="amber" :value="data.totals.insurance + data.totals.pit" tip="Bảo hiểm phần nhân viên đóng và thuế TNCN khấu trừ vào lương. Phần bảo hiểm cửa hàng đóng thêm không trừ vào lương.">
                    <template #chips><ReportChip>BH NV {{ short(data.totals.insurance) }}</ReportChip><ReportChip>Thuế {{ short(data.totals.pit) }}</ReportChip></template>
                    Cửa hàng đóng thêm BH {{ short(data.totals.employer_insurance) }}
                </ReportKpi>
            </div>

            <section class="mb-5 flex flex-wrap items-center gap-x-8 gap-y-4 rounded-[14px] border border-slate-200/80 bg-white px-5 py-4 shadow-[0_1px_2px_rgba(15,23,42,0.04)] sm:px-6" data-testid="payroll-status">
                <ol class="flex min-w-0 flex-1 flex-wrap items-center gap-y-3">
                    <li v-for="(s, i) in steps" :key="s.label" class="flex min-w-[12rem] flex-1 items-center">
                        <span v-if="s.done" class="grid size-8 shrink-0 place-items-center rounded-full bg-emerald-500 text-white"><Check class="size-4" /></span>
                        <span v-else-if="s.current" class="grid size-8 shrink-0 place-items-center rounded-full bg-blue-600 text-[13px] font-bold text-white ring-4 ring-blue-100">{{ i + 1 }}</span>
                        <span v-else class="grid size-8 shrink-0 place-items-center rounded-full border-2 border-slate-200 text-[13px] font-semibold text-slate-400">{{ i + 1 }}</span>
                        <div class="ml-3 min-w-0">
                            <p :class="cn('text-[14px] font-semibold', s.done || s.current ? 'text-slate-900' : 'text-slate-400')">{{ s.label }}</p>
                            <p class="text-[12px] text-slate-500">{{ s.hint }}</p>
                        </div>
                        <span v-if="i < 2" :class="cn('mx-4 hidden h-0.5 flex-1 rounded-full sm:block', s.done ? 'bg-emerald-400' : 'bg-slate-200')" />
                    </li>
                </ol>
                <p class="text-[13px] text-slate-500 lg:border-l lg:border-slate-100 lg:pl-8">
                    <template v-if="data.status === 'paid'">Chi ngày <b class="font-semibold text-slate-800">{{ dateText(data.paid_at) }}</b> · phiếu chi "Lương nhân viên" trong Sổ quỹ</template>
                    <template v-else-if="data.status === 'approved'">Duyệt ngày <b class="font-semibold text-slate-800">{{ dateText(data.approved_at) }}</b><template v-if="data.approved_by"> bởi {{ data.approved_by }}</template><br />Số liệu đã chốt, chấm công sửa sau không đổi bảng này</template>
                    <template v-else>Bấm Duyệt bảng lương khi đã kiểm tra xong,<br />nhân viên mới thấy phiếu lương</template>
                </p>
            </section>

            <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_25rem]">
                <section class="min-w-0 overflow-hidden rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4 sm:px-6">
                        <div>
                            <h2 class="text-[16px] font-semibold text-slate-900">Bảng lương {{ monthLabel(month).toLowerCase() }}</h2>
                            <p class="mt-0.5 text-[13px] text-slate-500">Bấm một dòng để xem cách tính. Công lấy từ Chấm công, hoa hồng lấy từ Hoa hồng.</p>
                        </div>
                        <FrameButton v-if="draft" sm data-testid="profile-add" @click="openProfile()"><UserPlus /> Hồ sơ lương</FrameButton>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[900px]" data-testid="payroll-lines">
                            <thead class="border-b border-slate-100 bg-slate-50/60 text-[12px] uppercase tracking-wider text-slate-400">
                                <tr class="[&>th]:whitespace-nowrap [&>th]:px-3 [&>th]:py-3 [&>th]:text-right [&>th]:font-semibold">
                                    <th class="!pl-5 !text-left sm:!pl-6">Nhân viên</th><th>Công</th><th>Lương theo công</th><th>Hoa hồng</th><th>Phụ cấp, thưởng</th><th>Tạm ứng</th><th>Bảo hiểm</th><th>Thuế</th><th>Thực nhận</th><th class="w-12 !pr-5 sm:!pr-6" />
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="l in data.lines"
                                    :key="l.id"
                                    :class="cn('cursor-pointer border-b border-slate-100 text-[14px] tabular-nums last:border-0 hover:bg-slate-50 [&>td]:whitespace-nowrap [&>td]:px-3 [&>td]:py-3 [&>td]:text-right', selected?.id === l.id && 'bg-blue-50/70 hover:bg-blue-50/70')"
                                    @click="selectedId = l.id"
                                >
                                    <td class="relative !pl-5 !text-left sm:!pl-6">
                                        <span v-if="selected?.id === l.id" class="absolute inset-y-2 left-0 w-[3px] rounded-r-full bg-blue-600" />
                                        <div class="flex items-center gap-3">
                                            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-blue-50 text-[12px] font-semibold text-blue-600">{{ initials(l.name) }}</span>
                                            <div class="min-w-0"><p class="font-medium text-slate-900">{{ l.name }}</p><p class="text-[12px] text-slate-500">{{ l.title }}</p></div>
                                        </div>
                                    </td>
                                    <td class="text-slate-600">{{ workText(l) }}</td>
                                    <td>{{ formatNumber(l.base_pay) }}</td>
                                    <td class="text-emerald-600">{{ l.commission ? formatNumber(l.commission) : '—' }}</td>
                                    <td>{{ l.allowance + l.bonus ? formatNumber(l.allowance + l.bonus) : '—' }}</td>
                                    <td :class="l.advance ? 'text-rose-600' : 'text-slate-300'">{{ l.advance ? `−${formatNumber(l.advance)}` : '—' }}</td>
                                    <td :class="l.insurance ? 'text-rose-600' : 'text-slate-300'">{{ l.insurance ? `−${formatNumber(l.insurance)}` : '—' }}</td>
                                    <td :class="l.pit ? 'text-rose-600' : 'text-slate-300'">{{ l.pit ? `−${formatNumber(l.pit)}` : '—' }}</td>
                                    <td class="text-[15px] font-bold text-slate-900">{{ formatNumber(l.net) }}</td>
                                    <td class="!pr-5 sm:!pr-6" @click.stop>
                                        <button v-if="draft" type="button" class="ml-auto grid size-8 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700" title="Thưởng, khấu trừ" :aria-label="`Thưởng, khấu trừ của ${l.name}`" @click="openAdjust(l)"><Pencil class="size-4" /></button>
                                        <ChevronRight v-else class="ml-auto size-4 text-slate-400" />
                                    </td>
                                </tr>
                                <tr v-if="!data.lines.length"><td colspan="10" class="px-6 py-10 text-center text-[14px] text-slate-500">Chưa có ai trong bảng lương. Bấm Hồ sơ lương để nhập lương cơ bản cho nhân viên.</td></tr>
                            </tbody>
                            <tfoot v-if="data.lines.length > 1" class="border-t border-slate-200 bg-slate-50/60 text-[14px] font-semibold tabular-nums">
                                <tr class="[&>td]:whitespace-nowrap [&>td]:px-3 [&>td]:py-3.5 [&>td]:text-right">
                                    <td class="!pl-5 !text-left sm:!pl-6">Tổng {{ data.lines.length }} nhân viên</td>
                                    <td class="text-slate-600">{{ sum('days') ? `${formatNumber(sum('days'))} công` : '' }}</td>
                                    <td>{{ formatNumber(sum('base_pay')) }}</td>
                                    <td class="text-emerald-600">{{ formatNumber(data.totals.commission) }}</td>
                                    <td>{{ formatNumber(sum('allowance') + sum('bonus')) }}</td>
                                    <td class="text-rose-600">{{ sum('advance') ? `−${formatNumber(sum('advance'))}` : '—' }}</td>
                                    <td class="text-rose-600">{{ data.totals.insurance ? `−${formatNumber(data.totals.insurance)}` : '—' }}</td>
                                    <td class="text-rose-600">{{ data.totals.pit ? `−${formatNumber(data.totals.pit)}` : '—' }}</td>
                                    <td class="text-[16px] font-bold text-blue-700">{{ formatNumber(data.totals.net) }}</td>
                                    <td />
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <p class="border-t border-slate-100 px-5 py-3 text-[12px] text-slate-500 sm:px-6">
                        Bảo hiểm NV {{ String(data.rules.employee_insurance).replace('.', ',') }}%, cửa hàng đóng thêm {{ String(data.rules.employer_insurance).replace('.', ',') }}%. Giảm trừ bản thân {{ formatMoney(data.rules.personal_deduction) }}, mỗi người phụ thuộc {{ formatMoney(data.rules.dependent_deduction) }}. Số tạm tính, nên đối chiếu với kế toán khi luật thay đổi.
                    </p>
                </section>

                <div class="space-y-5">
                    <section v-if="selected" class="overflow-hidden rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]" data-testid="pay-detail">
                        <header class="flex items-center gap-3 border-b border-slate-100 px-5 py-4">
                            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-blue-50 text-[12px] font-semibold text-blue-600">{{ initials(selected.name) }}</span>
                            <div class="min-w-0 flex-1">
                                <h3 class="truncate text-[16px] font-semibold text-slate-900">{{ selected.name }}</h3>
                                <p class="truncate text-[13px] text-slate-500">{{ selected.title }} · {{ selected.pay_type === 'hour' ? 'lương giờ' : 'lương tháng' }}<template v-if="selected.profile?.dependents"> · {{ selected.profile.dependents }} người phụ thuộc</template></p>
                            </div>
                            <FrameButton v-if="draft" sm @click="openProfile(selected)"><Pencil /> Hồ sơ</FrameButton>
                        </header>
                        <div class="px-5 py-1.5">
                            <div v-for="(d, i) in selected.detail" :key="i" class="flex justify-between gap-3 border-b border-slate-100 py-2.5 text-[14px] last:border-0"><span class="text-slate-600">{{ d.label }}</span><span :class="cn('shrink-0 whitespace-nowrap font-medium tabular-nums', d.amount < 0 && 'text-rose-600')">{{ d.amount < 0 ? `−${formatNumber(-d.amount)}` : formatNumber(d.amount) }}</span></div>
                        </div>
                        <div class="flex items-baseline justify-between bg-emerald-50 px-5 py-4"><span class="text-[15px] font-semibold text-emerald-900">Thực nhận</span><span class="text-[24px] font-bold tabular-nums text-emerald-600">{{ formatNumber(selected.net) }} <span class="text-[15px]">đ</span></span></div>
                        <p v-if="selected.employer_insurance" class="px-5 py-3 text-[12px] text-slate-500">Cửa hàng đóng thêm bảo hiểm {{ formatMoney(selected.employer_insurance) }} (không trừ vào lương).</p>
                        <div v-if="draft" class="grid grid-cols-2 gap-2 border-t border-slate-100 px-5 py-4">
                            <FrameButton sm class="!h-10 !text-[14px]" @click="openAdjust(selected)"><BadgePlus /> Thưởng, khấu trừ</FrameButton>
                            <FrameButton sm class="!h-10 !text-[14px]" @click="openAdvance(selected)"><HandCoins /> Tạm ứng</FrameButton>
                        </div>
                    </section>
                    <SideCard title="Tạm ứng trong tháng" description="Đã ghi phiếu chi trong Sổ quỹ, tự trừ vào lương" :badge="data.advances.length ? short(data.advances.reduce((s, a) => s + a.amount, 0)) : null">
                        <ul class="px-5" data-testid="payroll-advances">
                            <li v-for="(a, i) in data.advances" :key="i" class="flex items-center gap-3 border-b border-slate-100 py-3 last:border-0">
                                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-slate-100 text-[12px] font-semibold text-slate-600">{{ initials(a.name) }}</span>
                                <div class="min-w-0 flex-1"><p class="truncate text-[14px] font-medium text-slate-900">{{ a.name }}</p><p class="text-[12px] text-slate-500">{{ dayText(a.paid_at) }} · {{ methodText(a.method) }}</p></div>
                                <span class="text-[14px] font-semibold tabular-nums text-rose-600">−{{ formatNumber(a.amount) }}</span>
                            </li>
                            <li v-if="!data.advances.length" class="py-6 text-center text-[13px] text-slate-500">Chưa ai tạm ứng tháng này.</li>
                        </ul>
                    </SideCard>
                </div>
            </div>
        </template>
    </template>

    <Dialog v-model:open="adjOpen" :title="`Thưởng, khấu trừ: ${selected?.name ?? ''}`">
        <div class="grid grid-cols-2 gap-3 text-sm">
            <label class="block"><span class="mb-1 block font-medium">Thưởng (đ)</span><Input v-model.number="adjForm.bonus" type="number" min="0" step="100000" /></label>
            <label class="block"><span class="mb-1 block font-medium">Khấu trừ khác (đ)</span><Input v-model.number="adjForm.deduction" type="number" min="0" step="50000" /></label>
            <label class="col-span-2 block"><span class="mb-1 block font-medium">Ghi chú</span><Input v-model="adjForm.note" maxlength="255" placeholder="VD: thưởng Black Friday, đi trễ 3 lần" /></label>
        </div>
        <template #footer>
            <Button variant="outline" @click="adjOpen = false">Huỷ</Button>
            <Button :loading="busy" data-testid="adjust-save" @click="saveAdjust">Lưu</Button>
        </template>
    </Dialog>

    <Dialog v-model:open="advOpen" title="Tạm ứng lương" description="Ghi một phiếu chi vào Sổ quỹ và tự trừ vào lương tháng này.">
        <div class="space-y-3 text-sm">
            <label class="block"><span class="mb-1 block font-medium">Nhân viên</span>
                <Select v-model="advForm.member" :options="[{ value: '', label: 'Chọn nhân viên' }, ...members.map((m) => ({ value: m.id, label: m.name }))]" />
            </label>
            <div class="grid grid-cols-2 gap-3">
                <label class="block"><span class="mb-1 block font-medium">Số tiền (đ)</span><Input v-model.number="advForm.amount" type="number" min="1000" step="100000" /></label>
                <label class="block"><span class="mb-1 block font-medium">Chi bằng</span><Select v-model="advForm.method" :options="[{ value: 'cash', label: 'Tiền mặt' }, { value: 'bank_transfer', label: 'Chuyển khoản' }]" /></label>
            </div>
            <label class="block"><span class="mb-1 block font-medium">Ghi chú</span><Input v-model="advForm.note" maxlength="255" /></label>
        </div>
        <template #footer>
            <Button variant="outline" @click="advOpen = false">Huỷ</Button>
            <Button :loading="busy" :disabled="!advForm.member || advForm.amount < 1000" data-testid="advance-save" @click="saveAdvance">Ghi phiếu chi</Button>
        </template>
    </Dialog>

    <Dialog v-model:open="payOpen" title="Chi lương ra sổ quỹ" :description="`Ghi ${data?.lines.filter((l) => l.net > 0).length ?? 0} phiếu chi 'Lương nhân viên', tổng ${formatMoney(data?.totals.net ?? 0)}.`">
        <div class="grid grid-cols-2 gap-3 text-sm">
            <label class="block"><span class="mb-1 block font-medium">Chi bằng</span><Select v-model="payForm.method" :options="[{ value: 'bank_transfer', label: 'Chuyển khoản' }, { value: 'cash', label: 'Tiền mặt' }]" /></label>
            <label v-if="payForm.method === 'bank_transfer'" class="block"><span class="mb-1 block font-medium">Tài khoản chi</span><Input v-model="payForm.account" maxlength="40" placeholder="VD: VCB 0123" /></label>
        </div>
        <template #footer>
            <Button variant="outline" @click="payOpen = false">Huỷ</Button>
            <Button :loading="busy" data-testid="pay-confirm" @click="pay">Ghi phiếu chi</Button>
        </template>
    </Dialog>

    <Dialog v-model:open="profOpen" title="Hồ sơ lương" size="lg">
        <div class="space-y-4 text-sm">
            <label class="block"><span class="mb-1 block font-medium">Nhân viên</span>
                <Select :model-value="profMember" :options="members.map((m) => ({ value: m.id, label: `${m.name}${m.profile.set ? '' : ' (chưa có hồ sơ)'}` }))" @update:model-value="pickMember(String($event))" />
            </label>
            <div class="grid gap-3 sm:grid-cols-3">
                <label class="block"><span class="mb-1 block font-medium">Chức danh</span><Input v-model="prof.title" maxlength="100" placeholder="VD: Thu ngân" /></label>
                <label class="block"><span class="mb-1 block font-medium">Trả lương</span><Select v-model="prof.pay_type" :options="[{ value: 'month', label: 'Theo tháng (theo công)' }, { value: 'hour', label: 'Theo giờ' }]" /></label>
                <label v-if="prof.pay_type === 'month'" class="block"><span class="mb-1 block font-medium">Lương cơ bản (đ)</span><Input v-model.number="prof.base_salary" type="number" min="0" step="100000" /></label>
                <label v-else class="block"><span class="mb-1 block font-medium">Lương mỗi giờ (đ)</span><Input v-model.number="prof.hourly_rate" type="number" min="0" step="1000" /></label>
                <label v-if="prof.pay_type === 'month'" class="block"><span class="mb-1 block font-medium">Số công chuẩn / tháng</span><Input v-model.number="prof.standard_days" type="number" min="1" max="31" /></label>
                <label class="block"><span class="mb-1 block font-medium">Phụ cấp / tháng (đ)</span><Input v-model.number="prof.allowance" type="number" min="0" step="100000" /></label>
                <label class="block"><span class="mb-1 block font-medium">Mục tiêu doanh số (đ)</span><Input v-model.number="prof.sales_target" type="number" min="0" step="1000000" /></label>
                <label class="block"><span class="mb-1 block font-medium">Lương đóng bảo hiểm (đ)</span><Input v-model.number="prof.insured_salary" type="number" min="0" step="100000" /><span class="mt-1 block text-xs text-muted-foreground">Để 0 nếu không đóng bảo hiểm</span></label>
                <label class="block"><span class="mb-1 block font-medium">Người phụ thuộc</span><Input v-model.number="prof.dependents" type="number" min="0" max="20" /></label>
                <div class="grid grid-cols-2 gap-2">
                    <label class="block"><span class="mb-1 block font-medium">Vào ca</span><Input v-model="prof.shift_start" type="time" /></label>
                    <label class="block"><span class="mb-1 block font-medium">Tan ca</span><Input v-model="prof.shift_end" type="time" /></label>
                </div>
            </div>
            <p class="rounded-lg bg-muted/50 p-3 text-xs text-muted-foreground">Giờ vào ca dùng để tính đi trễ (quá 10 phút). Lương theo tháng = lương cơ bản × số công / số công chuẩn.</p>
        </div>
        <template #footer>
            <Button variant="outline" @click="profOpen = false">Huỷ</Button>
            <Button :loading="busy" :disabled="!profMember" data-testid="profile-save" @click="saveProfile">Lưu hồ sơ</Button>
        </template>
    </Dialog>
</template>
