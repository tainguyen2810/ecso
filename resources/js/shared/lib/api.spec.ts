import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AxiosHeaders, type InternalAxiosRequestConfig } from 'axios';
import { ApiError, createApi, getApiTenant, setApiTenant, setUnauthorizedHandler } from './api';

function adapterReturning(status: number, data: unknown) {
    return vi.fn(async (config: InternalAxiosRequestConfig) => {
        if (status >= 400) {
            const error = Object.assign(new Error('fail'), { config, response: { status, data, config, headers: {} }, isAxiosError: true });
            throw error;
        }
        return { data, status, statusText: 'OK', headers: {}, config };
    });
}

describe('api client', () => {
    beforeEach(() => {
        localStorage.clear();
        setApiTenant(null);
    });

    it('sends the selected tenant as X-Tenant and persists the choice', async () => {
        const api = createApi();
        const adapter = adapterReturning(200, { ok: true });
        api.defaults.adapter = adapter;

        setApiTenant('01TENANT');
        await api.get('/tenant');

        const headers = adapter.mock.calls[0]![0].headers as AxiosHeaders;
        expect(headers.get('X-Tenant')).toBe('01TENANT');
        expect(getApiTenant()).toBe('01TENANT');
        expect(localStorage.getItem('ecso.tenant')).toBe('01TENANT');
    });

    it('omits the tenant header when no tenant is selected', async () => {
        const api = createApi();
        const adapter = adapterReturning(200, {});
        api.defaults.adapter = adapter;

        await api.get('/tenants');

        expect((adapter.mock.calls[0]![0].headers as AxiosHeaders).has('X-Tenant')).toBe(false);
    });

    it('wraps errors with field messages', async () => {
        const api = createApi();
        api.defaults.adapter = adapterReturning(422, { message: 'Invalid', errors: { email: ['Email sai'] } });

        const error = await api.post('/auth/login').catch((e) => e);

        expect(error).toBeInstanceOf(ApiError);
        expect(error.status).toBe(422);
        expect(error.field('email')).toBe('Email sai');
    });

    it('calls the unauthorized handler on 401, except for the session probe', async () => {
        const handler = vi.fn();
        setUnauthorizedHandler(handler);
        const api = createApi();
        api.defaults.adapter = adapterReturning(401, { message: 'Unauthenticated.' });

        await api.get('/auth/me').catch(() => {});
        expect(handler).not.toHaveBeenCalled();

        await api.get('/members').catch(() => {});
        expect(handler).toHaveBeenCalledOnce();
    });
});
