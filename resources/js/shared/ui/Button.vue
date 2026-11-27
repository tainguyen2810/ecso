<script setup lang="ts">
import { computed } from 'vue';
import { cva, type VariantProps } from 'class-variance-authority';
import { Loader2 } from 'lucide-vue-next';
import { cn } from '@/shared/lib/utils';

const buttonVariants = cva(
    'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 [&_svg]:size-4 [&_svg]:shrink-0',
    {
        variants: {
            variant: {
                default: 'bg-primary text-primary-foreground hover:bg-[#0077e0]',
                secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
                // Sapo: secondary buttons are blue-outlined (Thoát, Thêm sản phẩm…).
                outline: 'border border-primary bg-card text-primary hover:bg-blue-50',
                ghost: 'hover:bg-muted',
                destructive: 'bg-destructive text-destructive-foreground hover:bg-destructive/90',
                link: 'text-primary underline-offset-4 hover:underline',
            },
            size: {
                default: 'h-9 px-4',
                sm: 'h-8 px-3 text-xs',
                lg: 'h-10 px-6',
                icon: 'size-9',
            },
        },
        defaultVariants: { variant: 'default', size: 'default' },
    },
);

type Variants = VariantProps<typeof buttonVariants>;

const props = withDefaults(
    defineProps<{
        variant?: Variants['variant'];
        size?: Variants['size'];
        type?: 'button' | 'submit';
        loading?: boolean;
        disabled?: boolean;
        class?: string;
    }>(),
    { type: 'button' },
);

const classes = computed(() => cn(buttonVariants({ variant: props.variant, size: props.size }), props.class));
</script>

<template>
    <button :type="type" :class="classes" :disabled="disabled || loading">
        <Loader2 v-if="loading" class="animate-spin" />
        <slot />
    </button>
</template>
