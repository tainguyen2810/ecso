<script setup lang="ts">
import { computed, onMounted, ref, type Component } from 'vue';
import { ArrowRightLeft, Boxes, Cable, CreditCard, HandCoins, MessageSquareWarning, Percent, Printer, Receipt, ScrollText, Search, SlidersHorizontal, Sparkles, Store, Users, UsersRound, Wallet, Warehouse } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Input from '@/shared/ui/Input.vue';
import Switch from '@/shared/ui/Switch.vue';
import { useToast } from '@/shared/composables/useToast';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import { api } from '@/shared/lib/api';
import { useTenantStore } from '@/shared/stores/tenant';

/** "Cấu hình" like Sapo: every business setting in one place, grouped, each tile opening its own page. */
interface Tile {
    to: string;
    icon: Component;
    title: string;
    description: string;
    permission: string;
}

const tenant = useTenantStore();
const groups: { title: string; tiles: Tile[] }[] = [
    {
        title: 'Cửa hàng',
        tiles: [
            { to: '/settings/store', icon: Store, title: 'Thông tin cửa hàng', description: 'Tên, số điện thoại, địa chỉ, mã số thuế, logo in trên hóa đơn', permission: 'settings.manage' },
            { to: '/inventory/warehouses', icon: Warehouse, title: 'Quản lý kho, chi nhánh', description: 'Thêm mới và quản lý kho, kho mặc định', permission: 'inventory.view' },
            { to: '/members', icon: Users, title: 'Nhân viên và phân quyền', description: 'Tài khoản nhân viên, vai trò, lời mời', permission: 'members.view' },
            { to: '/subscription', icon: CreditCard, title: 'Gói dịch vụ', description: 'Gói đang dùng, giới hạn và gia hạn', permission: 'billing.view' },
            { to: '/settings/pricing', icon: Percent, title: 'Thuế và chính sách giá', description: 'Thuế bán, thuế nhập mặc định; giá bán mặc định; cho nhân viên sửa giá', permission: 'settings.manage' },
            { to: '/settings/payments', icon: Wallet, title: 'Thanh toán', description: 'Tài khoản ngân hàng (VietQR trên hóa đơn), phương thức mặc định', permission: 'settings.manage' },
            { to: '/settings/print', icon: Printer, title: 'Mẫu in', description: 'Khổ in mặc định, logo, mã VietQR, chữ ký, lời chào cuối phiếu', permission: 'settings.manage' },
            { to: '/products/taxonomy', icon: Boxes, title: 'Danh mục và thương hiệu', description: 'Phân nhóm sản phẩm để lọc và báo cáo', permission: 'products.view' },
        ],
    },
    {
        title: 'Bán hàng',
        tiles: [
            { to: '/settings/sales', icon: SlidersHorizontal, title: 'Cấu hình bán hàng', description: 'Sửa thời gian bán, số liên in, bán tại quầy, lý do hủy và trả hàng', permission: 'settings.manage' },
            { to: '/sales-channels', icon: Store, title: 'Kênh bán riêng', description: 'Quầy, cửa hàng, Facebook, Zalo, website', permission: 'orders.view' },
            { to: '/settings/connections', icon: Cable, title: 'Kết nối dịch vụ', description: 'Vận chuyển, hóa đơn điện tử, Zalo của shop', permission: 'settings.manage' },
            { to: '/settings/sales#reasons', icon: MessageSquareWarning, title: 'Lý do hủy, trả hàng', description: 'Danh sách lý do chọn khi hủy đơn hoặc nhận hàng trả', permission: 'settings.manage' },
            { to: '/cash/types', icon: Receipt, title: 'Loại phiếu thu chi', description: 'Phân loại phiếu thu, phiếu chi', permission: 'finance.view' },
            { to: '/customers/groups', icon: UsersRound, title: 'Nhóm khách hàng', description: 'Nhóm khách và chính sách giá theo nhóm', permission: 'customers.view' },
        ],
    },
    {
        title: 'Dữ liệu',
        tiles: [
            { to: '/settings/data-migration', icon: ArrowRightLeft, title: 'Chuyển dữ liệu', description: 'Chuyển sản phẩm, tồn kho, khách hàng, công nợ từ Sapo, KiotViet', permission: 'products.manage' },
            { to: '/settings/demo-store', icon: Sparkles, title: 'Cửa hàng mẫu', description: 'Cửa hàng riêng có dữ liệu giả để bấm thử, không đụng dữ liệu thật', permission: 'settings.manage' },
            { to: '/audit', icon: ScrollText, title: 'Nhật ký hoạt động', description: 'Ai làm gì, lúc nào trong doanh nghiệp', permission: 'audit.view' },
            { to: '/reports/cashbook', icon: HandCoins, title: 'Sổ quỹ', description: 'Dòng tiền thu chi theo ngày', permission: 'finance.view' },
        ],
    },
];
// "Tìm cài đặt": matches titles and descriptions, without Vietnamese accents.
const q = ref('');
const plain = (x: string) => x.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D').toLowerCase();
const matches = (t: Tile) => !q.value.trim() || plain(`${t.title} ${t.description}`).includes(plain(q.value.trim()));
const visible = computed(() => groups.map((g) => ({ ...g, tiles: g.tiles.filter((t) => tenant.can(t.permission) && matches(t)) })).filter((g) => g.tiles.length));

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
    if (!mine.length) return { text: 'Chưa kết nối', tone: 'text-muted-foreground', connected: false };
    if (bad > 0) return { text: `${ok} shop đang chạy · ${bad} shop cần xem lại`, tone: 'text-amber-600', connected: true };
    return { text: ok ? `${ok} shop đang chạy` : 'Đã ngắt kết nối', tone: ok ? 'text-emerald-600' : 'text-muted-foreground', connected: ok > 0 };
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
const showMarkets = computed(() => canMarket.value && (!q.value.trim() || plain('san thuong mai dien tu shopee tiktok lazada tiki ket noi san').includes(plain(q.value.trim()))));
</script>

<template>
    <PageHeader title="Cấu hình" description="Thiết lập một lần, dùng cho cả cửa hàng.">
        <div class="relative w-72 max-w-full">
            <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input v-model="q" class="pl-9" placeholder="Tìm cài đặt, ví dụ “mẫu in”" aria-label="Tìm cài đặt" data-testid="settings-search" />
        </div>
    </PageHeader>
    <div class="space-y-5" data-testid="settings-hub">
        <section v-if="showMenu" class="rounded-xl border bg-card p-5" data-testid="settings-interface">
            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Giao diện</h2>
            <Switch
                :model-value="advanced"
                label="Hiện tính năng nâng cao"
                hint="Ca bán hàng, Đặt hàng nhập, Chuyển hàng, Điều chỉnh giá vốn, Đối soát tồn kho, Khách trùng, Loại phiếu. Tắt để menu gọn hơn: dữ liệu không mất, vẫn mở được bằng ô tìm kiếm (Ctrl K)."
                :disabled="savingMenu"
                data-testid="advanced-menu"
                @update:model-value="setAdvanced"
            />
        </section>
        <template v-for="(g, gi) in visible" :key="g.title">
            <section class="rounded-xl border bg-card p-5">
                <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{{ g.title }}</h2>
                <div class="grid gap-1 sm:grid-cols-2 xl:grid-cols-3">
                    <RouterLink v-for="t in g.tiles" :key="t.to" :to="t.to" class="flex items-start gap-3 rounded-lg p-3 hover:bg-slate-50">
                        <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><component :is="t.icon" class="size-5" :stroke-width="1.75" /></span>
                        <span>
                            <span class="block font-medium">{{ t.title }}</span>
                            <span class="block text-[13px] text-muted-foreground">{{ t.description }}</span>
                        </span>
                    </RouterLink>
                </div>
            </section>
            <!-- After the shop settings: the marketplaces, one line each, with their state now. -->
            <section v-if="gi === 0 && showMarkets" class="rounded-xl border bg-card p-5" data-testid="settings-marketplaces">
                <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Sàn thương mại điện tử</h2>
                    <span class="text-xs text-muted-foreground">Mỗi sàn kết nối riêng, mỗi sàn có thể nhiều shop</span>
                </div>
                <div class="grid gap-3 md:grid-cols-2">
                    <div v-for="m in markets" :key="m.key" class="flex items-center gap-3 rounded-xl border p-3.5">
                        <MarketplaceLogo :marketplace="m.key" :name="m.name" size="lg" />
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ m.name }}</p>
                            <p :class="['truncate text-sm', marketStatus(m.key).tone]">{{ marketStatus(m.key).text }}</p>
                        </div>
                        <RouterLink
                            :to="{ path: '/marketplaces', query: { san: m.key } }"
                            :class="['inline-flex h-9 shrink-0 items-center rounded-lg px-3.5 text-sm font-medium', marketStatus(m.key).connected ? 'border bg-card hover:bg-slate-50' : 'bg-primary text-primary-foreground hover:bg-primary/90']"
                        >
                            {{ marketStatus(m.key).connected ? 'Quản lý' : 'Kết nối' }}
                        </RouterLink>
                    </div>
                </div>
            </section>
        </template>
        <p v-if="!visible.length && !showMarkets && !showMenu" class="rounded-xl border bg-card p-8 text-center text-sm text-muted-foreground">Không thấy cài đặt nào khớp “{{ q }}”.</p>
    </div>
</template>
