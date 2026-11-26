/**
 * Bán khi mất mạng: what the till keeps on the device so it can sell while the connection is down.
 *  - the catalog (every SKU on sale, price, stock of the counter's warehouse) for scanning and searching;
 *  - the counter setup (channels, warehouses) for a reload without network;
 *  - the sales made offline, sent one by one once the connection is back, each with its own key so a sale whose
 *    answer was lost is never booked twice.
 * Everything lives in localStorage under the business, and every read and write survives a full or blocked storage.
 */
import type { Variant } from '@/shared/types';
import type { Cart } from './pos';

export interface CatalogItem {
    id: string;
    sku: string;
    barcode: string | null;
    name: string | null;
    ref_price: string | number | null;
    wholesale_price: string | number | null;
    product: { id: string; name: string; image: string | null; type?: string };
    stock: { available: number };
}

export interface OfflineSale {
    /** Also the Idempotency-Key and the offline_key the server dedupes on. */
    key: string;
    /** Local receipt number shown and printed until the server gives the real one. */
    number: string;
    created_at: string;
    status: 'pending' | 'failed' | 'sent';
    error?: string | null;
    /** The server's order code once sent. */
    order_number?: string | null;
    summary: { items: number; total: number; customer: string | null; method: Cart['method'] };
    payload: Record<string, unknown>;
}

const read = <T>(key: string, fallback: T): T => {
    try {
        const raw = localStorage.getItem(key);
        return raw ? (JSON.parse(raw) as T) : fallback;
    } catch {
        return fallback;
    }
};
const write = (key: string, value: unknown): boolean => {
    try {
        localStorage.setItem(key, JSON.stringify(value));
        return true;
    } catch {
        return false;
    }
};

const catalogKey = (tenant: string, warehouse: string) => `ecso.pos.catalog.${tenant}.${warehouse}`;
const queueKey = (tenant: string) => `ecso.pos.offline.${tenant}`;
const setupKey = (tenant: string) => `ecso.pos.setup.${tenant}`;

export function saveCatalog(tenant: string, warehouse: string, items: CatalogItem[], at: string): boolean {
    return write(catalogKey(tenant, warehouse), { at, items });
}
export function loadCatalog(tenant: string, warehouse: string): { at: string; items: CatalogItem[] } | null {
    return read<{ at: string; items: CatalogItem[] } | null>(catalogKey(tenant, warehouse), null);
}

export function saveSetup(tenant: string, setup: { channels: unknown[]; warehouses: unknown[]; shift?: unknown }): void {
    write(setupKey(tenant), setup);
}
export function loadSetup<T>(tenant: string): T | null {
    return read<T | null>(setupKey(tenant), null);
}

export function loadQueue(tenant: string): OfflineSale[] {
    const q = read<OfflineSale[]>(queueKey(tenant), []);
    return Array.isArray(q) ? q : [];
}
export function saveQueue(tenant: string, queue: OfflineSale[]): boolean {
    return write(queueKey(tenant), queue);
}

/** Same matching as the server's SKU search: barcode exactly, SKU / name / product name containing the words. */
export function searchCatalog(items: CatalogItem[], term: string, limit = 30): Variant[] {
    const q = term.trim().toLowerCase();
    const fold = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd');
    const words = fold(q).split(/\s+/).filter(Boolean);
    const hits = !q
        ? items
        : items.filter((v) => {
              if (v.barcode && v.barcode.toLowerCase() === q) return true;
              const hay = fold(`${v.sku} ${v.product.name} ${v.name ?? ''}`.toLowerCase());
              return words.every((w) => hay.includes(w));
          });
    return hits.slice(0, limit).map(asVariant);
}

/** An exact scan: the barcode or SKU typed in full. */
export function findByCode(items: CatalogItem[], code: string): Variant | undefined {
    const q = code.trim().toLowerCase();
    const hit = items.find((v) => v.barcode?.toLowerCase() === q || v.sku.toLowerCase() === q);
    return hit ? asVariant(hit) : undefined;
}

export function asVariant(v: CatalogItem): Variant {
    return {
        id: v.id,
        sku: v.sku,
        barcode: v.barcode,
        name: v.name,
        ref_price: v.ref_price,
        wholesale_price: v.wholesale_price,
        product: { id: v.product.id, name: v.product.name, image: v.product.image },
        stock: { on_hand: v.stock.available, reserved: 0, available: v.stock.available },
    } as unknown as Variant;
}

/** Takes what an offline sale sold off the stock the device shows, until the next catalog download. */
export function takeStock(items: CatalogItem[], lines: { variant_id: string; quantity: number }[]): void {
    for (const l of lines) {
        const item = items.find((v) => v.id === l.variant_id);
        if (item) item.stock.available -= Number(l.quantity) || 0;
    }
}

/** "OFF-2111-003": day, month and a running number of the sales kept on this device today. */
export function offlineNumber(queue: OfflineSale[], now = new Date()): string {
    const day = `${String(now.getDate()).padStart(2, '0')}${String(now.getMonth() + 1).padStart(2, '0')}`;
    const prefix = `OFF-${day}-`;
    const n = queue.filter((s) => s.number.startsWith(prefix)).length + 1;
    return `${prefix}${String(n).padStart(3, '0')}`;
}

/** A connection problem (no answer at all), as opposed to the server refusing the sale. */
export const isNetworkError = (e: unknown): boolean => typeof e === 'object' && e !== null && (e as { status?: number }).status === 0;

/** Pending first, then failed; sent ones only kept for today's list. */
export const pendingCount = (queue: OfflineSale[]) => queue.filter((s) => s.status === 'pending').length;
export const failedCount = (queue: OfflineSale[]) => queue.filter((s) => s.status === 'failed').length;

/** Drops sent sales older than a day, so the list does not grow forever. */
export function pruneSent(queue: OfflineSale[], now = Date.now()): OfflineSale[] {
    return queue.filter((s) => s.status !== 'sent' || now - new Date(s.created_at).getTime() < 24 * 3600 * 1000);
}
