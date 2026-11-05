<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { FileUp, Truck } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import CashNav from './CashNav.vue';
import CodImportDialog from './CodImportDialog.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime, formatMoney } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { codStatuses, type CodReconciliation } from './cod';

/** Đối soát COD: statements imported from carriers' files, each booking COD collections and the carrier's fees. */
const tenant = useTenantStore();
const rows = ref<CodReconciliation[]>([]);
const loading = ref(true);
const loadError = ref<string | null>(null);
const importing = ref(false);
const detail = ref<CodReconciliation | null>(null);
const detailOpen = computed({ get: () => detail.value !== null, set: (v) => !v && (detail.value = null) });

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        rows.value = (await api.get('/cod-reconciliations')).data.data;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

async function open(r: CodReconciliation) {
    detail.value = (await api.get(`/cod-reconciliations/${r.id}`)).data.data;
}
function saved(r: CodReconciliation) {
    load();
    detail.value = r;
}
</script>

<template>
    <PageHeader title="Thu chi" description="Đối soát COD: nhập file đối soát của hãng vận chuyển để ghi tiền thu hộ vào đơn và phí của hãng vào sổ quỹ.">
        <Button v-if="tenant.can('finance.vouchers')" data-testid="cod-import" @click="importing = true"><FileUp /> Nhập file đối soát</Button>
    </PageHeader>
    <CashNav />

    <ErrorState v-if="loadError" :message="loadError" @retry="load" />
    <Card v-else>
        <div v-if="loading && !rows.length" class="space-y-3 p-4"><Skeleton v-for="i in 4" :key="i" class="h-10" /></div>
        <div v-else-if="!rows.length" class="flex flex-col items-center gap-2 px-6 py-12 text-center text-sm text-muted-foreground">
            <Truck class="size-8" />
            <p>Chưa có phiên đối soát nào.</p>
            <p class="max-w-md text-xs">Tải file đối soát của GHN, Viettel Post, SPX hoặc J&amp;T. Hệ thống tự tìm cột mã vận đơn, tiền thu hộ và phí, khớp với đơn COD theo mã vận đơn, cho xem trước rồi mới ghi.</p>
        </div>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm" data-testid="cod-table">
                <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Phiên</th>
                        <th class="px-4 py-2 font-medium">Hãng</th>
                        <th class="px-4 py-2 font-medium">Ngày tiền về</th>
                        <th class="px-4 py-2 font-medium">Vào quỹ</th>
                        <th class="px-4 py-2 text-right font-medium">Thu hộ</th>
                        <th class="px-4 py-2 text-right font-medium">Phí hãng</th>
                        <th class="px-4 py-2 text-right font-medium">Hãng chuyển</th>
                        <th class="px-4 py-2 text-right font-medium">Khớp</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="r in rows" :key="r.id" class="cursor-pointer hover:bg-muted/30" @click="open(r)">
                        <td class="px-4 py-2.5"><div class="font-mono text-xs font-medium">{{ r.number }}</div><div class="max-w-48 truncate text-xs text-muted-foreground">{{ r.file_name }}</div></td>
                        <td class="px-4 py-2.5">{{ r.carrier_name }}</td>
                        <td class="px-4 py-2.5">{{ formatDateTime(r.remitted_at) }}<div class="text-xs text-muted-foreground">{{ r.created_by }}</div></td>
                        <td class="px-4 py-2.5">{{ r.account?.name ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ formatMoney(r.total_cod) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-destructive">−{{ formatMoney(r.total_fee) }}</td>
                        <td class="px-4 py-2.5 text-right font-medium tabular-nums">{{ formatMoney(r.transferred) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ r.matched_count }}/{{ r.lines_count }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </Card>

    <CodImportDialog v-model:open="importing" @saved="saved" />

    <Dialog v-model:open="detailOpen" :title="detail ? `Đối soát ${detail.number} · ${detail.carrier_name}` : ''" size="xl">
        <div v-if="detail" class="space-y-3 text-sm" data-testid="cod-detail">
            <p class="text-muted-foreground">
                Tiền về {{ formatDateTime(detail.remitted_at) }}, vào quỹ {{ detail.account?.name ?? '—' }}. Đã ghi {{ formatMoney(detail.total_booked) }} vào đơn,
                phí {{ formatMoney(detail.total_fee) }}<template v-if="detail.fee_voucher"> (phiếu {{ detail.fee_voucher.number }})</template>.
            </p>
            <div class="max-h-[60vh] overflow-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                        <tr><th class="px-3 py-2">Mã vận đơn</th><th class="px-3 py-2">Đơn</th><th class="px-3 py-2 text-right">Thu hộ</th><th class="px-3 py-2 text-right">Phí</th><th class="px-3 py-2 text-right">Đã ghi</th><th class="px-3 py-2">Kết quả</th></tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="(l, i) in detail.lines" :key="i">
                            <td class="px-3 py-2 font-mono text-xs">{{ l.tracking }}</td>
                            <td class="px-3 py-2"><RouterLink v-if="l.order" :to="`/orders/${l.order.id}`" class="font-mono text-xs text-primary hover:underline">{{ l.order.number }}</RouterLink><span v-else>—</span></td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ formatMoney(l.cod) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ formatMoney(l.fee) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ formatMoney(l.booked) }}</td>
                            <td class="px-3 py-2"><Badge :tone="codStatuses[l.status].tone">{{ codStatuses[l.status].label }}</Badge><div v-if="l.note" class="text-xs text-muted-foreground">{{ l.note }}</div></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </Dialog>
</template>
