<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Banknote, CheckCircle2, HandCoins, Landmark, Lock, Pencil, ShieldCheck, Undo2, UserPlus, Wallet } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import KpiCard from '@/shared/ui/KpiCard.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useIdempotencyKey } from '@/shared/composables/useIdempotencyKey';
import MonthSwitch from './MonthSwitch.vue';
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
const STATUS = { draft: { label: 'Nháp, tự cập nhật', tone: 'warning' }, approved: { label: 'Đã duyệt', tone: 'info' }, paid: { label: 'Đã chi lương', tone: 'success' } } as const;
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
</script>

<template>
    <PageHeader title="Bảng lương" description="Lương theo công + hoa hồng + phụ cấp, trừ tạm ứng, bảo hiểm và thuế TNCN. Số liệu lấy từ Chấm công và Hoa hồng.">
        <MonthSwitch v-model="month" />
        <template v-if="data?.manage">
            <Button variant="outline" data-testid="advance-open" @click="openAdvance()"><HandCoins /> Tạm ứng</Button>
            <Button v-if="draft" :loading="busy" :disabled="!data.lines.length" data-testid="payroll-approve" @click="run('approve')"><CheckCircle2 /> Duyệt bảng lương</Button>
            <template v-else-if="data.status === 'approved'">
                <Button variant="outline" :loading="busy" @click="run('reopen')"><Undo2 /> Mở lại</Button>
                <Button data-testid="payroll-pay" @click="payOpen = true"><Banknote /> Chi lương ra sổ quỹ</Button>
            </template>
        </template>
    </PageHeader>

    <div v-if="loading && !data" class="space-y-3"><Skeleton class="h-24" /><Skeleton class="h-64" /></div>
    <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
    <template v-else-if="data">
        <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
            <Badge :tone="STATUS[data.status].tone" data-testid="payroll-status">{{ STATUS[data.status].label }}</Badge>
            <span v-if="data.status === 'approved'" class="text-muted-foreground">Duyệt ngày {{ dateText(data.approved_at) }} · số liệu đã chốt, chấm công sửa sau không đổi bảng này</span>
            <span v-else-if="data.status === 'paid'" class="text-muted-foreground">Chi ngày {{ dateText(data.paid_at) }} · phiếu chi "Lương nhân viên" trong Sổ quỹ</span>
            <span v-else-if="data.manage" class="text-muted-foreground">Bấm Duyệt khi đã kiểm tra xong, nhân viên mới thấy phiếu lương</span>
        </div>

        <!-- A member: their own payslip only. -->
        <template v-if="!data.manage">
            <Card v-if="!data.lines.length" class="px-6 py-12 text-center text-sm text-muted-foreground">
                <Lock class="mx-auto mb-2 size-6" />Phiếu lương {{ monthLabel(month).toLowerCase() }} sẽ hiện ở đây khi chủ cửa hàng duyệt bảng lương.
            </Card>
            <Card v-for="l in data.lines" :key="l.id" class="mx-auto max-w-xl" data-testid="payslip">
                <div class="border-b px-5 py-4">
                    <div class="text-xs text-muted-foreground">Phiếu lương {{ monthLabel(month).toLowerCase() }}</div>
                    <div class="text-lg font-semibold">{{ l.name }} <span class="text-sm font-normal text-muted-foreground">· {{ l.title }}</span></div>
                </div>
                <ul class="divide-y text-sm">
                    <li v-for="(d, i) in l.detail" :key="i" class="flex justify-between gap-3 px-5 py-2.5"><span>{{ d.label }}</span><span :class="['shrink-0 whitespace-nowrap tabular-nums', d.amount < 0 && 'text-rose-700']">{{ formatMoney(d.amount) }}</span></li>
                </ul>
                <div class="flex items-baseline justify-between border-t bg-emerald-50 px-5 py-4"><span class="font-semibold">Thực nhận</span><span class="text-xl font-bold tabular-nums text-emerald-700">{{ formatMoney(l.net) }}</span></div>
            </Card>
        </template>

        <template v-else>
            <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <KpiCard label="Tổng thực nhận" :value="formatMoney(data.totals.net)" :icon="Wallet" tone="green">{{ data.totals.count }} nhân viên</KpiCard>
                <KpiCard label="Hoa hồng" :value="formatMoney(data.totals.commission)" :icon="HandCoins" tone="blue" />
                <KpiCard label="Bảo hiểm" :value="formatMoney(data.totals.insurance + data.totals.employer_insurance)" :icon="ShieldCheck" tone="amber">NV {{ formatNumber(data.totals.insurance) }} · cửa hàng {{ formatNumber(data.totals.employer_insurance) }}</KpiCard>
                <KpiCard label="Thuế TNCN khấu trừ" :value="formatMoney(data.totals.pit)" :icon="Landmark" tone="red" />
            </div>

            <div class="grid grid-cols-[minmax(0,1fr)] items-start gap-4 2xl:grid-cols-[minmax(0,1fr)_380px]">
                <Card class="min-w-0 overflow-hidden">
                    <div class="flex items-center justify-between gap-2 border-b px-4 py-3">
                        <span class="font-semibold">Bảng lương {{ monthLabel(month).toLowerCase() }}</span>
                        <Button v-if="draft" size="sm" variant="ghost" data-testid="profile-add" @click="openProfile()"><UserPlus /> Hồ sơ lương</Button>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[860px] text-sm" data-testid="payroll-lines">
                            <thead class="bg-muted/40 text-xs text-muted-foreground">
                                <tr class="[&>th]:px-3 [&>th]:py-2 [&>th]:text-right [&>th]:font-medium">
                                    <th class="!text-left">Nhân viên</th><th>Công</th><th>Lương theo công</th><th>Hoa hồng</th><th>Phụ cấp, thưởng</th><th>Tạm ứng</th><th>Bảo hiểm</th><th>Thuế</th><th>Thực nhận</th><th v-if="draft" />
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="l in data.lines" :key="l.id" :class="['cursor-pointer tabular-nums hover:bg-muted/40 [&>td]:px-3 [&>td]:py-2.5 [&>td]:text-right', selected?.id === l.id && 'bg-primary/5']" @click="selectedId = l.id">
                                    <td class="!text-left"><div class="font-medium">{{ l.name }}</div><div class="text-xs text-muted-foreground">{{ l.title }}</div></td>
                                    <td class="whitespace-nowrap">{{ workText(l) }}</td>
                                    <td>{{ formatNumber(l.base_pay) }}</td>
                                    <td class="text-emerald-700">{{ l.commission ? formatNumber(l.commission) : '—' }}</td>
                                    <td>{{ l.allowance + l.bonus ? formatNumber(l.allowance + l.bonus) : '—' }}</td>
                                    <td class="text-rose-700">{{ l.advance ? `−${formatNumber(l.advance)}` : '—' }}</td>
                                    <td class="text-rose-700">{{ l.insurance ? `−${formatNumber(l.insurance)}` : '—' }}</td>
                                    <td class="text-rose-700">{{ l.pit ? `−${formatNumber(l.pit)}` : '—' }}</td>
                                    <td class="font-semibold">{{ formatNumber(l.net) }}</td>
                                    <td v-if="draft" class="w-10" @click.stop>
                                        <button type="button" class="grid size-8 place-items-center rounded-lg text-muted-foreground hover:bg-muted" title="Thưởng, khấu trừ" @click="openAdjust(l)"><Pencil class="size-4" /></button>
                                    </td>
                                </tr>
                                <tr v-if="!data.lines.length"><td colspan="10" class="px-4 py-10 text-center text-muted-foreground">Chưa có ai trong bảng lương. Bấm Hồ sơ lương để nhập lương cơ bản cho nhân viên.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="border-t px-4 py-3 text-xs text-muted-foreground">
                        Bảo hiểm NV {{ String(data.rules.employee_insurance).replace('.', ',') }}%, cửa hàng đóng thêm {{ String(data.rules.employer_insurance).replace('.', ',') }}%. Giảm trừ bản thân {{ formatMoney(data.rules.personal_deduction) }}, mỗi người phụ thuộc {{ formatMoney(data.rules.dependent_deduction) }}. Số tạm tính, nên đối chiếu với kế toán khi luật thay đổi.
                    </p>
                </Card>

                <Card v-if="selected" class="min-w-0 2xl:max-w-none" data-testid="pay-detail">
                    <div class="flex items-center justify-between gap-2 border-b px-4 py-3">
                        <span class="font-semibold">Cách tính của {{ selected.name }}</span>
                        <Button v-if="draft" size="sm" variant="ghost" @click="openProfile(selected)"><Pencil /> Hồ sơ</Button>
                    </div>
                    <ul class="divide-y text-sm">
                        <li v-for="(d, i) in selected.detail" :key="i" class="flex justify-between gap-3 px-4 py-2.5"><span>{{ d.label }}</span><span :class="['shrink-0 whitespace-nowrap tabular-nums', d.amount < 0 && 'text-rose-700']">{{ formatMoney(d.amount) }}</span></li>
                    </ul>
                    <div class="flex items-baseline justify-between border-t bg-emerald-50 px-4 py-3"><span class="font-semibold">Thực nhận</span><span class="text-lg font-bold tabular-nums text-emerald-700">{{ formatMoney(selected.net) }}</span></div>
                    <p v-if="selected.employer_insurance" class="px-4 py-2.5 text-xs text-muted-foreground">Cửa hàng đóng thêm bảo hiểm {{ formatMoney(selected.employer_insurance) }} (không trừ vào lương).</p>
                    <div v-if="draft" class="flex gap-2 border-t px-4 py-3">
                        <Button size="sm" variant="outline" @click="openAdjust(selected)">Thưởng, khấu trừ</Button>
                        <Button size="sm" variant="outline" @click="openAdvance(selected)">Tạm ứng</Button>
                    </div>
                </Card>
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
