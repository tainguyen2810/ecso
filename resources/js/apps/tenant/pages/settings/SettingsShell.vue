<script setup lang="ts">
import { computed, provide } from 'vue';
import { useRoute } from 'vue-router';
import { LayoutGrid } from 'lucide-vue-next';
import type { RouteLocationRaw } from 'vue-router';
import { cn } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { canOpen, ownTileFor, settingsGroups, toneClasses } from './catalog';

/**
 * Frame of every settings page (same style as Báo cáo): breadcrumb "Cấu hình / page", big title, description, buttons
 * on the right, and on wide screens a left menu with all the settings pages so one can move between them without going
 * back to Cấu hình. `back` adds one more level (e.g. Zalo under Kết nối dịch vụ). `wide` drops the left menu for pages
 * with wide tables (bảng phí sàn).
 */
const props = defineProps<{ title: string; description?: string; back?: { to: RouteLocationRaw; label: string }; wide?: boolean }>();

const route = useRoute();
const tenant = useTenantStore();
const here = computed(() => ownTileFor(route.path));
const menu = computed(() =>
    settingsGroups
        .map((g) => ({ ...g, tiles: g.tiles.filter((t) => t.own && canOpen(t, tenant.can)) }))
        .filter((g) => g.tiles.length),
);
// Cards inside settings pages take the same rounded, bordered style.
provide('soft-cards', true);
const isActive = (to: string) => here.value?.tile.to === to;
</script>

<template>
    <div class="mb-5">
        <nav class="flex flex-wrap items-center gap-1 text-[13px] text-muted-foreground" aria-label="Đường dẫn">
            <RouterLink to="/settings" class="hover:text-foreground">Cấu hình</RouterLink>
            <template v-if="props.back">
                <span aria-hidden="true">/</span>
                <RouterLink :to="props.back.to" class="hover:text-foreground">{{ props.back.label }}</RouterLink>
            </template>
            <span aria-hidden="true">/</span>
            <span class="text-slate-700">{{ title }}</span>
        </nav>
        <div class="mt-1 flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0 max-w-3xl">
                <h1 class="text-[26px] font-bold leading-tight tracking-tight text-slate-900" data-testid="settings-title">{{ title }}</h1>
                <p v-if="description || here?.tile.description || $slots.description" class="mt-1 text-sm text-muted-foreground"><slot name="description">{{ description ?? here?.tile.description }}</slot></p>
            </div>
            <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2"><slot name="actions" /></div>
        </div>
    </div>
    <div :class="wide ? '' : 'lg:grid lg:grid-cols-[15rem_minmax(0,1fr)] lg:items-start lg:gap-6'">
        <aside v-if="!wide" class="sticky top-4 hidden rounded-[14px] border border-slate-200/80 bg-white p-2 shadow-[0_1px_2px_rgba(15,23,42,0.04)] lg:block" data-testid="settings-menu">
            <RouterLink to="/settings" class="flex items-center gap-2.5 rounded-[9px] px-2.5 py-2 text-sm text-slate-600 hover:bg-slate-50 hover:text-slate-900">
                <LayoutGrid class="size-4" /> Tất cả cài đặt
            </RouterLink>
            <div v-for="g in menu" :key="g.title" class="mt-2">
                <p class="px-2.5 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ g.title }}</p>
                <RouterLink
                    v-for="t in g.tiles"
                    :key="t.to"
                    :to="t.to"
                    :aria-current="isActive(t.to) ? 'page' : undefined"
                    :class="cn('flex items-center gap-2.5 rounded-[9px] px-2.5 py-1.5 text-sm', isActive(t.to) ? 'bg-blue-50 font-medium text-blue-700' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900')"
                >
                    <span :class="cn('grid size-6 shrink-0 place-items-center rounded-md', isActive(t.to) ? 'bg-white text-blue-600' : toneClasses[g.tone])"><component :is="t.icon" class="size-3.5" :stroke-width="2" /></span>
                    <span class="truncate">{{ t.title }}</span>
                </RouterLink>
            </div>
        </aside>
        <div class="min-w-0">
            <slot />
        </div>
    </div>
</template>
