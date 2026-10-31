import axios, { AxiosError, type AxiosInstance } from 'axios';

export const TENANT_STORAGE_KEY = 'ecso.tenant';

export interface ApiErrorBody {
    message?: string;
    code?: string;
    errors?: Record<string, string[]>;
}

export class ApiError extends Error {
    constructor(
        public readonly status: number,
        public readonly body: ApiErrorBody,
    ) {
        super(body.message ?? `HTTP ${status}`);
    }

    field(name: string): string | undefined {
        return this.body.errors?.[name]?.[0];
    }
}

let currentTenant: string | null = readStoredTenant();

export function readStoredTenant(): string | null {
    try {
        return localStorage.getItem(TENANT_STORAGE_KEY);
    } catch {
        return null;
    }
}

/** The tenant header is only a selector; the server checks membership on every request. */
export function setApiTenant(ulid: string | null): void {
    currentTenant = ulid;
    try {
        if (ulid) localStorage.setItem(TENANT_STORAGE_KEY, ulid);
        else localStorage.removeItem(TENANT_STORAGE_KEY);
    } catch {
        /* storage unavailable: keep in memory only */
    }
}

export function getApiTenant(): string | null {
    return currentTenant;
}

type UnauthorizedHandler = () => void;
let onUnauthorized: UnauthorizedHandler = () => {};
export function setUnauthorizedHandler(handler: UnauthorizedHandler): void {
    onUnauthorized = handler;
}

export function createApi(): AxiosInstance {
    const instance = axios.create({
        baseURL: '/api/v1',
        withCredentials: true,
        withXSRFToken: true,
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });

    instance.interceptors.request.use((config) => {
        if (currentTenant) config.headers.set('X-Tenant', currentTenant);
        return config;
    });

    instance.interceptors.response.use(
        (response) => response,
        (error: AxiosError<ApiErrorBody>) => {
            const status = error.response?.status ?? 0;
            // /auth/me answering 401 just means "not signed in yet"; it must not trigger a redirect loop.
            if (status === 401 && error.config?.url !== '/auth/me') onUnauthorized();
            return Promise.reject(new ApiError(status, error.response?.data ?? { message: 'Không kết nối được máy chủ.' }));
        },
    );

    return instance;
}

export const api = createApi();

/** Sanctum SPA: fetch the CSRF cookie before the first state-changing request. */
export async function ensureCsrf(): Promise<void> {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
}
