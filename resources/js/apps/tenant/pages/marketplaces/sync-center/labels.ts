import type { SyncTaskStatus, SyncTaskType } from '@/shared/types';

type BadgeTone = 'default' | 'success' | 'warning' | 'danger' | 'muted' | 'info';

/** Normalized task statuses of the Sync Center (SyncActivityQuery::STATUSES), in queue-chip order. */
export const taskStatuses: { value: SyncTaskStatus; label: string; tone: BadgeTone }[] = [
    { value: 'pending', label: 'Đang chờ', tone: 'muted' },
    { value: 'retry_scheduled', label: 'Chờ thử lại', tone: 'warning' },
    { value: 'processing', label: 'Đang chạy', tone: 'info' },
    { value: 'success', label: 'Thành công', tone: 'success' },
    { value: 'failed', label: 'Thất bại', tone: 'danger' },
    { value: 'dead_letter', label: 'Hết lượt thử', tone: 'danger' },
    { value: 'cancelled', label: 'Đã hủy', tone: 'muted' },
];
export const statusInfo = Object.fromEntries(taskStatuses.map((s) => [s.value, s])) as Record<SyncTaskStatus, (typeof taskStatuses)[number]>;

export const taskTypes: Record<SyncTaskType, string> = {
    webhook: 'Webhook',
    stock_push: 'Đẩy tồn',
    order_poll: 'Đọc đơn',
    price_push: 'Đẩy giá',
};

export const shopStatus: Record<string, { label: string; tone: BadgeTone }> = {
    connected: { label: 'Hoạt động', tone: 'success' },
    expired: { label: 'Mất kết nối', tone: 'danger' },
    error: { label: 'Lỗi kết nối', tone: 'danger' },
    revoked: { label: 'Đã ngắt', tone: 'muted' },
};

/** Codes whose fix is on the Kết nối sàn page (reconnect / new key). */
export const reconnectCodes = ['token_expired', 'permission_denied'];

/** Time windows offered by the queue filter, in hours (null = everything still kept). */
export const timeWindows: { value: string; label: string; hours: number | null }[] = [
    { value: '24h', label: '24 giờ qua', hours: 24 },
    { value: '7d', label: '7 ngày qua', hours: 24 * 7 },
    { value: '30d', label: '30 ngày qua', hours: 24 * 30 },
    { value: 'all', label: 'Tất cả', hours: null },
];

export function sinceIso(window: string): string | undefined {
    const hours = timeWindows.find((w) => w.value === window)?.hours;
    return hours ? new Date(Date.now() - hours * 3600_000).toISOString() : undefined;
}

export function duration(ms: number | null | undefined): string {
    if (ms === null || ms === undefined) return '—';
    return ms < 1000 ? `${ms} ms` : `${(ms / 1000).toLocaleString('vi-VN', { maximumFractionDigits: 1 })} giây`;
}

export function timeOnly(iso: string | null | undefined): string {
    if (!iso) return '—';
    const d = new Date(iso);
    const today = new Date().toDateString() === d.toDateString();
    return new Intl.DateTimeFormat('vi-VN', today ? { timeStyle: 'medium' } : { dateStyle: 'short', timeStyle: 'short' }).format(d);
}

/** Keys of an order poll's counts (sync_runs.counts). */
export const countLabels: Record<string, string> = { created: 'mới', updated: 'cập nhật', unchanged: 'không đổi', skipped: 'bỏ qua', stale: 'cũ', failed: 'lỗi', ignored_transition: 'xung đột', deleted_on_channel: 'đã xóa' };
