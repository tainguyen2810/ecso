import { inject } from "vue";

/**
 * The Tailwind form look (44px fields, 10px corners, blue focus ring), shared by every field so forms stay in step.
 * The tenant app turns it on for every page (main.ts); a dense screen (POS, line-item tables) can turn it off for its
 * own subtree with provide('soft-fields', false).
 */
export function useSoft(): boolean {
    const fields = inject<boolean | undefined>("soft-fields", undefined);
    return fields ?? inject<boolean>("soft-cards", false);
}

/** Box of a soft field: Input, Select, DateInput, Combobox, Textarea. */
export const SOFT_FIELD =
    "h-11 rounded-[10px] border-slate-200 bg-white text-[15px] shadow-none placeholder:text-slate-400 focus-visible:border-blue-500 focus-visible:ring-4 focus-visible:ring-blue-100 disabled:bg-slate-50";
/** Red frame of a soft field with an error. */
export const SOFT_INVALID =
    "border-red-300 ring-4 ring-red-50 focus-visible:border-red-400 focus-visible:ring-red-100";
