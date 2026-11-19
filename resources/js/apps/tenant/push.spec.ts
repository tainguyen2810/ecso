import { describe, expect, it } from 'vitest';
import { keyBytes } from './push';

describe('push key', () => {
    it('turns the base64url application server key into its 65 bytes', () => {
        const bytes = new Uint8Array(65).map((_, i) => (i * 37) % 256);
        const b64url = btoa(String.fromCharCode(...bytes)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
        expect(Array.from(keyBytes(b64url))).toEqual(Array.from(bytes));
    });
});
