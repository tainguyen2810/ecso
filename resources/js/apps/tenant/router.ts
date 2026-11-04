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
            { path: 'products/taxonomy', name: 'product-taxonomy', component: () => import('./pages/products/Taxonomy.vue'), meta: { permission: 'products.view', nav: 'products' } },
            { path: 'products/labels', name: 'product-labels', component: () => import('./pages/products/BarcodeLabels.vue'), meta: { permission: 'products.view', nav: 'products' } },
            { path: 'products/:id/edit', name: 'product-edit', component: () => import('./pages/products/ProductForm.vue'), meta: { permission: 'products.manage', nav: 'products' } },
            { path: 'products/:id', name: 'product-show', component: () => import('./pages/products/ProductDetail.vue'), meta: { permission: 'products.view', nav: 'products' } },
            { path: 'inventory', name: 'inventory', component: () => import('./pages/inventory/StockList.vue'), meta: { permission: 'inventory.view', nav: 'inventory' } },
            { path: 'inventory/sku/:id', name: 'inventory-card', component: () => import('./pages/inventory/StockCard.vue'), meta: { permission: 'inventory.view', nav: 'inventory' } },
            { path: 'inventory/reconciliation', name: 'inventory-reconciliation', component: () => import('./pages/inventory/Reconciliation.vue'), meta: { permission: 'inventory.view', nav: 'inventory' } },
            { path: 'inventory/stocktake', name: 'stocktake', component: () => import('./pages/inventory/StocktakeList.vue'), meta: { permission: 'inventory.view', nav: 'inventory' } },
            { path: 'inventory/stocktake/new', name: 'stocktake-new', component: () => import('./pages/inventory/Stocktake.vue'), meta: { permission: 'inventory.adjust', nav: 'inventory', section: 'stocktake' } },
            { path: 'inventory/stocktake/:id', name: 'stocktake-edit', component: () => import('./pages/inventory/Stocktake.vue'), meta: { permission: 'inventory.adjust', nav: 'inventory', section: 'stocktake' } },
            { path: 'inventory/cost-adjustments', name: 'cost-adjustments', component: () => import('./pages/inventory/CostAdjustments.vue'), meta: { permission: 'inventory.view', nav: 'inventory' } },
            { path: 'inventory/warehouses', name: 'warehouses', component: () => import('./pages/inventory/Warehouses.vue'), meta: { permission: 'inventory.view', nav: 'inventory' } },
            ...(['receipts', 'adjustments', 'transfers'] as const).flatMap((kind) => [
                { path: `inventory/${kind}`, name: `inventory-${kind}`, component: () => import('./pages/inventory/DocumentList.vue'), meta: { permission: 'inventory.view', nav: 'inventory', kind } },
                { path: `inventory/${kind}/new`, name: `inventory-${kind}-new`, component: () => import('./pages/inventory/DocumentForm.vue'), meta: { permission: documentKinds[kind].permission, nav: 'inventory', kind, section: `inventory-${kind}` } },
                { path: `inventory/${kind}/:id`, name: `inventory-${kind}-show`, component: () => import('./pages/inventory/DocumentDetail.vue'), meta: { permission: 'inventory.view', nav: 'inventory', kind, section: `inventory-${kind}` } },
            ]),
            { path: 'orders', name: 'orders', component: () => import('./pages/orders/OrderList.vue'), meta: { permission: 'orders.view', nav: 'orders' } },
            { path: 'orders/new', name: 'order-new', component: () => import('./pages/orders/OrderForm.vue'), meta: { permission: 'orders.create', nav: 'orders' } },
            { path: 'orders/:id/edit', name: 'order-edit', component: () => import('./pages/orders/OrderForm.vue'), meta: { permission: 'orders.create', nav: 'orders' } },
            { path: 'customers', name: 'customers', component: () => import('./pages/customers/CustomerList.vue'), meta: { permission: 'customers.view', nav: 'customers' } },
            { path: 'customers/groups', name: 'customer-groups', component: () => import('./pages/customers/CustomerGroups.vue'), meta: { permission: 'customers.view', nav: 'customers' } },
            { path: 'customers/:id', name: 'customer-show', component: () => import('./pages/customers/CustomerDetail.vue'), meta: { permission: 'customers.view', nav: 'customers' } },
            { path: 'pos/shifts', name: 'pos-shifts', component: () => import('./pages/sales/Shifts.vue'), meta: { anyPermission: ['orders.create', 'orders.shifts'], nav: 'pos' } },
            { path: 'pos', name: 'pos', component: () => import('./pages/sales/Pos.vue'), meta: { permission: 'orders.create', nav: 'pos' } },
            { path: 'sales-channels', name: 'sales-channels', component: () => import('./pages/sales/SalesChannels.vue'), meta: { permission: 'orders.view', nav: 'sales-channels' } },
            { path: 'orders/:id', name: 'order-show', component: () => import('./pages/orders/OrderDetail.vue'), meta: { permission: 'orders.view', nav: 'orders' } },
            { path: 'purchasing', name: 'purchasing', component: () => import('./pages/purchasing/PurchaseOrderList.vue'), meta: { permission: 'purchasing.view', nav: 'purchasing' } },
            { path: 'purchasing/orders', name: 'purchase-orders-ordering', component: () => import('./pages/purchasing/PurchaseOrderList.vue'), meta: { permission: 'purchasing.view', nav: 'purchasing', mode: 'ordering' } },
            { path: 'purchasing/new', name: 'purchase-new', component: () => import('./pages/purchasing/PurchaseOrderForm.vue'), meta: { permission: 'purchasing.manage', nav: 'purchasing' } },
            { path: 'purchasing/suppliers', name: 'suppliers', component: () => import('./pages/purchasing/SupplierList.vue'), meta: { permission: 'purchasing.view', nav: 'purchasing' } },
            { path: 'purchasing/suppliers/:id', name: 'supplier-show', component: () => import('./pages/purchasing/SupplierDetail.vue'), meta: { permission: 'purchasing.view', nav: 'purchasing' } },
            { path: 'purchasing/returns', name: 'supplier-returns', component: () => import('./pages/purchasing/SupplierReturnList.vue'), meta: { permission: 'purchasing.view', nav: 'purchasing' } },
            { path: 'purchasing/reorder', name: 'reorder', component: () => import('./pages/purchasing/Reorder.vue'), meta: { permission: 'purchasing.view', nav: 'purchasing' } },
            { path: 'purchasing/:id/edit', name: 'purchase-edit', component: () => import('./pages/purchasing/PurchaseOrderForm.vue'), meta: { permission: 'purchasing.manage', nav: 'purchasing' } },
            { path: 'purchasing/:id', name: 'purchase-show', component: () => import('./pages/purchasing/PurchaseOrderDetail.vue'), meta: { permission: 'purchasing.view', nav: 'purchasing' } },
            { path: 'returns', name: 'returns', component: () => import('./pages/returns/ReturnList.vue'), meta: { permission: 'returns.view', nav: 'returns' } },
            { path: 'returns/:id', name: 'return-show', component: () => import('./pages/returns/ReturnDetail.vue'), meta: { permission: 'returns.view', nav: 'returns' } },
            { path: 'marketplaces', name: 'marketplaces', component: () => import('./pages/marketplaces/Marketplaces.vue'), meta: { permission: 'marketplace.view', nav: 'marketplaces' } },
            { path: 'marketplaces/:marketplace/callback', name: 'marketplace-callback', component: () => import('./pages/marketplaces/MarketplaceCallback.vue'), meta: { permission: 'marketplace.connect', nav: 'marketplaces' } },
            { path: 'marketplaces/:id/store', name: 'marketplace-store', component: () => import('./pages/marketplaces/WooStore.vue'), meta: { permission: 'marketplace.view', nav: 'marketplaces' } },
            { path: 'marketplaces/prices', name: 'marketplace-prices', component: () => import('./pages/marketplaces/MarketplacePrices.vue'), meta: { permission: 'marketplace.view', nav: 'marketplaces' } },
            { path: 'marketplaces/:id/listings', name: 'marketplace-listings', component: () => import('./pages/marketplaces/Listings.vue'), meta: { permission: 'marketplace.view', nav: 'marketplaces' } },
            { path: 'cash', name: 'cash', component: () => import('./pages/cash/VoucherList.vue'), meta: { permission: 'finance.view', nav: 'cash' } },
            { path: 'cash/types', name: 'cash-types', component: () => import('./pages/cash/VoucherTypes.vue'), meta: { permission: 'finance.view', nav: 'cash' } },
            { path: 'finance', name: 'finance', component: () => import('./pages/finance/Finance.vue'), meta: { permission: 'finance.view', nav: 'finance' } },
            { path: 'reports', name: 'reports', redirect: { name: 'report-group', params: { group: 'sales' } } },
            { path: 'reports/group/:group(sales|purchases|stock|finance|customers)', name: 'report-group', component: () => import('./pages/reports/ReportGroup.vue'), meta: { permission: 'reports.view', nav: 'reports' } },
            { path: 'reports/sales', name: 'report-sales', component: () => import('./pages/reports/SalesReport.vue'), meta: { permission: 'reports.view', nav: 'reports' } },
            { path: 'reports/profit-loss', name: 'report-profit-loss', component: () => import('./pages/reports/ProfitLoss.vue'), meta: { permission: 'finance.profit', nav: 'reports' } },
            { path: 'reports/view/:report(product-sales|orders|sales-lines|stock-detail|returns|payments|purchases|customers|stock-on-hand|low-stock|over-stock|receivables|payables|supplier-payments|adjustments)', name: 'report-detail', component: () => import('./pages/reports/DetailReport.vue'), meta: { permission: 'reports.view', nav: 'reports' } },
            { path: 'reports/stock', name: 'report-stock', component: () => import('./pages/reports/StockLedgerReport.vue'), meta: { permission: 'inventory.view', nav: 'reports' } },
            { path: 'reports/cashbook', name: 'report-cashbook', component: () => import('./pages/reports/Cashbook.vue'), meta: { permission: 'finance.view', nav: 'cash' } },
            { path: 'reports/products', name: 'report-products', component: () => import('./pages/reports/ProductReports.vue'), meta: { permission: 'reports.view', nav: 'reports' } },
            { path: 'members', name: 'members', component: () => import('./pages/Members.vue'), meta: { permission: 'members.view', nav: 'members' } },
            { path: 'subscription', name: 'subscription', component: () => import('./pages/Subscription.vue'), meta: { permission: 'billing.view', nav: 'subscription' } },
            { path: 'audit', name: 'audit', component: () => import('./pages/AuditLog.vue'), meta: { permission: 'audit.view', nav: 'audit' } },
            { path: 'settings', name: 'settings', component: () => import('./pages/settings/SettingsHub.vue'), meta: { anyPermission: ['settings.manage', 'members.view', 'billing.view', 'audit.view'], nav: 'settings' } },
            { path: 'settings/store', name: 'settings-store', component: () => import('./pages/settings/StoreSettings.vue'), meta: { permission: 'settings.manage', nav: 'settings' } },
            { path: 'settings/pricing', name: 'settings-pricing', component: () => import('./pages/settings/PricingSettings.vue'), meta: { permission: 'settings.manage', nav: 'settings' } },
            { path: 'settings/pricing/fees', name: 'settings-pricing-fees', component: () => import('./pages/settings/FeeCheck.vue'), meta: { permission: 'settings.manage', nav: 'settings' } },
            { path: 'settings/payments', name: 'settings-payments', component: () => import('./pages/settings/PaymentSettings.vue'), meta: { permission: 'settings.manage', nav: 'settings' } },
            { path: 'settings/sales', name: 'settings-sales', component: () => import('./pages/settings/SalesSettings.vue'), meta: { permission: 'settings.manage', nav: 'settings' } },
            { path: 'settings/print', name: 'settings-print', component: () => import('./pages/settings/PrintSettings.vue'), meta: { permission: 'settings.manage', nav: 'settings' } },
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
        const anyPermission = to.meta.anyPermission as string[] | undefined;
        if (anyPermission && !anyPermission.some((p) => tenant.can(p))) return { name: 'forbidden' };
    }
    return true;
});
