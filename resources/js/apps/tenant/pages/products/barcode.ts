/**
 * Barcode encoding for printed labels, no dependency.
 *  - EAN-13 when the code is 13 digits with a valid check digit (the code already on retail packaging).
 *  - Code 128 otherwise: set C for an even run of digits (denser), set B for text (SKU).
 * Output is a list of bars in module units, drawn as SVG by `barcodeSvg`.
 */

// Code 128 bar/space widths for values 0..106 (106 = stop, 7 elements).
const C128 = [
    '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
    '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
    '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
    '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
    '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
    '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
    '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
    '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
    '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
    '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
    '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
];
const START_B = 104;
const START_C = 105;
const CODE_B = 100;
const CODE_C = 99;
const STOP = 106;

export interface Bar {
    x: number;
    width: number;
}

export interface Encoded {
    type: 'ean13' | 'code128';
    bars: Bar[];
    /** Total width in modules, quiet zones excluded. */
    modules: number;
}

/** Code 128 symbol values (start, data, check, stop) for printable ASCII. */
export function code128Values(text: string): number[] {
    if (!/^[\x20-\x7E]+$/.test(text)) throw new Error('Mã chỉ được chứa ký tự ASCII in được.');
    const values: number[] = [];
    let set: 'B' | 'C' | null = null;
    let i = 0;
    while (i < text.length) {
        const run = /^\d*/.exec(text.slice(i))![0].length;
        // Set C pays off for 4+ digits (or the whole remaining text when it is an even run of digits).
        const useC = run >= 4 || (run >= 2 && run === text.length - i && set !== 'B');
        if (useC) {
            const pairs = Math.floor(run / 2);
            if (set === null) values.push(START_C);
            else if (set !== 'C') values.push(CODE_C);
            set = 'C';
            for (let p = 0; p < pairs; p++, i += 2) values.push(Number(text.slice(i, i + 2)));
            continue;
        }
        if (set === null) values.push(START_B);
        else if (set !== 'B') values.push(CODE_B);
        set = 'B';
        values.push(text.charCodeAt(i) - 32);
        i++;
    }
    const check = values.reduce((sum, v, idx) => sum + v * (idx === 0 ? 1 : idx), 0) % 103;
    return [...values, check, STOP];
}

export function isEan13(code: string): boolean {
    if (!/^\d{13}$/.test(code)) return false;
    const digits = [...code].map(Number);
    const sum = digits.slice(0, 12).reduce((s, d, i) => s + d * (i % 2 ? 3 : 1), 0);
    return (10 - (sum % 10)) % 10 === digits[12];
}

const EAN_L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];
const EAN_R = EAN_L.map((p) => [...p].map((b) => (b === '1' ? '0' : '1')).join(''));
const EAN_G = EAN_R.map((p) => [...p].reverse().join(''));
const EAN_PARITY = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

/** EAN-13 as a 95-module string of 1 (bar) and 0 (space). */
export function ean13Pattern(code: string): string {
    if (!isEan13(code)) throw new Error('Mã EAN-13 không hợp lệ.');
    const d = [...code].map(Number);
    const parity = EAN_PARITY[d[0]!]!;
    let out = '101';
    for (let i = 1; i <= 6; i++) out += (parity[i - 1] === 'L' ? EAN_L : EAN_G)[d[i]!];
    out += '01010';
    for (let i = 7; i <= 12; i++) out += EAN_R[d[i]!];
    return out + '101';
}

function barsFromPattern(pattern: string): Bar[] {
    const bars: Bar[] = [];
    for (let i = 0; i < pattern.length; i++) {
        if (pattern[i] !== '1') continue;
        const last = bars[bars.length - 1];
        if (last && last.x + last.width === i) last.width++;
        else bars.push({ x: i, width: 1 });
    }
    return bars;
}

export function encodeBarcode(code: string): Encoded {
    if (isEan13(code)) {
        const pattern = ean13Pattern(code);
        return { type: 'ean13', bars: barsFromPattern(pattern), modules: pattern.length };
    }
    const bars: Bar[] = [];
    let x = 0;
    for (const value of code128Values(code)) {
        [...C128[value]!].forEach((w, idx) => {
            const width = Number(w);
            if (idx % 2 === 0) bars.push({ x, width });
            x += width;
        });
    }
    return { type: 'code128', bars, modules: x };
}

/** SVG of the bars only (text is laid out by the label), quiet zone of 10 modules on both sides. */
export function barcodeSvg(code: string, height = 40): string {
    const { bars, modules } = encodeBarcode(code);
    const quiet = 10;
    const width = modules + quiet * 2;
    const rects = bars.map((b) => `<rect x="${b.x + quiet}" y="0" width="${b.width}" height="${height}"/>`).join('');
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${width} ${height}" width="${width}" height="${height}" preserveAspectRatio="none" shape-rendering="crispEdges"><rect width="${width}" height="${height}" fill="#fff"/><g fill="#000">${rects}</g></svg>`;
}
