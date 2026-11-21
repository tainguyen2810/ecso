<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Clock, LogIn, LogOut, MapPin } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';
import MonthSwitch from './MonthSwitch.vue';
import { cellClass, cellText, monthLabel, thisMonth, type AttendanceMonth, type AttendanceRow, type DayCell } from './staff';

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
const fmtHours = (h: number) => String(Math.round(h * 10) / 10).replace('.', ',');

// A day set by hand.
const editing = ref<{ row: AttendanceRow; day: number; cell: DayCell | undefined } | null>(null);
const form = ref({ status: 'work', check_in: '08:00', check_out: '17:00', excuse_late: false, note: '' });
const saving = ref(false);
function openDay(row: AttendanceRow, day: number) {
    if (!data.value?.manage) return;
    const cell = row.days[String(day)];
    const [start, end] = row.shift.split('–');
    editing.value = { row, day, cell };
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
</script>

<template>
    <PageHeader :title="`Chấm công ${monthLabel(month)}`" description="Giờ công lấy từ ca bán hàng (mở ca, đóng ca) và nút Vào ca / Tan ca trên điện thoại.">
        <MonthSwitch v-model="month" />
    </PageHeader>

    <Card v-if="me" class="mb-4 flex flex-wrap items-center gap-4 p-4" data-testid="attendance-me">
        <span class="grid size-11 place-items-center rounded-full bg-primary/10 text-primary"><Clock class="size-5" /></span>
        <div class="min-w-0 flex-1 text-sm">
            <div class="font-semibold">Chấm công của tôi hôm nay</div>
            <div class="text-muted-foreground">
                <template v-if="me.open_shift">Bạn đang mở ca bán hàng {{ me.open_shift }}, giờ công tính theo ca.</template>
                <template v-else-if="me.manual">Quản lý đã chấm công cho bạn hôm nay.</template>
                <template v-else-if="me.check_in">Vào ca lúc {{ me.check_in }}<template v-if="me.check_out"> · tan ca lúc {{ me.check_out }}</template></template>
                <template v-else>Chưa vào ca. Bấm Vào ca khi bắt đầu làm (bật vị trí để ghi lại nơi chấm).</template>
            </div>
        </div>
        <template v-if="!me.open_shift && !me.manual">
            <Button v-if="!me.check_in" :loading="checking" data-testid="check-in" @click="check('in')"><LogIn /> Vào ca</Button>
            <Button v-else-if="!me.check_out" variant="outline" :loading="checking" data-testid="check-out" @click="check('out')"><LogOut /> Tan ca</Button>
        </template>
    </Card>

    <div v-if="loading && !data" class="space-y-3"><Skeleton class="h-64" /></div>
    <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
    <Card v-else-if="data" class="min-w-0">
        <div class="overflow-x-auto">
            <table class="w-full border-separate border-spacing-0 text-sm" data-testid="attendance-grid">
                <thead class="text-xs text-muted-foreground">
                    <tr>
                        <th class="sticky left-0 z-10 min-w-40 border-b bg-card px-4 py-2 text-left font-medium">Nhân viên</th>
                        <th v-for="d in dayNumbers" :key="d" class="border-b px-0.5 py-2 text-center font-medium tabular-nums" :class="[data.weekends.includes(d) && 'text-rose-500', data.today === d && 'text-primary']">{{ d }}</th>
                        <th class="border-b px-3 py-2 text-right font-medium">Công</th>
                        <th class="border-b px-3 py-2 text-right font-medium">Giờ</th>
                        <th class="border-b px-4 py-2 text-right font-medium">Muộn</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in data.members" :key="r.id">
                        <td class="sticky left-0 z-10 border-b bg-card px-4 py-2.5">
                            <div class="font-medium">{{ r.name }}</div>
                            <div class="text-xs text-muted-foreground">{{ r.title }} · {{ r.shift }}</div>
                        </td>
                        <td v-for="d in dayNumbers" :key="d" class="border-b px-0.5 py-2.5 text-center">
                            <button
                                type="button"
                                :class="['grid h-8 w-7 place-items-center rounded text-xs font-medium tabular-nums', r.days[String(d)] ? cellClass(r.days[String(d)]) : data.today !== null && d > data.today ? 'border border-dashed border-muted' : 'bg-muted/40', data.manage ? 'hover:ring-2 hover:ring-primary/40' : 'cursor-default']"
                                :title="r.days[String(d)] ? `${r.days[String(d)]!.in ?? ''}–${r.days[String(d)]!.out ?? ''}${r.days[String(d)]!.late ? ` · muộn ${r.days[String(d)]!.late} phút` : ''}` : ''"
                                @click="openDay(r, d)"
                            >
                                {{ cellText(r.days[String(d)]) }}
                            </button>
                        </td>
                        <td class="border-b px-3 py-2.5 text-right font-semibold tabular-nums">{{ r.work_days }}</td>
                        <td class="border-b px-3 py-2.5 text-right tabular-nums">{{ fmtHours(r.hours) }}</td>
                        <td class="border-b px-4 py-2.5 text-right tabular-nums" :class="r.late > 0 && 'text-amber-700'">{{ r.late }}</td>
                    </tr>
                    <tr v-if="!data.members.length"><td :colspan="data.days + 4" class="px-4 py-8 text-center text-muted-foreground">Chưa có nhân viên.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap gap-x-5 gap-y-1 border-t px-4 py-3 text-xs text-muted-foreground">
            <span class="flex items-center gap-1.5"><span class="size-3 rounded bg-emerald-100" /> Đủ ca (số giờ)</span>
            <span class="flex items-center gap-1.5"><span class="size-3 rounded bg-amber-200" /> Đi muộn</span>
            <span class="flex items-center gap-1.5"><span class="size-3 rounded bg-sky-100" /> P: nghỉ có phép</span>
            <span class="flex items-center gap-1.5"><span class="size-3 rounded bg-rose-100" /> N: nghỉ không lương</span>
            <span class="flex items-center gap-1.5"><span class="size-3 rounded bg-orange-100" /> ?: quên tan ca</span>
            <span v-if="data.manage" class="ml-auto">Bấm vào một ô để sửa ngày công.</span>
        </div>
    </Card>

    <Dialog :open="!!editing" :title="editing ? `Ngày ${editing.day}/${month.slice(5)} · ${editing.row.name}` : ''" size="md" @update:open="(v: boolean) => !v && (editing = null)">
        <div v-if="editing" class="space-y-4 text-sm">
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
