<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { CircleAlert, CircleCheck, FileUp, Hand, Plug, Plus, RefreshCw, Settings2, ShieldCheck, Zap } from 'lucide-vue-next';
import CarrierBox from './CarrierBox.vue';
import ShipHead from './ShipHead.vue';
import { btn, card, pct } from './ui';
import ErrorState from '@/shared/ui/ErrorState.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDateTime, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import type { MarketplaceAccountData } from '@/shared/types';
import { presetRange } from '../reports/range';
import CarrierConnectDialog from '../shipments/CarrierConnectDialog.vue';
import ShipmentImportDialog from '../shipments/ShipmentImportDialog.vue';
import { loadCarrierAccounts, type CarrierAccountData, type CarrierCatalogItem } from '../shipments/carriers';

/**
 * Vận chuyển › Kết nối đối tác (mẫu 28/11): carriers the shop connects with its own account (same dialog as Cấu hình ›
 * Kết nối dịch vụ) with their last 30 days, the marketplaces' own carriers (nothing to connect), and parcels the shop
 * delivers itself.
 */
interface CarrierStat { carrier: string; carrier_name: string | null; marketplace: boolean; count: number; delivered_rate: number | null; avg_fee: string | null }
interface ChannelStat { label: string; kind: string | null; count: number }

const tenant = useTenantStore();
const loading = ref(true);
const error = ref<string | null>(null);
const carriers = ref<CarrierCatalogItem[]>([]);
const accounts = ref<CarrierAccountData[]>([]);
const shops = ref<MarketplaceAccountData[]>([]);
const stats = ref<CarrierStat[]>([]);
const channelStats = ref<ChannelStat[]>([]);

async function load() {
    error.value = null;
    try {
        const [catalog, accs, m, o] = await Promise.all([
            api.get('/carriers'),
            loadCarrierAccounts(),
            tenant.can('marketplace.view') ? api.get('/marketplace-accounts').catch(() => null) : Promise.resolve(null),
            api.get('/shipping/overview', { params: presetRange('30d') }).catch(() => null),
        ]);
        carriers.value = (catalog.data.data as CarrierCatalogItem[]).filter((c) => c.carrier !== 'fake' || accs.some((a) => a.carrier === 'fake'));
        accounts.value = accs;
        shops.value = ((m?.data.data ?? []) as MarketplaceAccountData[]).filter((s) => ['shopee', 'tiktok', 'lazada'].includes(s.marketplace) && s.status === 'connected');
        stats.value = o?.data.data.carriers ?? [];
        channelStats.value = o?.data.data.channels ?? [];
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

const canManage = computed(() => tenant.can('settings.manage'));
/** Connected and connectable carriers first, the ones still to come last. */
const own = computed(() => carriers.value.filter((c) => c.carrier !== 'spx' || !c.available).sort((a, b) => Number(b.available) - Number(a.available)));
const accountsOf = (key: string) => accounts.value.filter((a) => a.carrier === key);
const state = (c: CarrierCatalogItem) => {
    const list = accountsOf(c.carrier);
    if (list.some((a) => a.status === 'error')) return 'error';
    if (list.length) return 'connected';
    if (!c.available) return 'soon';
    return 'off';
};
const statOf = (key: string) => stats.value.find((s) => s.carrier === key && !s.marketplace) ?? null;
const pickupCount = (a: CarrierAccountData) => a.pickups.filter((p) => p.pickup).length;
const shopCount = (s: MarketplaceAccountData) => channelStats.value.find((c) => c.kind === s.marketplace && c.label === s.shop_name)?.count ?? null;
const selfCount = computed(() => stats.value.find((s) => s.carrier === 'other')?.count ?? 0);
const connectable = computed(() => carriers.value.filter((c) => c.available && c.carrier !== 'fake').length);
const upcoming = computed(() => carriers.value.filter((c) => !c.available).length);
/** "Hãng giao tốt nhất 30 ngày": the two with the best delivery rate among carriers with at least 5 parcels. */
const best = computed(() => stats.value.filter((s) => s.count >= 5 && s.delivered_rate !== null && s.carrier !== 'other').sort((a, b) => (b.delivered_rate ?? 0) - (a.delivered_rate ?? 0)).slice(0, 2));
const money = (v: string | null) => (v === null ? '—' : `${formatNumber(Math.round(Number(v)))} đ`);

const connectOpen = ref(false);
const connectPick = ref<string | null>(null);
function openConnect(key: string | null) {
    connectPick.value = key;
    connectOpen.value = true;
}
const importOpen = ref(false);
</script>

<template>
    <ShipHead title="Kết nối đối tác vận chuyển" description="Shop dùng tài khoản của chính mình với từng hãng. Phí ship hãng tính thẳng cho shop, phần mềm không thu thêm.">
        <button v-if="canManage" type="button" :class="btn.primary" data-testid="partner-new" @click="openConnect(null)"><Plus class="size-4" /> Kết nối hãng mới</button>
    </ShipHead>
    <ErrorState v-if="error" :message="error" @retry="load" />
    <div v-else-if="loading" class="space-y-4"><Skeleton class="h-48" /><Skeleton class="h-32" /></div>
    <div v-else class="grid grid-cols-1 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_380px] 2xl:grid-cols-[minmax(0,1fr)_400px]" data-testid="carrier-partners">
        <div class="min-w-0">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-[18px] font-semibold text-slate-900">Hãng vận chuyển</h2>
                <span class="text-[13px] text-slate-500">{{ connectable }} hãng kết nối được<template v-if="upcoming"> · {{ upcoming }} sắp có</template></span>
            </div>
            <div class="grid gap-5 lg:grid-cols-2">
                <div v-for="c in own" :key="c.carrier" :class="cn(card, 'p-5', state(c) === 'soon' && 'opacity-75')" :data-testid="`partner-${c.carrier}`">
                    <div class="flex items-center justify-between gap-3">
                        <CarrierBox :carrier="c.carrier" :name="c.label" size="lg" />
                        <template v-if="c.available && canManage">
                            <button v-if="state(c) === 'error'" type="button" :class="btn.smallPrimary" :data-testid="`partner-open-${c.carrier}`" @click="openConnect(c.carrier)"><RefreshCw class="size-3.5" /> Kết nối lại</button>
                            <button v-else-if="state(c) === 'connected'" type="button" :class="btn.small" :data-testid="`partner-open-${c.carrier}`" @click="openConnect(c.carrier)"><Settings2 class="size-3.5" /> Cài đặt</button>
                            <button v-else type="button" :class="btn.smallPrimary" :data-testid="`partner-open-${c.carrier}`" @click="openConnect(c.carrier)"><Plug class="size-3.5" /> Kết nối</button>
                        </template>
                    </div>
                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <p class="text-[16px] font-semibold text-slate-900">{{ c.label }}</p>
                        <span v-if="state(c) === 'connected'" class="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-2 py-0.5 text-[12px] font-medium text-emerald-700"><CircleCheck class="size-3" /> Đã kết nối</span>
                        <span v-else-if="state(c) === 'error'" class="inline-flex items-center gap-1 rounded-md bg-red-50 px-2 py-0.5 text-[12px] font-medium text-red-600"><CircleAlert class="size-3" /> Lỗi kết nối</span>
                        <span v-else-if="state(c) === 'soon'" class="rounded-md bg-slate-100 px-2 py-0.5 text-[12px] font-medium text-slate-600">Sắp có</span>
                        <span v-else class="rounded-md bg-slate-100 px-2 py-0.5 text-[12px] font-medium text-slate-600">Chưa kết nối</span>
                    </div>
                    <div class="mt-2 space-y-1 text-[13px]">
                        <template v-if="accountsOf(c.carrier).length">
                            <div v-for="a in accountsOf(c.carrier)" :key="a.id">
                                <p class="flex flex-wrap items-center gap-x-2">
                                    <span :class="cn('size-1.5 rounded-full', a.status === 'connected' ? 'bg-emerald-500' : 'bg-red-500')" />
                                    <span class="font-medium text-slate-700">{{ a.name }}</span>
                                    <span class="text-slate-400">· {{ pickupCount(a) }} kho lấy hàng<template v-if="a.environment === 'sandbox'"> · thử nghiệm</template><template v-if="a.last_event_at"> · cập nhật {{ formatDateTime(a.last_event_at) }}</template></span>
                                </p>
                                <p v-if="a.status === 'error' && a.last_error" class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-[12px] text-red-600">{{ a.last_error }}</p>
                            </div>
                        </template>
                        <p v-else-if="!c.available" class="text-slate-500">{{ c.note }}</p>
                        <p v-else class="text-slate-500">Nhập khóa API do hãng cấp để bắt đầu.</p>
                    </div>
                    <div v-if="accountsOf(c.carrier).length && statOf(c.carrier)" class="mt-4 grid grid-cols-3 gap-2 border-t border-slate-100 pt-4">
                        <div><p class="text-[12px] text-slate-400">Đơn 30 ngày</p><p class="text-[15px] font-semibold tabular-nums">{{ formatNumber(statOf(c.carrier)!.count) }}</p></div>
                        <div><p class="text-[12px] text-slate-400">Giao thành công</p><p class="text-[15px] font-semibold tabular-nums">{{ pct(statOf(c.carrier)!.delivered_rate) }}</p></div>
                        <div><p class="text-[12px] text-slate-400">Phí TB</p><p class="text-[15px] font-semibold tabular-nums">{{ money(statOf(c.carrier)!.avg_fee) }}</p></div>
                    </div>
                </div>
            </div>

            <section :class="cn(card, 'mt-6')">
                <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                    <div>
                        <h3 class="text-[16px] font-semibold text-slate-900">Vận chuyển của sàn</h3>
                        <p class="mt-0.5 text-[13px] text-slate-500">Đơn Shopee, TikTok Shop đi hãng do sàn chỉ định. Không cần kết nối riêng, mã vận đơn và trạng thái lấy theo sàn.</p>
                    </div>
                    <RouterLink to="/marketplaces" class="text-[13px] font-medium text-blue-600 hover:underline">Quản lý gian hàng</RouterLink>
                </header>
                <div v-if="shops.length" class="grid gap-4 p-5 lg:grid-cols-2">
                    <div v-for="s in shops" :key="s.id" class="flex flex-wrap items-center gap-4 rounded-[12px] border border-slate-100 bg-slate-50/50 p-4">
                        <MarketplaceLogo :marketplace="s.marketplace" :name="s.marketplace_name" size="lg" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[15px] font-semibold text-slate-900">{{ s.shop_name }}</p>
                            <p class="text-[13px] text-slate-500">{{ s.marketplace_name }}<template v-if="s.marketplace === 'shopee'"> · SPX Express, J&amp;T, GHN… do sàn chọn</template><template v-else> · hãng do sàn chọn</template></p>
                        </div>
                        <div v-if="shopCount(s) !== null" class="text-right"><p class="text-[15px] font-semibold tabular-nums">{{ formatNumber(shopCount(s)) }} đơn</p><p class="text-[12px] text-slate-400">30 ngày</p></div>
                        <span class="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-2 py-0.5 text-[12px] font-medium text-emerald-700"><Zap class="size-3" /> Tự động theo sàn</span>
                    </div>
                </div>
                <div v-else class="flex flex-wrap items-center gap-3 px-5 py-4 text-[14px] text-slate-500">
                    Chưa kết nối gian hàng nào.
                    <RouterLink to="/marketplaces" class="font-medium text-blue-600 hover:underline">Kết nối sàn</RouterLink>
                </div>
            </section>

            <section :class="cn(card, 'mt-6 flex flex-wrap items-center gap-4 p-5')">
                <span class="grid size-12 shrink-0 place-items-center rounded-[12px] bg-slate-100 text-slate-500"><Hand class="size-6" /></span>
                <div class="min-w-0 flex-1">
                    <p class="text-[16px] font-semibold text-slate-900">Shop tự giao, hoặc gửi hãng chưa kết nối</p>
                    <p class="text-[13px] text-slate-500">Nhập mã vận đơn khi giao hàng, rồi cập nhật trạng thái hàng loạt bằng file Excel của hãng.<template v-if="selfCount"> {{ formatNumber(selfCount) }} đơn tự giao trong 30 ngày.</template></p>
                </div>
                <button v-if="tenant.can('orders.update')" type="button" :class="btn.outline" data-testid="partner-import" @click="importOpen = true"><FileUp class="size-4" /> Nhập trạng thái từ file</button>
            </section>
        </div>

        <div class="space-y-5">
            <section :class="cn(card, 'p-5')">
                <p class="text-[16px] font-semibold text-slate-900">Kết nối trong 1 phút</p>
                <ol class="mt-4 space-y-4">
                    <li v-for="(s, i) in [
                        ['Lấy khóa API', 'Đăng nhập trang của hãng, vào mục Tích hợp hoặc API để lấy mã.'],
                        ['Dán vào phần mềm', 'Chọn kho lấy hàng tương ứng với cửa hàng trên trang hãng.'],
                        ['Đẩy đơn, in phiếu', 'So giá các hãng ngay trên đơn, hành trình tự cập nhật.'],
                    ]" :key="i" class="flex gap-3">
                        <span class="grid size-8 shrink-0 place-items-center rounded-full bg-blue-50 text-[13px] font-semibold text-blue-600">{{ i + 1 }}</span>
                        <div><p class="text-[14px] font-medium text-slate-900">{{ s[0] }}</p><p class="text-[13px] text-slate-500">{{ s[1] }}</p></div>
                    </li>
                </ol>
            </section>
            <section class="rounded-[14px] bg-gradient-to-br from-blue-600 to-indigo-600 p-5 text-white shadow-[0_8px_24px_-8px_rgba(37,99,235,0.55)]">
                <span class="grid size-10 place-items-center rounded-[11px] bg-white/15"><ShieldCheck class="size-5" /></span>
                <p class="mt-3 text-[16px] font-semibold">Phí ship tính thẳng cho shop</p>
                <p class="mt-1 text-[13px] text-blue-100">Shop dùng tài khoản của chính mình với từng hãng, giữ giá đã thương lượng. Phần mềm không thu thêm phí.</p>
            </section>
            <section v-if="best.length" :class="cn(card, 'p-5')" data-testid="partner-best">
                <p class="text-[14px] text-slate-500">Hãng giao tốt nhất 30 ngày</p>
                <div v-for="b in best" :key="b.carrier" class="mt-3 flex items-center gap-3">
                    <CarrierBox :carrier="b.carrier" :name="b.carrier_name" />
                    <div>
                        <p class="text-[15px] font-semibold text-slate-900">{{ b.carrier_name ?? b.carrier }}</p>
                        <p class="text-[13px] text-emerald-600">{{ pct(b.delivered_rate) }} giao thành công · {{ formatNumber(b.count) }} đơn</p>
                    </div>
                </div>
            </section>
        </div>
    </div>
    <CarrierConnectDialog v-model:open="connectOpen" :accounts="accounts" :initial="connectPick" @changed="load" />
    <ShipmentImportDialog v-model:open="importOpen" @saved="load" />
</template>
