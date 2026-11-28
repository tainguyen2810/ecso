<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Check, ChevronDown, History, Minus, Search, Store } from 'lucide-vue-next';
import ChannelMark from '../reports/ChannelMark.vue';
import { cn, formatNumber } from '@/shared/lib/utils';
import type { MarketplaceAccountData, SalesChannel } from '@/shared/types';

/**
 * "Kênh bán" picker of the order list (mẫu 28/11): a shop can have several accounts on one marketplace and several
 * websites, so every account and own channel is its own row, grouped by marketplace (Shopee, TikTok…), Website, Chat
 * and Tại cửa hàng. Ticks are kept in the box until "Áp dụng"; a group's box ticks all of its rows.
 */
const props = defineProps<{
    accounts: MarketplaceAccountData[];
    channels: SalesChannel[];
    /** Picked account / channel ids. */
    pickedAccounts: string[];
    pickedChannels: string[];
    /** Orders per account / channel under the other filters. */
    counts: { accounts: Record<string, number>; channels: Record<string, number> };
    /** Old shortcut filters still reachable here: only own-channel orders, orders brought over from Sapo. */
    source: string;
    hasImported: boolean;
}>();
const emit = defineEmits<{ apply: [accounts: string[], channels: string[]]; source: [value: string] }>();

const open = ref(false);
const q = ref('');
const draftAccounts = ref(new Set<string>());
const draftChannels = ref(new Set<string>());
const root = ref<HTMLElement | null>(null);

function toggleOpen() {
    if (open.value) return void (open.value = false);
    draftAccounts.value = new Set(props.pickedAccounts);
    draftChannels.value = new Set(props.pickedChannels);
    q.value = '';
    open.value = true;
}
const onDoc = (e: MouseEvent) => open.value && root.value && !root.value.contains(e.target as Node) && (open.value = false);
const onKey = (e: KeyboardEvent) => e.key === 'Escape' && (open.value = false);
onMounted(() => (document.addEventListener('mousedown', onDoc), document.addEventListener('keydown', onKey)));
onBeforeUnmount(() => (document.removeEventListener('mousedown', onDoc), document.removeEventListener('keydown', onKey)));

interface Row { kind: 'account' | 'channel'; id: string; name: string; sub: string; mark: string; count: number }
interface Group { key: string; label: string; mark: string; rows: Row[] }

const marketNames: Record<string, string> = { shopee: 'Shopee', tiktok: 'TikTok Shop', lazada: 'Lazada', tiki: 'Tiki', woocommerce: 'WooCommerce' };
const channelGroupOf: Record<string, string> = { website: 'website', facebook: 'chat', zalo: 'chat' };
const channelTypeNames: Record<string, string> = { website: 'Website riêng', facebook: 'Facebook', zalo: 'Zalo', pos: 'Bán tại quầy', store: 'Cửa hàng', phone: 'Điện thoại', other: 'Kênh khác' };

const groups = computed<Group[]>(() => {
    const by = new Map<string, Group>();
    const add = (key: string, label: string, mark: string, r: Row) => {
        if (!by.has(key)) by.set(key, { key, label, mark, rows: [] });
        by.get(key)!.rows.push(r);
    };
    for (const a of props.accounts) {
        const m = a.marketplace;
        const row: Row = { kind: 'account', id: a.id, name: a.shop_name, sub: marketNames[m] ?? a.marketplace_name, mark: m, count: props.counts.accounts[a.id] ?? 0 };
        if (m === 'woocommerce') add('website', 'Website', 'website', row);
        else add(m, marketNames[m] ?? a.marketplace_name, m, row);
    }
    for (const c of props.channels) {
        const g = channelGroupOf[c.type] ?? 'store';
        const row: Row = { kind: 'channel', id: c.id, name: c.name, sub: channelTypeNames[c.type] ?? c.type, mark: c.type, count: props.counts.channels[c.id] ?? 0 };
        add(g, { website: 'Website', chat: 'Chat', store: 'Tại cửa hàng' }[g]!, g === 'chat' ? c.type : g === 'website' ? 'website' : 'store', row);
    }
    const order = ['shopee', 'tiktok', 'lazada', 'tiki', 'website', 'chat', 'store'];
    return [...by.values()].sort((a, b) => (order.indexOf(a.key) + 99) % 99 - (order.indexOf(b.key) + 99) % 99);
});

const shown = computed(() => {
    const s = q.value.trim().toLowerCase();
    if (!s) return groups.value;
    return groups.value.map((g) => ({ ...g, rows: g.rows.filter((r) => `${r.name} ${r.sub} ${g.label}`.toLowerCase().includes(s)) })).filter((g) => g.rows.length);
});

const setOf = (r: Row) => (r.kind === 'account' ? draftAccounts.value : draftChannels.value);
const ticked = (r: Row) => setOf(r).has(r.id);
function toggle(r: Row) {
    const s = new Set(setOf(r));
    s.has(r.id) ? s.delete(r.id) : s.add(r.id);
    if (r.kind === 'account') draftAccounts.value = s;
    else draftChannels.value = s;
}
const groupState = (g: Group) => {
    const n = g.rows.filter(ticked).length;
    return n === 0 ? 'none' : n === g.rows.length ? 'all' : 'some';
};
function toggleGroup(g: Group) {
    const on = groupState(g) !== 'all';
    const a = new Set(draftAccounts.value);
    const c = new Set(draftChannels.value);
    for (const r of g.rows) {
        const s = r.kind === 'account' ? a : c;
        on ? s.add(r.id) : s.delete(r.id);
    }
    draftAccounts.value = a;
    draftChannels.value = c;
}
const draftCount = computed(() => draftAccounts.value.size + draftChannels.value.size);
const draftOrders = computed(() => [...draftAccounts.value].reduce((n, id) => n + (props.counts.accounts[id] ?? 0), 0) + [...draftChannels.value].reduce((n, id) => n + (props.counts.channels[id] ?? 0), 0));
/** Logos of the picked channels on the button (up to 3). */
const pickedMarks = computed(() => {
    const kinds = [...props.pickedAccounts.map((id) => props.accounts.find((a) => a.id === id)?.marketplace), ...props.pickedChannels.map((id) => props.channels.find((c) => c.id === id)?.type)];
    return [...new Set(kinds.filter(Boolean) as string[])].slice(0, 3).map((k) => (k === 'woocommerce' ? 'website' : k));
});

function apply() {
    open.value = false;
    emit('apply', [...draftAccounts.value], [...draftChannels.value]);
}
function pickSource(v: string) {
    open.value = false;
    emit('source', props.source === v ? '' : v);
}

const pickedCount = computed(() => props.pickedAccounts.length + props.pickedChannels.length);
const buttonLabel = computed(() => {
    if (props.source === 'manual') return 'Kênh bán riêng';
    if (props.source === 'sapo') return 'Đơn cũ từ Sapo';
    if (!pickedCount.value) return 'Tất cả kênh';
    if (pickedCount.value === 1) {
        const id = props.pickedAccounts[0] ?? props.pickedChannels[0];
        return props.accounts.find((a) => a.id === id)?.shop_name ?? props.channels.find((c) => c.id === id)?.name ?? '1 kênh';
    }
    return `${pickedCount.value} tài khoản`;
});
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            :class="cn('flex h-9 min-w-56 items-center gap-2 rounded-[10px] border bg-white px-3 text-sm', pickedCount || source ? 'border-blue-300 text-blue-800 ring-2 ring-blue-50' : 'border-slate-200 text-slate-700 hover:border-slate-300')"
            :aria-expanded="open"
            data-testid="channel-picker"
            @click="toggleOpen"
        >
            <Store class="size-4 text-slate-400" />
            <span class="text-slate-500">Kênh bán:</span>
            <span v-if="pickedMarks.length" class="flex -space-x-1"><ChannelMark v-for="k in pickedMarks" :key="k" :kind="k" size="sm" class="!size-5 ring-2 ring-white" /></span>
            <span class="max-w-44 truncate font-semibold">{{ buttonLabel }}</span>
            <ChevronDown class="ml-auto size-4 text-slate-400" />
        </button>
        <div v-if="open" class="absolute left-0 top-11 z-40 w-[380px] overflow-hidden rounded-[14px] border border-slate-200 bg-white shadow-xl" role="dialog" aria-label="Chọn kênh bán" data-testid="channel-menu">
            <div class="border-b border-slate-100 p-2.5">
                <div class="relative">
                    <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-slate-400" />
                    <input v-model="q" class="h-9 w-full rounded-[9px] border border-slate-200 pl-8 pr-3 text-sm outline-none focus:border-blue-400" placeholder="Tìm tên shop, website, kênh…" data-testid="channel-search" />
                </div>
            </div>
            <div class="max-h-[420px] overflow-y-auto py-1">
                <p v-if="!shown.length" class="px-4 py-6 text-center text-sm text-slate-400">{{ groups.length ? 'Không có kênh nào khớp.' : 'Chưa kết nối sàn hay tạo kênh bán nào.' }}</p>
                <div v-for="g in shown" :key="g.key" class="py-1" :data-testid="`channel-group-${g.key}`">
                    <button type="button" class="flex w-full items-center gap-2.5 px-3.5 py-1.5 text-left hover:bg-slate-50" @click="toggleGroup(g)">
                        <span :class="cn('grid size-4 place-items-center rounded border', groupState(g) === 'none' ? 'border-slate-300' : 'border-blue-600 bg-blue-600 text-white')">
                            <Check v-if="groupState(g) === 'all'" class="size-3" :stroke-width="3" /><Minus v-else-if="groupState(g) === 'some'" class="size-3" :stroke-width="3" />
                        </span>
                        <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500">{{ g.label }}</span>
                        <span class="ml-auto text-[11px] text-slate-400">{{ g.rows.length }} {{ g.key === 'website' ? 'website' : g.key === 'chat' || g.key === 'store' ? 'kênh' : 'tài khoản' }}</span>
                    </button>
                    <button
                        v-for="r in g.rows"
                        :key="r.id"
                        type="button"
                        class="flex w-full items-center gap-2.5 py-1.5 pl-6 pr-3.5 text-left hover:bg-slate-50"
                        :data-testid="`channel-row-${r.id}`"
                        @click="toggle(r)"
                    >
                        <span :class="cn('grid size-4 shrink-0 place-items-center rounded border', ticked(r) ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-300')"><Check v-if="ticked(r)" class="size-3" :stroke-width="3" /></span>
                        <ChannelMark :kind="r.mark" :name="r.sub" size="sm" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[13px] font-medium text-slate-800">{{ r.name }}</span>
                            <span class="block truncate text-[11px] text-slate-400">{{ r.sub }}</span>
                        </span>
                        <span class="rounded-md bg-slate-100 px-1.5 text-[11px] tabular-nums text-slate-500">{{ formatNumber(r.count) }}</span>
                    </button>
                </div>
                <div class="mt-1 border-t border-slate-100 px-2 pt-1.5">
                    <button type="button" :class="cn('flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-[13px] hover:bg-slate-50', source === 'manual' ? 'font-semibold text-blue-700' : 'text-slate-600')" data-testid="channel-manual" @click="pickSource('manual')">
                        <Store class="size-4 text-slate-400" /> Chỉ đơn kênh bán riêng (không phải sàn)
                    </button>
                    <button v-if="hasImported || source === 'sapo'" type="button" :class="cn('flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-[13px] hover:bg-slate-50', source === 'sapo' ? 'font-semibold text-blue-700' : 'text-slate-600')" data-testid="channel-sapo" @click="pickSource('sapo')">
                        <History class="size-4 text-slate-400" /> Đơn cũ từ Sapo
                    </button>
                </div>
            </div>
            <div class="flex items-center gap-2 border-t border-slate-100 bg-slate-50 px-3.5 py-2.5">
                <button type="button" class="text-[13px] text-slate-500 hover:underline" data-testid="channel-clear" @click="(draftAccounts = new Set()), (draftChannels = new Set())">Bỏ chọn</button>
                <span class="text-[12px] text-slate-400">{{ draftCount ? `Đã chọn ${draftCount} · ${formatNumber(draftOrders)} đơn` : 'Mọi kênh' }}</span>
                <button type="button" class="ml-auto h-8 rounded-[8px] bg-blue-600 px-4 text-[13px] font-medium text-white hover:bg-blue-700" data-testid="channel-apply" @click="apply">Áp dụng</button>
            </div>
        </div>
    </div>
</template>
