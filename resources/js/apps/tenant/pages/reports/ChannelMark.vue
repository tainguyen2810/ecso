<script setup lang="ts">
import { computed, type Component } from 'vue';
import { Globe, Phone, Store } from 'lucide-vue-next';
import { cn } from '@/shared/lib/utils';

/**
 * Small mark of a sales channel in report rows: the real logos of the marketplaces, WooCommerce, Zalo and Facebook; an
 * icon for the shop's own channels (website, counter, phone).
 */
const props = withDefaults(defineProps<{ kind?: string | null; name?: string; size?: 'sm' | 'md' | 'lg' }>(), { kind: null, name: undefined, size: 'md' });

const logos: Record<string, string> = { shopee: 'marketplaces/shopee.webp', tiktok: 'marketplaces/tiktok.webp', lazada: 'marketplaces/lazada.webp', tiki: 'marketplaces/tiki.webp', zalo: 'channels/zalo.webp', woocommerce: 'channels/woocommerce.svg', facebook: 'channels/facebook.svg' };
const icons: Record<string, [Component, string]> = {
    website: [Globe, 'bg-violet-50 text-violet-600'],
    phone: [Phone, 'bg-emerald-50 text-emerald-600'],
    pos: [Store, 'bg-blue-50 text-blue-600'],
    store: [Store, 'bg-blue-50 text-blue-600'],
    manual: [Store, 'bg-blue-50 text-blue-600'],
};
const box = computed(() => ({ sm: 'size-6 rounded-md', md: 'size-8 rounded-[9px]', lg: 'size-10 rounded-[10px]' })[props.size]);
const logo = computed(() => (props.kind ? logos[props.kind] : undefined));
const icon = computed(() => icons[props.kind ?? ''] ?? [Store, 'bg-slate-100 text-slate-500']);
</script>

<template>
    <img v-if="logo" :src="`/images/${logo}`" :alt="name ?? kind ?? ''" :class="cn('shrink-0 object-contain', box)" loading="lazy" />
    <span v-else :class="cn('grid shrink-0 place-items-center', box, icon[1])" :title="name"><component :is="icon[0]" class="size-[55%]" :stroke-width="1.9" /></span>
</template>
