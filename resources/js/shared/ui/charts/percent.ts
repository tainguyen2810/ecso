/**
 * Whole percents of each value that add up to exactly 100 (largest remainder method), so a legend never shows
 * 99% or 101%. All zero → all 0.
 */
export function wholePercents(values: number[]): number[] {
    const total = values.reduce((s, v) => s + v, 0);
    if (total <= 0) return values.map(() => 0);
    const exact = values.map((v) => (v / total) * 100);
    const floors = exact.map(Math.floor);
    let left = 100 - floors.reduce((s, v) => s + v, 0);
    const order = exact.map((v, i) => ({ i, rest: v - floors[i]! })).sort((a, b) => b.rest - a.rest);
    for (const { i } of order) {
        if (left <= 0) break;
        floors[i]!++;
        left--;
    }
    return floors;
}
