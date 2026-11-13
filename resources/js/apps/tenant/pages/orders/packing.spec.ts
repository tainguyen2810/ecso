import { describe, expect, it } from "vitest";
import {
    matchScan,
    packProgress,
    pickListDocument,
    readPackSettings,
    shortLines,
    type PackLine,
} from "./packing";

const line = (
    id: string,
    sku: string,
    quantity: number,
    barcode: string | null = null,
): PackLine => ({
    id,
    sku,
    barcode,
    name: sku,
    variant_name: null,
    quantity,
    location: null,
    image: null,
    codes: [barcode?.toLowerCase(), sku.toLowerCase()].filter(
        Boolean,
    ) as string[],
});

describe("scan to pack", () => {
    const lines = [
        line("1", "SML-BE-M", 2, "8935001230011"),
        line("2", "POLO-NV-M", 1),
    ];

    it("matches a scan by barcode or SKU, ignoring case and spaces", () => {
        expect(matchScan(lines, {}, " 8935001230011 ")).toMatchObject({
            kind: "ok",
            line: { id: "1" },
        });
        expect(matchScan(lines, {}, "polo-nv-m")).toMatchObject({
            kind: "ok",
            line: { id: "2" },
        });
    });

    it("never counts a code that is not in the order or one more than ordered", () => {
        expect(matchScan(lines, {}, "NON-001").kind).toBe("wrong");
        expect(matchScan(lines, { "2": 1 }, "POLO-NV-M")).toMatchObject({
            kind: "extra",
            line: { id: "2" },
        });
    });

    it("fills a second line with the same code once the first is full", () => {
        const twice = [line("a", "X", 1), line("b", "X", 2)];
        expect(matchScan(twice, { a: 1 }, "x")).toMatchObject({
            kind: "ok",
            line: { id: "b" },
        });
    });

    it("reports progress and the lines still short", () => {
        expect(packProgress(lines, { "1": 2 })).toEqual({
            done: 2,
            total: 3,
            complete: false,
        });
        expect(packProgress(lines, { "1": 2, "2": 1 }).complete).toBe(true);
        expect(shortLines(lines, { "1": 1 })).toEqual([
            "SML-BE-M 1/2",
            "POLO-NV-M 0/1",
        ]);
    });

    it("reads saved options and falls back to the defaults", () => {
        expect(readPackSettings(() => '{"sound":false}')).toEqual({
            autoPrint: true,
            sound: false,
            allowShort: false,
        });
        expect(readPackSettings(() => "not json")).toEqual({
            autoPrint: true,
            sound: true,
            allowShort: false,
        });
    });
});

describe("pick list", () => {
    it("prints one row per SKU with its orders, totals in the header and signature lines", () => {
        const doc = pickListDocument(
            {
                orders: ["SO-1", "SO-2"],
                rows: [
                    {
                        sku: "A",
                        barcode: null,
                        name: "Áo",
                        location: "A-01",
                        quantity: 3,
                        orders: [
                            { code: "SO-1", quantity: 2 },
                            { code: "SO-2", quantity: 1 },
                        ],
                    },
                ],
            },
            {
                shop: "Cửa hàng",
                by: "Chủ",
                at: "2026-11-13T09:45:00Z",
                size: "A4",
            },
        );
        const text = JSON.stringify(doc);
        expect(doc.pageSize).toBe("A4");
        expect(text).toContain("2 đơn · 3 SP");
        expect(text).toContain("SO-1 (2), SO-2 (1)");
        expect(text).toContain("Người nhặt");
    });
});
