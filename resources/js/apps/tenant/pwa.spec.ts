import { describe, expect, it } from 'vitest';
import { BANNER_SNOOZE_MS, bannerSnoozed } from './pwa';

describe('install banner', () => {
    it('stays hidden for 30 days after "Để sau"', () => {
        const now = 1_800_000_000_000;
        expect(bannerSnoozed(now, () => null)).toBe(false);
        expect(bannerSnoozed(now, () => String(now - 1000))).toBe(true);
        expect(bannerSnoozed(now, () => String(now - BANNER_SNOOZE_MS - 1))).toBe(false);
    });
});
