<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Bot, ExternalLink, MessagesSquare, PanelRightClose, PanelRightOpen, RefreshCw, Search, Send, ShoppingCart, UserRound } from 'lucide-vue-next';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Input from '@/shared/ui/Input.vue';
import { api } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import type { Variant } from '@/shared/types';
import { orderStatusBadge } from '../orders/labels';
import { refreshInbox } from '../../composables/ezchatInbox';

/**
 * Tin nhắn: the EzChat inbox (Zalo, Messenger, website chat…) framed from EzChat, signed in as this person's
 * EzChat account with a one-time ticket. EzChat tells this page which chat is open; the column beside it shows
 * that customer from this app (by phone or email), their latest orders, a stock lookup and "Tạo đơn từ chat này".
 */
interface Chat { id: string; channel: string; name: string | null; phone: string | null; email: string | null }
interface Panel {
    customer: { id: string; code: string; name: string; phone: string | null; group: string | null; orders_count: number; total_spent: number; debt: number } | null;
    orders: { id: string; code: string; status: string; marketplace: string | null; raw_status: string | null; total: number; placed_at: string | null; channel: string | null }[];
    channel_id: string | null;
}

const tenant = useTenantStore();
const router = useRouter();
const frame = ref<HTMLIFrameElement | null>(null);
const url = ref<string | null>(null);
const error = ref<{ message: string; reason: string } | null>(null);
const opening = ref(true);
const chat = ref<Chat | null>(null);
const panel = ref<Panel | null>(null);
const panelLoading = ref(false);
/** Narrow screens: the column opens over the inbox. */
const panelOpen = ref(false);

const ezOrigin = computed(() => (url.value ? new URL(url.value).origin : null));
const channelNames: Record<string, string> = { WEB: 'Website', MESSENGER: 'Messenger', ZALO: 'Zalo', TELEGRAM: 'Telegram', WHATSAPP: 'WhatsApp', SHOPEE: 'Shopee', TIKTOK: 'TikTok', INSTAGRAM: 'Instagram' };

async function open() {
    opening.value = true;
    error.value = null;
    try {
        const { data } = await api.post('/ezchat-inbox/open', {});
        url.value = data.data.url;
    } catch (e) {
        const res = (e as { response?: { data?: { message?: string; reason?: string } } }).response?.data;
        error.value = { message: res?.message ?? 'Chưa mở được hộp thư EzChat. Thử lại sau ít phút.', reason: res?.reason ?? 'error' };
        url.value = null;
    } finally {
        opening.value = false;
    }
}

let badgeTimer: ReturnType<typeof setTimeout> | undefined;
function onMessage(e: MessageEvent) {
    if (!ezOrigin.value || e.origin !== ezOrigin.value || e.source !== frame.value?.contentWindow) return;
    const d = e.data as { type?: string; conversation?: Chat | null };
    if (d?.type === 'ezchat:reload') void open();
    if (d?.type === 'ezchat:conversation') {
        chat.value = d.conversation ?? null;
        // Reading a chat lowers the menu badge.
        clearTimeout(badgeTimer);
        badgeTimer = setTimeout(() => void refreshInbox(), 1500);
    }
}

onMounted(() => {
    window.addEventListener('message', onMessage);
    void open();
});
onBeforeUnmount(() => {
    window.removeEventListener('message', onMessage);
    clearTimeout(badgeTimer);
});

watch(
    () => (chat.value ? `${chat.value.id}|${chat.value.phone}|${chat.value.email}` : ''),
    async () => {
        panel.value = null;
        stockQ.value = '';
        stockRows.value = [];
        if (!chat.value) return;
        panelLoading.value = true;
        try {
            const { data } = await api.get('/ezchat-inbox/customer', { params: { phone: chat.value.phone || undefined, email: chat.value.email || undefined } });
            panel.value = data.data;
        } catch {
            panel.value = { customer: null, orders: [], channel_id: null };
        } finally {
            panelLoading.value = false;
        }
    },
);

/** Puts text in EzChat's reply box; staff read it and press Gửi there. */
function compose(text: string) {
    if (!ezOrigin.value) return;
    frame.value?.contentWindow?.postMessage({ type: 'ezchat:compose', text }, ezOrigin.value);
}
function sendStatus(o: Panel['orders'][number]) {
    compose(`Đơn ${o.code}: ${orderStatusBadge(o).label} · ${formatMoney(o.total)}`);
}

// Tra tồn nhanh.
const stockQ = ref('');
const stockRows = ref<Variant[]>([]);
let stockTimer: ReturnType<typeof setTimeout> | undefined;
/** The default warehouse, so each line shows what is left there. */
let warehouse: Promise<string | undefined> | null = null;
const defaultWarehouse = () =>
    (warehouse ??= api
        .get('/warehouses')
        .then(({ data }) => {
            const list = data.data as { id: string; is_default?: boolean; status: string }[];
            return (list.find((w) => w.is_default && w.status === 'active') ?? list.find((w) => w.status === 'active'))?.id;
        })
        .catch(() => undefined));
watch(stockQ, (q) => {
    clearTimeout(stockTimer);
    if (!q.trim()) {
        stockRows.value = [];
        return;
    }
    stockTimer = setTimeout(async () => {
        const { data } = await api.get('/variants', { params: { q, sellable: 1, per_page: 6, warehouse: await defaultWarehouse() } }).catch(() => ({ data: { data: [] } }));
        stockRows.value = (data.data as Variant[]).slice(0, 6);
    }, 250);
});
const variantName = (v: Variant) => [v.product?.name ?? v.name, Object.values(v.options ?? {}).join(' / ')].filter(Boolean).join(' · ');
function sendProduct(v: Variant) {
    compose(`${variantName(v)}: ${formatMoney(v.ref_price)}${v.stock ? ` (còn ${formatNumber(v.stock.available)})` : ''}`);
}

/** Tạo đơn from this chat, in a new tab so the chat stays open: the customer (or their name and phone) and the EzChat channel. */
const newOrderHref = computed(() => {
    const query: Record<string, string> = {};
    if (panel.value?.customer) query.customer = panel.value.customer.id;
    else if (chat.value) {
        if (chat.value.name) query.new_name = chat.value.name;
        if (chat.value.phone) query.new_phone = chat.value.phone;
    }
    if (panel.value?.channel_id) query.channel = panel.value.channel_id;
    return router.resolve({ path: '/orders/new', query }).href;
});
const fullInbox = computed(() => (ezOrigin.value ? `${ezOrigin.value}/inbox${chat.value ? `?c=${encodeURIComponent(chat.value.id)}` : ''}` : '#'));
</script>

<template>
    <div class="flex h-[calc(100dvh-3.5rem-4rem)] min-h-0 flex-1 flex-col lg:h-[calc(100dvh-3.5rem)]" data-testid="messages-page">
        <div class="flex h-12 shrink-0 items-center gap-2 border-b bg-card px-4">
            <MessagesSquare class="size-5 text-slate-500" />
            <h1 class="font-semibold">Tin nhắn</h1>
            <span class="hidden items-center gap-1 rounded-full bg-violet-50 px-2 py-0.5 text-xs font-medium text-violet-700 sm:inline-flex"><Bot class="size-3.5" /> Hộp thư EzChat</span>
            <div class="ml-auto flex items-center gap-2">
                <a v-if="url" :href="fullInbox" target="_blank" rel="noopener" class="hidden h-8 items-center gap-1.5 rounded-md border px-2.5 text-sm hover:bg-muted sm:inline-flex" data-testid="open-full-ezchat">
                    <ExternalLink class="size-4" /> Mở EzChat đầy đủ
                </a>
                <button
                    v-if="url"
                    type="button"
                    class="inline-flex h-8 items-center gap-1.5 rounded-md border px-2.5 text-sm hover:bg-muted xl:hidden"
                    data-testid="toggle-customer-panel"
                    @click="panelOpen = !panelOpen"
                >
                    <PanelRightClose v-if="panelOpen" class="size-4" /><PanelRightOpen v-else class="size-4" /> Khách, đơn
                </button>
            </div>
        </div>

        <div class="relative flex min-h-0 flex-1">
            <div v-if="opening && !url" class="grid flex-1 place-items-center text-sm text-muted-foreground">Đang mở hộp thư EzChat…</div>
            <div v-else-if="error" class="grid flex-1 place-items-center p-6" data-testid="messages-error">
                <div class="max-w-md space-y-3 rounded-xl border bg-card p-6 text-center">
                    <MessagesSquare class="mx-auto size-8 text-slate-400" />
                    <p class="font-medium">{{ error.message }}</p>
                    <div class="flex justify-center gap-2">
                        <Button v-if="error.reason === 'not_linked' && tenant.can('settings.manage')" variant="outline" @click="router.push('/sales-channels')">Nối với EzChat</Button>
                        <Button variant="outline" @click="open"><RefreshCw /> Thử lại</Button>
                    </div>
                </div>
            </div>
            <iframe
                v-if="url"
                ref="frame"
                :src="url"
                title="Hộp thư EzChat"
                class="min-w-0 flex-1 border-0 bg-white"
                allow="clipboard-write; microphone"
                data-testid="ezchat-frame"
            />

            <!-- This app's side of the open chat. -->
            <aside
                v-if="url"
                :class="cn('w-80 shrink-0 overflow-y-auto border-l bg-slate-50 p-3', 'max-xl:absolute max-xl:inset-y-0 max-xl:right-0 max-xl:z-10 max-xl:shadow-xl', !panelOpen && 'max-xl:hidden')"
                data-testid="customer-panel"
            >
                <p class="px-1 pb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Trong phần mềm</p>
                <div v-if="!chat" class="rounded-lg border border-dashed bg-card p-4 text-center text-sm text-muted-foreground">
                    Chọn một cuộc chat bên trái để xem khách, đơn hàng và tạo đơn nhanh.
                </div>
                <template v-else>
                    <div class="rounded-lg border bg-card p-3">
                        <div class="flex items-start gap-2">
                            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-slate-100 text-slate-500"><UserRound class="size-5" /></span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-semibold">{{ panel?.customer?.name || chat.name || 'Khách chưa có tên' }}</p>
                                <p class="text-xs text-muted-foreground">{{ channelNames[chat.channel] ?? chat.channel }}<template v-if="chat.phone || panel?.customer?.phone"> · {{ panel?.customer?.phone || chat.phone }}</template></p>
                            </div>
                            <Badge v-if="panel?.customer?.group" tone="warning">{{ panel.customer.group }}</Badge>
                        </div>
                        <p v-if="panelLoading" class="mt-2 text-xs text-muted-foreground">Đang tìm khách…</p>
                        <template v-else-if="panel?.customer">
                            <dl class="mt-2 space-y-1 text-sm">
                                <div class="flex justify-between"><dt class="text-muted-foreground">Mã khách</dt><dd>{{ panel.customer.code }}</dd></div>
                                <div class="flex justify-between"><dt class="text-muted-foreground">Đã mua</dt><dd>{{ panel.customer.orders_count }} đơn · {{ formatMoney(panel.customer.total_spent) }}</dd></div>
                                <div class="flex justify-between"><dt class="text-muted-foreground">Công nợ</dt><dd :class="panel.customer.debt > 0 && 'font-medium text-rose-600'">{{ formatMoney(panel.customer.debt) }}</dd></div>
                            </dl>
                            <RouterLink :to="`/customers/${panel.customer.id}`" class="mt-2 inline-block text-sm text-primary hover:underline">Mở hồ sơ khách →</RouterLink>
                        </template>
                        <p v-else-if="panel" class="mt-2 text-xs text-muted-foreground">
                            {{ chat.phone || chat.email ? 'Chưa có khách này trong danh bạ.' : 'Khách chưa để lại số điện thoại hay email, nên chưa tìm được trong danh bạ.' }}
                        </p>
                    </div>

                    <div v-if="tenant.can('orders.view')" class="mt-3 rounded-lg border bg-card p-3">
                        <p class="mb-1 font-semibold">Đơn gần đây</p>
                        <p v-if="!panelLoading && !panel?.orders.length" class="text-sm text-muted-foreground">Chưa có đơn.</p>
                        <div v-for="o in panel?.orders ?? []" :key="o.id" class="flex items-center gap-2 border-b py-1.5 text-sm last:border-0" data-testid="panel-order">
                            <RouterLink :to="`/orders/${o.id}`" class="min-w-0 flex-1 truncate font-medium text-primary hover:underline">{{ o.code }}</RouterLink>
                            <Badge :tone="orderStatusBadge(o).tone">{{ orderStatusBadge(o).label }}</Badge>
                            <button type="button" class="grid size-7 place-items-center rounded-md text-slate-500 hover:bg-muted hover:text-slate-900" title="Đưa trạng thái đơn vào ô trả lời" @click="sendStatus(o)">
                                <Send class="size-3.5" />
                            </button>
                        </div>
                    </div>

                    <div v-if="tenant.can('products.view')" class="mt-3 rounded-lg border bg-card p-3">
                        <p class="mb-2 font-semibold">Tra tồn nhanh</p>
                        <div class="relative">
                            <Search class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                            <Input v-model="stockQ" placeholder="Tên, SKU" class="pl-8" data-testid="panel-stock-search" />
                        </div>
                        <div v-for="v in stockRows" :key="v.id" class="flex items-center gap-2 border-b py-1.5 text-sm last:border-0">
                            <div class="min-w-0 flex-1">
                                <p class="truncate">{{ variantName(v) }}</p>
                                <p class="text-xs text-muted-foreground">{{ v.sku }} · {{ formatMoney(v.ref_price) }}<template v-if="v.stock"> · còn {{ formatNumber(v.stock.available) }}</template></p>
                            </div>
                            <button type="button" class="grid size-7 place-items-center rounded-md text-slate-500 hover:bg-muted hover:text-slate-900" title="Đưa tên và giá vào ô trả lời" @click="sendProduct(v)">
                                <Send class="size-3.5" />
                            </button>
                        </div>
                    </div>

                    <a
                        v-if="tenant.can('orders.create')"
                        :href="newOrderHref"
                        target="_blank"
                        rel="noopener"
                        class="mt-3 flex h-10 w-full items-center justify-center gap-2 rounded-md bg-primary text-sm font-medium text-white hover:bg-primary/90"
                        data-testid="order-from-chat"
                    >
                        <ShoppingCart class="size-4" /> Tạo đơn từ chat này
                    </a>
                    <p v-if="tenant.can('orders.create')" class="mt-1 text-center text-xs text-muted-foreground">Mở tab mới, điền sẵn khách và kênh chat</p>
                </template>
            </aside>
        </div>
    </div>
</template>
