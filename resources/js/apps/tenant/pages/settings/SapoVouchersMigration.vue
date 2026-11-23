<script setup lang="ts">
import { computed, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { Bookmark, CheckCircle2, FileSpreadsheet, Upload } from 'lucide-vue-next';
import Alert from '@/shared/ui/Alert.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { sapoGoVouchersBookmarklet } from './sapoGoVouchersExport';

/** What the server says about one "sapo-so-quy-….json" file (SapoVoucherImporter). */
interface VoucherFileResult {
    total: number;
    created: number;
    existing: number;
    skipped_auto: number;
    cancelled: number;
    receipts: number;
    payments: number;
    in: string;
    out: string;
    from: string | null;
    to: string | null;
    types: { name: string; amount: string }[];
    errors: { row: number; message: string }[];
}

/** Cấu hình › Chuyển dữ liệu › Sổ quỹ (Sapo GO): bookmark, file checked, then imported. */
const toast = useToast();
const bookmarklet = sapoGoVouchersBookmarklet();
const file = ref<File | null>(null);
const result = ref<VoucherFileResult | null>(null);
const error = ref<string | null>(null);
const busy = ref(false);
const done = ref(false);

const ready = computed(() => !!result.value && !done.value && !busy.value && result.value.created > 0);
const short = (n: number | string) => {
    const v = Number(n);
    return Math.abs(v) >= 1e9 ? `${(v / 1e9).toLocaleString('vi-VN', { maximumFractionDigits: 2 })} tỷ` : Math.abs(v) >= 1e6 ? `${(v / 1e6).toLocaleString('vi-VN', { maximumFractionDigits: 1 })} triệu` : `${formatNumber(Math.round(v))} ₫`;
};
const fmtDate = (d: string | null) => (d ? d.split('-').reverse().join('/') : '');

async function send(dryRun: boolean) {
    if (!file.value) return;
    const body = new FormData();
    body.append('file', file.value);
    body.append('dry_run', dryRun ? '1' : '0');
    busy.value = true;
    try {
        const { data } = await api.post('/cash-vouchers/import/sapo', body);
        result.value = data;
        error.value = null;
        if (!dryRun) {
            done.value = true;
            toast.success('Đã nhập xong phiếu thu, phiếu chi');
        }
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        busy.value = false;
    }
}

async function onFile(e: Event) {
    const input = e.target as HTMLInputElement;
    const picked = input.files?.[0] ?? null;
    input.value = '';
    if (!picked) return;
    file.value = picked;
    result.value = null;
    done.value = false;
    await send(true);
}
</script>

<template>
    <Card class="space-y-3 p-4" data-testid="migrate-vouchers">
        <h2 class="font-semibold">2. Lấy phiếu thu, phiếu chi từ Sapo GO</h2>
        <div class="space-y-2 rounded-lg border bg-muted/30 p-3 text-sm">
            <ol class="list-decimal space-y-1.5 pl-5">
                <li>
                    Kéo nút này lên thanh dấu trang của Chrome (làm một lần):
                    <a :href="bookmarklet" draggable="true" data-testid="migrate-vouchers-bookmarklet"
                        class="ml-1 inline-flex items-center gap-1 rounded-md bg-primary px-2.5 py-1 text-xs font-medium text-primary-foreground no-underline"
                        @click.prevent="toast.info('Kéo nút này lên thanh dấu trang, rồi bấm nó khi đang mở trang Sapo.')"><Bookmark class="size-3.5" /> Lấy sổ quỹ Sapo</a>
                </li>
                <li>Đăng nhập Sapo, mở <b>Sổ quỹ</b> rồi bấm dấu trang <b>Lấy sổ quỹ Sapo</b>. Giữ nguyên tab đến khi nút báo xong: máy lưu file <b>sapo-so-quy-….json</b>.</li>
                <li>Tải file đó lên ô bên dưới.</li>
            </ol>
            <p class="text-xs text-muted-foreground">Chỉ lấy phiếu do cửa hàng tự lập (tiền nhà, lương, phí ship, bao bì…). Phiếu Sapo tự tạo khi khách trả tiền đơn hàng hay khi trả tiền nhập hàng được bỏ qua, vì các khoản đó đã sang cùng đơn hàng và đơn nhập.</p>
        </div>
        <label class="flex cursor-pointer flex-col items-center gap-2 rounded-lg border border-dashed p-6 text-center text-sm hover:bg-muted/40">
            <Upload class="size-6 text-muted-foreground" />
            <span v-if="file" class="font-medium"><FileSpreadsheet class="inline size-4" /> {{ file.name }}</span>
            <span v-else>Chọn file sapo-so-quy-….json vừa lưu từ Sapo</span>
            <input type="file" accept=".json" class="sr-only" data-testid="migrate-vouchers-file" @change="onFile" />
        </label>
        <p v-if="busy" class="text-sm text-muted-foreground">{{ done ? 'Đang nhập…' : 'Đang kiểm tra file…' }}</p>
        <Alert v-if="error" tone="red">{{ error }}</Alert>

        <template v-if="result && !busy">
            <div class="grid gap-3 sm:grid-cols-4" data-testid="migrate-vouchers-summary">
                <div class="rounded-lg border p-3"><div class="text-xs text-muted-foreground">Phiếu sẽ nhập</div><div class="text-xl font-semibold tabular-nums">{{ formatNumber(result.created) }}</div><div class="text-xs text-muted-foreground">{{ fmtDate(result.from) }} → {{ fmtDate(result.to) }}</div></div>
                <div class="rounded-lg border p-3"><div class="text-xs text-muted-foreground">Phiếu chi</div><div class="whitespace-nowrap text-xl font-semibold tabular-nums text-destructive">{{ short(result.out) }}</div><div class="text-xs text-muted-foreground">{{ formatNumber(result.payments) }} phiếu</div></div>
                <div class="rounded-lg border p-3"><div class="text-xs text-muted-foreground">Phiếu thu</div><div class="whitespace-nowrap text-xl font-semibold tabular-nums text-emerald-600">{{ short(result.in) }}</div><div class="text-xs text-muted-foreground">{{ formatNumber(result.receipts) }} phiếu</div></div>
                <div class="rounded-lg border p-3"><div class="text-xs text-muted-foreground">Phiếu tự động bỏ qua</div><div class="text-xl font-semibold tabular-nums">{{ formatNumber(result.skipped_auto) }}</div><div class="text-xs text-muted-foreground">Đã sang cùng đơn hàng, đơn nhập</div></div>
            </div>
            <div v-if="result.types.length" class="overflow-x-auto rounded border text-sm">
                <table class="w-full">
                    <thead class="bg-muted/40 text-left text-xs text-muted-foreground"><tr><th class="px-3 py-1.5 font-medium">Loại phiếu</th><th class="px-3 py-1.5 text-right font-medium">Số tiền</th></tr></thead>
                    <tbody>
                        <tr v-for="t in result.types.slice(0, 12)" :key="t.name" class="border-t"><td class="px-3 py-1.5">{{ t.name }}</td><td class="px-3 py-1.5 text-right tabular-nums">{{ formatNumber(Math.round(Number(t.amount))) }} ₫</td></tr>
                    </tbody>
                </table>
            </div>
            <p v-if="result.existing || result.cancelled" class="text-sm text-muted-foreground">
                <template v-if="result.existing">{{ formatNumber(result.existing) }} phiếu đã nhập lần trước, bỏ qua. </template>
                <template v-if="result.cancelled">{{ formatNumber(result.cancelled) }} phiếu đã hủy trên Sapo, bỏ qua.</template>
            </p>
            <Alert v-for="(e, i) in result.errors.slice(0, 5)" :key="i" tone="amber">{{ e.message }}</Alert>
            <Alert tone="green" :icon="CheckCircle2">Phiếu cũ là lịch sử: hiện trong Thu chi với nhãn Sapo, được tính vào báo cáo lãi lỗ và sổ chi phí, nhưng không thuộc quỹ nào nên không đổi số dư quỹ hiện tại. Loại phiếu giữ tên như Sapo; loại chưa có sẽ được tạo.</Alert>
        </template>
    </Card>
    <Card class="flex flex-wrap items-center justify-between gap-3 p-4">
        <div>
            <h2 class="font-semibold">3. Nhập vào EzChat Bán hàng</h2>
            <p class="text-sm text-muted-foreground">Nhập lại cùng file không tạo trùng: phiếu đã có được bỏ qua.</p>
        </div>
        <Button :disabled="!ready" :loading="busy" data-testid="migrate-vouchers-run" @click="send(false)">Nhập {{ result ? formatNumber(result.created) : '' }} phiếu</Button>
    </Card>
    <Alert v-if="done && result" tone="green" :icon="CheckCircle2" data-testid="migrate-vouchers-done">
        Đã nhập {{ formatNumber(result.created) }} phiếu thu chi.
        <RouterLink to="/cash" class="ml-1 underline">Xem Thu chi</RouterLink> · <RouterLink to="/reports/group/finance" class="underline">Xem báo cáo tài chính</RouterLink>
    </Alert>
</template>
