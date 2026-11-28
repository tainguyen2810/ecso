<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ChevronRight, Search } from 'lucide-vue-next';
import Input from '@/shared/ui/Input.vue';
import Switch from '@/shared/ui/Switch.vue';
import { useToast } from '@/shared/composables/useToast';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import { api } from '@/shared/lib/api';
import { cn } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { canOpen, settingsGroups as groups, toneClasses, type SettingsTile as Tile } from './catalog';

/** "Cấu hình" (same style as Báo cáo): every business setting in one place, grouped, each tile opening its own page. */
const tenant = useTenantStore();
// "Tìm cài đặt": matches titles and descriptions, without Vietnamese accents.
const q = ref('');
const plain = (x: string) => x.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D').toLowerCase();
const matches = (t: Tile) => !q.value.trim() || plain(`${t.title} ${t.description}`).includes(plain(q.value.trim()));
const visible = computed(() => groups.map((g) => ({ ...g, tiles: g.tiles.filter((t) => canOpen(t, tenant.can) && matches(t)) })).filter((g) => g.tiles.length));

/** Sàn thương mại điện tử: each marketplace on its own line with what is connected now. */
const markets = [
    { key: 'shopee', name: 'Shopee' },
    { key: 'tiktok', name: 'TikTok Shop' },
    { key: 'lazada', name: 'Lazada' },
    { key: 'tiki', name: 'Tiki' },
];
const accounts = ref<{ marketplace: string; status: string }[]>([]);
const canMarket = computed(() => tenant.can('marketplace.view'));
onMounted(async () => {
    if (!canMarket.value) return;
    try {
        accounts.value = (await api.get('/marketplace-accounts')).data.data;
    } catch {
        accounts.value = [];
    }
});
const marketStatus = (key: string) => {
    const mine = accounts.value.filter((a) => a.marketplace === key);
    const ok = mine.filter((a) => a.status === 'connected').length;
    const bad = mine.length - ok - mine.filter((a) => a.status === 'disconnected').length;
    if (!mine.length) return { text: 'Chưa kết nối', tone: 'text-slate-500', connected: false, dot: 'bg-slate-300' };
    if (bad > 0) return { text: `${ok} shop đang chạy · ${bad} shop cần xem lại`, tone: 'text-amber-600', connected: true, dot: 'bg-amber-500' };
    return { text: ok ? `${ok} shop đang chạy` : 'Đã ngắt kết nối', tone: ok ? 'text-emerald-600' : 'text-slate-500', connected: ok > 0, dot: ok ? 'bg-emerald-500' : 'bg-slate-300' };
};
/** Basic or full menu (Đợt 2): saved for the whole business, the sidebar follows at once. */
const toast = useToast();
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
const showMenu = computed(() => tenant.can('settings.manage') && (!q.value.trim() || plain('giao dien menu tinh nang nang cao co ban an hien').includes(plain(q.value.trim()))));
const connectedShops = computed(() => accounts.value.filter((a) => a.status === 'connected' && markets.some((m) => m.key === a.marketplace)).length);
const showMarkets = computed(() => canMarket.value && (!q.value.trim() || plain('san thuong mai dien tu shopee tiktok lazada tiki ket noi san').includes(plain(q.value.trim()))));
</script>

<template>
    <div class="mb-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-[26px] font-bold leading-tight tracking-tight text-slate-900">Cấu hình</h1>
                <p class="mt-1 text-sm text-muted-foreground">Thiết lập một lần, dùng cho cả cửa hàng.</p>
            </div>
            <div class="relative w-80 max-w-full">
                <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input v-model="q" class="h-10 rounded-[10px] bg-white pl-9" placeholder="Tìm cài đặt, ví dụ “mẫu in”" aria-label="Tìm cài đặt" data-testid="settings-search" />
            </div>
        </div>
    </div>
    <div class="space-y-7" data-testid="settings-hub">
        <section
            v-if="showMenu"
            class="flex flex-wrap items-center gap-4 rounded-[14px] border border-slate-200/80 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.04)]"
            data-testid="settings-interface"
        >
            <div class="min-w-0 flex-1">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Giao diện</p>
                <Switch
                    :model-value="advanced"
                    label="Hiện tính năng nâng cao"
                    hint="Ca bán hàng, Đặt hàng nhập, Chuyển hàng, Điều chỉnh giá vốn, Đối soát tồn kho, Khách trùng, Loại phiếu. Tắt để menu gọn hơn: dữ liệu không mất, vẫn mở được bằng ô tìm kiếm (Ctrl K)."
                    :disabled="savingMenu"
                    data-testid="advanced-menu"
                    @update:model-value="setAdvanced"
                />
            </div>
            <span :class="cn('rounded-md px-2 py-1 text-xs font-medium', advanced ? 'bg-blue-50 text-blue-700' : 'bg-slate-100 text-slate-600')">{{ advanced ? 'Menu đầy đủ' : 'Menu cơ bản' }}</span>
        </section>
        <template v-for="(g, gi) in visible" :key="g.title">
            <section>
                <h2 class="mb-3 text-[13px] font-semibold uppercase tracking-wide text-slate-500">{{ g.title }}</h2>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <RouterLink
                        v-for="t in g.tiles"
                        :key="t.to"
                        :to="t.to"
                        class="group flex items-start gap-3.5 rounded-[14px] border border-slate-200/80 bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition-colors hover:border-blue-200 hover:shadow-[0_2px_8px_rgba(37,99,235,0.08)]"
                    >
                        <span :class="cn('grid size-10 shrink-0 place-items-center rounded-[11px]', toneClasses[g.tone])"><component :is="t.icon" class="size-5" :stroke-width="1.9" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-semibold text-slate-900 group-hover:text-blue-600">{{ t.title }}</span>
                            <span class="mt-0.5 block text-[13px] leading-snug text-slate-500">{{ t.description }}</span>
                        </span>
                        <ChevronRight class="mt-2.5 size-4 shrink-0 text-slate-300 group-hover:text-blue-500" aria-hidden="true" />
                    </RouterLink>
                </div>
            </section>
            <!-- After the shop settings: the marketplaces, one card each, with their state now. -->
            <section v-if="gi === 0 && showMarkets" data-testid="settings-marketplaces">
                <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-[13px] font-semibold uppercase tracking-wide text-slate-500">Sàn thương mại điện tử</h2>
                    <span class="text-xs text-muted-foreground">{{ connectedShops ? `${connectedShops} shop đang kết nối · ` : '' }}Mỗi sàn kết nối riêng, mỗi sàn có thể nhiều shop</span>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <div v-for="m in markets" :key="m.key" class="flex flex-col rounded-[14px] border border-slate-200/80 bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
                        <div class="flex items-center gap-3">
                            <MarketplaceLogo :marketplace="m.key" :name="m.name" size="lg" />
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-slate-900">{{ m.name }}</p>
                                <p :class="['flex items-center gap-1.5 truncate text-[13px]', marketStatus(m.key).tone]"><span :class="['size-1.5 shrink-0 rounded-full', marketStatus(m.key).dot]" />{{ marketStatus(m.key).text }}</p>
                            </div>
                        </div>
                        <RouterLink
                            :to="{ path: '/marketplaces', query: { san: m.key } }"
                            :class="[
                                'mt-4 inline-flex h-9 items-center justify-center rounded-[10px] text-sm font-medium',
                                marketStatus(m.key).connected ? 'border border-slate-200 text-slate-700 hover:bg-slate-50' : 'bg-blue-50 text-blue-700 hover:bg-blue-100',
                            ]"
                        >
                            {{ marketStatus(m.key).connected ? 'Quản lý' : 'Kết nối' }}
                        </RouterLink>
                    </div>
                </div>
            </section>
        </template>
        <p v-if="!visible.length && !showMarkets && !showMenu" class="rounded-[14px] border border-slate-200/80 bg-white p-8 text-center text-sm text-muted-foreground">Không thấy cài đặt nào khớp “{{ q }}”.</p>
    </div>
</template>
