<script setup lang="ts">
import { computed, type Component } from 'vue';
import { Boxes, Cable, CreditCard, HandCoins, MessageSquareWarning, Percent, PlugZap, Printer, Receipt, ScrollText, SlidersHorizontal, Store, Users, UsersRound, Wallet, Warehouse } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Card from '@/shared/ui/Card.vue';
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
        title: 'Thiết lập cửa hàng',
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
        title: 'Thiết lập bán hàng',
        tiles: [
            { to: '/settings/sales', icon: SlidersHorizontal, title: 'Cấu hình bán hàng', description: 'Sửa thời gian bán, số liên in, bán tại quầy, lý do hủy và trả hàng', permission: 'settings.manage' },
            { to: '/sales-channels', icon: Store, title: 'Kênh bán riêng', description: 'Quầy, cửa hàng, Facebook, Zalo, website', permission: 'orders.view' },
            { to: '/settings/connections', icon: Cable, title: 'Kết nối dịch vụ', description: 'Hãng vận chuyển, hóa đơn điện tử, Zalo: dùng tài khoản của shop', permission: 'settings.manage' },
            { to: '/marketplaces', icon: PlugZap, title: 'Kết nối sàn', description: 'Shopee, TikTok Shop, Lazada, Tiki', permission: 'marketplace.view' },
            { to: '/settings/sales#reasons', icon: MessageSquareWarning, title: 'Lý do hủy, trả hàng', description: 'Danh sách lý do chọn khi hủy đơn hoặc nhận hàng trả', permission: 'settings.manage' },
            { to: '/cash/types', icon: Receipt, title: 'Loại phiếu thu chi', description: 'Phân loại phiếu thu, phiếu chi', permission: 'finance.view' },
            { to: '/customers/groups', icon: UsersRound, title: 'Nhóm khách hàng', description: 'Nhóm khách và chính sách giá theo nhóm', permission: 'customers.view' },
        ],
    },
    {
        title: 'Nhật ký',
        tiles: [
            { to: '/audit', icon: ScrollText, title: 'Nhật ký hoạt động', description: 'Ai làm gì, lúc nào trong doanh nghiệp', permission: 'audit.view' },
            { to: '/reports/cashbook', icon: HandCoins, title: 'Sổ quỹ', description: 'Dòng tiền thu chi theo ngày', permission: 'finance.view' },
        ],
    },
];
const visible = computed(() => groups.map((g) => ({ ...g, tiles: g.tiles.filter((t) => tenant.can(t.permission)) })).filter((g) => g.tiles.length));
</script>

<template>
    <PageHeader title="Cấu hình" />
    <div class="space-y-5" data-testid="settings-hub">
        <Card v-for="g in visible" :key="g.title" :caps="false" :title="g.title">
            <div class="grid gap-1 p-3 sm:grid-cols-2 xl:grid-cols-3">
                <RouterLink v-for="t in g.tiles" :key="t.to" :to="t.to" class="flex items-start gap-3 rounded-lg p-3 hover:bg-muted/50">
                    <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-blue-50 text-blue-600"><component :is="t.icon" class="size-5" /></span>
                    <span>
                        <span class="block font-medium text-primary">{{ t.title }}</span>
                        <span class="block text-[13px] text-muted-foreground">{{ t.description }}</span>
                    </span>
                </RouterLink>
            </div>
        </Card>
    </div>
</template>
