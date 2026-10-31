import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import { useAuthStore } from '@/shared/stores/auth';
import { useTenantStore } from '@/shared/stores/tenant';
import { allNavItems } from './navigation';
import { documentKinds } from './pages/inventory/labels';

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
            { path: 'products', name: 'products', component: () => import('./pages/products/ProductList.vue'), meta: { permission: 'products.view', nav: 'products' } },
            { path: 'products/new', name: 'product-new', component: () => import('./pages/products/ProductForm.vue'), meta: { permission: 'products.manage', nav: 'products' } },
            { path: 'products/:id', name: 'product-edit', component: () => import('./pages/products/ProductForm.vue'), meta: { permission: 'products.view', nav: 'products' } },
            { path: 'inventory', name: 'inventory', component: () => import('./pages/inventory/StockList.vue'), meta: { permission: 'inventory.view', nav: 'inventory' } },
            { path: 'inventory/sku/:id', name: 'inventory-card', component: () => import('./pages/inventory/StockCard.vue'), meta: { permission: 'inventory.view', nav: 'inventory' } },
            { path: 'inventory/warehouses', name: 'warehouses', component: () => import('./pages/inventory/Warehouses.vue'), meta: { permission: 'inventory.view', nav: 'inventory' } },
            ...(['receipts', 'adjustments', 'transfers'] as const).flatMap((kind) => [
                { path: `inventory/${kind}`, name: `inventory-${kind}`, component: () => import('./pages/inventory/DocumentList.vue'), meta: { permission: 'inventory.view', nav: 'inventory', kind } },
                { path: `inventory/${kind}/new`, name: `inventory-${kind}-new`, component: () => import('./pages/inventory/DocumentForm.vue'), meta: { permission: documentKinds[kind].permission, nav: 'inventory', kind, section: `inventory-${kind}` } },
                { path: `inventory/${kind}/:id`, name: `inventory-${kind}-show`, component: () => import('./pages/inventory/DocumentDetail.vue'), meta: { permission: 'inventory.view', nav: 'inventory', kind, section: `inventory-${kind}` } },
            ]),
            { path: 'orders', name: 'orders', component: () => import('./pages/orders/OrderList.vue'), meta: { permission: 'orders.view', nav: 'orders' } },
            { path: 'orders/:id', name: 'order-show', component: () => import('./pages/orders/OrderDetail.vue'), meta: { permission: 'orders.view', nav: 'orders' } },
            { path: 'returns', name: 'returns', component: () => import('./pages/returns/ReturnList.vue'), meta: { permission: 'returns.view', nav: 'returns' } },
            { path: 'returns/:id', name: 'return-show', component: () => import('./pages/returns/ReturnDetail.vue'), meta: { permission: 'returns.view', nav: 'returns' } },
            { path: 'marketplaces', name: 'marketplaces', component: () => import('./pages/marketplaces/Marketplaces.vue'), meta: { permission: 'marketplace.view', nav: 'marketplaces' } },
            { path: 'marketplaces/:marketplace/callback', name: 'marketplace-callback', component: () => import('./pages/marketplaces/MarketplaceCallback.vue'), meta: { permission: 'marketplace.connect', nav: 'marketplaces' } },
            { path: 'marketplaces/:id/listings', name: 'marketplace-listings', component: () => import('./pages/marketplaces/Listings.vue'), meta: { permission: 'marketplace.view', nav: 'marketplaces' } },
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
