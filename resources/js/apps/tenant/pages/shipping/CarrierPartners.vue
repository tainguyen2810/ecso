<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { FileUp, Hand } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import CarrierLogo from '@/shared/ui/CarrierLogo.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import type { MarketplaceAccountData } from '@/shared/types';
import CarrierConnectDialog from '../shipments/CarrierConnectDialog.vue';
import ShipmentImportDialog from '../shipments/ShipmentImportDialog.vue';
import { loadCarrierAccounts, type CarrierAccountData, type CarrierCatalogItem } from '../shipments/carriers';

/**
 * Vận chuyển › Kết nối đối tác: carriers the shop connects with its own account (same dialog as Cấu hình › Kết nối
 * dịch vụ), the marketplaces' own carriers (nothing to connect), and parcels the shop delivers itself.
 */
const tenant = useTenantStore();
const loading = ref(true);
const error = ref<string | null>(null);
const carriers = ref<CarrierCatalogItem[]>([]);
const accounts = ref<CarrierAccountData[]>([]);
const shops = ref<MarketplaceAccountData[]>([]);

async function load() {
    error.value = null;
    try {
        const [catalog, accs, m] = await Promise.all([
            api.get('/carriers'),
            loadCarrierAccounts(),
            tenant.can('marketplace.view') ? api.get('/marketplace-accounts').catch(() => null) : Promise.resolve(null),
        ]);
        carriers.value = (catalog.data.data as CarrierCatalogItem[]).filter((c) => c.carrier !== 'fake' || accs.some((a) => a.carrier === 'fake'));
        accounts.value = accs;
        shops.value = ((m?.data.data ?? []) as MarketplaceAccountData[]).filter((s) => ['shopee', 'tiktok', 'lazada'].includes(s.marketplace) && s.status === 'connected');
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

const canManage = computed(() => tenant.can('settings.manage'));
const own = computed(() => carriers.value.filter((c) => c.carrier !== 'spx'));
const accountsOf = (key: string) => accounts.value.filter((a) => a.carrier === key);
const state = (c: CarrierCatalogItem) => {
    const list = accountsOf(c.carrier);
    if (list.some((a) => a.status === 'error')) return { tone: 'danger', label: 'Lỗi kết nối' } as const;
    if (list.length) return { tone: 'success', label: 'Đã kết nối' } as const;
    if (!c.available) return { tone: 'muted', label: 'Sắp có' } as const;
    return { tone: 'muted', label: 'Chưa kết nối' } as const;
};
const pickupCount = (a: CarrierAccountData) => a.pickups.filter((p) => p.pickup).length;

const connectOpen = ref(false);
const connectPick = ref<string | null>(null);
function openConnect(key: string) {
    connectPick.value = key;
    connectOpen.value = true;
}
const importOpen = ref(false);
</script>

<template>
    <PageHeader title="Kết nối đối tác vận chuyển" description="Shop dùng tài khoản của chính mình với từng hãng. Phí ship hãng tính thẳng cho shop, phần mềm không thu thêm." />
    <ErrorState v-if="error" :message="error" @retry="load" />
    <div v-else-if="loading" class="space-y-4"><Skeleton class="h-48" /><Skeleton class="h-32" /></div>
    <div v-else class="space-y-5" data-testid="carrier-partners">
        <Card caps title="Hãng vận chuyển" description="Kết nối để so giá, đẩy đơn, in phiếu và tự cập nhật hành trình">
            <div class="grid gap-3 p-4 md:grid-cols-2">
                <div v-for="c in own" :key="c.carrier" class="flex items-center gap-4 rounded-lg border p-4" :class="!c.available && 'bg-muted/30'" :data-testid="`partner-${c.carrier}`">
                    <div class="grid h-14 w-32 shrink-0 place-items-center rounded-md border bg-white">
                        <CarrierLogo :carrier="c.carrier" :name="c.label" :width="104" :height="36" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{ c.label }}</span>
                            <Badge :tone="state(c).tone">{{ state(c).label }}</Badge>
                        </div>
                        <div class="mt-1 text-xs text-muted-foreground">
                            <template v-if="accountsOf(c.carrier).length">
                                <div v-for="a in accountsOf(c.carrier)" :key="a.id">
                                    {{ a.name }}<template v-if="a.environment === 'sandbox'"> · thử nghiệm</template> · {{ pickupCount(a) }} kho lấy hàng
                                    <template v-if="a.last_event_at"> · cập nhật {{ formatDateTime(a.last_event_at) }}</template>
                                    <div v-if="a.status === 'error' && a.last_error" class="text-rose-600">{{ a.last_error }}</div>
                                </div>
                            </template>
                            <template v-else-if="!c.available">{{ c.note }}</template>
                            <template v-else>Nhập khóa API do hãng cấp để bắt đầu.</template>
                        </div>
                    </div>
                    <Button v-if="c.available && canManage" size="sm" :variant="accountsOf(c.carrier).length ? 'outline' : 'default'" :data-testid="`partner-open-${c.carrier}`" @click="openConnect(c.carrier)">
                        {{ accountsOf(c.carrier).length ? 'Cài đặt' : 'Kết nối' }}
                    </Button>
                </div>
            </div>
        </Card>

        <Card caps title="Vận chuyển của sàn" description="Đơn Shopee, TikTok Shop đi hãng do sàn chỉ định. Không cần kết nối riêng, mã vận đơn và trạng thái lấy theo sàn.">
            <div v-if="shops.length" class="divide-y">
                <div v-for="s in shops" :key="s.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
                    <MarketplaceLogo :marketplace="s.marketplace" :name="s.marketplace_name" />
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">{{ s.shop_name }}</div>
                        <div class="text-xs text-muted-foreground">{{ s.marketplace_name }}<template v-if="s.marketplace === 'shopee'"> · SPX Express, J&T, GHN… do sàn chọn</template></div>
                    </div>
                    <Badge tone="success">Tự động theo sàn</Badge>
                </div>
            </div>
            <div v-else class="flex flex-wrap items-center gap-3 px-5 py-4 text-sm text-muted-foreground">
                Chưa kết nối gian hàng nào.
                <RouterLink to="/marketplaces" class="font-medium text-primary hover:underline">Kết nối sàn</RouterLink>
            </div>
        </Card>

        <Card caps title="Tự giao hoặc hãng khác">
            <div class="flex flex-wrap items-center gap-3 px-5 py-4">
                <span class="grid size-10 shrink-0 place-items-center rounded-full bg-slate-100 text-slate-500"><Hand class="size-5" /></span>
                <div class="min-w-0 flex-1">
                    <div class="font-medium">Shop tự giao, hoặc gửi hãng chưa kết nối</div>
                    <div class="text-xs text-muted-foreground">Nhập mã vận đơn khi giao hàng, rồi cập nhật trạng thái hàng loạt bằng file Excel của hãng.</div>
                </div>
                <Button v-if="tenant.can('orders.update')" size="sm" variant="outline" data-testid="partner-import" @click="importOpen = true"><FileUp class="size-4" /> Nhập trạng thái từ file</Button>
            </div>
        </Card>
    </div>
    <CarrierConnectDialog v-model:open="connectOpen" :accounts="accounts" :initial="connectPick" @changed="load" />
    <ShipmentImportDialog v-model:open="importOpen" @saved="load" />
</template>
