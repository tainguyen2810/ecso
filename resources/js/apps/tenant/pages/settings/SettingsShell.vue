<script setup lang="ts">
import { computed, provide, useSlots } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft } from 'lucide-vue-next';
import type { RouteLocationRaw } from 'vue-router';
import { cn } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { canOpen, ownTileFor, settingsGroups, toneClasses } from './catalog';

/**
 * Frame of every settings page (mẫu Cấu hình 28/11, Tailwind): breadcrumb "Cấu hình / page", big title, description,
 * buttons on the right; below, the settings menu on the left, the page in the middle and, when the page has one, a
 * preview column on the right (#aside: invoice header, VietQR, receipt). `back` adds one more level (Zalo under Kết nối
 * dịch vụ). `wide` drops the left menu for pages with wide tables (bảng phí sàn).
 */
const props = defineProps<{ title: string; description?: string; back?: { to: RouteLocationRaw; label: string }; wide?: boolean }>();

const route = useRoute();
const slots = useSlots();
const tenant = useTenantStore();
const here = computed(() => ownTileFor(route.path));
const menu = computed(() =>
    settingsGroups
        .map((g) => ({ ...g, tiles: g.tiles.filter((t) => t.menu && canOpen(t, tenant.can)) }))
        .filter((g) => g.tiles.length),
);
// Cards inside settings pages take the same rounded, bordered style.
provide('soft-cards', true);
const isActive = (to: string) => here.value?.tile.to === to;
const hasAside = computed(() => !!slots.aside);
// With a preview column the menu only shows on very wide screens, so the form keeps room.
const grid = computed(() => {
    if (props.wide) return hasAside.value ? 'xl:grid xl:grid-cols-[minmax(0,1fr)_330px] xl:items-start xl:gap-6' : '';
    return hasAside.value
        ? 'xl:grid xl:grid-cols-[minmax(0,1fr)_330px] xl:items-start xl:gap-6 2xl:grid-cols-[250px_minmax(0,1fr)_330px]'
        : 'lg:grid lg:grid-cols-[250px_minmax(0,1fr)] lg:items-start lg:gap-6';
});
const menuShow = computed(() => (props.wide ? 'hidden' : hasAside.value ? 'hidden 2xl:block' : 'hidden lg:block'));
</script>

<template>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0 max-w-3xl">
            <nav class="flex flex-wrap items-center gap-1 text-[14px] text-slate-500" aria-label="Đường dẫn">
                <RouterLink to="/settings" class="hover:text-slate-900">Cấu hình</RouterLink>
                <template v-if="props.back">
                    <span aria-hidden="true">/</span>
                    <RouterLink :to="props.back.to" class="hover:text-slate-900">{{ props.back.label }}</RouterLink>
                </template>
                <span aria-hidden="true">/</span>
                <span>{{ title }}</span>
            </nav>
            <h1 class="mt-1 text-[26px] font-bold leading-tight tracking-tight text-slate-900 sm:text-[30px]" data-testid="settings-title">{{ title }}</h1>
            <p v-if="description || here?.tile.description || $slots.description" class="mt-1 text-[15px] text-slate-500"><slot name="description">{{ description ?? here?.tile.description }}</slot></p>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-3"><slot name="actions" /></div>
    </div>
    <div :class="grid">
        <aside :class="cn('sticky top-4 rounded-[14px] border border-slate-200/80 bg-white p-2.5 shadow-[0_1px_2px_rgba(15,23,42,0.04)]', menuShow)" data-testid="settings-menu">
            <RouterLink to="/settings" class="flex items-center gap-2.5 rounded-[10px] px-3 py-2 text-[14px] text-slate-600 hover:bg-slate-50 hover:text-slate-900">
                <ArrowLeft class="size-4" /> Tất cả cài đặt
            </RouterLink>
            <div v-for="g in menu" :key="g.title">
                <p class="px-3 pb-1.5 pt-4 text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ g.title }}</p>
                <RouterLink
                    v-for="t in g.tiles"
                    :key="t.to"
                    :to="t.to"
                    :aria-current="isActive(t.to) ? 'page' : undefined"
                    :class="cn('relative flex items-center gap-3 rounded-[10px] px-3 py-2 text-[14px]', isActive(t.to) ? 'bg-blue-50 font-semibold text-blue-700' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900')"
                >
                    <span v-if="isActive(t.to)" class="absolute -left-2.5 top-1.5 h-6 w-[3px] rounded-full bg-blue-600" aria-hidden="true" />
                    <span :class="cn('grid size-7 shrink-0 place-items-center rounded-lg', isActive(t.to) ? 'bg-white text-blue-600 shadow-sm' : toneClasses[g.tone])"><component :is="t.icon" class="size-[15px]" :stroke-width="2" /></span>
                    <span class="truncate">{{ t.short ?? t.title }}</span>
                </RouterLink>
            </div>
        </aside>
        <div class="min-w-0">
            <slot />
        </div>
        <aside v-if="$slots.aside" class="mt-6 space-y-5 xl:sticky xl:top-4 xl:mt-0"><slot name="aside" /></aside>
    </div>
</template>
