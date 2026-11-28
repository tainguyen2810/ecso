<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Search, ScrollText } from 'lucide-vue-next';
import SettingsShell from './settings/SettingsShell.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber, initials } from '@/shared/lib/utils';
import { auditGroupOf, auditGroups, auditLabel } from './auditLabels';
import { paymentMethods } from './orders/labels';

/**
 * Nhật ký hoạt động (mẫu 28/11): who did what and when, one line per change in words ("Tạo đơn SO-000177" with the
 * total under it), grouped by day, filtered by kind of work and searched in the loaded lines.
 */
interface Log {
    id: number;
    action: string;
    actor: { name: string; email: string } | null;
    subject_type: string | null;
    changes: Record<string, unknown> | null;
    ip: string | null;
    created_at: string;
}

const logs = ref<Log[]>([]);
const cursor = ref<string | null>(null);
const loading = ref(true);
const loadingMore = ref(false);
const error = ref<string | null>(null);
const group = ref<string>('');
const q = ref('');

async function load(more = false) {
    more ? (loadingMore.value = true) : (loading.value = true);
    error.value = null;
    try {
        const prefixes = auditGroups.find((g) => g.key === group.value)?.prefixes;
        const { data } = await api.get('/audit-logs', { params: { cursor: more ? cursor.value : undefined, prefixes } });
        logs.value = more ? [...logs.value, ...data.data] : data.data;
        cursor.value = data.next_cursor;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
        loadingMore.value = false;
    }
}
onMounted(() => load());
watch(group, () => load());

/** The document a change is about (order code, voucher number, product name…), shown after the action. */
function subject(c: Record<string, unknown> | null): string {
    if (!c) return '';
    for (const k of ['order', 'code', 'number', 'reference', 'sku', 'email', 'name', 'shop', 'tenant']) {
        const v = c[k];
        if (typeof v === 'string' || typeof v === 'number') return String(v);
    }
    return '';
}

const keyLabels: Record<string, string> = {
    total: 'Tổng', amount: 'Số tiền', method: 'Cách trả', channel: 'Kênh', role: 'Vai trò', reason: 'Lý do', status: 'Trạng thái', section: 'Mục', lines: 'Số dòng', count: 'Số lượng',
    imported: 'Đã nhập', provider: 'Nhà cung cấp', from: 'Từ', to: 'Sang', plan: 'Gói', cycle: 'Chu kỳ', note: 'Ghi chú', warehouse: 'Kho', customer: 'Khách', supplier: 'Nhà cung cấp',
};
const roleNames: Record<string, string> = {
    TENANT_OWNER: 'Chủ doanh nghiệp', TENANT_ADMIN: 'Quản trị doanh nghiệp', MANAGER: 'Quản lý vận hành', WAREHOUSE_STAFF: 'Nhân viên kho', ORDER_STAFF: 'Nhân viên xử lý đơn', ACCOUNTANT: 'Kế toán', VIEWER: 'Chỉ xem',
};
/** Codes stored in the log (payment method, role) in words. */
function word(k: string, v: unknown): string {
    if (k === 'method') return paymentMethods[String(v)] ?? String(v);
    if (k === 'role') return roleNames[String(v)] ?? String(v);
    return String(v);
}
const moneyKeys = new Set(['total', 'amount', 'paid', 'refund', 'cost']);
const skip = new Set(['order', 'code', 'number', 'reference', 'sku', 'email', 'name', 'shop', 'tenant', 'deltas', 'self_approved', 'completed', 'setup', 'send_email']);

/** The rest of the change as a short line of words. */
function details(c: Record<string, unknown> | null): string {
    if (!c) return '';
    return Object.entries(c)
        .filter(([k, v]) => !skip.has(k) && v !== null && v !== '' && typeof v !== 'object')
        .slice(0, 4)
        .map(([k, v]) => `${keyLabels[k] ?? k}: ${moneyKeys.has(k) && !Number.isNaN(Number(v)) ? formatMoney(Number(v)) : typeof v === 'number' ? formatNumber(v) : word(k, v)}`)
        .join(' · ');
}

const shown = computed(() => {
    const term = q.value.trim().toLowerCase();
    if (!term) return logs.value;
    return logs.value.filter((l) => [auditLabel(l.action), subject(l.changes), details(l.changes), l.actor?.name ?? 'Hệ thống'].join(' ').toLowerCase().includes(term));
});

const dayFmt = new Intl.DateTimeFormat('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric' });
const timeFmt = new Intl.DateTimeFormat('vi-VN', { hour: '2-digit', minute: '2-digit' });
const days = computed(() => {
    const today = dayFmt.format(new Date());
    const yesterday = dayFmt.format(new Date(Date.now() - 86_400_000));
    const out: { label: string; logs: Log[] }[] = [];
    for (const l of shown.value) {
        const d = dayFmt.format(new Date(l.created_at));
        const label = d === today ? `Hôm nay · ${d}` : d === yesterday ? `Hôm qua · ${d}` : d;
        if (out.at(-1)?.label !== label) out.push({ label, logs: [] });
        out.at(-1)!.logs.push(l);
    }
    return out;
});
</script>

<template>
    <SettingsShell title="Nhật ký hoạt động" description="Ai làm gì, lúc nào trong doanh nghiệp. Nhật ký không sửa, không xóa được.">
        <div class="overflow-hidden rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
            <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-4">
                <div class="relative w-full sm:w-72">
                    <Search class="absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                    <input
                        v-model="q"
                        placeholder="Tìm mã đơn, sản phẩm, người làm"
                        aria-label="Tìm trong nhật ký"
                        class="h-11 w-full rounded-[10px] border border-slate-200 bg-white pl-10 pr-3 text-[15px] outline-none placeholder:text-slate-400 focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                    />
                </div>
                <div class="flex flex-wrap gap-1 rounded-[12px] bg-slate-100 p-1 text-[14px]" role="tablist" aria-label="Loại việc">
                    <button
                        v-for="g in [{ key: '', label: 'Tất cả' }, ...auditGroups]"
                        :key="g.key"
                        type="button"
                        role="tab"
                        :aria-selected="group === g.key"
                        :class="cn('rounded-[9px] px-3 py-1.5', group === g.key ? 'bg-white font-semibold text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-900')"
                        :data-testid="`audit-group-${g.key || 'all'}`"
                        @click="group = g.key"
                    >
                        {{ g.label }}
                    </button>
                </div>
            </div>

            <div v-if="loading" class="space-y-3 p-5"><Skeleton v-for="i in 6" :key="i" class="h-12" /></div>
            <ErrorState v-else-if="error" :message="error" @retry="load()" />
            <EmptyState v-else-if="!shown.length" :icon="ScrollText" :title="q ? 'Không tìm thấy hoạt động phù hợp' : 'Chưa có hoạt động'" />
            <div v-else data-testid="audit-list">
                <template v-for="d in days" :key="d.label">
                    <p class="bg-slate-50 px-5 py-2 text-[12px] font-semibold uppercase tracking-wider text-slate-500">{{ d.label }}</p>
                    <div class="divide-y divide-slate-100">
                        <div v-for="log in d.logs" :key="log.id" class="flex items-center gap-4 px-5 py-3 text-[14px]">
                            <span class="w-11 shrink-0 text-[13px] tabular-nums text-slate-500">{{ timeFmt.format(new Date(log.created_at)) }}</span>
                            <span :class="cn('grid size-9 shrink-0 place-items-center rounded-[10px] text-[11px] font-bold uppercase', auditGroupOf(log.action).tone)">{{ auditGroupOf(log.action).label.slice(0, 2) }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-slate-900">
                                    {{ auditLabel(log.action) }} <b v-if="subject(log.changes)" class="font-semibold">{{ subject(log.changes) }}</b>
                                </p>
                                <p v-if="details(log.changes)" class="truncate text-[13px] text-slate-500" :title="details(log.changes)">{{ details(log.changes) }}</p>
                            </div>
                            <div class="hidden w-52 shrink-0 items-center gap-2.5 md:flex">
                                <span :class="cn('grid size-8 shrink-0 place-items-center rounded-full text-[11px] font-semibold', log.actor ? 'bg-slate-100 text-slate-500' : 'bg-slate-800 text-white')">{{ log.actor ? initials(log.actor.name) : 'HT' }}</span>
                                <span class="truncate text-slate-700" :title="log.actor?.email">{{ log.actor?.name ?? 'Hệ thống' }}</span>
                            </div>
                            <span class="hidden w-32 shrink-0 text-right font-mono text-[12px] text-slate-400 xl:block">{{ log.ip ?? '—' }}</span>
                        </div>
                    </div>
                </template>
            </div>
            <div v-if="cursor && !loading" class="border-t border-slate-100 py-3 text-center">
                <button type="button" class="rounded-[9px] px-4 py-1.5 text-[14px] font-medium text-blue-700 hover:bg-blue-50 disabled:opacity-60" :disabled="loadingMore" @click="load(true)">
                    {{ loadingMore ? 'Đang tải…' : 'Xem thêm' }}
                </button>
            </div>
        </div>
    </SettingsShell>
</template>
