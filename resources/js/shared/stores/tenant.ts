import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { api, getApiTenant, setApiTenant } from '@/shared/lib/api';
import { useSettingsStore } from './settings';
import type { Tenant, TenantContextData } from '@/shared/types';

export const useTenantStore = defineStore('tenant', () => {
    const tenants = ref<Tenant[]>([]);
    const context = ref<TenantContextData | null>(null);

    const current = computed(() => context.value?.tenant ?? null);
    const permissions = computed(() => new Set(context.value?.permissions ?? []));

    function can(permission: string): boolean {
        return permissions.value.has(permission);
    }

    /** Whether the member may move stock in this warehouse (no warehouses assigned = every warehouse). */
    function worksIn(warehouseId: string | null | undefined): boolean {
        const assigned = context.value?.warehouses;
        return !assigned || !warehouseId || assigned.includes(warehouseId);
    }

    async function fetchTenants(): Promise<Tenant[]> {
        const { data } = await api.get('/tenants');
        tenants.value = data.data;
        return tenants.value;
    }

    /** Loads the context of the stored tenant, or the first tenant the user belongs to. */
    async function restore(): Promise<boolean> {
        await fetchTenants();
        const stored = getApiTenant();
        const target = tenants.value.find((t) => t.id === stored) ?? tenants.value[0];
        if (!target) {
            setApiTenant(null);
            context.value = null;
            return false;
        }
        await select(target.id);
        return true;
    }

    async function select(ulid: string): Promise<void> {
        setApiTenant(ulid);
        useSettingsStore().reset();
        try {
            const { data } = await api.get('/tenant');
            context.value = data.data;
        } catch (e) {
            setApiTenant(null);
            context.value = null;
            throw e;
        }
    }

    async function create(name: string): Promise<Tenant> {
        const { data } = await api.post('/tenants', { name });
        await fetchTenants();
        await select(data.data.id);
        return data.data;
    }

    /** Reloads the current business (after the setup steps change it). */
    async function refresh(): Promise<void> {
        if (current.value) await select(current.value.id);
    }

    /** A2: the user's sample store; made on the first call. The fake data is filled in the background. */
    async function createDemo(): Promise<Tenant> {
        const { data } = await api.post('/demo-store');
        await fetchTenants();
        return data.data;
    }

    async function deleteDemo(ulid: string): Promise<void> {
        await api.delete(`/demo-store/${ulid}`);
        await fetchTenants();
    }

    function reset(): void {
        tenants.value = [];
        context.value = null;
    }

    return { tenants, context, current, permissions, can, worksIn, fetchTenants, restore, select, create, refresh, createDemo, deleteDemo, reset };
});
