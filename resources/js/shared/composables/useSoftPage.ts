import { provide } from 'vue';

/**
 * Puts a whole page in the Tailwind mockup style (28/11) without rewriting it: PageHeader shows the menu name and a big
 * title, HeaderLink turns blue and cards take the 14px-corner look. Fields and buttons are already soft app-wide
 * ('soft-fields', main.ts).
 */
export function useSoftPage(section: string): void {
    provide('soft-page', section);
    provide('soft-cards', true);
}
