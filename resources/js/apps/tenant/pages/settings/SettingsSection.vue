<script setup lang="ts">
import type { Component } from 'vue';
import { cn } from '@/shared/lib/utils';
import { toneClasses, type SettingsTone } from './catalog';

/**
 * One block of a settings page as a card (mẫu Cấu hình): icon, title and explanation on top, the fields below.
 * `flush` drops the body padding for lists that run edge to edge.
 */
withDefaults(defineProps<{ title: string; description?: string; icon?: Component; tone?: SettingsTone; flush?: boolean }>(), { tone: 'blue' });
</script>

<template>
    <section class="scroll-mt-4 rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
        <header class="flex items-start gap-3.5 border-b border-slate-100 px-5 py-4 sm:px-6 sm:py-5">
            <span v-if="icon" :class="cn('grid size-10 shrink-0 place-items-center rounded-[11px]', toneClasses[tone])"><component :is="icon" class="size-5" :stroke-width="1.9" /></span>
            <div class="min-w-0 flex-1">
                <h2 class="text-[17px] font-semibold text-slate-900">{{ title }}</h2>
                <p v-if="description" class="mt-0.5 text-[14px] text-slate-500">{{ description }}</p>
            </div>
            <slot name="actions" />
        </header>
        <div :class="flush ? '' : 'p-5 sm:p-6'"><slot /></div>
    </section>
</template>
