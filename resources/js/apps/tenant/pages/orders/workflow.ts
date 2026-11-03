import type { OrderDetail } from '@/shared/types';

/** Steps of the progress bar on an order (placed → confirmed → packed → handed over → done). */
export const workflowSteps = ['Đặt hàng', 'Xác nhận', 'Đóng gói', 'Xuất kho', 'Hoàn tất'] as const;

/** Index of the last step a status has reached. */
const reached: Record<string, number> = {
    UNPAID: 0,
    PENDING_CONFIRMATION: 0,
    READY_TO_PACK: 1,
    PACKED: 2,
    HANDED_OVER: 3,
    IN_TRANSIT: 3,
    FAILED_DELIVERY: 3,
    DELIVERED: 4,
    COMPLETED: 4,
    RETURN_REQUESTED: 4,
    RETURNED: 4,
};

export interface WorkflowStep {
    label: string;
    state: 'done' | 'stopped' | 'todo';
    at: string | null;
}

/**
 * Progress of an order for the stepper. Steps already reached are done, with the first time the order got there
 * (from the status history). A cancelled order or a failed delivery shows a red step right after the last one reached.
 */
export function orderWorkflow(order: Pick<OrderDetail, 'status' | 'placed_at' | 'history'>): WorkflowStep[] {
    const history = order.history ?? [];
    const cancel = order.status === 'CANCELLED' ? [...history].reverse().find((h) => h.to === 'CANCELLED') : undefined;
    const failedAt = history.find((h) => h.to === 'FAILED_DELIVERY');
    const failed = order.status === 'FAILED_DELIVERY' || (order.status === 'RETURNED' && failedAt !== undefined);
    const last = cancel ? (reached[cancel.from ?? ''] ?? 0) : failed ? 3 : (reached[order.status] ?? 0);

    const firstAt = (index: number): string | null => {
        if (index === 0) return order.placed_at;
        const h = history.find((e) => (reached[e.to] ?? -1) >= index);
        return h ? (h.occurred_at ?? h.created_at) : null;
    };

    return workflowSteps.map((label, index): WorkflowStep => {
        if (index <= last) return { label, state: 'done', at: firstAt(index) };
        if (index === last + 1 && cancel) return { label: 'Đã hủy', state: 'stopped', at: cancel.occurred_at ?? cancel.created_at };
        if (index === last + 1 && failed) return { label: 'Giao thất bại', state: 'stopped', at: failedAt ? (failedAt.occurred_at ?? failedAt.created_at) : null };
        return { label, state: 'todo', at: null };
    });
}
