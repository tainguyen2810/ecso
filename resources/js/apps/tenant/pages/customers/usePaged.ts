import { ref } from 'vue';
import { api, ApiError } from '@/shared/lib/api';

/** A server-paged list for a customer tab, loaded the first time it is opened. */
export function usePaged<T>(url: () => string, perPage = 20) {
    const rows = ref<T[]>([]) as { value: T[] };
    const meta = ref({ current_page: 1, last_page: 1, total: 0 });
    const loaded = ref(false);
    const loading = ref(false);
    const error = ref<string | null>(null);

    async function load(page = 1) {
        loading.value = true;
        error.value = null;
        try {
            const { data } = await api.get(url(), { params: { page, per_page: perPage } });
            rows.value = data.data;
            meta.value = data.meta;
            loaded.value = true;
        } catch (e) {
            error.value = (e as ApiError).message;
        } finally {
            loading.value = false;
        }
    }
    const ensure = () => (loaded.value ? undefined : load());

    return { rows, meta, loaded, loading, error, load, ensure };
}
