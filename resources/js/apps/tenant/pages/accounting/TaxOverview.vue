<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { CalendarClock, FileCode2, FileText, Settings2, TriangleAlert } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatMoney } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import TaxSettingsDialog from './TaxSettingsDialog.vue';
import { dmy, periodLabel, thresholdProgress, type Overview } from './accounting';

/**
 * Kế toán, thuế › Tổng quan thuế: doanh thu năm của mọi kênh so với ngưỡng, phần tự khai (quầy, website, đơn tự tạo)
 * tách khỏi phần sàn đã khấu trừ, thuế tạm tính của kỳ đang chạy và việc cần làm.
 */
const tenant = useTenantStore();
const thisYear = new Date().getFullYear();
const year = ref(thisYear);
const data = ref<Overview | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const settingsOpen = ref(false);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        data.value = (await api.get('/accounting/overview', { params: { year: year.value } })).data.data;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

const progress = computed(() => (data.value ? thresholdProgress(data.value.revenue, data.value.threshold) : { pct: 0, left: 0 }));
const own = computed(() => data.value?.channels.filter((c) => c.kind === 'self') ?? []);
const market = computed(() => data.value?.channels.filter((c) => c.kind === 'market') ?? []);
const millions = (n: number) => (n >= 1e9 ? `${Math.round(n / 1e8) / 10} tỷ` : `${Math.round(n / 1e6)} triệu`);
</script>

<template>
    <PageHeader title="Tổng quan thuế">
        <template #description>
            <span v-if="data">{{ data.current.taxpayer?.store ?? 'Hộ kinh doanh' }} · khai thuế {{ data.settings.period === 'month' ? 'theo tháng' : 'theo quý' }} · tỷ lệ % trên doanh thu</span>
        </template>
        <select v-model.number="year" class="h-9 rounded-md border bg-card px-2 text-sm" aria-label="Năm" @change="load">
            <option v-for="y in [thisYear, thisYear - 1, thisYear - 2]" :key="y" :value="y">Năm {{ y }}</option>
        </select>
        <Button v-if="tenant.can('settings.manage') && data" variant="outline" data-testid="tax-settings" @click="settingsOpen = true"><Settings2 /> Thiết lập thuế</Button>
    </PageHeader>

    <div v-if="loading && !data" class="space-y-3"><Skeleton class="h-28" /><Skeleton class="h-64" /></div>
    <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
    <template v-else-if="data">
        <Card class="mb-4 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="text-sm text-muted-foreground">Doanh thu năm {{ data.year }} của mọi kênh{{ data.year === thisYear ? ' đến hôm nay' : '' }}</div>
                    <div class="mt-1 text-3xl font-bold tabular-nums">{{ formatMoney(data.revenue) }}</div>
                </div>
                <div class="text-sm sm:text-right">
                    <span v-if="progress.left > 0" class="inline-block rounded-full bg-amber-50 px-2.5 py-1 font-medium text-amber-800">Còn {{ formatMoney(progress.left) }} nữa chạm ngưỡng {{ millions(data.threshold) }}</span>
                    <span v-else class="inline-block rounded-full bg-rose-50 px-2.5 py-1 font-medium text-rose-700">Đã vượt ngưỡng {{ millions(data.threshold) }}</span>
                    <div v-if="data.reach_on" class="mt-2 text-muted-foreground">Theo đà bán hiện tại, dự kiến chạm ngưỡng khoảng {{ dmy(data.reach_on) }}</div>
                </div>
            </div>
            <div class="mt-4 h-3 overflow-hidden rounded-full bg-muted" role="progressbar" :aria-valuenow="progress.pct" aria-valuemin="0" aria-valuemax="100">
                <div class="h-full rounded-full bg-gradient-to-r from-blue-500 to-amber-500" :style="{ width: `${progress.pct}%` }" />
            </div>
            <div class="mt-1 flex justify-between text-xs text-muted-foreground"><span>0</span><span>{{ millions(data.threshold / 2) }}</span><span>{{ millions(data.threshold) }}</span></div>
        </Card>

        <div class="grid grid-cols-[minmax(0,1fr)] items-start gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
            <Card>
                <div class="border-b px-4 py-3 font-semibold">Doanh thu và thuế theo kênh · năm {{ data.year }}</div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="tax-channels">
                        <thead class="bg-muted/50 text-left text-xs text-muted-foreground">
                            <tr><th class="px-4 py-2">Kênh</th><th class="px-4 py-2 text-right">Doanh thu</th><th class="px-4 py-2">Ai nộp thuế</th><th class="px-4 py-2 text-right">Thuế GTGT + TNCN</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in [...own, ...market]" :key="c.label + c.kind" class="border-t">
                                <td class="px-4 py-3">{{ c.label }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ formatMoney(c.revenue) }}</td>
                                <td class="px-4 py-3">
                                    <span v-if="c.kind === 'self'" class="whitespace-nowrap rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700">Tự khai</span>
                                    <span v-else class="whitespace-nowrap rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Sàn đã khấu trừ</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums" :class="c.kind === 'market' && 'text-muted-foreground line-through'">{{ formatMoney(c.tax) }}</td>
                            </tr>
                            <tr v-if="!data.channels.length"><td colspan="4" class="px-4 py-8 text-center text-muted-foreground">Chưa có doanh thu trong năm {{ data.year }}.</td></tr>
                        </tbody>
                        <tfoot v-if="data.channels.length" class="border-t text-sm">
                            <tr class="font-medium"><td class="px-4 py-3">Phần mình tự khai, tự nộp</td><td class="px-4 py-3 text-right tabular-nums">{{ formatMoney(data.self) }}</td><td></td><td class="px-4 py-3 text-right tabular-nums text-primary">{{ formatMoney(data.tax) }}</td></tr>
                            <tr class="text-muted-foreground"><td class="px-4 py-2">Sàn đã khấu trừ và nộp thay</td><td class="px-4 py-2 text-right tabular-nums">{{ formatMoney(data.market) }}</td><td></td><td class="px-4 py-2 text-right tabular-nums">{{ formatMoney(data.withheld) }}</td></tr>
                        </tfoot>
                    </table>
                </div>
            </Card>

            <div class="space-y-4">
                <Card class="p-4">
                    <div class="mb-3 font-semibold">Việc cần làm</div>
                    <ul class="space-y-3 text-sm">
                        <li class="flex gap-2.5">
                            <CalendarClock class="mt-0.5 size-4 shrink-0 text-rose-600" />
                            <div>
                                <RouterLink :to="{ path: '/accounting/declaration', query: { period: data.todo.declare.period } }" class="font-medium hover:underline">Nộp tờ khai {{ periodLabel(data.todo.declare.period) }}</RouterLink>
                                <div class="text-muted-foreground">Hạn {{ dmy(data.todo.declare.deadline) }} · số liệu đã sẵn</div>
                            </div>
                        </li>
                        <li v-if="data.todo.invoices_open" class="flex gap-2.5">
                            <FileCode2 class="mt-0.5 size-4 shrink-0 text-blue-600" />
                            <div>
                                <RouterLink to="/purchasing/invoices" class="font-medium hover:underline">{{ data.todo.invoices_open }} hoá đơn mua vào chưa vào phiếu nhập</RouterLink>
                                <div v-if="data.todo.invoices_review" class="text-muted-foreground">{{ data.todo.invoices_review }} hoá đơn cần xem lại</div>
                            </div>
                        </li>
                        <li v-if="!data.settings.taxpayer_name || !data.current.taxpayer?.tax_code" class="flex gap-2.5">
                            <TriangleAlert class="mt-0.5 size-4 shrink-0 text-amber-600" />
                            <div class="text-muted-foreground">Điền tên chủ hộ (Thiết lập thuế) và MST (Cấu hình › Cửa hàng) để tờ khai có đủ thông tin.</div>
                        </li>
                    </ul>
                </Card>

                <Card class="p-4">
                    <div class="mb-3 font-semibold">Thuế tạm tính {{ periodLabel(data.current.period) }}</div>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-muted-foreground">Doanh thu tự khai</dt><dd class="tabular-nums">{{ formatMoney(data.current.self) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-muted-foreground">Thuế GTGT</dt><dd class="tabular-nums">{{ formatMoney(data.current.vat) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-muted-foreground">Thuế TNCN</dt><dd class="tabular-nums">{{ formatMoney(data.current.pit) }}</dd></div>
                        <div class="flex justify-between border-t pt-2 font-semibold"><dt>Phải nộp</dt><dd class="tabular-nums text-primary">{{ formatMoney(data.current.tax) }}</dd></div>
                    </dl>
                    <RouterLink :to="{ path: '/accounting/declaration', query: { period: data.current.period } }" class="mt-3 block">
                        <Button class="w-full"><FileText /> Xem tờ khai {{ periodLabel(data.current.period) }}</Button>
                    </RouterLink>
                </Card>
            </div>
        </div>

        <p class="mt-4 text-xs text-muted-foreground">
            Số liệu tạm tính để tham khảo, lấy từ đơn đã giao (kênh riêng) và đơn sàn đã hoàn tất, trừ hàng trả lại. Thuế sàn đã khấu trừ là ước tính theo tỷ lệ của nhóm ngành.
            Tỷ lệ và ngưỡng sửa trong Thiết lập thuế; đối chiếu với cơ quan thuế hoặc kế toán trước khi nộp.
        </p>

        <TaxSettingsDialog v-model:open="settingsOpen" :settings="data.settings" @saved="load" />
    </template>
</template>
