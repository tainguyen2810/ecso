<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ChevronLeft } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import Switch from '@/shared/ui/Switch.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { featurePayload, featureRows, yearlyHint, type AdminPlan, type FeatureDef, type FeatureRow } from './plans';

/** A1: add or edit one plan: prices, trial, limits and what the plans list shows. */
const route = useRoute();
const router = useRouter();
const toast = useToast();
const key = computed(() => (route.params.key as string | undefined) ?? 'new');
const isNew = computed(() => key.value === 'new');

const plan = ref<AdminPlan | null>(null);
const rows = ref<FeatureRow[]>([]);
const form = ref({ key: '', name: '', description: '', badge: '', highlights: '', price_monthly: 0 as number | string, price_yearly: 0 as number | string, trial_days: 14 as number | string, is_public: true });
const errors = ref<Record<string, string | undefined>>({});
const loaded = ref(false);
const loadError = ref<string | null>(null);
const saving = ref(false);
const deleteOpen = ref(false);

async function load() {
    loadError.value = null;
    try {
        const { data } = await api.get('/admin/plans');
        const defs = data.features as FeatureDef[];
        const found = (data.data as AdminPlan[]).find((p) => p.key === key.value) ?? null;
        if (!isNew.value && !found) throw new ApiError(404, { message: 'Không tìm thấy gói.' });
        plan.value = found;
        rows.value = featureRows(defs, found?.features ?? {});
        if (found) {
            form.value = { key: found.key, name: found.name, description: found.description ?? '', badge: found.badge ?? '', highlights: found.highlights.join('\n'), price_monthly: found.price_monthly, price_yearly: found.price_yearly, trial_days: found.trial_days, is_public: found.is_public };
        }
        loaded.value = true;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    }
}
onMounted(load);

const hint = computed(() => yearlyHint(Number(form.value.price_monthly) || 0, Number(form.value.price_yearly) || 0));
const previewLines = computed(() => [
    ...rows.value
        .filter((r) => (r.type === 'boolean' ? r.enabled : r.unlimited || Number(r.limit) > 0))
        .slice(0, 4)
        .map((r) => (r.type === 'boolean' ? r.name : r.unlimited ? `${r.name}: không giới hạn` : `${r.name}: ${formatNumber(Number(r.limit))}`)),
    ...form.value.highlights.split('\n').map((l) => l.trim()).filter(Boolean),
]);

async function save() {
    saving.value = true;
    errors.value = {};
    const body = {
        ...(isNew.value ? { key: form.value.key.trim() } : {}),
        name: form.value.name,
        description: form.value.description,
        badge: form.value.badge,
        highlights: form.value.highlights.split('\n'),
        price_monthly: Number(form.value.price_monthly) || 0,
        price_yearly: Number(form.value.price_yearly) || 0,
        trial_days: Number(form.value.trial_days) || 0,
        is_public: form.value.is_public,
        features: featurePayload(rows.value),
    };
    try {
        if (isNew.value) await api.post('/admin/plans', body);
        else await api.put(`/admin/plans/${key.value}`, body);
        toast.success(`Đã lưu gói ${form.value.name}.`);
        router.push('/admin/plans');
    } catch (e) {
        const err = e as ApiError;
        errors.value = Object.fromEntries(Object.entries(err.body?.errors ?? {}).map(([k, v]) => [k, v[0]]));
        toast.error(err.message);
    } finally {
        saving.value = false;
    }
}

async function remove() {
    saving.value = true;
    try {
        await api.delete(`/admin/plans/${key.value}`);
        toast.success('Đã xóa gói.');
        router.push('/admin/plans');
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        saving.value = false;
        deleteOpen.value = false;
    }
}
</script>

<template>
    <RouterLink to="/admin/plans" class="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ChevronLeft class="size-4" /> Gói dịch vụ</RouterLink>
    <h1 class="mb-6 text-xl font-semibold">{{ isNew ? 'Thêm gói' : `Sửa gói ${plan?.name ?? ''}` }}</h1>

    <ErrorState v-if="loadError" :message="loadError" @retry="load" />
    <Skeleton v-else-if="!loaded" class="h-96" />
    <div v-else class="grid gap-5 lg:grid-cols-[1fr_360px]" data-testid="plan-form">
        <div class="space-y-5">
            <Card title="Thông tin gói">
                <div class="grid gap-4 p-5 sm:grid-cols-3">
                    <FormField label="Tên hiển thị" :error="errors.name"><Input v-model="form.name" data-testid="plan-name" /></FormField>
                    <FormField label="Mã gói" :error="errors.key" :hint="isNew ? 'Chữ thường không dấu, số, gạch ngang. Không đổi được sau khi tạo.' : 'Không đổi được sau khi tạo'">
                        <Input v-model="form.key" :disabled="!isNew" placeholder="vd: doi-tac-abc" data-testid="plan-key" />
                    </FormField>
                    <FormField label="Nhãn nổi bật" hint="vd: Phổ biến. Để trống nếu không cần." :error="errors.badge"><Input v-model="form.badge" /></FormField>
                    <FormField label="Mô tả ngắn" class="sm:col-span-3" :error="errors.description"><Input v-model="form.description" placeholder="Gói này hợp với ai" /></FormField>
                </div>
            </Card>

            <Card title="Giá">
                <div class="grid gap-4 p-5 sm:grid-cols-3">
                    <FormField label="Giá tháng (₫)" :error="errors.price_monthly"><Input v-model="form.price_monthly" type="number" min="0" data-testid="plan-price-monthly" /></FormField>
                    <FormField label="Giá năm (₫)" :hint="hint" :error="errors.price_yearly"><Input v-model="form.price_yearly" type="number" min="0" /></FormField>
                    <FormField label="Dùng thử (ngày)" hint="0 = không cho dùng thử" :error="errors.trial_days"><Input v-model="form.trial_days" type="number" min="0" max="365" /></FormField>
                </div>
            </Card>

            <Card title="Giới hạn và tính năng" description="Giới hạn 0 là tắt tính năng đó. Đổi ở đây áp dụng ngay cho mọi khách đang dùng gói.">
                <table class="w-full text-sm">
                    <tbody>
                        <tr v-for="r in rows" :key="r.key" class="border-t first:border-t-0">
                            <td class="px-5 py-2.5">{{ r.name }}</td>
                            <td class="px-5 py-2.5">
                                <template v-if="r.type === 'limit'">
                                    <div class="flex items-center gap-4">
                                        <Input v-model="r.limit" type="number" min="0" class="w-32" :disabled="r.unlimited" :data-testid="`plan-limit-${r.key}`" />
                                        <label class="flex items-center gap-2 text-muted-foreground"><input v-model="r.unlimited" type="checkbox" /> Không giới hạn</label>
                                    </div>
                                </template>
                                <Switch v-else v-model="r.enabled" :label="r.enabled ? 'Có' : 'Không'" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </Card>
        </div>

        <div class="space-y-4">
            <Card title="Hiển thị">
                <div class="p-5">
                    <Switch v-model="form.is_public" label="Công khai" hint="Hiện trong trang Gói dịch vụ của khách (khách tự chọn được) và trong bảng giá của trang WordPress. Tắt: chỉ gán tay cho khách." />
                    <FormField label="Dòng thêm trên bảng giá (mỗi dòng 1 ý)" class="mt-3" :error="errors.highlights">
                        <textarea v-model="form.highlights" rows="3" class="w-full rounded-md border bg-card px-3 py-2 text-sm" placeholder="Hỗ trợ qua Zalo trong giờ hành chính" />
                    </FormField>
                </div>
            </Card>
            <Card class="p-4">
                <p class="mb-2 text-xs font-semibold uppercase text-muted-foreground">Xem trước</p>
                <div :class="['relative rounded-xl border p-4', form.badge ? 'border-2 border-primary' : '']">
                    <span v-if="form.badge" class="absolute -top-2.5 left-4 rounded-full bg-primary px-2.5 py-0.5 text-[11px] font-bold text-white">{{ form.badge }}</span>
                    <p class="font-semibold">{{ form.name || 'Tên gói' }}</p>
                    <p v-if="form.description" class="text-xs text-muted-foreground">{{ form.description }}</p>
                    <p class="mt-2 text-2xl font-bold">{{ formatMoney(Number(form.price_monthly) || 0) }}<span class="text-xs font-normal text-muted-foreground">/tháng</span></p>
                    <ul class="mt-2 space-y-1 text-sm">
                        <li v-for="l in previewLines" :key="l" class="flex gap-2"><span class="text-success">✓</span>{{ l }}</li>
                    </ul>
                </div>
            </Card>
            <div class="flex flex-wrap justify-end gap-2">
                <Button v-if="plan?.deletable" variant="ghost" class="mr-auto text-destructive" @click="deleteOpen = true">Xóa gói</Button>
                <RouterLink to="/admin/plans"><Button variant="outline">Hủy</Button></RouterLink>
                <Button :loading="saving" data-testid="plan-save" @click="save">Lưu gói</Button>
            </div>
            <p v-if="plan && !plan.deletable && !plan.is_default" class="text-right text-xs text-muted-foreground">Gói đã có khách dùng hoặc có hóa đơn: chỉ ẩn được, không xóa được.</p>
        </div>
    </div>

    <Dialog v-model:open="deleteOpen" title="Xóa gói?">
        <p class="text-sm text-slate-700">Chưa có khách nào dùng gói {{ plan?.name }}. Xóa rồi không khôi phục được.</p>
        <template #footer>
            <Button variant="outline" @click="deleteOpen = false">Giữ lại</Button>
            <Button variant="destructive" :loading="saving" @click="remove">Xóa gói</Button>
        </template>
    </Dialog>
</template>
