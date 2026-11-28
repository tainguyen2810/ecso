<script setup lang="ts">
import { computed } from "vue";
import { Store } from "lucide-vue-next";
import { cn } from "@/shared/lib/utils";
import { channelColor } from "@/shared/lib/tones";

/** Marketplace logo (public/images/marketplaces/<key>.webp; WooCommerce, Zalo, Facebook under images/channels). Own channels get a store icon, unknown keys a letter. */
const props = withDefaults(
    defineProps<{
        marketplace: string;
        name?: string;
        size?: "sm" | "md" | "lg";
        class?: string;
    }>(),
    { size: "md" },
);

const logos: Record<string, string> = {
    shopee: "marketplaces/shopee.webp",
    tiktok: "marketplaces/tiktok.webp",
    lazada: "marketplaces/lazada.webp",
    tiki: "marketplaces/tiki.webp",
    woocommerce: "channels/woocommerce.svg",
    zalo: "channels/zalo.webp",
    facebook: "channels/facebook.svg",
};
const box = computed(
    () =>
        ({
            sm: "size-5 rounded",
            md: "size-7 rounded-md",
            lg: "size-9 rounded-lg",
        })[props.size],
);
</script>

<template>
    <img
        v-if="logos[marketplace]"
        :src="`/images/${logos[marketplace]}`"
        :alt="name ?? marketplace"
        :class="cn('shrink-0 object-cover', box, props.class)"
        loading="lazy"
    />
    <span
        v-else-if="marketplace === 'manual'"
        :class="
            cn(
                'grid shrink-0 place-items-center bg-emerald-50 text-emerald-600',
                box,
                props.class,
            )
        "
        :title="name"
        ><Store class="size-[60%]" :stroke-width="1.75"
    /></span>
    <span
        v-else
        :class="
            cn(
                'grid shrink-0 place-items-center text-xs font-bold text-white',
                box,
                props.class,
            )
        "
        :style="{ background: channelColor(marketplace) }"
        :title="name"
        >{{ (name ?? marketplace).charAt(0).toUpperCase() }}</span
    >
</template>
