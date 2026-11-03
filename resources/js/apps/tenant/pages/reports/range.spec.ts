import { describe, expect, it } from 'vitest';
import { presetRange, toCsv } from './range';

describe('report ranges', () => {
    const now = new Date(2026, 10, 3); // 3 Nov 2026
    it('computes presets', () => {
        expect(presetRange('today', now)).toEqual({ from: '2026-11-03', to: '2026-11-03' });
        expect(presetRange('7d', now)).toEqual({ from: '2026-10-28', to: '2026-11-03' });
        expect(presetRange('month', now)).toEqual({ from: '2026-11-01', to: '2026-11-03' });
        expect(presetRange('last-month', now)).toEqual({ from: '2026-10-01', to: '2026-10-31' });
    });
    it('quotes CSV cells that need it', () => {
        expect(toCsv(['a', 'b'], [['x,y', 1], ['say "hi"', null]])).toBe('﻿a,b\n"x,y",1\n"say ""hi""",');
    });
});
