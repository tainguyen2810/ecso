import { ref } from 'vue';
import { api } from '@/shared/lib/api';
import { loadCarrierAccounts, type CarrierAccountData } from '../shipments/carriers';
import { loadEInvoiceSettings, type EInvoiceSettings } from '../connections/einvoice';
import { loadZalo, type ZaloSettings } from '../connections/zalo';

/** One outside service the shop connected (carrier, e-invoice, Zalo, marketplace shop) and how it is doing now. */
export interface ConnectedService {
    key: string;
    name: string;
    /** Logo of the service itself (public/images/...), null when we have none. */
    logo: string | null;
    ok: boolean;
    error: string | null;
    to: string;
}

const carrierLogos: Record<string, string> = { ghn: 'carriers/ghn.webp', viettelpost: 'carriers/viettelpost.webp', jt: 'carriers/jt.webp', ghtk: 'carriers/ghtk.webp', spx: 'carriers/spx.webp' };
export const einvoiceLogos: Record<string, string> = { misa: 'einvoice/misa.svg' };
const marketLogos: Record<string, string> = { shopee: 'marketplaces/shopee.webp', tiktok: 'marketplaces/tiktok.webp', lazada: 'marketplaces/lazada.webp', tiki: 'marketplaces/tiki.webp' };
const marketNames: Record<string, string> = { shopee: 'Shopee', tiktok: 'TikTok Shop', lazada: 'Lazada', tiki: 'Tiki' };

export const logoUrl = (path: string | null | undefined) => (path ? `/images/${path}` : null);
export const carrierLogo = (key: string) => logoUrl(carrierLogos[key]);

/**
 * Loads what the shop has connected, each source on its own (one failing or not allowed leaves the others):
 * carriers, e-invoice, Zalo ZNS (settings.manage) and marketplace shops (marketplace.view).
 */
export function useConnectedServices(can: (p: string) => boolean) {
    const services = ref<ConnectedService[]>([]);
    const loaded = ref(false);
    async function load() {
        const list: ConnectedService[] = [];
        const tasks: Promise<void>[] = [];
        if (can('settings.manage')) {
            tasks.push(
                loadCarrierAccounts()
                    .then((accs: CarrierAccountData[]) => {
                        for (const a of accs.filter((x) => x.status !== 'disconnected' && x.carrier !== 'fake')) {
                            list.push({ key: `carrier-${a.id}`, name: a.carrier_label, logo: carrierLogo(a.carrier), ok: a.status === 'connected', error: a.status === 'error' ? (a.last_error ?? 'Lỗi kết nối') : null, to: '/settings/connections' });
                        }
                    })
                    .catch(() => undefined),
                loadEInvoiceSettings()
                    .then((s: EInvoiceSettings) => {
                        const a = s.account;
                        if (a && a.status !== 'disconnected') list.push({ key: 'einvoice', name: a.provider_label, logo: logoUrl(einvoiceLogos[a.provider]), ok: a.status === 'connected', error: a.status === 'error' ? (a.last_error ?? 'Lỗi kết nối') : null, to: '/settings/connections' });
                    })
                    .catch(() => undefined),
                loadZalo()
                    .then((z: ZaloSettings) => {
                        const a = z.account;
                        if (a && a.status !== 'disconnected') list.push({ key: 'zalo', name: 'Zalo ZNS', logo: logoUrl('channels/zalo.webp'), ok: a.status === 'connected', error: a.status === 'connected' ? null : (a.last_error ?? 'Lỗi kết nối'), to: '/settings/connections/zalo' });
                    })
                    .catch(() => undefined),
            );
        }
        if (can('marketplace.view')) {
            tasks.push(
                api
                    .get('/marketplace-accounts')
                    .then(({ data }) => {
                        for (const a of data.data as { id: string; marketplace: string; shop_name: string | null; status: string; last_error?: string | null }[]) {
                            if (a.status === 'disconnected' || !marketNames[a.marketplace]) continue;
                            list.push({ key: `market-${a.id}`, name: `${marketNames[a.marketplace]}${a.shop_name ? ` · ${a.shop_name}` : ''}`, logo: logoUrl(marketLogos[a.marketplace]), ok: a.status === 'connected', error: a.status === 'connected' ? null : (a.last_error ?? 'Cần kết nối lại'), to: `/marketplaces?san=${a.marketplace}` });
                        }
                    })
                    .catch(() => undefined),
            );
        }
        await Promise.all(tasks);
        services.value = list;
        loaded.value = true;
    }
    return { services, loaded, load };
}
