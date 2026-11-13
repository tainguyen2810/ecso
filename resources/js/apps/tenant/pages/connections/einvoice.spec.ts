import { describe, expect, it } from 'vitest';
import { canIssueAgain, currentInvoice, type EInvoiceData } from './einvoice';

const inv = (id: string, status: EInvoiceData['status']): EInvoiceData => ({
    id, status, reference_code: 'R', template_code: '1', invoice_series: 'C26TEZ', invoice_number: null, issued_at: null, pdf_url: null, buyer: null, total: '100000', message: null, created_at: null,
});

describe('e-invoice on the order', () => {
    it('shows the issued or pending invoice before failed tries', () => {
        expect(currentInvoice([inv('a', 'failed'), inv('b', 'issued')])?.id).toBe('b');
        expect(currentInvoice([inv('a', 'failed')])?.id).toBe('a');
        expect(currentInvoice([])).toBeNull();
    });
    it('issues again only while nothing is issued or pending', () => {
        expect(canIssueAgain([])).toBe(true);
        expect(canIssueAgain([inv('a', 'failed')])).toBe(true);
        expect(canIssueAgain([inv('a', 'failed'), inv('b', 'pending')])).toBe(false);
    });
});
