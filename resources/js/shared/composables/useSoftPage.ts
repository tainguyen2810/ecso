import { provide } from 'vue';

/**
 * Puts a whole page in the Tailwind mockup style (28/11) without rewriting it: PageHeader shows the menu name and a big
 * title, HeaderLink turns blue, Card/Input/Select/Switch take the 14px-card, 44px-field look and Button the 44px,
 * 12px-corner one used in popups.
 */
export function useSoftPage(section: string): void {
    provide('soft-page', section);
    provide('soft-cards', true);
    provide('soft-dialog', true);
}
