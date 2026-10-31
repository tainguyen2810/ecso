import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/shared/stores/auth';
import { api } from '@/shared/lib/api';
import { adminNav } from './navigation';

export interface TwoFactorStatus {
    required: boolean;
    enabled: boolean;
    passed: boolean;
    recovery_codes_left: number | null;
}

let cached: TwoFactorStatus | null = null;
export async function twoFactorStatus(useCache = false): Promise<TwoFactorStatus> {
    if (useCache && cached?.passed) return cached;
    cached = (await api.get('/admin/2fa')).data.data as TwoFactorStatus;
    return cached;
}

export const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/admin', name: 'dashboard', component: () => import('./pages/Dashboard.vue') },
        { path: '/admin/tenants', name: 'tenants', component: () => import('./pages/Tenants.vue') },
        { path: '/admin/billing', name: 'billing', component: () => import('./pages/Billing.vue') },
        { path: '/admin/monitoring', name: 'monitoring', component: () => import('./pages/Monitoring.vue') },
        { path: '/admin/2fa', name: 'two-factor', component: () => import('./pages/TwoFactor.vue'), meta: { bare: true } },
        ...adminNav
            .filter((i) => i.phase)
            .map((i) => ({ path: i.path, name: i.name, component: () => import('./pages/Placeholder.vue') })),
    ],
});

router.beforeEach(async (to) => {
    const auth = useAuthStore();
    if (!auth.loaded) await auth.fetchMe();
    if (!auth.user) {
        window.location.href = '/login?redirect=/admin';
        return false;
    }
    if (!auth.user.is_super_admin) {
        window.location.href = '/';
        return false;
    }
    // Two-factor: set up (first time) or verify (each session) before any admin page.
    if (to.name !== 'two-factor') {
        const status = await twoFactorStatus(true);
        if (status.required && (!status.enabled || !status.passed)) return { name: 'two-factor', query: { redirect: to.fullPath } };
    }
    return true;
});
