import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/shared/stores/auth';
import { adminNav } from './navigation';

export const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/admin', name: 'dashboard', component: () => import('./pages/Dashboard.vue') },
        { path: '/admin/tenants', name: 'tenants', component: () => import('./pages/Tenants.vue') },
        ...adminNav
            .filter((i) => i.phase)
            .map((i) => ({ path: i.path, name: i.name, component: () => import('./pages/Placeholder.vue') })),
    ],
});

router.beforeEach(async () => {
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
    return true;
});
