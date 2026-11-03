import { describe, expect, it } from 'vitest';
import { orderWorkflow } from './workflow';

const h = (from: string | null, to: string, at: string) => ({ from, to, raw_status: null, source: 'user', actor: null, note: null, occurred_at: at, created_at: at });

describe('order workflow stepper', () => {
    it('marks the steps reached with the first time the order got there', () => {
        const steps = orderWorkflow({
            status: 'PACKED',
            placed_at: '2026-11-01T08:00:00+07:00',
            history: [h(null, 'PENDING_CONFIRMATION', 't0'), h('PENDING_CONFIRMATION', 'READY_TO_PACK', 't1'), h('READY_TO_PACK', 'PACKED', 't2')],
        });

        expect(steps.map((s) => s.state)).toEqual(['done', 'done', 'done', 'todo', 'todo']);
        expect(steps.map((s) => s.at)).toEqual(['2026-11-01T08:00:00+07:00', 't1', 't2', null, null]);
    });

    it('fills skipped steps when an order jumps ahead (completed at the counter)', () => {
        const steps = orderWorkflow({ status: 'COMPLETED', placed_at: 't0', history: [h(null, 'PENDING_CONFIRMATION', 't0'), h('PENDING_CONFIRMATION', 'COMPLETED', 't5')] });

        expect(steps.every((s) => s.state === 'done')).toBe(true);
        expect(steps[2]!.at).toBe('t5');
    });

    it('stops in red right after the last step reached when the order is cancelled', () => {
        const steps = orderWorkflow({ status: 'CANCELLED', placed_at: 't0', history: [h(null, 'PENDING_CONFIRMATION', 't0'), h('PENDING_CONFIRMATION', 'READY_TO_PACK', 't1'), h('READY_TO_PACK', 'CANCELLED', 't2')] });

        expect(steps.map((s) => s.state)).toEqual(['done', 'done', 'stopped', 'todo', 'todo']);
        expect(steps[2]).toEqual({ label: 'Đã hủy', state: 'stopped', at: 't2' });
    });

    it('shows a failed delivery after handover', () => {
        const steps = orderWorkflow({ status: 'FAILED_DELIVERY', placed_at: 't0', history: [h(null, 'READY_TO_PACK', 't0'), h('READY_TO_PACK', 'HANDED_OVER', 't3'), h('HANDED_OVER', 'FAILED_DELIVERY', 't4')] });

        expect(steps.map((s) => s.state)).toEqual(['done', 'done', 'done', 'done', 'stopped']);
        expect(steps[4]).toEqual({ label: 'Giao thất bại', state: 'stopped', at: 't4' });
    });
});
