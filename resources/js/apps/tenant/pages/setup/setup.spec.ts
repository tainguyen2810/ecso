import { describe, expect, it } from 'vitest';
import { demoDaysLeft, hidesPos, startTarget } from './setup';

describe('setup steps', () => {
    it('leads the last step to the right page', () => {
        expect(startTarget('migrate')).toBe('/settings/data-migration');
        expect(startTarget('demo')).toBe('/');
        expect(startTarget('empty')).toBe('/');
    });

    it('hides the POS menu only when the shop said it does not sell in a store', () => {
        expect(hidesPos(null)).toBe(false);
        expect(hidesPos([])).toBe(false);
        expect(hidesPos(['shopee', 'pos'])).toBe(false);
        expect(hidesPos(['shopee', 'social'])).toBe(true);
    });

    it('counts the days a sample store has left', () => {
        const now = new Date('2026-11-14T03:00:00Z');
        expect(demoDaysLeft('2026-12-14T03:00:00Z', now)).toBe(30);
        expect(demoDaysLeft('2026-11-13T03:00:00Z', now)).toBe(0);
        expect(demoDaysLeft(null, now)).toBe(0);
    });
});
