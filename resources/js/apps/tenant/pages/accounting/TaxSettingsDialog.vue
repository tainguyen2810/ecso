<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Switch from '@/shared/ui/Switch.vue';
import { api, ApiError } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';
import type { Group, TaxSettings } from './accounting';

/**
 * "Thiết lập thuế": kỳ khai, ngưỡng doanh thu năm, nhóm ngành mặc định và theo danh mục, tỷ lệ thuế từng nhóm.
 * Mặc định theo Thông tư 40/2021; sửa ở đây khi quy định thay đổi.
 */
const props = defineProps<{ settings: TaxSettings }>();
const emit = defineEmits<{ saved: [TaxSettings] }>();
const open = defineModel<boolean>('open', { required: true });
const toast = useToast();

const form = ref<TaxSettings>(JSON.parse(JSON.stringify(props.settings)));
const categories = ref<{ id: string; name: string; parent?: { name: string } | null }[]>([]);
const saving = ref(false);
const errors = ref<ApiError | null>(null);

watch(open, async (o) => {
    if (!o) return;
    form.value = JSON.parse(JSON.stringify(props.settings));
    if (Array.isArray(form.value.category_groups)) form.value.category_groups = {};
    errors.value = null;
    if (!categories.value.length) categories.value = (await api.get('/categories').catch(() => null))?.data?.data ?? [];
});

const groupOptions = computed(() => (Object.keys(form.value.groups) as Group[]).map((g) => ({ value: g, label: form.value.groups[g] })));
const threshold = computed({
    get: () => String(form.value.threshold ?? ''),
    set: (v: string) => (form.value.threshold = Number(String(v).replace(/\D/g, '')) || 0),
});

function categoryGroup(id: string): string {
    return form.value.category_groups[id] ?? '';
}
function setCategoryGroup(id: string, g: unknown) {
    const v = String(g ?? '');
    if (v) form.value.category_groups[id] = v as Group;
    else delete form.value.category_groups[id];
}

async function save() {
    saving.value = true;
    errors.value = null;
    try {
        const { data } = await api.put('/accounting/settings', {
            period: form.value.period,
            threshold: form.value.threshold,
            taxpayer_name: form.value.taxpayer_name || null,
            default_group: form.value.default_group,
            include_shipping: form.value.include_shipping,
            category_groups: form.value.category_groups,
            rates: form.value.rates,
        });
        toast.success('Đã lưu thiết lập thuế.');
        emit('saved', data.data);
        open.value = false;
    } catch (e) {
        errors.value = e as ApiError;
        toast.error((e as ApiError).message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open" title="Thiết lập thuế" description="Hộ kinh doanh nộp thuế theo tỷ lệ % trên doanh thu. Tỷ lệ mặc định theo Thông tư 40/2021, sửa được khi quy định thay đổi." size="lg">
        <div class="space-y-5 text-sm">
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block font-medium">Người nộp thuế (chủ hộ)</span>
                    <Input v-model="form.taxpayer_name" placeholder="VD: Nguyễn Văn A" />
                </label>
                <label class="block">
                    <span class="mb-1 block font-medium">Kỳ khai thuế</span>
                    <Select v-model="form.period" :options="[{ value: 'quarter', label: 'Theo quý' }, { value: 'month', label: 'Theo tháng' }]" />
                </label>
                <label class="block">
                    <span class="mb-1 block font-medium">Ngưỡng doanh thu năm không phải nộp thuế (đ)</span>
                    <Input v-model="threshold" inputmode="numeric" />
                    <span class="mt-1 block text-xs text-muted-foreground">Dùng để báo trước khi doanh thu năm sắp chạm ngưỡng. Kiểm tra mức hiện hành với cơ quan thuế.</span>
                </label>
                <label class="block">
                    <span class="mb-1 block font-medium">Nhóm ngành mặc định</span>
                    <Select v-model="form.default_group" :options="groupOptions" />
                    <span class="mt-1 block text-xs text-muted-foreground">Áp cho sản phẩm không thuộc danh mục nào được chọn nhóm riêng bên dưới.</span>
                </label>
            </div>
            <Switch v-model="form.include_shipping" label="Tính phí giao hàng thu của khách vào doanh thu" hint="Bật nếu tiền ship khách trả là doanh thu của cửa hàng (cửa hàng tự giao hoặc tự trả phí cho hãng)." />

            <div>
                <div class="mb-2 font-medium">Tỷ lệ thuế theo nhóm ngành (%)</div>
                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left text-xs text-muted-foreground"><tr><th class="px-3 py-2">Nhóm ngành</th><th class="w-24 px-3 py-2">GTGT</th><th class="w-24 px-3 py-2">TNCN</th></tr></thead>
                        <tbody>
                            <tr v-for="g in groupOptions" :key="g.value" class="border-t">
                                <td class="px-3 py-2">{{ g.label }}</td>
                                <td class="px-3 py-2"><input v-model.number="form.rates[g.value as Group].vat" type="number" step="0.1" min="0" max="20" class="h-8 w-20 rounded border bg-card px-2 text-right" :aria-label="`GTGT ${g.label}`" /></td>
                                <td class="px-3 py-2"><input v-model.number="form.rates[g.value as Group].pit" type="number" step="0.1" min="0" max="20" class="h-8 w-20 rounded border bg-card px-2 text-right" :aria-label="`TNCN ${g.label}`" /></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="categories.length">
                <div class="mb-2 font-medium">Nhóm ngành theo danh mục sản phẩm</div>
                <div class="max-h-60 space-y-2 overflow-y-auto rounded-lg border p-3">
                    <div v-for="c in categories" :key="c.id" class="flex items-center gap-3">
                        <span class="min-w-0 flex-1 truncate">{{ c.parent ? `${c.parent.name} › ${c.name}` : c.name }}</span>
                        <Select :model-value="categoryGroup(c.id)" class="w-56" :options="[{ value: '', label: 'Theo nhóm mặc định' }, ...groupOptions]" @update:model-value="(v: unknown) => setCategoryGroup(c.id, v)" />
                    </div>
                </div>
                <p class="mt-1 text-xs text-muted-foreground">Danh mục con theo danh mục cha nếu không chọn riêng. VD: danh mục "Dịch vụ lắp đặt" chọn nhóm Dịch vụ.</p>
            </div>
            <p v-if="errors?.message" class="text-rose-700">{{ errors.message }}</p>
        </div>
        <template #footer>
            <Button variant="outline" @click="open = false">Huỷ</Button>
            <Button :loading="saving" data-testid="tax-settings-save" @click="save">Lưu thiết lập</Button>
        </template>
    </Dialog>
</template>
