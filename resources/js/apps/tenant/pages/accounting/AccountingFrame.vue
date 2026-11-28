<script setup lang="ts">
import { computed } from 'vue';
import { BookOpen, FileText, LayoutDashboard, Receipt, type LucideIcon } from 'lucide-vue-next';
import { cn } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { openInvoices } from './accounting';

/**
 * Frame of the Kế toán, thuế pages (mẫu 28/11): breadcrumb, title, description, the page's buttons on the right and
 * the four pages as tabs. "Hoá đơn mua vào" carries the number of invoices not yet in a purchase order once known.
 */
defineProps<{ page: string; title: string; description: string }>();
const tenant = useTenantStore();

const tabs = computed(() => {
    const all: { label: string; to: string; icon: LucideIcon; badge?: number | null }[] = [
        { label: 'Tổng quan thuế', to: '/accounting', icon: LayoutDashboard },
        { label: 'Sổ sách', to: '/accounting/books', icon: BookOpen },
        { label: 'Tờ khai thuế', to: '/accounting/declaration', icon: FileText },
    ];
    if (tenant.can('purchasing.view')) all.push({ label: 'Hoá đơn mua vào', to: '/purchasing/invoices', icon: Receipt, badge: openInvoices.value });
    return all;
});
</script>

<template>
    <div class="mb-6">
        <p class="text-[14px] text-slate-500">Kế toán, thuế / {{ page }}</p>
        <div class="mt-1 flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-[26px] font-bold leading-tight tracking-tight text-slate-900 sm:text-[30px]">{{ title }}</h1>
                <p class="mt-1 text-[15px] text-slate-500">{{ description }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-3"><slot /></div>
        </div>
        <nav class="mt-5 flex gap-5 overflow-x-auto border-b border-slate-200 sm:gap-8" aria-label="Kế toán, thuế" data-testid="accounting-tabs">
            <RouterLink
                v-for="t in tabs"
                :key="t.to"
                :to="t.to"
                :class="cn('-mb-px inline-flex shrink-0 items-center gap-2 border-b-2 px-1 pb-3 pt-1 text-[15px]', t.label === page ? 'border-blue-600 font-semibold text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-900')"
            >
                <component :is="t.icon" class="size-[17px]" /> {{ t.label }}
                <span v-if="t.badge" class="rounded-full bg-amber-100 px-1.5 text-[12px] font-semibold tabular-nums text-amber-700">{{ t.badge }}</span>
            </RouterLink>
        </nav>
    </div>
</template>
