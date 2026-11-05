import { api } from './api';

/** Vietnam's administrative units since 1 July 2025: 34 provinces, wards directly under them (no district). */
export interface Province {
    code: string;
    name: string;
    short_name: string;
    former: string[];
}
export interface Ward {
    code: string;
    name: string;
    /** Pre-2025 wards it absorbed, e.g. "P.5, P.11 (Q. Bình Thạnh)": shown to help, never stored. */
    former_hint: string | null;
    /** Name and former names without marks, for matching what is typed. */
    search: string;
}
/** Standard parts of an address; the full text is composed from them. */
export interface AddressParts {
    street: string;
    province_code: string;
    ward_code: string;
}
export interface AddressSuggestion {
    street: string | null;
    province_code: string;
    province_name: string;
    ward_code: string | null;
    ward_name: string | null;
    address: string | null;
    via_former: boolean;
}

/** Carriers the shop ships with: an order handed to one needs province, ward, street, recipient and phone. */
export const CARRIERS = ['GHN', 'Viettel Post', 'SPX Express', 'J&T Express'] as const;

export const emptyAddress = (): AddressParts => ({ street: '', province_code: '', ward_code: '' });

/** Lower case without Vietnamese marks: "Phường Bình Thạnh" -> "phuong binh thanh". Same as the server's fold(). */
export function fold(text: string): string {
    return text
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[đĐ]/g, 'd')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();
}

/** Whether every word typed is found (in order of appearance not required): "binh thanh" finds "Phường Bình Thạnh". */
export function matches(search: string, query: string): boolean {
    const q = fold(query);
    if (!q) return true;
    const hay = ` ${search} `;
    return q.split(' ').every((w) => hay.includes(` ${w}`));
}

/** Text address from the parts, the way the server composes it. */
export function composeAddress(street: string | null | undefined, ward: string | null | undefined, province: string | null | undefined): string {
    return [street?.trim(), ward, province].filter((p) => p && p.length > 0).join(', ');
}

let provincesPromise: Promise<Province[]> | null = null;
const wardPromises = new Map<string, Promise<Ward[]>>();

export function loadProvinces(): Promise<Province[]> {
    provincesPromise ??= api.get('/geo/provinces').then(({ data }) => data.data as Province[]).catch((e) => {
        provincesPromise = null;
        throw e;
    });
    return provincesPromise;
}

export function loadWards(province: string): Promise<Ward[]> {
    let p = wardPromises.get(province);
    if (!p) {
        p = api.get(`/geo/provinces/${province}/wards`).then(({ data }) => data.data as Ward[]).catch((e) => {
            wardPromises.delete(province);
            throw e;
        });
        wardPromises.set(province, p);
    }
    return p;
}

/** Reads a typed or pasted one-line address into standard parts (server side, also from pre-2025 names). */
export async function suggestAddress(text: string): Promise<AddressSuggestion | null> {
    const { data } = await api.get('/geo/suggest', { params: { q: text } });
    return data.data as AddressSuggestion | null;
}

/** Full province/ward names of the parts, for showing a saved address. */
export async function describe(parts: Pick<AddressParts, 'province_code' | 'ward_code'>): Promise<{ province: string | null; ward: string | null }> {
    if (!parts.province_code) return { province: null, ward: null };
    const [provinces, wards] = await Promise.all([loadProvinces(), loadWards(parts.province_code)]);
    return { province: provinces.find((p) => p.code === parts.province_code)?.name ?? null, ward: wards.find((w) => w.code === parts.ward_code)?.name ?? null };
}
