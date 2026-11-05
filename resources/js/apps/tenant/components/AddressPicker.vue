<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { AlertTriangle, Sparkles } from 'lucide-vue-next';
import Combobox, { type ComboOption } from './Combobox.vue';
import Button from '@/shared/ui/Button.vue';
import Input from '@/shared/ui/Input.vue';
import { composeAddress, loadProvinces, loadWards, suggestAddress, type AddressParts, type AddressSuggestion, type Province, type Ward } from '@/shared/lib/geo';
import { cn } from '@/shared/lib/utils';

/**
 * Standard Vietnamese address (units since 1 July 2025): Tỉnh/Thành phố → Phường/Xã → số nhà, đường.
 * - "Gõ nhanh": paste a whole line, the server splits it (also from old ward/district names).
 * - `legacy`: an old free-text address (not standardised yet) is shown with a suggestion to convert it.
 * Emits `update:text` with the full text address composed like the server does.
 */
const model = defineModel<AddressParts>({ required: true });
const props = withDefaults(
    defineProps<{ idPrefix?: string; required?: boolean; quick?: boolean; legacy?: string | null; errors?: Partial<Record<'street' | 'province_code' | 'ward_code', string | undefined>>; streetLabel?: string; columns?: 1 | 2 }>(),
    { idPrefix: 'addr', required: false, quick: true, legacy: null, errors: () => ({}), streetLabel: 'Địa chỉ cụ thể', columns: 2 },
);
const emit = defineEmits<{ 'update:text': [text: string] }>();

const provinces = ref<Province[]>([]);
const wards = ref<Ward[]>([]);
const loadError = ref(false);
const quickText = ref('');
const reading = ref(false);
const suggestion = ref<AddressSuggestion | null>(null);
const suggestFor = ref<string | null>(null);
const quickMiss = ref(false);

const provinceOptions = computed<ComboOption[]>(() => provinces.value.map((p) => ({ value: p.code, label: p.name, hint: p.former.length ? p.former.join(', ') : null })));
const wardOptions = computed<ComboOption[]>(() => wards.value.map((w) => ({ value: w.code, label: w.name, hint: w.former_hint, search: w.search })));
const provinceName = computed(() => provinces.value.find((p) => p.code === model.value.province_code)?.name ?? null);
const wardName = computed(() => wards.value.find((w) => w.code === model.value.ward_code)?.name ?? null);
const text = computed(() => composeAddress(model.value.street, wardName.value, provinceName.value));
const mark = computed(() => (props.required ? ' *' : ''));

onMounted(async () => {
    try {
        provinces.value = await loadProvinces();
        if (model.value.province_code) wards.value = await loadWards(model.value.province_code);
    } catch {
        loadError.value = true;
    }
});

watch(
    () => model.value.province_code,
    async (code, old) => {
        if (code === old) return;
        wards.value = [];
        if (!code) return;
        try {
            wards.value = await loadWards(code);
        } catch {
            loadError.value = true;
        }
        // A ward of another province no longer fits.
        if (model.value.ward_code && !wards.value.some((w) => w.code === model.value.ward_code)) model.value = { ...model.value, ward_code: '' };
    },
);
watch(text, (t) => emit('update:text', t));

// An old free-text address: offer its standard reading.
watch(
    () => props.legacy,
    async (legacy) => {
        suggestion.value = null;
        suggestFor.value = null;
        if (!legacy || model.value.ward_code) return;
        try {
            const s = await suggestAddress(legacy);
            if (props.legacy === legacy) [suggestion.value, suggestFor.value] = [s, legacy];
        } catch {
            /* suggestion is optional */
        }
    },
    { immediate: true },
);

function set(patch: Partial<AddressParts>) {
    model.value = { ...model.value, ...patch };
}
function apply(s: AddressSuggestion) {
    set({ province_code: s.province_code, ward_code: s.ward_code ?? '', street: s.street ?? model.value.street });
}
async function readQuick() {
    const t = quickText.value.trim();
    if (!t) return;
    reading.value = true;
    quickMiss.value = false;
    try {
        const s = await suggestAddress(t);
        if (s) {
            apply(s);
            quickText.value = '';
            quickMiss.value = !s.ward_code;
        } else quickMiss.value = true;
    } finally {
        reading.value = false;
    }
}
function onPaste() {
    setTimeout(readQuick, 0);
}
</script>

<template>
    <div class="space-y-3" data-testid="address-picker">
        <div v-if="legacy && !model.ward_code" class="rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900" data-testid="address-legacy">
            <p class="flex items-center gap-1.5 font-medium"><AlertTriangle class="size-3.5" /> Địa chỉ chưa chuẩn hóa, chưa đẩy được qua hãng vận chuyển</p>
            <p class="mt-1 text-amber-800">"{{ legacy }}"</p>
            <template v-if="suggestion && suggestFor === legacy">
                <p class="mt-2">
                    Gợi ý: <b>{{ suggestion.address }}</b>
                    <span v-if="!suggestion.ward_code"> (chưa nhận ra phường/xã, chọn thêm bên dưới)</span>
                </p>
                <Button size="sm" class="mt-2" data-testid="address-use-suggestion" @click="apply(suggestion)">Dùng gợi ý</Button>
            </template>
        </div>

        <div v-if="quick" class="space-y-1.5">
            <label :for="`${idPrefix}-quick`" class="text-sm font-medium leading-none">Gõ nhanh địa chỉ <span class="font-normal text-muted-foreground">(dán cả dòng, hệ thống tự tách)</span></label>
            <div class="flex gap-2">
                <Input :id="`${idPrefix}-quick`" v-model="quickText" class="border-dashed" placeholder="VD: 12 Nguyễn Huệ, P. Bến Nghé, Q.1, TP.HCM" data-testid="address-quick" @paste="onPaste" @keydown.enter.prevent="readQuick" />
                <Button variant="outline" :disabled="!quickText.trim() || reading" data-testid="address-quick-read" @click="readQuick"><Sparkles /> Tách</Button>
            </div>
            <p v-if="quickMiss" class="text-xs text-amber-700">Chưa nhận ra đủ tỉnh/phường, chọn tiếp bên dưới.</p>
        </div>

        <div :class="cn('grid gap-3', columns === 2 && 'sm:grid-cols-2')">
            <div class="space-y-1.5">
                <label :for="`${idPrefix}-province`" class="text-sm font-medium leading-none">Tỉnh / Thành phố{{ mark }}</label>
                <Combobox
                    :id="`${idPrefix}-province`"
                    :model-value="model.province_code"
                    :options="provinceOptions"
                    placeholder="Chọn tỉnh/thành"
                    :invalid="!!errors.province_code"
                    data-testid="address-province"
                    @update:model-value="(v: string) => set({ province_code: v })"
                />
                <p v-if="errors.province_code" class="text-xs text-destructive">{{ errors.province_code }}</p>
            </div>
            <div class="space-y-1.5">
                <label :for="`${idPrefix}-ward`" class="text-sm font-medium leading-none">Phường / Xã{{ mark }}</label>
                <Combobox
                    :id="`${idPrefix}-ward`"
                    :model-value="model.ward_code"
                    :options="wardOptions"
                    :disabled="!model.province_code"
                    :placeholder="model.province_code ? 'Gõ tên phường/xã, cả tên cũ' : 'Chọn tỉnh/thành trước'"
                    :invalid="!!errors.ward_code"
                    data-testid="address-ward"
                    @update:model-value="(v: string) => set({ ward_code: v })"
                />
                <p v-if="errors.ward_code" class="text-xs text-destructive">{{ errors.ward_code }}</p>
            </div>
        </div>
        <div class="space-y-1.5">
            <label :for="`${idPrefix}-street`" class="text-sm font-medium leading-none">{{ streetLabel }}{{ mark }} <span class="font-normal text-muted-foreground">(số nhà, ngõ, tên đường, tòa nhà)</span></label>
            <Input :id="`${idPrefix}-street`" :model-value="model.street" :invalid="!!errors.street" maxlength="255" data-testid="address-street" @update:model-value="(v) => set({ street: String(v ?? '') })" />
            <p v-if="errors.street" class="text-xs text-destructive">{{ errors.street }}</p>
        </div>
        <p v-if="loadError" class="text-xs text-destructive">Không tải được danh mục tỉnh/phường. Tải lại trang để thử lại.</p>
    </div>
</template>
