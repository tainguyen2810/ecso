import { describe, expect, it } from 'vitest';
import { cn, formatVnd, initials } from './utils';

describe('utils', () => {
    it('merges tailwind classes, last one wins', () => {
        expect(cn('px-2 py-1', false && 'hidden', 'px-4')).toBe('py-1 px-4');
    });

    it('builds initials from the last two words of a Vietnamese name', () => {
        expect(initials('Nguyễn Văn An')).toBe('VA');
        expect(initials('Cửa hàng Mẫu')).toBe('HM');
    });

    it('formats VND without decimals', () => {
        expect(formatVnd(199000).replace(/\s/g, ' ')).toBe('199.000 ₫');
    });
});
