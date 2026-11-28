<script setup lang="ts">
import { CalendarCheck, HandCoins, Wallet } from 'lucide-vue-next';
import { cn } from '@/shared/lib/utils';
import MonthSwitch from './MonthSwitch.vue';

/**
 * Frame of the three "Chấm công, lương" pages (mẫu 28/11, like the report menus): breadcrumb, title, month, buttons
 * and the three pages as tabs. The month lives in the URL (?month=), so switching tab keeps it.
 */
type Page = 'attendance' | 'commissions' | 'payroll';
defineProps<{ page: Page; title: string; description: string }>();
const month = defineModel<string>('month', { required: true });

const tabs: { page: Page; route: string; label: string; icon: typeof Wallet }[] = [
    { page: 'attendance', route: 'staff-attendance', label: 'Chấm công', icon: CalendarCheck },
    { page: 'commissions', route: 'staff-commissions', label: 'Hoa hồng', icon: HandCoins },
    { page: 'payroll', route: 'staff-payroll', label: 'Bảng lương', icon: Wallet },
];
</script>

<template>
    <div class="mb-5">
        <p class="text-[14px] text-slate-500">Chấm công, lương / {{ tabs.find((t) => t.page === page)?.label }}</p>
        <div class="mt-1 flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-[26px] font-bold leading-tight tracking-tight text-slate-900 sm:text-[30px]" data-testid="staff-title">{{ title }}</h1>
                <p class="mt-1 text-[15px] text-slate-500">{{ description }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <MonthSwitch v-model="month" />
                <slot name="actions" />
            </div>
        </div>
        <nav class="mt-5 flex gap-5 overflow-x-auto border-b border-slate-200 sm:gap-7" aria-label="Chấm công, lương" data-testid="staff-tabs">
            <RouterLink
                v-for="t in tabs"
                :key="t.page"
                :to="{ name: t.route, query: { month } }"
                :class="cn('-mb-px inline-flex shrink-0 items-center gap-2 border-b-2 px-1 pb-3 pt-1 text-[15px]', t.page === page ? 'border-blue-600 font-semibold text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-900')"
            >
                <component :is="t.icon" class="size-[17px]" /> {{ t.label }}
            </RouterLink>
        </nav>
    </div>
</template>
