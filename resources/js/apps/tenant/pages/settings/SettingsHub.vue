<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ArrowRight, Cable, ChevronRight, Clock, CreditCard, PanelsTopLeft, Search, Store, TriangleAlert } from 'lucide-vue-next';
import { useToast } from '@/shared/composables/useToast';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import { api } from '@/shared/lib/api';
import { cn, formatDate } from '@/shared/lib/utils';
import { useSettingsStore, type TenantSettingsData } from '@/shared/stores/settings';
import { useTenantStore } from '@/shared/stores/tenant';
import { auditLabels } from '../auditLabels';
import { subscriptionStatus } from '../billing/labels';
import { canOpen, settingsGroups as groups, toneClasses, type SettingsTile as Tile } from './catalog';
import { useConnectedServices } from './connectionsSummary';

/**
 * "Cấu hình" (mẫu 28/11, Tailwind): four cards on top (shop profile, plan, connections, menu), a warning line when a
 * connection broke, then every setting grouped, each row showing how it is set now, and on the right the marketplaces
 * and the latest changes from the activity log.
 */
const tenant = useTenantStore();
const toast = useToast();
const settingsStore = useSettingsStore();
const settings = ref<TenantSettingsData | null>(null);
const { services, load: loadServices } = useConnectedServices(tenant.can);

// "Tìm cài đặt": matches titles and descriptions, without Vietnamese accents.
const q = ref('');
const plain = (x: string) => x.normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D').toLowerCase();
const matches = (t: Tile) => !q.value.trim() || plain(`${t.title} ${t.description}`).includes(plain(q.value.trim()));
const groupInfo: Record<string, { description: string; icon: typeof Store }> = {
    'Cửa hàng': { description: 'Thông tin in trên hóa đơn, kho, nhân viên và cách thu tiền', icon: Store },
    'Bán hàng': { description: 'Quy tắc khi tạo đơn, bán tại quầy và chăm khách', icon: CreditCard },
    'Dữ liệu': { description: 'Chuyển dữ liệu cũ, bấm thử và xem lại lịch sử', icon: PanelsTopLeft },
};
const visible = computed(() => groups.map((g) => ({ ...g, tiles: g.tiles.filter((t) => canOpen(t, tenant.can) && matches(t)) })).filter((g) => g.tiles.length));
const searching = computed(() => !!q.value.trim());

/* ---- Hồ sơ cửa hàng: the eight things a complete profile has ---- */
const profile = computed(() => {
    const s = settings.value;
    if (!s) return null;
    const items: [string, boolean][] = [
        ['Tên cửa hàng', !!s.name],
        ['Logo', !!s.logo_url],
        ['Số điện thoại', !!s.store.phone],
        ['Email', !!s.store.email],
        ['Địa chỉ', !!(s.store.province_code || s.store.address)],
        ['Mã số thuế', !!s.store.tax_code],
        ['Lĩnh vực kinh doanh', !!s.store.business_type],
        ['Website', !!s.store.website],
    ];
    const done = items.filter(([, ok]) => ok).length;
    return { done, total: items.length, missing: items.filter(([, ok]) => !ok).map(([k]) => k) };
});

/* ---- Gói dịch vụ ---- */
interface SubscriptionInfo {
    plan: { key: string; name: string } | null;
    status: string;
    trial_ends_at: string | null;
    current_period_end: string | null;
    entitlements: Record<string, { enabled: boolean; limit: number | null }>;
    usage: Record<string, number>;
}
const sub = ref<SubscriptionInfo | null>(null);
const subEnd = computed(() => (sub.value?.status === 'trialing' ? sub.value.trial_ends_at : sub.value?.current_period_end) ?? null);
const daysLeft = computed(() => (subEnd.value ? Math.max(0, Math.ceil((new Date(subEnd.value).getTime() - Date.now()) / 86_400_000)) : null));
const users = computed(() => {
    const limit = sub.value?.entitlements['limit.users']?.limit;
    const used = sub.value?.usage['limit.users'];
    return used !== undefined ? `${used}${limit ? `/${limit}` : ''} nhân viên` : null;
});

/* ---- Kết nối ---- */
const running = computed(() => services.value.filter((s) => s.ok));
const broken = computed(() => services.value.filter((s) => !s.ok));

/* ---- Giao diện menu (Đợt 2): basic or full menu, saved for the whole business ---- */
const advanced = ref(tenant.current?.advanced_menu !== false);
const savingMenu = ref(false);
async function setAdvanced(on: boolean) {
    advanced.value = on;
    savingMenu.value = true;
    try {
        await api.put('/tenant/settings/interface', { advanced_menu: on });
        await tenant.refresh();
        toast.success(on ? 'Đã hiện tính năng nâng cao trên menu.' : 'Menu đã chuyển sang chế độ cơ bản.');
    } catch {
        advanced.value = !on;
        toast.error('Chưa lưu được, thử lại sau.');
    } finally {
        savingMenu.value = false;
    }
}
const canManage = computed(() => tenant.can('settings.manage'));

/* ---- How each setting is set now, under its name ---- */
function valueOf(t: Tile): { text: string; warn?: boolean } | null {
    const s = settings.value;
    switch (t.to) {
        case '/settings/store':
            return profile.value ? (profile.value.missing.includes('Mã số thuế') ? { text: 'Thiếu mã số thuế', warn: true } : { text: `${profile.value.done}/${profile.value.total} mục` }) : null;
        case '/subscription':
            return sub.value?.plan ? { text: sub.value.plan.name } : null;
        case '/settings/pricing':
            return s ? { text: `${s.tax.prices_include_tax ? 'Giá gồm thuế' : 'Giá chưa gồm thuế'} · ${s.pricing.sale_price === 'retail' ? 'Bán lẻ' : 'Bán buôn'}` } : null;
        case '/settings/payments': {
            if (!s) return null;
            const ways = Object.values(s.payments.methods).filter(Boolean).length;
            return s.payments.bank_accounts.length ? { text: `${s.payments.bank_accounts.length} tài khoản · ${ways} cách` } : { text: 'Chưa có tài khoản', warn: true };
        }
        case '/settings/print':
            return s ? { text: `Khổ ${s.print.defaults.sale.toUpperCase()}${s.print.show_qr ? ' · có QR' : ''}` } : null;
        case '/settings/order-codes':
            return s ? { text: s.order_codes?.enabled ? `${s.order_codes.prefix} · đang chạy` : 'Chưa bật' } : null;
        case '/settings/sales':
            return s ? { text: `${s.sales.invoice_copies} liên${s.sales.pos_require_shift ? ' · bắt buộc mở ca' : ''}` } : null;
        case '/settings/subscriptions': {
            if (!s) return null;
            const days = [...s.subscriptions.remind_days].sort((a, b) => b - a);
            return { text: days.length ? `Nhắc trước ${days.join(', ')} ngày` : 'Không nhắc' };
        }
        case '/settings/connections': {
            const own = services.value.filter((x) => !x.key.startsWith('market-'));
            const bad = own.filter((x) => !x.ok).length;
            return bad ? { text: `${bad} cần xem lại`, warn: true } : own.length ? { text: `${own.length} đang chạy` } : null;
        }
        case '/settings/sales#reasons':
            return s ? { text: `${s.reasons.length} lý do` } : null;
        default:
            return null;
    }
}

/* ---- Sàn thương mại điện tử ---- */
const markets = [
    { key: 'shopee', name: 'Shopee' },
    { key: 'tiktok', name: 'TikTok Shop' },
    { key: 'lazada', name: 'Lazada' },
    { key: 'tiki', name: 'Tiki' },
];
const accounts = ref<{ marketplace: string; status: string }[]>([]);
const canMarket = computed(() => tenant.can('marketplace.view'));
const marketStatus = (key: string) => {
    const mine = accounts.value.filter((a) => a.marketplace === key);
    const ok = mine.filter((a) => a.status === 'connected').length;
    const bad = mine.length - ok - mine.filter((a) => a.status === 'disconnected').length;
    if (!mine.length) return { text: 'Chưa kết nối', tone: 'text-slate-500', dot: 'bg-slate-300', connected: false };
    if (bad > 0) return { text: `${ok} shop đang chạy · ${bad} cần xem lại`, tone: 'text-amber-600', dot: 'bg-amber-500', connected: true };
    return ok ? { text: `${ok} shop đang chạy`, tone: 'text-emerald-600', dot: 'bg-emerald-500', connected: true } : { text: 'Đã ngắt kết nối', tone: 'text-slate-500', dot: 'bg-slate-300', connected: false };
};

/* ---- Thay đổi gần đây (Nhật ký hoạt động) ---- */
interface Log {
    id: number;
    action: string;
    actor: { name: string } | null;
    changes?: { section?: string } | null;
    created_at: string;
}
// Settings sections in words, for "Sửa <trang>" in the recent changes.
const sectionNames: Record<string, string> = {
    store: 'Thông tin cửa hàng',
    tax: 'Thuế',
    pricing: 'Chính sách giá',
    payments: 'Thanh toán',
    sales: 'Cấu hình bán hàng',
    reasons: 'Lý do hủy, trả',
    print: 'Mẫu in',
    shipping: 'Vận chuyển',
    subscriptions: 'Thuê bao',
    order_codes: 'Mã đơn hàng',
    household_tax: 'Thuế hộ kinh doanh',
};
const logText = (l: Log) => (l.action === 'tenant.settings_updated' && l.changes?.section ? `Sửa ${sectionNames[l.changes.section] ?? 'cấu hình'}` : (auditLabels[l.action] ?? l.action));
const logs = ref<Log[]>([]);
const canAudit = computed(() => tenant.can('audit.view'));
const initials = (name: string) =>
    name
        .split(/\s+/)
        .filter(Boolean)
        .slice(-2)
        .map((w) => w[0]!.toUpperCase())
        .join('');
const avatarTones = ['bg-blue-50 text-blue-600', 'bg-emerald-50 text-emerald-600', 'bg-violet-50 text-violet-600', 'bg-amber-50 text-amber-600'];
const avatarTone = (name: string) => avatarTones[[...name].reduce((a, c) => a + c.charCodeAt(0), 0) % avatarTones.length];
function when(iso: string) {
    const d = new Date(iso);
    const today = new Date();
    const time = d.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });
    if (d.toDateString() === today.toDateString()) return `${time} hôm nay`;
    const y = new Date(today);
    y.setDate(today.getDate() - 1);
    if (d.toDateString() === y.toDateString()) return `${time} hôm qua`;
    return formatDate(iso);
}

onMounted(() => {
    if (canManage.value) settingsStore.load().then((s) => (settings.value = s), () => undefined);
    if (tenant.can('billing.view')) api.get('/subscription').then(({ data }) => (sub.value = data.data), () => undefined);
    if (canMarket.value) api.get('/marketplace-accounts').then(({ data }) => (accounts.value = data.data), () => (accounts.value = []));
    if (canAudit.value) api.get('/audit-logs', { params: { action: 'tenant.', per_page: 4 } }).then(({ data }) => (logs.value = (data.data as Log[]).slice(0, 4)), () => undefined);
    loadServices();
});

const card = 'rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]';
</script>

<template>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[26px] font-bold leading-tight tracking-tight text-slate-900 sm:text-[30px]">Cấu hình</h1>
            <p class="mt-1 text-[15px] text-slate-500">Thiết lập một lần, dùng cho cả cửa hàng. Mỗi mục hiện cách đang cài để xem nhanh.</p>
        </div>
        <label class="flex h-11 w-full max-w-[380px] items-center gap-3 rounded-[12px] border border-slate-200 bg-white px-4 text-slate-400 focus-within:border-blue-300 focus-within:ring-2 focus-within:ring-blue-100">
            <Search class="size-4 shrink-0" />
            <input v-model="q" class="h-full min-w-0 flex-1 bg-transparent text-[15px] text-slate-900 outline-none placeholder:text-slate-400" placeholder="Tìm cài đặt, ví dụ “mẫu in”" aria-label="Tìm cài đặt" data-testid="settings-search" />
        </label>
    </div>

    <div class="space-y-6" data-testid="settings-hub">
        <!-- Four cards on top -->
        <div v-if="!searching" class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
            <RouterLink v-if="profile" to="/settings/store" :class="cn(card, 'block p-5 hover:border-blue-200')" data-testid="hub-profile">
                <div class="flex items-start justify-between"><p class="text-[14px] text-slate-500">Hồ sơ cửa hàng</p><span :class="cn('grid size-10 place-items-center rounded-[11px]', toneClasses.blue)"><Store class="size-5" /></span></div>
                <p class="-mt-2 text-[26px] font-bold text-slate-900">{{ profile.done }}<span class="text-lg font-semibold text-slate-400">/{{ profile.total }} mục</span></p>
                <div class="mt-2.5 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-blue-600" :style="{ width: `${(profile.done / profile.total) * 100}%` }" /></div>
                <p class="mt-2.5 truncate text-[13px] text-slate-500">
                    <template v-if="profile.missing.length">Còn thiếu: <span class="font-medium text-blue-600">{{ profile.missing.slice(0, 2).join(', ') }}</span></template>
                    <template v-else>Đủ thông tin in trên hóa đơn</template>
                </p>
            </RouterLink>
            <RouterLink v-if="sub" to="/subscription" :class="cn(card, 'block p-5 hover:border-blue-200')" data-testid="hub-plan">
                <div class="flex items-start justify-between"><p class="text-[14px] text-slate-500">Gói dịch vụ</p><span :class="cn('grid size-10 place-items-center rounded-[11px]', toneClasses.violet)"><CreditCard class="size-5" /></span></div>
                <p class="-mt-2 truncate text-[26px] font-bold text-slate-900">{{ sub.plan?.name ?? 'Chưa chọn gói' }}</p>
                <div class="mt-2.5 flex flex-wrap gap-1.5">
                    <span v-if="daysLeft !== null" :class="cn('inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-[12px] font-medium', daysLeft <= 7 ? 'bg-amber-50 text-amber-700' : 'bg-blue-50 text-blue-700')"><Clock class="size-3" /> Còn {{ daysLeft }} ngày</span>
                    <span v-else class="rounded-md bg-slate-100 px-2 py-0.5 text-[12px] font-medium text-slate-600">{{ subscriptionStatus[sub.status]?.label ?? sub.status }}</span>
                    <span v-if="users" class="rounded-md bg-slate-100 px-2 py-0.5 text-[12px] font-medium text-slate-600">{{ users }}</span>
                </div>
                <p class="mt-2.5 text-[13px] text-slate-500">{{ subEnd ? `${sub.status === 'trialing' ? 'Dùng thử đến' : 'Gia hạn ngày'} ${formatDate(subEnd)}` : (subscriptionStatus[sub.status]?.label ?? '') }}</p>
            </RouterLink>
            <RouterLink v-if="canManage || canMarket" to="/settings/connections" :class="cn(card, 'block p-5 hover:border-blue-200')" data-testid="hub-connections">
                <div class="flex items-start justify-between"><p class="text-[14px] text-slate-500">Kết nối đang chạy</p><span :class="cn('grid size-10 place-items-center rounded-[11px]', toneClasses.green)"><Cable class="size-5" /></span></div>
                <div class="-mt-2 flex items-center gap-3">
                    <p class="text-[26px] font-bold text-slate-900">{{ running.length }}</p>
                    <div class="flex items-center gap-1.5">
                        <span v-for="s in running.slice(0, 4)" :key="s.key" class="flex h-7 min-w-7 items-center justify-center rounded-lg bg-white px-1 ring-1 ring-slate-200" :title="s.name">
                            <img v-if="s.logo" :src="s.logo" :alt="s.name" class="max-h-5 max-w-12 object-contain" />
                            <Cable v-else class="size-3.5 text-slate-400" />
                        </span>
                    </div>
                </div>
                <div class="mt-2.5">
                    <span v-if="broken.length" class="inline-flex items-center gap-1 rounded-md bg-amber-50 px-2 py-0.5 text-[12px] font-medium text-amber-700"><TriangleAlert class="size-3" /> {{ broken.length }} cần xem lại</span>
                    <span v-else class="inline-flex rounded-md bg-emerald-50 px-2 py-0.5 text-[12px] font-medium text-emerald-700">Không có lỗi</span>
                </div>
                <p class="mt-2.5 truncate text-[13px] text-slate-500">{{ broken[0] ? `${broken[0].name}: ${broken[0].error}` : 'Vận chuyển, hóa đơn điện tử, Zalo, sàn' }}</p>
            </RouterLink>
            <div v-if="canManage" :class="cn(card, 'p-5')" data-testid="settings-interface">
                <div class="flex items-start justify-between"><p class="text-[14px] text-slate-500">Giao diện menu</p><span :class="cn('grid size-10 place-items-center rounded-[11px]', toneClasses.amber)"><PanelsTopLeft class="size-5" /></span></div>
                <p class="-mt-2 text-[26px] font-bold text-slate-900">{{ advanced ? 'Đầy đủ' : 'Cơ bản' }}</p>
                <label class="mt-2.5 flex cursor-pointer items-center gap-2.5" data-testid="advanced-menu">
                    <button
                        type="button"
                        role="switch"
                        :aria-checked="advanced"
                        aria-label="Hiện tính năng nâng cao"
                        :disabled="savingMenu"
                        :class="cn('relative h-6 w-11 shrink-0 rounded-full transition-colors disabled:opacity-60', advanced ? 'bg-blue-600' : 'bg-slate-200')"
                        @click="setAdvanced(!advanced)"
                    >
                        <span :class="cn('absolute top-0.5 size-5 rounded-full bg-white shadow transition-all', advanced ? 'left-[22px]' : 'left-0.5')" />
                    </button>
                    <span class="text-[14px] text-slate-700">Hiện tính năng nâng cao</span>
                </label>
                <p class="mt-2.5 text-[13px] text-slate-500" title="Ca bán hàng, Đặt hàng nhập, Chuyển hàng, Điều chỉnh giá vốn, Đối soát tồn kho, Khách trùng, Loại phiếu. Vẫn mở được bằng ô tìm kiếm (Ctrl K).">Tắt để menu gọn, dữ liệu không mất</p>
            </div>
        </div>

        <!-- A broken connection, first thing to fix -->
        <RouterLink v-if="!searching && broken[0]" :to="broken[0].to" class="flex items-center gap-3 rounded-[12px] border border-amber-200 bg-amber-50 px-4 py-3 text-[14px] text-amber-800" data-testid="hub-alert">
            <TriangleAlert class="size-[18px] shrink-0 text-amber-500" />
            <span class="min-w-0"><b>{{ broken[0].name }}</b>: {{ broken[0].error }}<template v-if="broken.length > 1"> (và {{ broken.length - 1 }} kết nối khác)</template></span>
            <span class="ml-auto inline-flex shrink-0 items-center gap-1 font-medium text-amber-700">Xem lại <ArrowRight class="size-4" /></span>
        </RouterLink>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_400px]">
            <div class="space-y-6">
                <section v-for="g in visible" :key="g.title" :class="card">
                    <header class="flex items-center gap-3 border-b border-slate-100 px-5 py-4 sm:px-6">
                        <span :class="cn('grid size-8 place-items-center rounded-lg', toneClasses[g.tone])"><component :is="groupInfo[g.title]?.icon ?? Store" class="size-4" /></span>
                        <div>
                            <h2 class="text-[16px] font-semibold text-slate-900">{{ g.title }}</h2>
                            <p class="text-[13px] text-slate-500">{{ groupInfo[g.title]?.description }}</p>
                        </div>
                    </header>
                    <div class="grid gap-x-2 p-2 sm:p-3 md:grid-cols-2">
                        <RouterLink v-for="t in g.tiles" :key="t.to" :to="t.to" class="group flex min-w-0 items-center gap-3.5 rounded-[12px] px-3 py-3 hover:bg-slate-50">
                            <span :class="cn('grid size-10 shrink-0 place-items-center rounded-[11px]', toneClasses[g.tone])"><component :is="t.icon" class="size-5" :stroke-width="1.9" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-[15px] font-semibold text-slate-900 group-hover:text-blue-600">{{ t.title }}</span>
                                <span class="block truncate text-[13px] text-slate-500">{{ t.description }}</span>
                            </span>
                            <span v-if="valueOf(t)" :class="cn('hidden shrink-0 text-right text-[13px] 2xl:block', valueOf(t)!.warn ? 'font-medium text-amber-600' : 'text-slate-500')">{{ valueOf(t)!.text }}</span>
                            <ChevronRight class="size-4 shrink-0 text-slate-300 group-hover:text-blue-500" aria-hidden="true" />
                        </RouterLink>
                    </div>
                </section>
                <p v-if="!visible.length" :class="cn(card, 'p-8 text-center text-sm text-slate-500')">Không thấy cài đặt nào khớp “{{ q }}”.</p>
            </div>

            <div v-if="!searching && (canMarket || (canAudit && logs.length))" class="space-y-6">
                <section v-if="canMarket" :class="card" data-testid="settings-marketplaces">
                    <header class="border-b border-slate-100 px-5 py-4">
                        <h2 class="text-[16px] font-semibold text-slate-900">Sàn thương mại điện tử</h2>
                        <p class="text-[13px] text-slate-500">Mỗi sàn kết nối riêng, có thể nhiều shop</p>
                    </header>
                    <div class="divide-y divide-slate-100 px-5">
                        <div v-for="m in markets" :key="m.key" class="flex items-center gap-3 py-3">
                            <MarketplaceLogo :marketplace="m.key" :name="m.name" size="lg" />
                            <div class="min-w-0 flex-1">
                                <p class="text-[15px] font-semibold text-slate-900">{{ m.name }}</p>
                                <p :class="cn('flex items-center gap-1.5 truncate text-[13px]', marketStatus(m.key).tone)"><span :class="cn('size-1.5 shrink-0 rounded-full', marketStatus(m.key).dot)" />{{ marketStatus(m.key).text }}</p>
                            </div>
                            <RouterLink
                                :to="{ path: '/marketplaces', query: { san: m.key } }"
                                :class="cn('shrink-0 rounded-[9px] px-3 py-1.5 text-[13px] font-medium', marketStatus(m.key).connected ? 'border border-slate-200 text-slate-700 hover:bg-slate-50' : 'bg-blue-50 text-blue-700 hover:bg-blue-100')"
                            >
                                {{ marketStatus(m.key).connected ? 'Quản lý' : 'Kết nối' }}
                            </RouterLink>
                        </div>
                    </div>
                </section>
                <section v-if="canAudit && logs.length" :class="card" data-testid="settings-recent">
                    <header class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                        <div>
                            <h2 class="text-[16px] font-semibold text-slate-900">Thay đổi gần đây</h2>
                            <p class="text-[13px] text-slate-500">Từ nhật ký hoạt động</p>
                        </div>
                        <RouterLink to="/audit" class="rounded-[9px] border border-slate-200 px-3 py-1.5 text-[13px] text-slate-700 hover:bg-slate-50">Xem tất cả</RouterLink>
                    </header>
                    <div class="divide-y divide-slate-100 px-5">
                        <div v-for="l in logs" :key="l.id" class="flex gap-3 py-3">
                            <span :class="cn('grid size-8 shrink-0 place-items-center rounded-full text-[11px] font-semibold', avatarTone(l.actor?.name ?? 'Hệ thống'))">{{ initials(l.actor?.name ?? 'Hệ thống') }}</span>
                            <div class="min-w-0">
                                <p class="text-[14px] text-slate-700">
                                    <span class="font-medium text-slate-900">{{ l.actor?.name ?? 'Hệ thống' }}</span> · {{ logText(l) }}
                                </p>
                                <p class="text-[12px] text-slate-400">{{ when(l.created_at) }}</p>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>
