<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch, type Component } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { AlertTriangle, Bell, ChevronRight, CircleCheck, Clock, FileText, KeyRound, Package, PackageX, PlugZap, Settings2, ShoppingCart, Unplug, Wallet } from 'lucide-vue-next';
import { api } from '@/shared/lib/api';
import { cn } from '@/shared/lib/utils';
import { toneClasses, type Tone } from '@/shared/lib/tones';
import { useTenantStore } from '@/shared/stores/tenant';
import { usePopover } from '@/shared/composables/usePopover';
import { inboxItems, inboxKinds, readJson, unseenCount, writeJson, type InboxItem } from './inbox';

/**
 * Chuông thông báo (B2): what waits for the member now, grouped by kind. Counts are live, so a row goes away once
 * the work is done; the red number is how many rows changed since "Đánh dấu đã xem". Kinds can be switched off.
 */
const tenant = useTenantStore();
const route = useRoute();
const router = useRouter();

const items = inboxItems;
items.value = [];
const seen = ref<Record<string, number>>({});
const off = ref<string[]>([]);
const settings = ref(false);
const anchor = ref<HTMLElement | null>(null);
const panel = ref<HTMLElement | null>(null);
const pop = usePopover(anchor, panel, () => (settings.value = false));

const storeKey = (name: string) => `ecso.inbox.${name}.${tenant.current?.id ?? ''}`;
const shown = computed(() => items.value.filter((i) => !off.value.includes(i.key)));
const unseen = computed(() => unseenCount(shown.value, seen.value));

const groups: { key: InboxItem['group']; label: string }[] = [
    { key: 'orders', label: 'Đơn hàng' },
    { key: 'ops', label: 'Kết nối và kho' },
    { key: 'money', label: 'Tiền' },
];
const icons: Record<string, Component> = {
    'orders.failed_delivery': AlertTriangle,
    'orders.overdue': Clock,
    'orders.due_soon': Clock,
    'orders.shortage': PackageX,
    'orders.to_confirm': ShoppingCart,
    'orders.to_pack': Package,
    'sync.disconnected': Unplug,
    'sync.problems': PlugZap,
    'sync.token_expiring': KeyRound,
    'stock.low': Package,
    'cash.pending': Wallet,
    'suppliers.overdue': FileText,
};

let lastLoad = 0;
async function load() {
    if (!tenant.current || !tenant.can('dashboard.view')) return;
    lastLoad = Date.now();
    try {
        const { data } = await api.get<{ data: InboxItem[] }>('/inbox');
        items.value = data.data;
    } catch {
        // The bell keeps the last list when the request fails; the pages themselves still show the figures.
    }
}
function restore() {
    seen.value = readJson(storeKey('seen'), {});
    off.value = readJson(storeKey('off'), []);
}
function markSeen() {
    seen.value = Object.fromEntries(items.value.map((i) => [i.key, i.count]));
    writeJson(storeKey('seen'), seen.value);
}
function toggleKind(key: string) {
    off.value = off.value.includes(key) ? off.value.filter((k) => k !== key) : [...off.value, key];
    writeJson(storeKey('off'), off.value);
}
function openItem(item: InboxItem) {
    pop.close();
    router.push(item.to);
}

let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    restore();
    load();
    // Every 2 minutes while the tab is visible, and when coming back to it.
    timer = setInterval(() => document.visibilityState === 'visible' && load(), 120_000);
    document.addEventListener('visibilitychange', onVisible);
});
onBeforeUnmount(() => {
    clearInterval(timer);
    document.removeEventListener('visibilitychange', onVisible);
});
function onVisible() {
    if (document.visibilityState === 'visible' && Date.now() - lastLoad > 30_000) load();
}
// Moving to another page (often after handling something) refreshes the counts, at most every 30 seconds.
watch(() => route.path, () => Date.now() - lastLoad > 30_000 && load());
watch(() => tenant.current?.id, () => (restore(), load()));

const tone = (t: InboxItem['tone']): Tone => t;
</script>

<template>
    <div ref="anchor" class="relative">
        <button
            type="button"
            :class="cn('relative grid size-9 place-items-center rounded-lg text-slate-600 hover:bg-slate-100', pop.open.value && 'bg-blue-50 text-blue-600 hover:bg-blue-50')"
            :aria-label="unseen ? `Thông báo, ${unseen} mục mới` : 'Thông báo'"
            data-testid="inbox-bell"
            @click="pop.open.value ? pop.close() : (pop.show(), load())"
        >
            <Bell class="size-[18px]" :stroke-width="1.75" />
            <span v-if="unseen" class="absolute -right-0.5 -top-0.5 grid h-[18px] min-w-[18px] place-items-center rounded-full border-2 border-card bg-red-600 px-1 text-[10px] font-bold text-white" data-testid="inbox-count">
                {{ unseen > 9 ? '9+' : unseen }}
            </span>
        </button>
        <Teleport to="body">
            <div
                v-if="pop.open.value"
                ref="panel"
                :style="pop.style.value"
                class="z-[60] flex max-h-[min(640px,calc(100vh-80px))] w-[min(420px,calc(100vw-16px))] flex-col overflow-hidden rounded-xl border bg-card text-foreground shadow-xl"
                data-testid="inbox-panel"
            >
                <div class="flex items-center border-b px-4 py-3">
                    <span class="text-[15px] font-semibold">{{ settings ? 'Cài đặt thông báo' : 'Cần xử lý' }}</span>
                    <span v-if="!settings && shown.length" class="ml-2 rounded-full bg-red-100 px-2 text-xs font-semibold text-red-700">{{ shown.length }}</span>
                    <button v-if="!settings && unseen" type="button" class="ml-auto text-xs font-medium text-blue-600 hover:underline" data-testid="inbox-mark-seen" @click="markSeen">Đánh dấu đã xem</button>
                </div>

                <div v-if="settings" class="min-h-0 flex-1 overflow-y-auto p-2">
                    <p class="px-2 pb-2 text-xs text-muted-foreground">Bỏ chọn loại nào thì chuông không báo loại đó nữa (chỉ trên trình duyệt này).</p>
                    <label v-for="k in inboxKinds" :key="k.key" class="flex cursor-pointer items-center gap-3 rounded-md px-2 py-2 text-sm hover:bg-slate-50">
                        <input type="checkbox" class="size-4" :checked="!off.includes(k.key)" @change="toggleKind(k.key)" />
                        {{ k.label }}
                    </label>
                </div>
                <div v-else class="min-h-0 flex-1 overflow-y-auto">
                    <template v-for="g in groups" :key="g.key">
                        <template v-if="shown.some((i) => i.group === g.key)">
                            <p class="px-4 pb-1 pt-3 text-[11px] font-bold uppercase tracking-wide text-slate-400">{{ g.label }}</p>
                            <button
                                v-for="item in shown.filter((i) => i.group === g.key)"
                                :key="item.key"
                                type="button"
                                :class="cn('flex w-full items-center gap-3 border-b border-slate-100 px-4 py-2.5 text-left hover:bg-slate-50', item.count > (seen[item.key] ?? 0) && 'bg-blue-50/40')"
                                data-testid="inbox-item"
                                @click="openItem(item)"
                            >
                                <span :class="cn('grid size-9 shrink-0 place-items-center rounded-full', toneClasses[tone(item.tone)].bubble)">
                                    <component :is="icons[item.key] ?? Bell" class="size-4" :stroke-width="1.75" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-semibold">{{ item.title }}</span>
                                    <span v-if="item.detail" class="block truncate text-xs text-muted-foreground">{{ item.detail }}</span>
                                </span>
                                <ChevronRight class="size-4 shrink-0 text-slate-400" />
                            </button>
                        </template>
                    </template>
                    <div v-if="!shown.length" class="flex flex-col items-center gap-2 px-6 py-10 text-center text-sm text-muted-foreground">
                        <CircleCheck class="size-8 text-green-500" :stroke-width="1.5" />
                        Không có việc nào đang chờ.
                    </div>
                </div>

                <div class="flex items-center justify-between border-t bg-slate-50 px-4 py-2.5 text-xs">
                    <button type="button" class="flex items-center gap-1.5 font-medium text-blue-600 hover:underline" data-testid="inbox-settings" @click="settings = !settings">
                        <Settings2 class="size-3.5" />{{ settings ? 'Quay lại' : 'Cài đặt thông báo' }}
                    </button>
                    <span class="text-muted-foreground">Tự cập nhật 2 phút một lần</span>
                </div>
            </div>
        </Teleport>
    </div>
</template>
