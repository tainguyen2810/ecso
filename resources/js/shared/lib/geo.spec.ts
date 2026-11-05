import { describe, expect, it } from 'vitest';
import { composeAddress, fold, matches } from './geo';

describe('geo', () => {
    it('folds Vietnamese text like the server', () => {
        expect(fold('Phường Bình Thạnh')).toBe('phuong binh thanh');
        expect(fold('Đắk Lắk, P.11')).toBe('dak lak p 11');
    });

    it('finds a ward by its name or a former name without marks', () => {
        const search = 'phuong binh loi trung phuong 11 quan binh thanh';
        expect(matches(search, 'binh loi')).toBe(true);
        expect(matches(search, 'Bình Thạnh')).toBe(true);
        expect(matches(search, 'phuong 11')).toBe(true);
        expect(matches(search, 'go vap')).toBe(false);
        // A word must start where a word starts: "1" does not find "11".
        expect(matches('phuong 11', 'phuong 1 ')).toBe(true);
        expect(matches('phuong 11', 'oi')).toBe(false);
    });

    it('composes the address the way the server does', () => {
        expect(composeAddress(' 12 Nguyễn Huệ ', 'Phường Sài Gòn', 'Thành phố Hồ Chí Minh')).toBe('12 Nguyễn Huệ, Phường Sài Gòn, Thành phố Hồ Chí Minh');
        expect(composeAddress('', null, 'Tỉnh Cao Bằng')).toBe('Tỉnh Cao Bằng');
    });
});
