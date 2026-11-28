<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { AlarmClock, CalendarCheck, Clock, Download, Info, LogIn, LogOut, MapPin, Search, TriangleAlert, UserCheck, UserPen } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';
import { cn, formatNumber } from '@/shared/lib/utils';
import ReportChip from '../reports/ReportChip.vue';
import ReportKpi from '../reports/ReportKpi.vue';
import { downloadCsv, toCsv } from '../reports/range';
import FrameButton from './FrameButton.vue';
import StaffFrame from './StaffFrame.vue';
import { attendanceSummary, cellClass, cellText, monthLabel, thisMonth, WEEKDAYS, type AttendanceMonth, type AttendanceRow, type DayCell } from './staff';

/**
 * Nhân viên › Chấm công: one row per member, one cell per day. Hours come from the counter's cash shifts and the
 * phone's Vào ca / Tan ca; a manager clicks a cell to set it by hand. A member sees only their own row.
 */
const route = useRoute();
const router = useRouter();
const toast = useToast();
const month = ref(typeof route.query.month === 'string' && /^\d{4}-\d{2}$/.test(route.query.month) ? route.query.month : thisMonth());
const data = ref<AttendanceMonth | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const me = ref<{ check_in: string | null; check_out: string | null; manual: boolean; open_shift: string | null } | null>(null);
const checking = ref(false);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        data.value = (await api.get('/staff/attendance', { params: { month: month.value } })).data.data;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
async function loadMe() {
    me.value = (await api.get('/staff/attendance/me').catch(() => null))?.data?.data ?? null;
}
watch(month, (m) => {
    router.replace({ query: { month: m } });
    load();
});
onMounted(() => {
    load();
    loadMe();
});

/** Vào ca / Tan ca, with the phone's position when the browser allows it (not required). */
async function check(action: 'in' | 'out') {
    checking.value = true;
    try {
        const pos = await new Promise<GeolocationPosition | null>((resolve) => {
            if (!navigator.geolocation) return resolve(null);
            navigator.geolocation.getCurrentPosition(resolve, () => resolve(null), { timeout: 5000, maximumAge: 60000 });
        });
        await api.post('/staff/attendance/check', { action, lat: pos?.coords.latitude ?? null, lng: pos?.coords.longitude ?? null });
        toast.success(action === 'in' ? 'Đã vào ca.' : 'Đã tan ca.');
        await Promise.all([loadMe(), load()]);
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        checking.value = false;
    }
}

const dayNumbers = computed(() => Array.from({ length: data.value?.days ?? 0 }, (_, i) => i + 1));
const initials = (name: string) => name.trim().split(/\s+/).slice(-2).map((w) => w[0]).join('').toUpperCase();
const fmtHours = (h: number) => String(Math.round(h * 10) / 10).replace('.', ',');

// A day set by hand.
const editing = ref<{ row: AttendanceRow; day: number; cell: DayCell | undefined; pick: boolean } | null>(null);
const form = ref({ status: 'work', check_in: '08:00', check_out: '17:00', excuse_late: false, note: '' });
const saving = ref(false);
function openDay(row: AttendanceRow, day: number, pick = false) {
    if (!data.value?.manage) return;
    const cell = row.days[String(day)];
    const [start, end] = row.shift.split('–');
    editing.value = { row, day, cell, pick };
    form.value = {
        status: cell?.status === 'leave' || cell?.status === 'off' ? cell.status : 'work',
        check_in: cell?.in ?? start ?? '08:00',
        check_out: cell?.out ?? end ?? '17:00',
        excuse_late: false,
        note: cell?.note ?? '',
    };
}
const dayIso = computed(() => (editing.value ? `${month.value}-${String(editing.value.day).padStart(2, '0')}` : ''));
async function saveDay(status?: string) {
    if (!editing.value) return;
    saving.value = true;
    try {
        await api.put(`/staff/attendance/${editing.value.row.id}/${dayIso.value}`, { ...form.value, status: status ?? form.value.status });
        toast.success('Đã lưu ngày công.');
        editing.value = null;
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        saving.value = false;
    }
}
const sourceLabel = { shift: 'Ca bán hàng', phone: 'Chấm trên điện thoại', manual: 'Quản lý đặt tay' } as const;

// Headline figures and the grid's options.
const summary = computed(() => (data.value ? attendanceSummary(data.value) : null));
const show = ref<'hours' | 'in'>('hours');
const search = ref('');
const rows = computed(() => {
    const q = search.value.trim().toLowerCase();
    return (data.value?.members ?? []).filter((r) => !q || r.name.toLowerCase().includes(q));
});
const weekday = (d: number) => WEEKDAYS[new Date(Number(month.value.slice(0, 4)), Number(month.value.slice(5)) - 1, d).getDay()];
const label = (c: DayCell | undefined) => (show.value === 'in' && c?.in && (c.status === 'work' || c.status === 'late' || c.status === 'open' || c.status === 'no_out') ? c.in : cellText(c));

/** Minutes since check-in, while the member is on shift. */
const workedText = computed(() => {
    const m = me.value;
    if (!m?.check_in || m.check_out) return null;
    const [h, mi] = m.check_in.split(':').map(Number) as [number, number];
    const now = new Date();
    const mins = Math.max(0, now.getHours() * 60 + now.getMinutes() - (h * 60 + mi));
    return `${Math.floor(mins / 60)} giờ ${mins % 60} phút`;
});

/** Chấm công hộ: the day dialog for a member and day picked in it (today by default). */
function checkFor() {
    const r = data.value?.members[0];
    if (r) openDay(r, data.value?.today ?? 1, true);
}
function pickMember(id: string) {
    const r = data.value?.members.find((m) => m.id === id);
    if (r && editing.value) openDay(r, editing.value.day, true);
}
function pickDay(day: number) {
    if (editing.value) openDay(editing.value.row, day, true);
}

/** Bảng chấm công as CSV: one row per member, one column per day, then work days, hours and minutes late. */
function exportCsv() {
    if (!data.value) return;
    const days = dayNumbers.value;
    downloadCsv(
        `cham-cong-${month.value}.csv`,
        toCsv(
            ['Nhân viên', 'Chức danh', 'Ca', ...days.map(String), 'Công', 'Giờ', 'Phút muộn'],
            data.value.members.map((r) => [r.name, r.title, r.shift, ...days.map((d) => cellText(r.days[String(d)])), r.work_days, r.hours, r.late]),
        ),
    );
}
</script>

<template>
    <StaffFrame v-model:month="month" page="attendance" title="Chấm công" :description="data?.manage === false ? 'Giờ công của bạn theo ngày, lấy từ ca bán hàng và nút Vào ca / Tan ca.' : 'Giờ công của từng nhân viên theo ngày. Nhân viên chỉ thấy dòng của mình.'">
        <template #actions>
            <FrameButton v-if="data?.members.length" data-testid="attendance-export" @click="exportCsv"><Download class="text-slate-500" /> Xuất Excel</FrameButton>
            <FrameButton v-if="data?.manage && data.members.length" primary data-testid="attendance-for" @click="checkFor"><UserPen /> Chấm công hộ</FrameButton>
        </template>
    </StaffFrame>

    <div v-if="loading && !data" class="space-y-5"><div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4"><Skeleton v-for="i in 4" :key="i" class="h-36" /></div><Skeleton class="h-72" /></div>
    <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
    <template v-else-if="data && summary">
        <div v-if="data.manage" class="mb-5 grid gap-5 sm:grid-cols-2 xl:grid-cols-4" data-testid="attendance-kpis">
            <ReportKpi v-if="data.today !== null" primary label="Đi làm hôm nay" :icon="UserCheck" :value="null" :text="`${summary.workingToday}/${summary.members}`" tip="Nhân viên có giờ công hôm nay (đang trong ca, đã tan ca hoặc quên tan ca). Nghỉ có phép và nghỉ không lương không tính.">
                <template #chips><ReportChip tone="onblue">{{ summary.onShift }} người đang trong ca</ReportChip></template>
                {{ summary.awayToday.length ? `Nghỉ hôm nay: ${summary.awayToday.join(', ')}` : 'Không ai nghỉ hôm nay' }}
            </ReportKpi>
            <ReportKpi v-else primary label="Nhân viên" :icon="UserCheck" :value="summary.members" unit="người">{{ monthLabel(month) }} đã qua</ReportKpi>
            <ReportKpi label="Tổng công tháng" :icon="CalendarCheck" tone="green" :value="summary.workDays" unit="công" tip="Cộng số công của mọi nhân viên trong tháng. Nghỉ có phép vẫn tính công.">
                <template #chips><ReportChip>{{ formatNumber(summary.hours) }} giờ làm việc</ReportChip></template>
                Trung bình {{ summary.members ? formatNumber(Math.round((summary.workDays / summary.members) * 10) / 10) : 0 }} công mỗi người
            </ReportKpi>
            <ReportKpi label="Đi muộn" :icon="AlarmClock" tone="amber" :value="summary.late" unit="lần" tip="Số ngày vào ca muộn quá 10 phút so với giờ vào ca trong Hồ sơ lương. Bỏ đi muộn trong ô ngày công nếu có lý do.">
                <template #chips><ReportChip :tone="summary.lateMembers ? 'amber' : 'plain'">{{ summary.lateMembers }} nhân viên</ReportChip></template>
                Muộn quá 10 phút so với giờ vào ca
            </ReportKpi>
            <ReportKpi label="Cần xem lại" :icon="TriangleAlert" :tone="summary.noOut ? 'red' : 'slate'" :value="summary.noOut" unit="ô" tip="Ngày nhân viên vào ca nhưng quên tan ca. Bấm vào ô có dấu ? để đặt giờ ra trước khi duyệt bảng lương.">
                <template #chips><ReportChip :tone="summary.noOut ? 'red' : 'plain'">{{ summary.noOut }} quên tan ca</ReportChip><ReportChip v-if="summary.unpaid">{{ summary.unpaid }} ngày nghỉ không lương</ReportChip></template>
                Sửa trước khi duyệt bảng lương
            </ReportKpi>
        </div>

        <section v-if="me" class="mb-5 flex flex-wrap items-center gap-x-5 gap-y-3 rounded-[14px] border border-slate-200/80 bg-white px-5 py-4 shadow-[0_1px_2px_rgba(15,23,42,0.04)] sm:px-6" data-testid="attendance-me">
            <span class="grid size-12 shrink-0 place-items-center rounded-[12px] bg-emerald-50 text-emerald-600"><Clock class="size-6" /></span>
            <div class="min-w-0 flex-1">
                <p class="text-[16px] font-semibold text-slate-900">Chấm công của tôi hôm nay</p>
                <p class="mt-0.5 text-[14px] text-slate-500">
                    <template v-if="me.open_shift">Bạn đang mở ca bán hàng {{ me.open_shift }}, giờ công tính theo ca.</template>
                    <template v-else-if="me.manual">Quản lý đã chấm công cho bạn hôm nay.</template>
                    <template v-else-if="me.check_in">Vào ca lúc <b class="font-semibold text-slate-800">{{ me.check_in }}</b><template v-if="me.check_out"> · tan ca lúc <b class="font-semibold text-slate-800">{{ me.check_out }}</b></template></template>
                    <template v-else>Chưa vào ca. Bấm Vào ca khi bắt đầu làm (bật vị trí để ghi lại nơi chấm).</template>
                </p>
            </div>
            <div v-if="workedText" class="border-slate-100 text-[13px] text-slate-500 sm:border-l sm:pl-8">
                <p>Đã làm</p>
                <p class="mt-0.5 text-[18px] font-bold tabular-nums text-slate-900">{{ workedText }}</p>
            </div>
            <template v-if="!me.open_shift && !me.manual">
                <FrameButton v-if="!me.check_in" primary :loading="checking" data-testid="check-in" @click="check('in')"><LogIn /> Vào ca</FrameButton>
                <FrameButton v-else-if="!me.check_out" :loading="checking" data-testid="check-out" @click="check('out')"><LogOut /> Tan ca</FrameButton>
            </template>
        </section>

        <section class="min-w-0 overflow-hidden rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4 sm:px-6">
                <div>
                    <h2 class="text-[16px] font-semibold text-slate-900">Bảng chấm công {{ monthLabel(month).toLowerCase() }}</h2>
                    <p class="mt-0.5 text-[13px] text-slate-500">{{ data.manage ? 'Bấm vào một ô để xem giờ vào, giờ ra hoặc sửa ngày công.' : 'Rê chuột vào một ô để xem giờ vào, giờ ra.' }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <label v-if="data.members.length > 1" class="flex h-10 w-64 items-center gap-2.5 rounded-[10px] border border-slate-200 bg-white px-3.5 text-[14px] focus-within:border-blue-400">
                        <Search class="size-4 text-slate-400" />
                        <input v-model="search" type="search" placeholder="Tìm nhân viên" class="min-w-0 flex-1 bg-transparent outline-none placeholder:text-slate-400" />
                    </label>
                    <div class="flex rounded-[12px] bg-slate-100 p-1 text-[14px]" role="group" aria-label="Hiện trong ô">
                        <button type="button" :class="cn('rounded-[9px] px-3.5 py-1.5 text-slate-500', show === 'hours' && 'bg-white font-semibold text-slate-900 shadow-sm')" @click="show = 'hours'">Số giờ</button>
                        <button type="button" :class="cn('rounded-[9px] px-3.5 py-1.5 text-slate-500', show === 'in' && 'bg-white font-semibold text-slate-900 shadow-sm')" data-testid="show-in" @click="show = 'in'">Giờ vào</button>
                    </div>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1180px] table-fixed" data-testid="attendance-grid">
                    <colgroup><col class="w-[230px]" /><col v-for="d in dayNumbers" :key="d" /><col class="w-[64px]" /><col class="w-[72px]" /><col class="w-[84px]" /></colgroup>
                    <thead class="border-b border-slate-100 bg-slate-50/60">
                        <tr>
                            <th class="sticky left-0 z-10 bg-slate-50 py-2.5 pl-5 text-left text-[12px] font-semibold uppercase tracking-wider text-slate-400 sm:pl-6">Nhân viên</th>
                            <th v-for="d in dayNumbers" :key="d" :class="cn('px-0 py-2.5 text-center font-medium', data.today === d && 'bg-blue-50/70')">
                                <div :class="cn('text-[12px] tabular-nums', data.weekends.includes(d) ? 'text-rose-500' : 'text-slate-500', data.today === d && 'font-bold !text-blue-700')">{{ d }}</div>
                                <div :class="cn('text-[10px] font-normal', data.weekends.includes(d) ? 'text-rose-400' : 'text-slate-400')">{{ weekday(d) }}</div>
                            </th>
                            <th class="px-3 text-right text-[12px] font-semibold uppercase tracking-wider text-slate-400">Công</th>
                            <th class="px-3 text-right text-[12px] font-semibold uppercase tracking-wider text-slate-400">Giờ</th>
                            <th class="pl-3 pr-5 text-right text-[12px] font-semibold uppercase tracking-wider text-slate-400 sm:pr-6">Muộn</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.id" class="border-b border-slate-100 last:border-0">
                            <td class="sticky left-0 z-10 bg-white py-2 pl-5 pr-3 sm:pl-6">
                                <div class="flex items-center gap-3">
                                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-blue-50 text-[12px] font-semibold text-blue-600">{{ initials(r.name) }}</span>
                                    <div class="min-w-0">
                                        <p class="truncate text-[14px] font-medium text-slate-900">{{ r.name }}</p>
                                        <p class="truncate text-[12px] text-slate-500">{{ r.title }} · {{ r.shift }}</p>
                                    </div>
                                </div>
                            </td>
                            <td v-for="d in dayNumbers" :key="d" :class="cn('px-[1px] py-2', data.today === d && 'bg-blue-50/60')">
                                <button
                                    type="button"
                                    :class="
                                        cn(
                                            'mx-auto grid h-8 w-full max-w-[34px] place-items-center rounded-[7px] font-semibold tabular-nums',
                                            show === 'in' ? 'text-[9px]' : 'text-[11px]',
                                            r.days[String(d)] ? cellClass(r.days[String(d)]) : data.today !== null && d > data.today ? 'border border-dashed border-slate-200' : 'bg-slate-50',
                                            data.manage ? 'hover:ring-2 hover:ring-blue-400/50' : 'cursor-default',
                                        )
                                    "
                                    :title="r.days[String(d)] ? `${r.days[String(d)]!.in ?? ''}–${r.days[String(d)]!.out ?? ''}${r.days[String(d)]!.late ? ` · muộn ${r.days[String(d)]!.late} phút` : ''}${r.days[String(d)]!.note ? ` · ${r.days[String(d)]!.note}` : ''}` : ''"
                                    :aria-label="`Ngày ${d}, ${r.name}`"
                                    @click="openDay(r, d)"
                                >
                                    {{ label(r.days[String(d)]) }}
                                </button>
                            </td>
                            <td class="px-3 text-right text-[15px] font-semibold tabular-nums text-slate-900">{{ r.work_days }}</td>
                            <td class="px-3 text-right text-[14px] tabular-nums text-slate-600">{{ fmtHours(r.hours) }}</td>
                            <td :class="cn('pl-3 pr-5 text-right text-[14px] tabular-nums sm:pr-6', r.late > 0 ? 'font-medium text-amber-600' : 'text-slate-400')">{{ r.late || '—' }}</td>
                        </tr>
                        <tr v-if="!rows.length"><td :colspan="data.days + 4" class="px-6 py-10 text-center text-[14px] text-slate-500">{{ data.members.length ? 'Không có nhân viên nào khớp.' : 'Chưa có nhân viên.' }}</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-slate-100 px-5 py-3.5 text-[13px] text-slate-500 sm:px-6">
                <span class="flex items-center gap-2"><span class="size-3.5 rounded bg-emerald-100" />Đủ ca (số giờ)</span>
                <span class="flex items-center gap-2"><span class="size-3.5 rounded bg-amber-200" />Đi muộn</span>
                <span class="flex items-center gap-2"><span class="grid size-3.5 place-items-center rounded bg-sky-100 text-[9px] font-bold text-sky-700">P</span>Nghỉ có phép</span>
                <span class="flex items-center gap-2"><span class="grid size-3.5 place-items-center rounded bg-rose-100 text-[9px] font-bold text-rose-700">N</span>Nghỉ không lương</span>
                <span class="flex items-center gap-2"><span class="grid size-3.5 place-items-center rounded bg-orange-100 text-[9px] font-bold text-orange-700">?</span>Quên tan ca</span>
                <span class="flex items-center gap-2"><span class="size-3.5 rounded bg-blue-600" />Đang trong ca</span>
                <span class="flex items-center gap-1.5 xl:ml-auto"><Info class="size-3.5" /> Giờ công lấy từ ca bán hàng (mở ca, đóng ca) và nút Vào ca / Tan ca trên điện thoại</span>
            </div>
        </section>
    </template>
    <Dialog :open="!!editing" :title="editing ? (editing.pick ? 'Chấm công hộ' : `Ngày ${editing.day}/${month.slice(5)} · ${editing.row.name}`) : ''" size="md" @update:open="(v: boolean) => !v && (editing = null)">
        <div v-if="editing" class="space-y-4 text-sm">
            <div v-if="editing.pick" class="grid grid-cols-[minmax(0,1fr)_7rem] gap-3">
                <label class="block"><span class="mb-1 block font-medium">Nhân viên</span>
                    <Select :model-value="editing.row.id" :options="(data?.members ?? []).map((m) => ({ value: m.id, label: m.name }))" data-testid="for-member" @update:model-value="pickMember(String($event))" />
                </label>
                <label class="block"><span class="mb-1 block font-medium">Ngày</span>
                    <Select :model-value="String(editing.day)" :options="dayNumbers.filter((d) => data?.today === null || d <= (data?.today ?? 31)).map((d) => ({ value: String(d), label: `${d}/${month.slice(5)}` }))" @update:model-value="pickDay(Number($event))" />
                </label>
            </div>
            <dl v-if="editing.cell" class="space-y-1.5 rounded-lg bg-muted/40 p-3">
                <div class="flex justify-between"><dt class="text-muted-foreground">Ca xếp</dt><dd>{{ editing.row.shift }}</dd></div>
                <div class="flex justify-between"><dt class="text-muted-foreground">Vào · ra</dt><dd :class="editing.cell.late > 0 && 'text-amber-700'">{{ editing.cell.in ?? '—' }} – {{ editing.cell.out ?? '—' }}<template v-if="editing.cell.late"> (muộn {{ editing.cell.late }} phút)</template></dd></div>
                <div class="flex justify-between"><dt class="text-muted-foreground">Nguồn</dt><dd class="flex items-center gap-1">{{ sourceLabel[editing.cell.source] }}<template v-if="editing.cell.shifts.length"> · {{ editing.cell.shifts.join(', ') }}</template><MapPin v-if="editing.cell.located" class="size-3.5 text-emerald-600" /></dd></div>
            </dl>
            <label class="block"><span class="mb-1 block font-medium">Ngày này</span>
                <Select v-model="form.status" :options="[{ value: 'work', label: 'Đi làm' }, { value: 'leave', label: 'Nghỉ có phép (vẫn tính công)' }, { value: 'off', label: 'Nghỉ không lương' }]" />
            </label>
            <div v-if="form.status === 'work'" class="grid grid-cols-2 gap-3">
                <label class="block"><span class="mb-1 block font-medium">Giờ vào</span><Input v-model="form.check_in" type="time" /></label>
                <label class="block"><span class="mb-1 block font-medium">Giờ ra</span><Input v-model="form.check_out" type="time" /></label>
                <label class="col-span-2 flex items-center gap-2"><input v-model="form.excuse_late" type="checkbox" class="size-4" /> Bỏ đi muộn (có lý do)</label>
            </div>
            <label class="block"><span class="mb-1 block font-medium">Ghi chú</span><Input v-model="form.note" maxlength="255" placeholder="VD: đi giao hàng cho khách" /></label>
        </div>
        <template #footer>
            <Button v-if="editing?.cell?.source === 'manual'" variant="ghost" :loading="saving" @click="saveDay('clear')">Bỏ sửa tay</Button>
            <Button variant="outline" @click="editing = null">Huỷ</Button>
            <Button :loading="saving" data-testid="day-save" @click="saveDay()">Lưu</Button>
        </template>
    </Dialog>
</template>
