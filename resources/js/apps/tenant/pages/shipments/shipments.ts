/** Shipments (vận đơn): types and labels shared by the shipments page, its drawer and the order's shipment card. */

export type BadgeTone = 'default' | 'success' | 'warning' | 'danger' | 'muted' | 'info';

export type { ShipmentAction, ShipmentData, ShipmentEventData, ShipmentSummary } from '@/shared/types';
import type { ShipmentData } from '@/shared/types';

export const shipmentTone: Record<string, BadgeTone> = {
    pending: 'muted',
    awaiting_pickup: 'muted',
    picked_up: 'info',
    in_transit: 'info',
    out_for_delivery: 'info',
    redelivering: 'info',
    delivered: 'success',
    failed: 'danger',
    returning: 'warning',
    returned: 'warning',
    cancelled: 'muted',
};

export const shipmentTabs = [
    { value: 'all', label: 'Tất cả' },
    { value: 'awaiting', label: 'Chờ lấy hàng' },
    { value: 'delivering', label: 'Đang giao' },
    { value: 'failed', label: 'Giao thất bại' },
    { value: 'returning', label: 'Đang hoàn' },
    { value: 'delivered', label: 'Giao thành công' },
    { value: 'returned', label: 'Đã hoàn' },
    { value: 'cancelled', label: 'Đã hủy' },
];

export const carrierKeys: Record<string, string> = { ghn: 'GHN', viettelpost: 'Viettel Post', spx: 'SPX Express', jt: 'J&T Express', other: 'Hãng khác / tự giao' };

/** "Giao thất bại · lần 2" */
export function statusText(s: Pick<ShipmentData, 'status' | 'status_label' | 'attempts'>): string {
    return s.status === 'failed' && s.attempts > 1 ? `${s.status_label} · lần ${s.attempts}` : s.status_label;
}

export function carrierText(s: Pick<ShipmentData, 'carrier' | 'carrier_name'>): string {
    return s.carrier_name ?? (s.carrier ? carrierKeys[s.carrier] ?? s.carrier : 'Chưa có hãng');
}

/** Primary action first: what the shop most likely does next. */
export const actionOrder = ['pick_up', 'redeliver', 'deliver', 'fail', 'return', 'cancel'];

/** Actions that ask for a reason. */
export const actionNeedsReason: Record<string, string> = {
    fail: 'Lý do giao thất bại (hãng báo)',
    return: 'Lý do chuyển hoàn',
    cancel: 'Lý do hủy vận đơn',
};
