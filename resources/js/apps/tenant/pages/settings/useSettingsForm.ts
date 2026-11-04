import { computed, onMounted, ref } from 'vue';
import { ApiError } from '@/shared/lib/api';
import { useSettingsStore, type SettingsSection, type TenantSettingsData } from '@/shared/stores/settings';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';

/**
 * Editable copy of one settings section: `pick` takes the fields from the saved settings, Lưu sends them, Hủy puts the
 * saved values back. Dirty = the copy differs from what is saved.
 */
export function useSettingsForm<T extends object>(section: SettingsSection, pick: (s: TenantSettingsData) => T, toBody: (form: T) => unknown = (f) => f) {
    const store = useSettingsStore();
    const tenant = useTenantStore();
    const toast = useToast();
    const form = ref<T | null>(null);
    const saved = ref('');
    const saving = ref(false);
    const error = ref<ApiError | null>(null);
    const canSave = computed(() => tenant.can('settings.manage'));

    function fill(s: TenantSettingsData) {
        const value = pick(s);
        saved.value = JSON.stringify(value);
        form.value = JSON.parse(saved.value);
    }
    onMounted(async () => fill(await store.load()));

    const dirty = computed(() => form.value !== null && JSON.stringify(form.value) !== saved.value);

    async function save() {
        if (!form.value) return;
        saving.value = true;
        error.value = null;
        try {
            fill(await store.save(section, toBody(form.value as T)));
            if (section === 'store') await tenant.select(tenant.current!.id);
            toast.success('Đã lưu cấu hình.');
        } catch (e) {
            error.value = e as ApiError;
            if (!error.value.body?.errors) toast.error(error.value.message);
        } finally {
            saving.value = false;
        }
    }
    function cancel() {
        form.value = JSON.parse(saved.value);
        error.value = null;
    }

    /** Takes the saved values again (after another form on the page saved). */
    const refresh = () => store.data && fill(store.data);

    return { form, dirty, saving, error, canSave, save, cancel, refresh, store };
}
