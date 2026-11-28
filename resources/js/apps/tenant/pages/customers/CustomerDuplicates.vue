<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Building2, Copy, Info, Mail, Merge, RefreshCw, Star, UserSearch, UsersRound } from 'lucide-vue-next';
import ReportChip from '../reports/ReportChip.vue';
import ReportKpi from '../reports/ReportKpi.vue';
import CustomersFrame from './CustomersFrame.vue';
import MergeDialog from './MergeDialog.vue';
import { avatarTone, btnOutline, card, duplicateGroups, initials, pill } from './frame';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDate, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';

/** Khách trùng (mẫu 28/11): cards that may be the same buyer, side by side to compare, merged one by one by hand. */
interface DuplicateCard { id: string; code: string; name: string; phone: string | null; email: string | null; address: string | null; status: string; orders_count: number; total_spent: string; debt: string; created_at: string | null }
interface DuplicateGroup { key: string; reason: 'email' | 'tax_code' | 'name'; value: string; customers: DuplicateCard[] }

const toast = useToast();
const groups = ref<DuplicateGroup[] | null>(null);
const error = ref<string | null>(null);
const loading = ref(false);
const loadedAt = ref<Date | null>(null);
const reason = ref<'' | DuplicateGroup['reason']>('');
const reasons = {
    email: { label: 'Trùng email', tone: 'bg-red-50 text-red-600', hint: 'Rất có thể là cùng một khách.' },
    tax_code: { label: 'Trùng mã số thuế', tone: 'bg-red-50 text-red-600', hint: 'Rất có thể là cùng một đơn vị.' },
    name: { label: 'Trùng tên', tone: 'bg-amber-50 text-amber-700', hint: 'Chỉ là gợi ý: hai người có thể trùng tên. Kiểm tra số điện thoại, địa chỉ trước khi gộp.' },
};

async function load() {
    error.value = null;
    loading.value = true;
    try {
        groups.value = (await api.get('/customers/duplicates')).data.data;
        loadedAt.value = new Date();
        duplicateGroups.value = groups.value!.length;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

const count = (r: DuplicateGroup['reason']) => (groups.value ?? []).filter((g) => g.reason === r).length;
const cards = computed(() => (groups.value ?? []).reduce((a, g) => a + g.customers.length, 0));
const shown = computed(() => (groups.value ?? []).filter((g) => !reason.value || g.reason === reason.value));
const filters = computed(() => [
    { value: '' as const, label: 'Tất cả', n: groups.value?.length ?? 0 },
    { value: 'email' as const, label: 'Trùng email', n: count('email') },
    { value: 'tax_code' as const, label: 'Trùng mã số thuế', n: count('tax_code') },
    { value: 'name' as const, label: 'Trùng tên', n: count('name') },
]);
const money = (v: string | number) => formatNumber(Math.round(Number(v)));
const time = computed(() => (loadedAt.value ? loadedAt.value.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' }) : ''));

// The card with the most orders (then the oldest) is kept by default.
function keeper(g: DuplicateGroup): DuplicateCard {
    return [...g.customers].sort((a, b) => b.orders_count - a.orders_count || String(a.created_at).localeCompare(String(b.created_at)))[0]!;
}
const dialog = ref(false);
const pair = ref({ keep: '', source: '' });
function startMerge(keep: DuplicateCard, source: DuplicateCard) {
    pair.value = { keep: keep.id, source: source.id };
    dialog.value = true;
}
const dismissing = ref<string | null>(null);
async function dismiss(g: DuplicateGroup) {
    if (!window.confirm(`Đánh dấu ${g.customers.length} thẻ "${g.value}" không phải cùng một khách? Nhóm này sẽ không hiện lại, trừ khi có thêm thẻ trùng.`)) return;
    dismissing.value = g.key;
    try {
        await api.post('/customers/duplicates/dismiss', { key: g.key });
        toast.success('Đã bỏ nhóm khỏi danh sách khách trùng.');
        groups.value = groups.value!.filter((x) => x.key !== g.key);
        duplicateGroups.value = groups.value.length;
    } catch (e) {
        toast.error((e as ApiError).field('key') ?? (e as ApiError).message);
    } finally {
        dismissing.value = null;
    }
}
</script>

<template>
    <CustomersFrame page="Khách trùng" description="Các thẻ khách có thể là cùng một người. Phần mềm không tự gộp: bạn kiểm tra rồi chọn gộp từng thẻ.">
        <button type="button" :class="btnOutline" :disabled="loading" @click="load"><RefreshCw :class="cn('size-4', loading && 'animate-spin')" /> Quét lại</button>
    </CustomersFrame>

    <div v-if="groups === null && !error" class="space-y-4"><Skeleton v-for="i in 3" :key="i" class="h-40 rounded-[14px]" /></div>
    <ErrorState v-else-if="error" :message="error" @retry="load" />
    <template v-else>
        <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <ReportKpi label="Nhóm cần xem" :icon="Copy" tone="blue" :value="groups!.length" unit="nhóm">
                <template #chips><ReportChip tone="blue">{{ formatNumber(cards) }} thẻ khách</ReportChip></template>
                Quét lúc {{ time }} hôm nay
            </ReportKpi>
            <ReportKpi label="Trùng email" :icon="Mail" tone="red" :value="count('email')" unit="nhóm">
                <template #chips><ReportChip tone="red">Rất có thể cùng khách</ReportChip></template>
                Nên gộp để lịch sử mua về một chỗ
            </ReportKpi>
            <ReportKpi label="Trùng mã số thuế" :icon="Building2" tone="red" :value="count('tax_code')" unit="nhóm">
                <template #chips><ReportChip tone="red">Rất có thể cùng đơn vị</ReportChip></template>
                Gộp để công nợ tính đúng
            </ReportKpi>
            <ReportKpi label="Trùng tên" :icon="UserSearch" tone="amber" :value="count('name')" unit="nhóm">
                <template #chips><ReportChip tone="amber">Chỉ là gợi ý</ReportChip></template>
                Kiểm tra số điện thoại, địa chỉ trước
            </ReportKpi>
        </div>

        <div :class="cn(card, 'p-10')" v-if="!groups!.length"><EmptyState :icon="UsersRound" title="Không thấy khách trùng" description="Không có hai khách nào trùng email, mã số thuế hoặc tên." /></div>
        <template v-else>
            <div class="mb-5 flex items-start gap-3 rounded-[14px] border border-blue-200 bg-blue-50 px-5 py-3.5">
                <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-blue-100 text-blue-600"><Info class="size-[18px]" /></span>
                <p class="flex-1 self-center text-[14px] text-blue-900">
                    Khi gộp, <b>đơn hàng, công nợ, điểm và ghi chú</b> của thẻ bị gộp chuyển sang thẻ được giữ. Thẻ có nhiều đơn nhất (rồi đến thẻ cũ nhất) được đề xuất giữ. Bấm "Không phải trùng" nếu đó là hai khách khác nhau.
                </p>
            </div>
            <div class="mb-4 flex overflow-x-auto">
                <div class="flex rounded-[12px] bg-slate-100 p-1 text-[14px]" role="group" aria-label="Lý do">
                    <button v-for="f in filters" :key="f.value" type="button" :class="pill(reason === f.value)" :aria-pressed="reason === f.value" @click="reason = f.value">{{ f.label }} <span class="tabular-nums text-slate-400">{{ f.n }}</span></button>
                </div>
            </div>
            <div class="space-y-5" data-testid="duplicate-groups">
                <section v-for="g in shown" :key="g.key" :class="cn(card, 'overflow-hidden')">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-slate-100 px-5 py-3.5">
                        <span :class="cn('inline-flex whitespace-nowrap rounded-md px-2 py-0.5 text-[12px] font-medium', reasons[g.reason].tone)">{{ reasons[g.reason].label }}</span>
                        <span class="text-[15px] font-semibold text-slate-900">{{ g.value }}</span>
                        <span class="text-[13px] text-slate-500">{{ reasons[g.reason].hint }}</span>
                        <span class="ml-auto text-[13px] text-slate-500">{{ g.customers.length }} thẻ</span>
                        <button type="button" class="inline-flex h-9 items-center rounded-[10px] px-3 text-[13px] text-slate-500 hover:bg-slate-100 hover:text-slate-900 disabled:opacity-50" :disabled="dismissing === g.key" :data-testid="`dismiss-${g.key}`" @click="dismiss(g)">Không phải trùng</button>
                    </div>
                    <div class="grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                        <div v-for="c in g.customers" :key="c.id" :class="cn('flex flex-col rounded-[12px] border p-4', c.id === keeper(g).id ? 'border-emerald-200 bg-emerald-50/30' : 'border-slate-200 bg-white')">
                            <div class="flex items-start gap-3">
                                <span :class="cn('grid size-10 shrink-0 place-items-center rounded-full text-[13px] font-semibold', avatarTone(g.value))">{{ initials(c.name) }}</span>
                                <div class="min-w-0 flex-1">
                                    <RouterLink :to="`/customers/${c.id}`" class="block truncate text-[15px] font-semibold text-slate-900 hover:text-blue-700 hover:underline">{{ c.name }}</RouterLink>
                                    <p class="font-mono text-[12px] text-slate-400">{{ c.code }}<span v-if="c.status === 'inactive'" class="ml-1.5 font-sans">· Ngừng giao dịch</span></p>
                                </div>
                                <span v-if="c.id === keeper(g).id" class="inline-flex shrink-0 items-center gap-1 rounded-md bg-emerald-50 px-2 py-0.5 text-[12px] font-semibold text-emerald-700"><Star class="size-3" /> Đề xuất giữ</span>
                            </div>
                            <dl class="mt-3 space-y-1.5 text-[13px]">
                                <div class="flex gap-2"><dt class="w-20 shrink-0 text-slate-500">Điện thoại</dt><dd class="text-slate-800">{{ c.phone ?? '—' }}</dd></div>
                                <div class="flex gap-2"><dt class="w-20 shrink-0 text-slate-500">Email</dt><dd class="truncate text-slate-800">{{ c.email ?? '—' }}</dd></div>
                                <div class="flex gap-2"><dt class="w-20 shrink-0 text-slate-500">Địa chỉ</dt><dd class="truncate text-slate-800" :title="c.address ?? undefined">{{ c.address ?? '—' }}</dd></div>
                                <div class="flex gap-2"><dt class="w-20 shrink-0 text-slate-500">Đã mua</dt><dd class="text-slate-800"><b>{{ formatNumber(c.orders_count) }} đơn</b> · {{ money(c.total_spent) }} ₫</dd></div>
                                <div class="flex gap-2"><dt class="w-20 shrink-0 text-slate-500">Công nợ</dt><dd :class="Number(c.debt) > 0 ? 'font-semibold text-amber-700' : 'text-slate-400'">{{ Number(c.debt) > 0 ? `${money(c.debt)} ₫` : '—' }}</dd></div>
                                <div class="flex gap-2"><dt class="w-20 shrink-0 text-slate-500">Thêm ngày</dt><dd class="text-slate-800">{{ formatDate(c.created_at) }}</dd></div>
                            </dl>
                            <div class="mt-auto pt-4">
                                <span v-if="c.id === keeper(g).id" class="flex h-9 items-center justify-center rounded-[10px] bg-white/70 text-[13px] text-slate-500">Thẻ được giữ lại</span>
                                <button
                                    v-else
                                    type="button"
                                    class="flex h-9 w-full items-center justify-center gap-1.5 rounded-[10px] border border-blue-200 bg-blue-50 text-[13px] font-medium text-blue-700 hover:bg-blue-100"
                                    :data-testid="`merge-${c.code}`"
                                    @click="startMerge(keeper(g), c)"
                                >
                                    <Merge class="size-4" /> Gộp vào {{ keeper(g).code }}
                                </button>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </template>
    </template>
    <MergeDialog v-model:open="dialog" :keep-id="pair.keep" :source-id="pair.source" @merged="load" />
</template>
