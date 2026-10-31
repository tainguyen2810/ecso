import { describe, expect, it } from 'vitest';
import { passwordProblem, suggestedUrl, workerCommands } from './installApi';

describe('install wizard helpers', () => {
    it('suggests the current origin as site URL', () => {
        expect(suggestedUrl({ protocol: 'https:', host: 'shop.example.vn' })).toBe('https://shop.example.vn');
    });

    it('checks the password like the server does', () => {
        expect(passwordProblem('short1', 'short1')).toMatch(/12/);
        expect(passwordProblem('onlyletterslong', 'onlyletterslong')).toMatch(/chữ và số/);
        expect(passwordProblem('Matkhau123456', 'Matkhau12345')).toMatch(/không khớp/);
        expect(passwordProblem('Matkhau123456', 'Matkhau123456')).toBeNull();
    });

    it('uses Horizon with Redis and queue:work without it', () => {
        expect(workerCommands('/www/wwwroot/ecso', '/usr/bin/php', 'redis')[0].command).toContain('artisan horizon');
        const db = workerCommands('/www/wwwroot/ecso', '', 'database');
        expect(db[0].command).toMatch(/^php .*queue:work/);
        expect(db[1].name).toBe('ecso-scheduler');
    });
});
