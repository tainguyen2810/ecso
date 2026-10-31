/**
 * The wizard runs before the app has an APP_KEY, so there is no session or CSRF cookie: every call
 * carries the one-time install token instead.
 */
export interface InstallErrorBody {
    message?: string;
    code?: string;
    errors?: Record<string, string[]>;
}

export class InstallError extends Error {
    constructor(
        public readonly status: number,
        public readonly body: InstallErrorBody,
    ) {
        super(body.message ?? `HTTP ${status}`);
    }

    field(name: string): string | undefined {
        return this.body.errors?.[name]?.[0];
    }
}

export async function call<T>(method: 'GET' | 'POST', path: string, token: string | null, body?: unknown): Promise<T> {
    const headers: Record<string, string> = { Accept: 'application/json' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (token) headers['X-Install-Token'] = token;

    const res = await fetch(`/install/api/${path}`, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new InstallError(res.status, json as InstallErrorBody);
    return (json as { data: T }).data;
}

export const STEPS = [
    { key: 'token', label: 'Mã cài đặt' },
    { key: 'requirements', label: 'Kiểm tra máy chủ' },
    { key: 'database', label: 'Database' },
    { key: 'redis', label: 'Redis' },
    { key: 'site', label: 'Website' },
    { key: 'admin', label: 'Quản trị viên' },
    { key: 'done', label: 'Hoàn tất' },
] as const;

export type StepKey = (typeof STEPS)[number]['key'];

/** Default site URL: where the wizard is being opened, without the /install path. */
export function suggestedUrl(location: { protocol: string; host: string }): string {
    return `${location.protocol}//${location.host}`;
}

/** Mirrors the server rule (min 12, letters and numbers) so the user sees the problem before submitting. */
export function passwordProblem(password: string, confirmation: string): string | null {
    if (password.length < 12) return 'Mật khẩu tối thiểu 12 ký tự.';
    if (!/[A-Za-z]/.test(password) || !/\d/.test(password)) return 'Mật khẩu phải có cả chữ và số.';
    if (password !== confirmation) return 'Mật khẩu nhập lại không khớp.';
    return null;
}

/** Worker commands to put into aaPanel's Supervisor plugin after installation. */
export function workerCommands(basePath: string, phpBinary: string, queue: string): { name: string; command: string }[] {
    const php = phpBinary || 'php';
    const worker =
        queue === 'redis'
            ? { name: 'ecso-horizon', command: `${php} ${basePath}/artisan horizon` }
            : { name: 'ecso-queue', command: `${php} ${basePath}/artisan queue:work --queue=webhooks,sync,default --tries=3 --max-time=3600` };
    return [worker, { name: 'ecso-scheduler', command: `${php} ${basePath}/artisan schedule:work` }];
}
