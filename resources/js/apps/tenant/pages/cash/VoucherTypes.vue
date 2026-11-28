<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { ArrowDownLeft, ArrowUpRight, Building2, Check, CircleDashed, Info, Pencil, Plus, ShoppingBag, X } from 'lucide-vue-next';
import SettingsShell from '../settings/SettingsShell.vue';
import Button from '@/shared/ui/Button.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';
import { cn, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import type { Direction, VoucherType } from './voucher';

const tenant = useTenantStore();
const toast = useToast();
const types = ref<VoucherType[] | null>(null);
const loadError = ref<string | null>(null);
const drafts = reactive<Record<Direction, string>>({ receipt: '', payment: '' });
const editing = ref<string | null>(null);
const editName = ref('');
const busy = ref<string | null>(null);
const canEdit = computed(() => tenant.can('finance.vouchers'));
const columns: { direction: Direction; title: string; hint: string; icon: typeof ArrowDownLeft; tone: string }[] = [
    { direction: 'receipt', title: 'Loại phiếu thu', hint: 'Thu nhập khác, bồi thường, thanh lý tài sản…', icon: ArrowDownLeft, tone: 'bg-emerald-50 text-emerald-600' },
    { direction: 'payment', title: 'Loại phiếu chi', hint: 'Quảng cáo, vận chuyển, lương, mặt bằng…', icon: ArrowUpRight, tone: 'bg-pink-50 text-pink-600' },
];

async function load() {
    loadError.value = null;
    try {
        types.value = (await api.get('/cash-voucher-types')).data.data;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    }
}
onMounted(load);

const groupTone: Record<string, string> = { sales: 'bg-blue-50 text-blue-700', operating: 'bg-amber-50 text-amber-700', '': 'bg-slate-100 text-slate-600' };
const groupHelp = [
    { icon: ShoppingBag, tone: 'bg-blue-50 text-blue-600', name: 'Chi phí bán hàng', text: 'Trừ vào lợi nhuận đóng góp: vận chuyển, quảng cáo, hoa hồng.' },
    { icon: Building2, tone: 'bg-amber-50 text-amber-600', name: 'Chi phí vận hành', text: 'Trừ vào lợi nhuận quản trị: mặt bằng, lương, điện nước.' },
    { icon: CircleDashed, tone: 'bg-slate-100 text-slate-600', name: 'Chi phí khác', text: 'Khoản không đều, cũng trừ vào lợi nhuận quản trị.' },
];
const of = (d: Direction) => (types.value ?? []).filter((t) => t.direction === d);

function replace(t: VoucherType) {
    types.value = (types.value ?? []).map((x) => (x.id === t.id ? t : x));
}

async function add(direction: Direction) {
    const name = drafts[direction].trim();
    if (!name) return;
    busy.value = direction;
    try {
        const { data } = await api.post('/cash-voucher-types', { direction, name });
        types.value = [...(types.value ?? []), { ...data.data, vouchers_count: 0 }];
        drafts[direction] = '';
        toast.success(`Đã thêm loại “${name}”.`);
    } catch (e) {
        toast.error((e as ApiError).field('name') ?? (e as ApiError).message);
    } finally {
        busy.value = null;
    }
}

async function update(t: VoucherType, changes: Partial<VoucherType>) {
    busy.value = t.id;
    try {
        replace((await api.patch(`/cash-voucher-types/${t.id}`, changes)).data.data);
        editing.value = null;
    } catch (e) {
        toast.error((e as ApiError).field('name') ?? (e as ApiError).message);
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <SettingsShell wide title="Loại phiếu thu chi" description="Gom các khoản thu chi cùng tính chất để xem báo cáo. Nhóm chi phí quyết định khoản chi trừ vào lợi nhuận nào.">
        <ErrorState v-if="loadError" :message="loadError" @retry="load" />
        <div v-else-if="!types" class="grid gap-5 xl:grid-cols-2"><Skeleton v-for="i in 2" :key="i" class="h-80 rounded-[14px]" /></div>
        <template v-else>
            <div class="grid gap-5 xl:grid-cols-2">
                <section v-for="col in columns" :key="col.direction" class="flex flex-col overflow-hidden rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]" :data-testid="`types-${col.direction}`">
                    <header class="flex items-center gap-3 border-b border-slate-100 px-6 py-4">
                        <span :class="cn('grid size-10 place-items-center rounded-[11px]', col.tone)"><component :is="col.icon" class="size-5" /></span>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-[17px] font-semibold text-slate-900">{{ col.title }}</h2>
                            <p class="text-[13px] text-slate-500">{{ of(col.direction).length }} loại · {{ col.hint }}</p>
                        </div>
                    </header>
                    <ul class="flex-1 divide-y divide-slate-100">
                        <li v-for="t in of(col.direction)" :key="t.id" :class="cn('flex flex-wrap items-center gap-3 px-6 py-3 text-[14px]', !t.is_active && 'opacity-60')">
                            <form v-if="editing === t.id" class="flex flex-1 gap-1" @submit.prevent="update(t, { name: editName })">
                                <Input v-model="editName" class="h-9" maxlength="100" required aria-label="Tên loại phiếu" />
                                <Button type="submit" size="icon" variant="ghost" class="size-9" aria-label="Lưu tên" :loading="busy === t.id"><Check /></Button>
                                <Button type="button" size="icon" variant="ghost" class="size-9" aria-label="Thôi sửa" @click="editing = null"><X /></Button>
                            </form>
                            <div v-else class="flex min-w-0 flex-1 items-center gap-2">
                                <span class="truncate font-medium text-slate-900">{{ t.name }}</span>
                                <span v-if="!t.is_active" class="rounded-md bg-slate-100 px-2 py-0.5 text-[12px] font-medium text-slate-500">Ngừng dùng</span>
                            </div>
                            <select
                                v-if="t.direction === 'payment' && t.counts_in_pnl"
                                :class="cn('h-8 rounded-md border-0 px-2 text-[12px] font-medium outline-none focus:ring-2 focus:ring-blue-100', groupTone[t.pnl_group ?? ''])"
                                :value="t.pnl_group ?? ''"
                                :disabled="!canEdit || busy === t.id"
                                aria-label="Nhóm chi phí"
                                title="Chi phí bán hàng trừ vào lợi nhuận đóng góp, chi phí vận hành trừ vào lợi nhuận quản trị"
                                @change="update(t, { pnl_group: (($event.target as HTMLSelectElement).value || null) as VoucherType['pnl_group'] })"
                            >
                                <option value="sales">Chi phí bán hàng</option>
                                <option value="operating">Chi phí vận hành</option>
                                <option value="">Chi phí khác</option>
                            </select>
                            <label
                                :class="cn('flex cursor-pointer items-center gap-1.5 rounded-md px-2 py-1 text-[12px] font-medium', t.counts_in_pnl ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500')"
                                title="Có tính vào báo cáo kết quả kinh doanh hay không"
                            >
                                <input type="checkbox" class="size-3.5 accent-emerald-600" :checked="t.counts_in_pnl" :disabled="!canEdit || busy === t.id" @change="update(t, { counts_in_pnl: !t.counts_in_pnl })" />
                                {{ t.counts_in_pnl ? 'Tính KQKD' : 'Không tính KQKD' }}
                            </label>
                            <span class="w-20 text-right text-[13px] tabular-nums text-slate-500">{{ formatNumber(t.vouchers_count ?? 0) }} phiếu</span>
                            <template v-if="canEdit && editing !== t.id">
                                <button type="button" class="grid size-8 place-items-center rounded-[8px] text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Đổi tên" @click="((editing = t.id), (editName = t.name))">
                                    <Pencil class="size-4" />
                                </button>
                                <button type="button" class="rounded-[8px] px-2 py-1 text-[13px] text-slate-500 hover:bg-slate-100 hover:text-slate-900 disabled:opacity-50" :disabled="busy === t.id" @click="update(t, { is_active: !t.is_active })">
                                    {{ t.is_active ? 'Ngừng dùng' : 'Dùng lại' }}
                                </button>
                            </template>
                        </li>
                    </ul>
                    <form v-if="canEdit" class="flex gap-2 border-t border-slate-100 bg-slate-50/60 px-6 py-3.5" @submit.prevent="add(col.direction)">
                        <Input v-model="drafts[col.direction]" maxlength="100" :placeholder="`Tên ${col.title.toLowerCase()} mới`" :data-testid="`new-type-${col.direction}`" />
                        <button type="submit" class="inline-flex h-11 shrink-0 items-center gap-2 rounded-[10px] bg-blue-600 px-4 text-[14px] font-medium text-white hover:bg-blue-700 disabled:opacity-60" :disabled="busy === col.direction">
                            <Plus class="size-4" /> Thêm
                        </button>
                    </form>
                </section>
            </div>
            <div class="mt-5 grid gap-4 md:grid-cols-3">
                <div v-for="g in groupHelp" :key="g.name" class="flex gap-3 rounded-[14px] border border-slate-200/80 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
                    <span :class="cn('grid size-9 shrink-0 place-items-center rounded-[10px]', g.tone)"><component :is="g.icon" class="size-[18px]" /></span>
                    <div>
                        <p class="font-semibold text-slate-900">{{ g.name }}</p>
                        <p class="mt-0.5 text-[13px] text-slate-500">{{ g.text }}</p>
                    </div>
                </div>
            </div>
            <p class="mt-4 flex items-start gap-2.5 text-[13px] text-slate-500">
                <Info class="mt-0.5 size-4 shrink-0" /> Loại đã có phiếu không xóa được, chỉ ngừng dùng để giữ đúng lịch sử. “Tính KQKD” là mặc định cho phiếu mới, đổi ở đây không làm thay đổi phiếu cũ. “Tiền sàn chuyển
                về” không tính KQKD vì doanh thu đã tính theo đơn.
            </p>
        </template>
    </SettingsShell>
</template>
