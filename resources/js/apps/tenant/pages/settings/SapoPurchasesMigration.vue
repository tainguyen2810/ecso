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
import { sapoGoPurchasesBookmarklet } from './sapoGoPurchasesExport';

/** What the server says about one "sapo-nhap-hang-….json" file (SapoPurchaseImporter). */
interface PurchaseFileResult {
    suppliers_total: number;
    suppliers_found: number;
    suppliers_new: number;
    total: number;
    created: number;
    updated: number;
    skipped_open: number;
    cancelled: number;
    from: string | null;
    to: string | null;
    value: string;
    paid: string;
    lines: number;
    unmatched_lines: number;
    unmatched: { name: string; lines: number }[];
    returns: number;
    returned: string;
    refunded: string;
    debt: string;
    errors: { row: number; message: string }[];
}
interface PurchaseFile { file: File; result: PurchaseFileResult | null; error: string | null; done: boolean }

/** Cấu hình › Chuyển dữ liệu › Nhà cung cấp và nhập hàng (Sapo GO): bookmark, files checked, then imported. */
const toast = useToast();
const bookmarklet = sapoGoPurchasesBookmarklet();
const files = ref<PurchaseFile[]>([]);
const busy = ref(false);
const progress = ref('');
const done = ref(false);

const sum = (key: keyof PurchaseFileResult) => files.value.reduce((n, f) => n + Number((f.result?.[key] as number | string | undefined) ?? 0), 0);
const totals = computed(() => {
    const checked = files.value.filter((f) => f.result);
    const dates = checked.flatMap((f) => [f.result!.from, f.result!.to]).filter(Boolean).sort() as string[];
    const unmatched = new Map<string, number>();
    for (const f of checked) for (const u of f.result!.unmatched) unmatched.set(u.name, (unmatched.get(u.name) ?? 0) + u.lines);
    return {
        files: checked.length,
        suppliers: sum('suppliers_total'), suppliersFound: sum('suppliers_found'), suppliersNew: sum('suppliers_new'),
        orders: sum('created'), updated: sum('updated'), open: sum('skipped_open'), cancelled: sum('cancelled'),
        value: sum('value'), paid: sum('paid'), debt: sum('debt'), lines: sum('lines'), unmatchedLines: sum('unmatched_lines'),
        returns: sum('returns'), returned: sum('returned'), refunded: sum('refunded'),
        errors: checked.flatMap((f) => f.result!.errors),
        from: dates[0] ?? null, to: dates[dates.length - 1] ?? null,
        unmatched: [...unmatched.entries()].sort((a, b) => b[1] - a[1]).slice(0, 30),
    };
});
const ready = computed(() => files.value.length > 0 && files.value.every((f) => f.result || f.error) && files.value.some((f) => f.result && !f.done) && !busy.value);
/** 2,04 tỷ / 815 triệu: a big sum that fits its tile. */
const short = (n: number) => (Math.abs(n) >= 1e9 ? `${(n / 1e9).toLocaleString('vi-VN', { maximumFractionDigits: 2 })} tỷ` : Math.abs(n) >= 1e6 ? `${(n / 1e6).toLocaleString('vi-VN', { maximumFractionDigits: 1 })} triệu` : `${formatNumber(Math.round(n))} ₫`);
const fmtDate = (d: string | null) => (d ? d.split('-').reverse().join('/') : '');

async function send(entry: PurchaseFile, dryRun: boolean) {
    const body = new FormData();
    body.append('file', entry.file);
    body.append('dry_run', dryRun ? '1' : '0');
    try {
        const { data } = await api.post('/purchase-orders/import/sapo', body);
        entry.result = data;
        entry.error = null;
        if (!dryRun) entry.done = true;
    } catch (e) {
        entry.error = (e as ApiError).message;
    }
}

async function onFiles(e: Event) {
    const input = e.target as HTMLInputElement;
    const picked = [...(input.files ?? [])].sort((a, b) => a.name.localeCompare(b.name));
    input.value = '';
    if (!picked.length) return;
    done.value = false;
    files.value = picked.map((file) => ({ file, result: null, error: null, done: false }));
    busy.value = true;
    try {
        for (const [i, entry] of files.value.entries()) {
            progress.value = `Đang kiểm tra file ${i + 1}/${files.value.length}…`;
            await send(entry, true);
        }
    } finally {
        busy.value = false;
        progress.value = '';
    }
}

async function run() {
    busy.value = true;
    try {
        const todo = files.value.filter((f) => f.result && !f.done);
        for (const [i, entry] of todo.entries()) {
            progress.value = `Đang nhập file ${i + 1}/${todo.length} (${entry.file.name})…`;
            await send(entry, false);
            if (entry.error) {
                toast.error(`Dừng ở file ${entry.file.name}: ${entry.error}`);
                return;
            }
        }
        done.value = true;
        toast.success('Đã nhập xong nhà cung cấp và đơn nhập hàng');
    } finally {
        busy.value = false;
        progress.value = '';
    }
}
</script>

<template>
    <Card class="space-y-3 p-4" data-testid="migrate-purchases">
        <h2 class="font-semibold">2. Lấy nhà cung cấp và nhập hàng từ Sapo GO</h2>
        <div class="space-y-2 rounded-lg border bg-muted/30 p-3 text-sm">
            <ol class="list-decimal space-y-1.5 pl-5">
                <li>
                    Kéo nút này lên thanh dấu trang của Chrome (làm một lần):
                    <a :href="bookmarklet" draggable="true" data-testid="migrate-purchases-bookmarklet"
                        class="ml-1 inline-flex items-center gap-1 rounded-md bg-primary px-2.5 py-1 text-xs font-medium text-primary-foreground no-underline"
                        @click.prevent="toast.info('Kéo nút này lên thanh dấu trang, rồi bấm nó khi đang mở trang Sapo.')"><Bookmark class="size-3.5" /> Lấy nhập hàng Sapo</a>
                </li>
                <li>Đăng nhập Sapo, mở <b>Sản phẩm → Nhập hàng</b> rồi bấm dấu trang <b>Lấy nhập hàng Sapo</b>. Giữ nguyên tab đến khi nút báo xong: máy lưu file <b>sapo-nhap-hang-….json</b>.</li>
                <li>Tải file đó lên ô bên dưới.</li>
            </ol>
            <p class="text-xs text-muted-foreground">Lấy sang nhà cung cấp, đơn nhập đã nhập kho hoặc đã hủy, đơn trả hàng nhà cung cấp và các khoản đã trả. Đơn còn chờ hàng trên Sapo được để lại: nhập xong trên Sapo rồi lấy lại, đơn đã có sẽ không bị tạo trùng.</p>
        </div>
        <label class="flex cursor-pointer flex-col items-center gap-2 rounded-lg border border-dashed p-6 text-center text-sm hover:bg-muted/40">
            <Upload class="size-6 text-muted-foreground" />
            <span v-if="files.length" class="font-medium"><FileSpreadsheet class="inline size-4" /> {{ files.map((f) => f.file.name).join(', ') }}</span>
            <span v-else>Chọn file sapo-nhap-hang-….json vừa lưu từ Sapo</span>
            <input type="file" accept=".json" multiple class="sr-only" data-testid="migrate-purchases-files" @change="onFiles" />
        </label>
        <p v-if="progress" class="text-sm text-muted-foreground">{{ progress }}</p>
        <Alert v-for="f in files.filter((x) => x.error)" :key="f.file.name" tone="red">{{ f.file.name }}: {{ f.error }}</Alert>

        <template v-if="totals.files && !busy">
            <div class="grid gap-3 sm:grid-cols-4" data-testid="migrate-purchases-summary">
                <div class="rounded-lg border p-3"><div class="text-xs text-muted-foreground">Đơn nhập sẽ nhập</div><div class="text-xl font-semibold tabular-nums">{{ formatNumber(totals.orders) }}</div><div class="text-xs text-muted-foreground">{{ fmtDate(totals.from) }} → {{ fmtDate(totals.to) }}</div></div>
                <div class="rounded-lg border p-3"><div class="text-xs text-muted-foreground">Tiền hàng đã nhập</div><div class="whitespace-nowrap text-xl font-semibold tabular-nums">{{ short(totals.value) }}</div><div class="text-xs text-muted-foreground">Đã trả {{ short(totals.paid) }}</div></div>
                <div class="rounded-lg border p-3"><div class="text-xs text-muted-foreground">Còn nợ nhà cung cấp</div><div class="whitespace-nowrap text-xl font-semibold tabular-nums" :class="totals.debt > 0 ? 'text-destructive' : ''">{{ short(totals.debt) }}</div><div class="text-xs text-muted-foreground">Sau trả hàng, hoàn tiền</div></div>
                <div class="rounded-lg border p-3"><div class="text-xs text-muted-foreground">Còn chờ hàng trên Sapo</div><div class="text-xl font-semibold tabular-nums" :class="totals.open ? 'text-amber-600' : ''">{{ formatNumber(totals.open) }}</div><div class="text-xs text-muted-foreground">Để lại, lấy sau</div></div>
            </div>
            <div class="overflow-x-auto rounded border text-sm">
                <table class="w-full">
                    <thead class="bg-muted/40 text-left text-xs text-muted-foreground"><tr><th class="px-3 py-1.5 font-medium">Mục</th><th class="px-3 py-1.5 font-medium">Kết quả</th><th class="px-3 py-1.5 font-medium">Cách xử lý</th></tr></thead>
                    <tbody>
                        <tr class="border-t"><td class="px-3 py-1.5">Nhà cung cấp</td><td class="px-3 py-1.5 tabular-nums">{{ formatNumber(totals.suppliersFound) }} đã có · {{ formatNumber(totals.suppliersNew) }} mới</td><td class="px-3 py-1.5 text-muted-foreground">Khớp theo mã NCC của Sapo, rồi tên. NCC mới giữ mã Sapo.</td></tr>
                        <tr class="border-t"><td class="px-3 py-1.5">Dòng sản phẩm</td><td class="px-3 py-1.5 tabular-nums">{{ formatNumber(totals.lines - totals.unmatchedLines) }}/{{ formatNumber(totals.lines) }} khớp SKU</td><td class="px-3 py-1.5 text-muted-foreground">Khớp theo SKU, rồi mã vạch. Tổng tiền đơn giữ đúng như Sapo.</td></tr>
                        <tr class="border-t"><td class="px-3 py-1.5">Đơn đã có</td><td class="px-3 py-1.5 tabular-nums">{{ formatNumber(totals.updated) }}</td><td class="px-3 py-1.5 text-muted-foreground">Chỉ cập nhật trạng thái và các khoản đã trả.</td></tr>
                        <tr class="border-t"><td class="px-3 py-1.5">Đơn đã hủy</td><td class="px-3 py-1.5 tabular-nums">{{ formatNumber(totals.cancelled) }}</td><td class="px-3 py-1.5 text-muted-foreground">Giữ làm lịch sử, không tính nợ.</td></tr>
                        <tr class="border-t"><td class="px-3 py-1.5">Trả hàng nhà cung cấp</td><td class="px-3 py-1.5 tabular-nums">{{ formatNumber(totals.returns) }} đơn · {{ short(totals.returned) }}</td><td class="px-3 py-1.5 text-muted-foreground">Nối vào đơn nhập gốc, giảm nợ; NCC đã hoàn {{ short(totals.refunded) }}. Không trừ kho.</td></tr>
                    </tbody>
                </table>
            </div>
            <details v-if="totals.unmatched.length" class="text-sm">
                <summary class="cursor-pointer text-amber-700">{{ formatNumber(totals.unmatchedLines) }} dòng không khớp sản phẩm nào: xem mã</summary>
                <div class="mt-1 flex flex-wrap gap-1.5"><span v-for="[name, n] in totals.unmatched" :key="name" class="rounded border bg-muted/40 px-2 py-0.5 text-xs">{{ name }} · {{ n }}</span></div>
            </details>
            <Alert v-for="(e, i) in totals.errors.slice(0, 5)" :key="i" tone="amber">{{ e.message }}</Alert>
            <Alert tone="green" :icon="CheckCircle2">Đơn nhập cũ chỉ là lịch sử: hiện trong Nhập hàng với nhãn Sapo và được tính vào báo cáo nhập hàng, nhưng không cộng tồn kho, không đổi giá vốn và không vào Sổ quỹ. Công nợ nhà cung cấp được tính từ các đơn này, phần còn nợ thanh toán tiếp ở đây.</Alert>
        </template>
    </Card>
    <Card class="flex flex-wrap items-center justify-between gap-3 p-4">
        <div>
            <h2 class="font-semibold">3. Nhập vào EzChat Bán hàng</h2>
            <p class="text-sm text-muted-foreground">Nhập lại cùng file không tạo trùng: đơn đã có chỉ được cập nhật trạng thái và các khoản đã trả.</p>
        </div>
        <Button :disabled="!ready" :loading="busy" data-testid="migrate-purchases-run" @click="run">Nhập {{ totals.files ? formatNumber(totals.orders + totals.updated) : '' }} đơn nhập</Button>
    </Card>
    <Alert v-if="done" tone="green" :icon="CheckCircle2" data-testid="migrate-purchases-done">
        Đã nhập {{ formatNumber(totals.orders) }} đơn nhập, {{ formatNumber(totals.suppliers) }} nhà cung cấp, {{ formatNumber(totals.returns) }} đơn trả.
        <RouterLink to="/purchasing" class="ml-1 underline">Xem nhập hàng</RouterLink> · <RouterLink to="/purchasing/suppliers" class="underline">Xem công nợ NCC</RouterLink>
    </Alert>
</template>
