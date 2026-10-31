import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import { useAuthStore } from '@/shared/stores/auth';
import { useTenantStore } from '@/shared/stores/tenant';
import { allNavItems } from './navigation';

const moduleRoutes: RouteRecordRaw[] = allNavItems
    .filter((item) => item.phase !== undefined)
    .map((item) => ({
        path: item.path,
        name: item.name,
        component: () => import('./pages/ModulePlaceholder.vue'),
        meta: { permission: item.permission, nav: item.name },
    }));

const routes: RouteRecordRaw[] = [
    { path: '/login', name: 'login', component: () => import('./pages/auth/Login.vue'), meta: { guest: true } },
    { path: '/register', name: 'register', component: () => import('./pages/auth/Register.vue'), meta: { guest: true } },
    { path: '/forgot-password', name: 'forgot-password', component: () => import('./pages/auth/ForgotPassword.vue'), meta: { guest: true } },
    { path: '/reset-password', name: 'reset-password', component: () => import('./pages/auth/ResetPassword.vue'), meta: { guest: true } },
    { path: '/verify-email', name: 'verify-email', component: () => import('./pages/auth/VerifyEmail.vue'), meta: { auth: true } },
    { path: '/invitations/:token', name: 'invitation', component: () => import('./pages/Invitation.vue') },
    { path: '/tenants', name: 'tenants', component: () => import('./pages/SelectTenant.vue'), meta: { auth: true, verified: true } },
    {
        path: '/',
        component: () => import('./layouts/AppLayout.vue'),
        meta: { auth: true, verified: true, tenant: true },
        children: [
            { path: '', name: 'dashboard', component: () => import('./pages/Dashboard.vue'), meta: { nav: 'dashboard' } },
            ...moduleRoutes,
            { path: 'members', name: 'members', component: () => import('./pages/Members.vue'), meta: { permission: 'members.view', nav: 'members' } },
            { path: 'subscription', name: 'subscription', component: () => import('./pages/Subscription.vue'), meta: { permission: 'billing.view', nav: 'subscription' } },
            { path: 'audit', name: 'audit', component: () => import('./pages/AuditLog.vue'), meta: { permission: 'audit.view', nav: 'audit' } },
            { path: 'settings', name: 'settings', component: () => import('./pages/Settings.vue'), meta: { permission: 'settings.manage', nav: 'settings' } },
            { path: 'forbidden', name: 'forbidden', component: () => import('./pages/Forbidden.vue') },
        ],
    },
    { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('./pages/NotFound.vue') },
];

export const router = createRouter({ history: createWebHistory(), routes });

router.beforeEach(async (to) => {
    const auth = useAuthStore();
    const tenant = useTenantStore();
    if (!auth.loaded) await auth.fetchMe();

    const needsAuth = to.matched.some((r) => r.meta.auth);
    if (needsAuth && !auth.user) return { name: 'login', query: { redirect: to.fullPath } };
    if (to.meta.guest && auth.user) return { path: '/' };

    if (to.matched.some((r) => r.meta.verified) && auth.user && !auth.user.email_verified) return { name: 'verify-email' };

    if (to.matched.some((r) => r.meta.tenant)) {
        if (!tenant.context) {
            const ok = await tenant.restore().catch(() => false);
            if (!ok) return { name: 'tenants' };
        }
        const permission = to.meta.permission as string | undefined;
        if (permission && !tenant.can(permission)) return { name: 'forbidden' };
    }
    return true;
});
