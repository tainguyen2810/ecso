<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { ArrowRight } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';
import { customerSources, customerTypes, genderLabels } from './labels';

/**
 * Merges the `sourceId` card into the `keepId` card. Shows what moves and, for each field where the cards differ,
 * lets the person pick which value stays (the kept card's by default).
 */
const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{ keepId: string; sourceId: string }>();
const emit = defineEmits<{ merged: [keepId: string] }>();
const toast = useToast();

interface Preview {
    keep: { id: string; code: string; name: string };
    merge: { id: string; code: string; name: string };
    moves: Record<string, number>;
    differences: { field: string; keep: unknown; merge: unknown }[];
}
const preview = ref<Preview | null>(null);
const error = ref<string | null>(null);
const take = ref<Record<string, 'keep' | 'merge'>>({});
const saving = ref(false);

watch(open, async (o) => {
    if (!o) return;
    preview.value = null;
    error.value = null;
    try {
        preview.value = (await api.get(`/customers/${props.keepId}/merge-preview`, { params: { source: props.sourceId } })).data.data;
        take.value = Object.fromEntries(preview.value!.differences.map((d) => [d.field, d.keep === null || d.keep === '' ? 'merge' : 'keep']));
    } catch (e) {
        error.value = (e as ApiError).message;
    }
});

const fieldLabels: Record<string, string> = {
    name: 'Tên', phone: 'Số điện thoại', email: 'Email', address: 'Địa chỉ', birthday: 'Ngày sinh', gender: 'Giới tính', tax_code: 'Mã số thuế',
    type: 'Loại khách', source: 'Nguồn', invoice_name: 'Tên xuất hóa đơn', invoice_address: 'Địa chỉ xuất hóa đơn', invoice_email: 'Email hóa đơn',
    customer_group_id: 'Nhóm', assigned_user_id: 'Phụ trách', payment_terms_days: 'Hạn thanh toán', credit_limit: 'Hạn mức nợ', note: 'Ghi chú',
};
const moveLabels: Record<string, string> = { orders: 'đơn hàng', payments: 'phiếu thu/hoàn', debt_entries: 'chứng từ công nợ', vouchers: 'phiếu sổ quỹ', addresses: 'địa chỉ giao', notes: 'ghi chú', identities: 'mã khách trên kênh' };
const moves = computed(() => Object.entries(preview.value?.moves ?? {}).filter(([, n]) => n > 0).map(([k, n]) => `${n} ${moveLabels[k] ?? k}`));
function show(field: string, value: unknown): string {
    if (value === null || value === undefined || value === '') return '—';
    if (field === 'gender') return genderLabels[String(value)] ?? String(value);
    if (field === 'type') return customerTypes[String(value)] ?? String(value);
    if (field === 'source') return customerSources[String(value)] ?? String(value);
    return String(value);
}

async function merge() {
    saving.value = true;
    try {
        const fields = Object.entries(take.value).filter(([, v]) => v === 'merge').map(([k]) => k);
        await api.post(`/customers/${props.keepId}/merge`, { source: props.sourceId, take: fields });
        toast.success(`Đã gộp ${preview.value!.merge.code} vào ${preview.value!.keep.code}.`);
        open.value = false;
        emit('merged', props.keepId);
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open" title="Gộp khách hàng" description="Mọi đơn, phiếu thu, công nợ, địa chỉ, ghi chú của khách bị gộp chuyển sang khách được giữ. Thẻ khách bị gộp được lưu lại trong lịch sử gộp." size="lg">
        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
        <Skeleton v-else-if="!preview" class="h-40" />
        <div v-else class="space-y-4 text-sm" data-testid="merge-preview">
            <div class="flex items-center gap-3 rounded-md bg-muted/50 px-3 py-2">
                <div><div class="text-xs text-muted-foreground">Gộp khách</div><div class="font-medium">{{ preview.merge.name }} <span class="font-mono text-xs text-muted-foreground">{{ preview.merge.code }}</span></div></div>
                <ArrowRight class="size-4 text-muted-foreground" />
                <div><div class="text-xs text-muted-foreground">vào khách</div><div class="font-medium">{{ preview.keep.name }} <span class="font-mono text-xs text-muted-foreground">{{ preview.keep.code }}</span></div></div>
            </div>
            <p>Sẽ chuyển: {{ moves.length ? moves.join(', ') : 'không có chứng từ nào' }}.</p>
            <div v-if="preview.differences.length">
                <p class="mb-2 font-medium">Thông tin khác nhau, chọn giá trị giữ lại</p>
                <table class="w-full text-sm">
                    <thead class="border-b text-left text-[11px] uppercase tracking-wide text-muted-foreground"><tr><th class="py-1.5 pr-2 font-medium">Trường</th><th class="px-2 py-1.5 font-medium">Khách được giữ</th><th class="px-2 py-1.5 font-medium">Khách bị gộp</th></tr></thead>
                    <tbody class="divide-y">
                        <tr v-for="d in preview.differences" :key="d.field">
                            <td class="py-2 pr-2 text-muted-foreground">{{ fieldLabels[d.field] ?? d.field }}</td>
                            <td class="px-2 py-2"><label class="flex items-start gap-2"><input v-model="take[d.field]" type="radio" value="keep" class="mt-0.5" :name="`m-${d.field}`" />{{ show(d.field, d.keep) }}</label></td>
                            <td class="px-2 py-2"><label class="flex items-start gap-2"><input v-model="take[d.field]" type="radio" value="merge" class="mt-0.5" :name="`m-${d.field}`" :data-testid="`take-${d.field}`" />{{ show(d.field, d.merge) }}</label></td>
                        </tr>
                    </tbody>
                </table>
                <p class="mt-2 text-xs text-muted-foreground">Số điện thoại, địa chỉ không được chọn vẫn được giữ làm địa chỉ giao hàng. Tags của hai khách được cộng lại.</p>
            </div>
        </div>
        <template #footer>
            <Button variant="outline" @click="open = false">Hủy</Button>
            <Button :disabled="!preview" :loading="saving" variant="destructive" data-testid="merge-confirm" @click="merge">Gộp khách</Button>
        </template>
    </Dialog>
</template>
