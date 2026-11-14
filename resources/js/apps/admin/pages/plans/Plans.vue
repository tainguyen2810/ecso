<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ArrowDown, ArrowUp, Plus } from 'lucide-vue-next';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { limitSummary, moved, type AdminPlan } from './plans';

/** A1: Admin → Gói dịch vụ: prices, trial and limits of every plan, set up after install. */
const toast = useToast();
const plans = ref<AdminPlan[] | null>(null);
const error = ref<string | null>(null);
const busy = ref(false);

async function load() {
    error.value = null;
    try {
        plans.value = (await api.get('/admin/plans')).data.data;
    } catch (e) {
        error.value = (e as ApiError).message;
    }
}
onMounted(load);

const keys = computed(() => (plans.value ?? []).map((p) => p.key));

async function move(key: string, by: -1 | 1) {
    const next = moved(keys.value, key, by);
    if (next === keys.value || busy.value) return;
    busy.value = true;
    try {
        await api.post('/admin/plans/reorder', { keys: next });
        plans.value = next.map((k) => plans.value!.find((p) => p.key === k)!);
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = false;
    }
}

async function togglePublic(plan: AdminPlan) {
    try {
        await api.put(`/admin/plans/${plan.key}`, { is_public: !plan.is_public });
        plan.is_public = !plan.is_public;
        toast.success(plan.is_public ? `Đã hiện gói ${plan.name}.` : `Đã ẩn gói ${plan.name}. Khách đang dùng vẫn giữ gói.`);
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}
</script>

<template>
    <div class="mb-6 flex flex-wrap items-start gap-3">
        <div class="min-w-0 flex-1">
            <h1 class="text-xl font-semibold">Gói dịch vụ</h1>
            <p class="mt-1 text-sm text-muted-foreground">Giá, thời gian dùng thử và giới hạn của từng gói. Đổi giá áp dụng từ hóa đơn tiếp theo, hóa đơn đã phát hành giữ nguyên số tiền.</p>
        </div>
        <RouterLink to="/admin/plans/new"><Button data-testid="plan-new"><Plus /> Thêm gói</Button></RouterLink>
    </div>

    <ErrorState v-if="error" :message="error" @retry="load" />
    <Skeleton v-else-if="!plans" class="h-64" />
    <template v-else>
        <Card data-testid="admin-plans">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase text-muted-foreground">
                        <tr>
                            <th class="w-16 px-3 py-2.5"></th>
                            <th class="px-4 py-2.5">Gói</th>
                            <th class="px-4 py-2.5 text-right">Giá tháng</th>
                            <th class="px-4 py-2.5 text-right">Giá năm</th>
                            <th class="px-4 py-2.5 text-right">Dùng thử</th>
                            <th class="px-4 py-2.5">Giới hạn chính</th>
                            <th class="px-4 py-2.5 text-right">Đang dùng</th>
                            <th class="px-4 py-2.5">Công khai</th>
                            <th class="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(p, i) in plans" :key="p.key" class="border-t" :data-testid="`plan-row-${p.key}`">
                            <td class="px-3 py-3">
                                <div class="flex gap-0.5">
                                    <button class="rounded p-1 text-muted-foreground hover:bg-muted disabled:opacity-30" :disabled="i === 0 || busy" :aria-label="`Đưa ${p.name} lên`" @click="move(p.key, -1)"><ArrowUp class="size-4" /></button>
                                    <button class="rounded p-1 text-muted-foreground hover:bg-muted disabled:opacity-30" :disabled="i === plans.length - 1 || busy" :aria-label="`Đưa ${p.name} xuống`" @click="move(p.key, 1)"><ArrowDown class="size-4" /></button>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-semibold">{{ p.name }}</span>
                                <Badge v-if="p.badge" tone="info" class="ml-1.5">{{ p.badge }}</Badge>
                                <Badge v-if="!p.is_public" tone="muted" class="ml-1.5">Ẩn</Badge>
                                <div class="text-xs text-muted-foreground">{{ p.key }}<template v-if="p.is_default"> · gói mặc định khi đăng ký</template></div>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ formatMoney(p.price_monthly) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ formatMoney(p.price_yearly) }}</td>
                            <td class="px-4 py-3 text-right">{{ p.trial_days ? `${p.trial_days} ngày` : '–' }}</td>
                            <td class="px-4 py-3">{{ limitSummary(p.features) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ formatNumber(p.tenants) }}</td>
                            <td class="px-4 py-3">
                                <button type="button" role="switch" :aria-checked="p.is_public" :aria-label="`Công khai gói ${p.name}`" :class="['relative h-5 w-9 rounded-full transition-colors', p.is_public ? 'bg-primary' : 'bg-slate-300']" @click="togglePublic(p)">
                                    <span :class="['absolute top-0.5 size-4 rounded-full bg-white shadow transition-all', p.is_public ? 'left-[18px]' : 'left-0.5']" />
                                </button>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <RouterLink :to="`/admin/plans/${p.key}`"><Button size="sm" variant="outline" :data-testid="`plan-edit-${p.key}`">Sửa</Button></RouterLink>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
        <p class="mt-3 text-xs leading-relaxed text-muted-foreground">
            Gói công khai hiện trong trang Gói dịch vụ của khách và trong API <code class="rounded bg-muted px-1">GET /api/v1/public/plans</code> để trang WordPress hiện bảng giá. Gói đang có khách dùng không xóa được, chỉ ẩn: khách cũ vẫn giữ gói, khách mới không chọn được.
        </p>
    </template>
</template>
