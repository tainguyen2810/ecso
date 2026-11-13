import { formatDateTime } from "@/shared/lib/utils";

/**
 * Scan-to-pack (C1). The screen keeps a count per order line; a scan matches a line by its barcode or SKU (case and
 * spaces ignored). A scan of something not in the order, or one more than ordered, is never counted.
 */
export interface PackLine {
    id: string;
    sku: string | null;
    barcode: string | null;
    name: string;
    variant_name: string | null;
    quantity: number;
    location: string | null;
    image: string | null;
    codes: string[];
}

export interface PackOrder {
    id: string;
    external_order_id: string;
    marketplace: string;
    shop: string | null;
    buyer_name: string | null;
    carrier: string | null;
    tracking_number: string | null;
    note: string | null;
    is_cod: boolean;
    total: string;
    lines: PackLine[];
}

export type Counts = Record<string, number>;
export type ScanResult =
    | { kind: "ok"; line: PackLine }
    | { kind: "extra"; line: PackLine }
    | { kind: "wrong" };

export function normalizeCode(code: string): string {
    return code.trim().toLowerCase();
}

/** Which line a scanned code belongs to: the first matching line that still needs items. */
export function matchScan(
    lines: PackLine[],
    counts: Counts,
    code: string,
): ScanResult {
    const c = normalizeCode(code);
    const matches = lines.filter((l) => l.codes.includes(c));
    if (!matches.length) return { kind: "wrong" };
    const open = matches.find((l) => (counts[l.id] ?? 0) < l.quantity);

    return open
        ? { kind: "ok", line: open }
        : { kind: "extra", line: matches[0]! };
}

export function packProgress(
    lines: PackLine[],
    counts: Counts,
): { done: number; total: number; complete: boolean } {
    const total = lines.reduce((s, l) => s + l.quantity, 0);
    const done = lines.reduce(
        (s, l) => s + Math.min(counts[l.id] ?? 0, l.quantity),
        0,
    );

    return { done, total, complete: total > 0 && done >= total };
}

/** Lines still short, e.g. "POLO-NV-M 3/5". */
export function shortLines(lines: PackLine[], counts: Counts): string[] {
    return lines
        .filter((l) => (counts[l.id] ?? 0) < l.quantity)
        .map((l) => `${l.sku ?? l.name} ${counts[l.id] ?? 0}/${l.quantity}`);
}

export interface PackSettings {
    autoPrint: boolean;
    sound: boolean;
    allowShort: boolean;
}

const settingsKey = "ecso.pack.settings";
export const defaultPackSettings: PackSettings = {
    autoPrint: true,
    sound: true,
    allowShort: false,
};

export function readPackSettings(
    read: () => string | null = () => localStorage.getItem(settingsKey),
): PackSettings {
    try {
        const raw = JSON.parse(read() ?? "{}") as Partial<PackSettings>;
        return {
            autoPrint:
                typeof raw.autoPrint === "boolean"
                    ? raw.autoPrint
                    : defaultPackSettings.autoPrint,
            sound:
                typeof raw.sound === "boolean"
                    ? raw.sound
                    : defaultPackSettings.sound,
            allowShort:
                typeof raw.allowShort === "boolean"
                    ? raw.allowShort
                    : defaultPackSettings.allowShort,
        };
    } catch {
        return { ...defaultPackSettings };
    }
}

export function savePackSettings(s: PackSettings): void {
    try {
        localStorage.setItem(settingsKey, JSON.stringify(s));
    } catch {
        // Private mode: back to the defaults next visit.
    }
}

let audio: AudioContext | null = null;
/** "tít" for a good scan, "bíp bíp" (two low tones) for a wrong one. */
export function beep(ok: boolean): void {
    try {
        audio ??= new AudioContext();
        const tones = ok
            ? [[1400, 0, 0.09]]
            : [
                  [320, 0, 0.14],
                  [320, 0.2, 0.14],
              ];
        for (const [freq, at, len] of tones as [number, number, number][]) {
            const osc = audio.createOscillator();
            const gain = audio.createGain();
            osc.type = ok ? "sine" : "square";
            osc.frequency.value = freq;
            gain.gain.value = 0.15;
            osc.connect(gain).connect(audio.destination);
            const start = audio.currentTime + at;
            osc.start(start);
            osc.stop(start + len);
        }
    } catch {
        // No audio (old browser, blocked autoplay): the colours still tell.
    }
}

export interface PickRow {
    sku: string | null;
    barcode: string | null;
    name: string;
    location: string | null;
    quantity: number;
    orders: { code: string; quantity: number }[];
}

export interface PickListData {
    orders: string[];
    rows: PickRow[];
}

/** pdfmake document for the pick list: one row per SKU with a tick box, sorted by shelf location (from the API). */
export function pickListDocument(
    data: PickListData,
    meta: { shop: string; by: string; at: string; size: "A4" | "A5" },
): Record<string, unknown> {
    const qty = data.rows.reduce((s, r) => s + r.quantity, 0);
    return {
        pageSize: meta.size,
        pageMargins: [24, 24, 24, 24],
        defaultStyle: {
            font: "Roboto",
            fontSize: meta.size === "A4" ? 10 : 8.5,
        },
        styles: {
            title: { fontSize: 14, bold: true },
            muted: { color: "#666666", fontSize: 8 },
            head: { bold: true, fillColor: "#f1f5f9" },
        },
        content: [
            {
                columns: [
                    {
                        stack: [
                            { text: "PHIẾU NHẶT HÀNG", style: "title" },
                            {
                                text: `${meta.shop} · In lúc ${formatDateTime(meta.at)} · Người in: ${meta.by}`,
                                style: "muted",
                                margin: [0, 2, 0, 0],
                            },
                        ],
                    },
                    {
                        text: `${data.orders.length} đơn · ${qty} SP`,
                        bold: true,
                        fontSize: 13,
                        alignment: "right",
                        width: "auto",
                    },
                ],
            },
            {
                margin: [0, 10, 0, 0],
                table: {
                    headerRows: 1,
                    widths: [14, 48, 70, "*", 26, "*"],
                    body: [
                        [
                            "",
                            "Vị trí",
                            "SKU",
                            "Sản phẩm",
                            { text: "SL", alignment: "right" },
                            "Thuộc đơn",
                        ].map((t) =>
                            typeof t === "string"
                                ? { text: t, style: "head" }
                                : { ...t, style: "head" },
                        ),
                        ...data.rows.map((r) => [
                            {
                                canvas: [
                                    {
                                        type: "rect",
                                        x: 0,
                                        y: 1,
                                        w: 9,
                                        h: 9,
                                        lineWidth: 0.8,
                                    },
                                ],
                            },
                            r.location ?? "—",
                            r.sku ?? "—",
                            r.name,
                            {
                                text: String(r.quantity),
                                bold: true,
                                alignment: "right",
                            },
                            {
                                text: r.orders
                                    .map((o) => `${o.code} (${o.quantity})`)
                                    .join(", "),
                                color: "#475569",
                            },
                        ]),
                    ],
                },
                layout: "lightHorizontalLines",
            },
            {
                margin: [0, 24, 0, 0],
                columns: [
                    {
                        text: "Người nhặt: ..............................",
                        style: "muted",
                    },
                    {
                        text: "Người kiểm: ..............................",
                        style: "muted",
                    },
                ],
            },
        ],
        info: { title: `Phieu-nhat-hang-${data.orders.length}-don` },
    };
}

export async function downloadPickList(
    data: PickListData,
    meta: { shop: string; by: string; size: "A4" | "A5" },
): Promise<void> {
    const [{ default: pdfMake }, { default: vfs }] = await Promise.all([
        import("pdfmake/build/pdfmake"),
        import("pdfmake/build/vfs_fonts"),
    ]);
    pdfMake.addVirtualFileSystem(vfs);
    const now = new Date();
    const stamp = now.toISOString().slice(0, 16).replace(/[-:T]/g, "");
    await pdfMake
        .createPdf(pickListDocument(data, { ...meta, at: now.toISOString() }))
        .download(`phieu-nhat-hang-${stamp}.pdf`);
}
