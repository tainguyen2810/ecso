<script setup lang="ts">
import { ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ChevronLeft, ChevronRight, FileSpreadsheet, Info } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError, downloadFile } from '@/shared/lib/api';
import { formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { dmy, periodLabel, shiftPeriod, type Declaration } from './accounting';

/**
 * Kế toán, thuế › Tờ khai thuế: số liệu tờ khai của hộ kinh doanh nộp thuế theo tỷ lệ % trên doanh thu (mẫu 01/CNKD),
 * theo nhóm ngành, chỉ phần tự khai. Doanh thu sàn đã khấu trừ hiện riêng để đối chiếu, không đưa vào tờ khai.
 */
const route = useRoute();
const router = useRouter();
const toast = useToast();
const period = ref<string | null>(typeof route.query.period === 'string' && /^\d{4}-(Q[1-4]|\d{2})$/.test(route.query.period) ? route.query.period : null);
const data = ref<Declaration | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const exporting = ref(false);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        data.value = (await api.get('/accounting/declaration', { params: period.value ? { period: period.value } : {} })).data.data;
        if (!period.value && data.value) period.value = data.value.period;
    } catch (e) {
        loadError.value = (e as ApiError).message;
        data.value = null;
    } finally {
        loading.value = false;
    }
}
watch(period, (p, old) => {
    if (p && old !== undefined) router.replace({ query: { period: p } });
    if (!data.value || data.value.period !== p) load();
}, { immediate: true });

function go(by: number) {
    if (data.value) period.value = shiftPeriod(data.value.period, by);
}

async function exportFile() {
    if (!data.value) return;
    exporting.value = true;
    try {
        await downloadFile(`/accounting/declaration?period=${data.value.period}&export=1`, `to-khai-${data.value.period}.xlsx`);
    } catch (e) {
        toast.error((e as ApiError).message || 'Chưa xuất được tờ khai.');
    } finally {
        exporting.value = false;
    }
}

const pct = (n: number) => `${String(n).replace('.', ',')}%`;
</script>

<template>
    <PageHeader title="Tờ khai thuế" description="Số liệu đã điền sẵn từ đơn hàng. Anh/chị đối chiếu rồi nhập vào cổng thuế hoặc gửi kế toán.">
        <div class="flex items-center rounded-md border bg-card">
            <button type="button" class="grid size-9 place-items-center hover:bg-muted" aria-label="Kỳ trước" @click="go(-1)"><ChevronLeft class="size-4" /></button>
            <span class="min-w-28 px-2 text-center text-sm font-medium">{{ data ? periodLabel(data.period) : '…' }}</span>
            <button type="button" class="grid size-9 place-items-center hover:bg-muted" aria-label="Kỳ sau" @click="go(1)"><ChevronRight class="size-4" /></button>
        </div>
        <Button variant="outline" :loading="exporting" :disabled="!data" data-testid="declaration-export" @click="exportFile"><FileSpreadsheet /> Tải Excel</Button>
    </PageHeader>

    <div v-if="loading && !data" class="space-y-3"><Skeleton class="h-40" /><Skeleton class="h-64" /></div>
    <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
    <div v-else-if="data" class="grid grid-cols-[minmax(0,1fr)] items-start gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">
        <Card class="min-w-0">
            <div class="border-b px-4 py-5 text-center">
                <div class="text-xs text-muted-foreground">Mẫu 01/CNKD</div>
                <div class="mt-1 font-semibold uppercase">Tờ khai thuế đối với hộ kinh doanh, cá nhân kinh doanh</div>
                <div class="mt-1 text-sm text-muted-foreground">Kỳ tính thuế: {{ data.label }} (từ {{ dmy(data.from) }} đến {{ dmy(data.to) }})</div>
            </div>
            <dl class="grid gap-x-6 gap-y-2 border-b px-4 py-4 text-sm sm:grid-cols-2">
                <div class="flex gap-2"><dt class="text-muted-foreground">Người nộp thuế:</dt><dd class="font-medium">{{ data.taxpayer?.name || '—' }}</dd></div>
                <div class="flex gap-2"><dt class="text-muted-foreground">Mã số thuế:</dt><dd class="font-mono">{{ data.taxpayer?.tax_code || '—' }}</dd></div>
                <div class="flex gap-2"><dt class="text-muted-foreground">Tên cửa hàng:</dt><dd>{{ data.taxpayer?.store || '—' }}</dd></div>
                <div class="flex gap-2"><dt class="text-muted-foreground">Điện thoại:</dt><dd>{{ data.taxpayer?.phone || '—' }}</dd></div>
                <div class="flex gap-2 sm:col-span-2"><dt class="shrink-0 text-muted-foreground">Địa chỉ:</dt><dd>{{ data.taxpayer?.address || '—' }}</dd></div>
            </dl>
            <div class="overflow-x-auto">
                <table class="w-full text-sm" data-testid="declaration-table">
                    <thead class="bg-muted/50 text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2">Nhóm ngành nghề</th>
                            <th class="px-4 py-2 text-right">Doanh thu</th>
                            <th class="px-4 py-2 text-right">Tỷ lệ GTGT</th>
                            <th class="px-4 py-2 text-right">Thuế GTGT</th>
                            <th class="px-4 py-2 text-right">Tỷ lệ TNCN</th>
                            <th class="px-4 py-2 text-right">Thuế TNCN</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="g in data.groups.filter((x) => x.self !== 0)" :key="g.group" class="border-t">
                            <td class="px-4 py-3">{{ g.label }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ formatMoney(g.self) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-muted-foreground">{{ pct(g.vat) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ formatMoney(g.vat_amount) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-muted-foreground">{{ pct(g.pit) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ formatMoney(g.pit_amount) }}</td>
                        </tr>
                        <tr v-if="!data.groups.some((x) => x.self !== 0)"><td colspan="6" class="px-4 py-8 text-center text-muted-foreground">Kỳ này chưa có doanh thu tự khai.</td></tr>
                    </tbody>
                    <tfoot class="border-t font-semibold">
                        <tr>
                            <td class="px-4 py-3">Tổng cộng</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ formatMoney(data.self) }}</td>
                            <td></td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ formatMoney(data.vat) }}</td>
                            <td></td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ formatMoney(data.pit) }}</td>
                        </tr>
                        <tr class="bg-primary/5">
                            <td class="px-4 py-3" colspan="5">Tổng số thuế phải nộp (GTGT + TNCN)</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-base tabular-nums text-primary" data-testid="declaration-tax">{{ formatMoney(data.tax) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div v-if="data.market" class="m-4 flex gap-2.5 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-900">
                <Info class="mt-0.5 size-4 shrink-0" />
                <div>
                    <b>Không đưa vào tờ khai:</b> doanh thu sàn {{ formatMoney(data.market) }}. Sàn đã khấu trừ và nộp thay khoảng {{ formatMoney(data.withheld) }} (ước tính theo tỷ lệ nhóm ngành).
                </div>
            </div>
        </Card>

        <div class="space-y-4">
            <Card class="p-4">
                <div class="mb-3 font-semibold">Số liệu lấy từ đâu</div>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-muted-foreground">Đơn kênh riêng đã giao</dt><dd class="tabular-nums">{{ formatNumber(data.orders ?? 0) }} đơn</dd></div>
                    <div class="flex justify-between"><dt class="text-muted-foreground">Hàng trả lại đã trừ</dt><dd class="tabular-nums">{{ formatMoney(data.returns ?? 0) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted-foreground">Doanh thu sàn (tách riêng)</dt><dd class="tabular-nums">{{ formatMoney(data.market) }}</dd></div>
                </dl>
                <RouterLink :to="{ path: '/accounting/books', query: { book: 's1', month: data.from.slice(0, 7) } }" class="mt-3 block text-sm text-primary hover:underline">Xem chi tiết trong Sổ doanh thu (S1)</RouterLink>
            </Card>
            <Card class="p-4">
                <div class="mb-1 font-semibold">Nộp tờ khai</div>
                <div class="mb-3 text-sm text-muted-foreground">Hạn nộp {{ dmy(data.deadline) }}</div>
                <ol class="list-decimal space-y-1.5 pl-5 text-sm">
                    <li>Bấm <b>Tải Excel</b> để lưu số liệu kỳ này.</li>
                    <li>Đăng nhập cổng thuế điện tử, chọn tờ khai 01/CNKD, kỳ {{ data.label }}.</li>
                    <li>Nhập doanh thu và thuế theo từng nhóm ngành như bảng bên.</li>
                    <li>Nộp tờ khai và nộp tiền thuế trước hạn.</li>
                </ol>
            </Card>
            <p class="text-xs text-muted-foreground">Số liệu tạm tính để tham khảo. Tỷ lệ thuế theo Thiết lập thuế; đối chiếu với cơ quan thuế hoặc kế toán trước khi nộp.</p>
        </div>
    </div>
</template>
