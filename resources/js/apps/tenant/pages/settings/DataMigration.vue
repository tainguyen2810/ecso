<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { Bookmark, CheckCircle2, Download, FileSpreadsheet, TriangleAlert, Upload } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Alert from '@/shared/ui/Alert.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import FormField from '@/shared/ui/FormField.vue';
import Select from '@/shared/ui/Select.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import type { Warehouse } from '@/shared/types';
import { sapoGoBookmarklet } from './sapoGoExport';

/**
 * Chuyển dữ liệu (C7): bring products and customers over from Sapo or KiotViet by uploading the files those apps
 * export, as they are. Step 1 what, step 2 the file (checked at once), step 3 import.
 */
type Kind = 'products' | 'customers';
interface Row { row: number; message: string }
interface Result {
    message: string;
    source?: 'ezsell' | 'sapo' | 'kiotviet';
    columns?: { header: string; key: string | null }[];
    total?: number;
    created: number;
    updated: number;
    products?: number;
    debts?: number;
    errors: Row[];
    skipped?: Row[];
    warnings?: Row[];
    zero_cost_rows?: number[];
    opening_receipt?: string | null;
    opening_receipts?: string[];
    branches?: { name: string; quantity: number; warehouse_id: string | null }[];
    images?: number;
    queued?: boolean;
}

const SOURCES = { ezsell: 'EzChat Bán hàng', sapo: 'Sapo', kiotviet: 'KiotViet' } as const;
const KEYS: Record<string, string> = {
    product_key: 'Nhóm biến thể', product_name: 'Tên sản phẩm', sku: 'SKU', variant_name: 'Tên phiên bản', barcode: 'Barcode', category: 'Danh mục', brand: 'Thương hiệu',
    ref_price: 'Giá bán lẻ', wholesale_price: 'Giá bán buôn', weight_gram: 'Khối lượng', status: 'Trạng thái', opening_qty: 'Tồn đầu kỳ', opening_cost: 'Giá vốn đầu kỳ', unit: 'Đơn vị tính',
    description: 'Mô tả', image_urls: 'Ảnh', branch_qty: 'Tồn theo chi nhánh', branch_cost: 'Giá vốn theo chi nhánh',
    code: 'Mã khách', name: 'Tên khách', phone: 'Số điện thoại', email: 'Email', address: 'Địa chỉ', group: 'Nhóm khách', birthday: 'Ngày sinh', gender: 'Giới tính',
    tax_code: 'Mã số thuế', tags: 'Tags', note: 'Ghi chú', debt: 'Nợ đầu kỳ',
};

const tenant = useTenantStore();
const toast = useToast();
const kinds = computed(() => [
    { key: 'products' as Kind, title: 'Sản phẩm và tồn kho', hint: 'Sapo: Sản phẩm → Xuất file. KiotViet: Hàng hóa → Xuất file.', allowed: tenant.can('products.manage') },
    { key: 'customers' as Kind, title: 'Khách hàng và công nợ', hint: 'Sapo: Khách hàng → Xuất file. KiotViet: Khách hàng → Xuất file.', allowed: tenant.can('customers.import') },
]);
const kind = ref<Kind>(tenant.can('products.manage') ? 'products' : 'customers');
const file = ref<File | null>(null);
const warehouse = ref('');
const warehouses = ref<Warehouse[]>([]);
const skipInvalid = ref(false);
const withImages = ref(true);
/** Sapo branch name → EzChat Bán hàng warehouse id ('' = not brought over); filled from the first check. */
const branchChoice = ref<Record<string, string>>({});
/** How the user gets the Sapo data: the Excel export or the bookmark (Sapo GO has no API key). */
const how = ref<'file' | 'sapo'>('file');
const bookmarklet = sapoGoBookmarklet();
const sellingWarehouses = computed(() => warehouses.value.filter((w) => w.status === 'active' && (w.purpose ?? 'sale') === 'sale'));
const branches = computed(() => check.value?.branches ?? []);
const check = ref<Result | null>(null);
const done = ref<Result | null>(null);
const busy = ref(false);

const recognised = computed(() => (check.value?.columns ?? []).filter((c) => c.key));
const ignored = computed(() => (check.value?.columns ?? []).filter((c) => !c.key));
const problems = computed(() => [...(check.value?.errors ?? []), ...(check.value?.skipped ?? [])]);
const ready = computed(() => !!check.value && !check.value.errors.length && !check.value.queued && (check.value.created + check.value.updated) > 0);

onMounted(async () => {
    if (tenant.can('inventory.receive')) {
        warehouses.value = (await api.get('/warehouses')).data.data;
        warehouse.value = warehouses.value.find((w) => w.is_default)?.id ?? warehouses.value[0]?.id ?? '';
    }
});
watch(kind, () => reset());
watch([warehouse, skipInvalid, withImages], () => {
    if (file.value) send(true);
});

function reset() {
    file.value = null;
    branchChoice.value = {};
    check.value = null;
    done.value = null;
}

function onFile(e: Event) {
    const input = e.target as HTMLInputElement;
    file.value = input.files?.[0] ?? null;
    input.value = '';
    branchChoice.value = {};
    done.value = null;
    if (file.value) send(true);
}

async function send(dryRun: boolean) {
    if (!file.value) return;
    busy.value = true;
    const body = new FormData();
    body.append('file', file.value);
    body.append('dry_run', dryRun ? '1' : '0');
    body.append('skip_invalid', skipInvalid.value ? '1' : '0');
    if (kind.value === 'products') {
        if (warehouse.value) body.append('warehouse_id', warehouse.value);
        for (const [name, id] of Object.entries(branchChoice.value)) body.append(`branch_warehouses[${name}]`, id);
        body.append('with_images', withImages.value ? '1' : '0');
    }
    try {
        const { data } = await api.post(kind.value === 'products' ? '/products/import' : '/customers/import', body);
        if (data.queued) {
            check.value = { created: 0, updated: 0, errors: [], message: '', queued: true };
        } else if (dryRun) {
            check.value = data;
            for (const b of (data as Result).branches ?? []) {
                if (!(b.name in branchChoice.value)) branchChoice.value[b.name] = b.warehouse_id ?? '';
            }
        } else {
            done.value = data;
            check.value = null;
            file.value = null;
            toast.success('Đã chuyển dữ liệu xong');
        }
    } catch (e) {
        const err = e as ApiError;
        check.value = { created: 0, updated: 0, errors: [], ...(err.body as Partial<Result>), message: err.message } as Result;
        for (const b of check.value.branches ?? []) {
            if (!(b.name in branchChoice.value)) branchChoice.value[b.name] = b.warehouse_id ?? '';
        }
    } finally {
        busy.value = false;
    }
}

function chooseBranch(name: string, id: string) {
    branchChoice.value = { ...branchChoice.value, [name]: id };
    send(true);
}

/** The rows with problems as a CSV the user can open in Excel and fix. */
function downloadProblems() {
    const lines = ['Dòng,Lỗi', ...problems.value.map((p) => `${p.row},"${p.message.replace(/"/g, '""')}"`)];
    const url = URL.createObjectURL(new Blob(['﻿' + lines.join('\n')], { type: 'text/csv;charset=utf-8' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = 'dong-loi.csv';
    a.click();
    URL.revokeObjectURL(url);
}
</script>

<template>
    <PageHeader title="Chuyển dữ liệu" description="Chuyển sản phẩm, tồn kho, khách hàng và công nợ từ Sapo hoặc KiotViet sang EzChat Bán hàng bằng chính file xuất từ phần mềm cũ, không cần sửa cột." :back="{ to: '/settings', label: 'Cấu hình' }" />

    <div class="grid gap-4 lg:grid-cols-[1fr_320px]">
        <div class="space-y-4">
            <Card class="p-4">
                <h2 class="mb-3 font-semibold">1. Chọn dữ liệu cần chuyển</h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <button v-for="k in kinds" :key="k.key" type="button" :disabled="!k.allowed" :data-testid="`migrate-${k.key}`"
                        class="rounded-lg border p-3 text-left transition disabled:opacity-50"
                        :class="kind === k.key ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'hover:bg-muted/50'" @click="kind = k.key">
                        <div class="font-medium">{{ k.title }}</div>
                        <div class="mt-1 text-xs text-muted-foreground">{{ k.allowed ? k.hint : 'Bạn chưa có quyền nhập dữ liệu này.' }}</div>
                    </button>
                </div>
            </Card>

            <Card class="space-y-3 p-4">
                <h2 class="font-semibold">2. Tải file lên</h2>
                <template v-if="kind === 'products'">
                    <div class="flex flex-wrap gap-2" role="tablist">
                        <button type="button" role="tab" :aria-selected="how === 'file'" data-testid="migrate-how-file"
                            class="rounded-md border px-3 py-1.5 text-sm" :class="how === 'file' ? 'border-primary bg-primary/5 font-medium text-primary' : 'hover:bg-muted/50'" @click="how = 'file'">Bằng file Excel</button>
                        <button type="button" role="tab" :aria-selected="how === 'sapo'" data-testid="migrate-how-sapo"
                            class="rounded-md border px-3 py-1.5 text-sm" :class="how === 'sapo' ? 'border-primary bg-primary/5 font-medium text-primary' : 'hover:bg-muted/50'" @click="how = 'sapo'">Lấy thẳng từ Sapo GO</button>
                    </div>
                    <p v-if="how === 'file'" class="text-sm text-muted-foreground">
                        Trên Sapo: <b>Sản phẩm → Xuất file → Tất cả sản phẩm</b>. Trên KiotViet: <b>Hàng hóa → Xuất file</b>. Tải nguyên file đó lên đây, không cần sửa cột.
                    </p>
                    <div v-else class="space-y-2 rounded-lg border bg-muted/30 p-3 text-sm" data-testid="migrate-sapo-steps">
                        <p>Sapo GO không cấp khóa API cho cửa hàng, nên EzChat Bán hàng dùng một nút chạy ngay trong trang Sapo của bạn. Cách này lấy được tồn thực tế và giá vốn bình quân của từng chi nhánh, ảnh và combo. EzChat Bán hàng không cần mật khẩu Sapo.</p>
                        <ol class="list-decimal space-y-1.5 pl-5">
                            <li>
                                Kéo nút này lên thanh dấu trang của Chrome (làm một lần):
                                <a :href="bookmarklet" draggable="true" data-testid="migrate-bookmarklet"
                                    class="ml-1 inline-flex items-center gap-1 rounded-md bg-primary px-2.5 py-1 text-xs font-medium text-primary-foreground no-underline"
                                    @click.prevent="toast.info('Kéo nút này lên thanh dấu trang, rồi bấm nó khi đang mở trang Sapo.')"><Bookmark class="size-3.5" /> Lấy dữ liệu Sapo</a>
                            </li>
                            <li>Đăng nhập Sapo, mở <b>Sản phẩm → Danh sách sản phẩm</b> rồi bấm dấu trang <b>Lấy dữ liệu Sapo</b>. Trình duyệt lưu file <b>sapo-du-lieu-….json</b>.</li>
                            <li>Tải file đó lên ô bên dưới.</li>
                        </ol>
                        <p class="text-xs text-muted-foreground">Nút đọc dữ liệu nội bộ của trang Sapo. Nếu Sapo thay đổi và nút báo lỗi, hãy dùng cách file Excel.</p>
                    </div>
                </template>
                <label class="flex cursor-pointer flex-col items-center gap-2 rounded-lg border border-dashed p-6 text-center text-sm hover:bg-muted/40">
                    <Upload class="size-6 text-muted-foreground" />
                    <span v-if="file" class="font-medium"><FileSpreadsheet class="inline size-4" /> {{ file.name }}</span>
                    <span v-else-if="kind === 'products' && how === 'sapo'">Chọn file sapo-du-lieu-….json vừa lưu từ Sapo</span>
                    <span v-else>Chọn file Excel (.xlsx) hoặc CSV xuất từ Sapo, KiotViet hoặc file mẫu EzChat Bán hàng</span>
                    <input type="file" :accept="kind === 'products' ? '.xlsx,.csv,.json' : '.xlsx,.csv'" class="sr-only" data-testid="migrate-file" @change="onFile" />
                </label>
                <div class="grid gap-3 sm:grid-cols-2">
                    <FormField v-if="kind === 'products' && warehouses.length && !branches.length" label="Nhập tồn đầu kỳ vào kho">
                        <Select v-model="warehouse" :options="warehouses.map((w) => ({ value: w.id, label: w.name }))" />
                    </FormField>
                    <label class="flex items-center gap-2 self-end pb-2 text-sm">
                        <input v-model="skipInvalid" type="checkbox" data-testid="migrate-skip" /> Bỏ qua dòng lỗi, nhập các dòng còn lại
                    </label>
                    <label v-if="kind === 'products'" class="flex items-center gap-2 self-end pb-2 text-sm">
                        <input v-model="withImages" type="checkbox" data-testid="migrate-images" /> Tải ảnh sản phẩm từ link trong file<template v-if="check?.images"> ({{ formatNumber(check.images) }} ảnh)</template>
                    </label>
                </div>
                <p v-if="busy" class="text-sm text-muted-foreground">Đang đọc file…</p>

                <template v-if="check && !busy">
                    <Alert v-if="check.queued" tone="blue">File lớn: hệ thống đang kiểm tra nền. Xem tiến độ và bấm nhập ở <RouterLink to="/customers" class="underline">danh sách khách hàng</RouterLink>.</Alert>
                    <template v-else>
                        <div class="flex flex-wrap items-center gap-2 text-sm" data-testid="migrate-summary">
                            <Badge v-if="check.source" tone="info" data-testid="migrate-source">File {{ SOURCES[check.source] }}</Badge>
                            <span v-if="check.total">{{ formatNumber(check.total) }} dòng</span>
                            <span v-if="!check.errors.length">· tạo mới <b>{{ formatNumber(check.created) }}</b> · cập nhật <b>{{ formatNumber(check.updated) }}</b></span>
                            <span v-if="check.debts">· <b>{{ formatNumber(check.debts) }}</b> khách có nợ đầu kỳ</span>
                            <span v-if="check.skipped?.length" class="text-amber-700">· bỏ qua {{ check.skipped.length }} lỗi</span>
                        </div>
                        <div v-if="kind === 'products' && branches.length" class="text-sm" data-testid="migrate-branches">
                            <div class="mb-1 text-muted-foreground">Tồn kho theo chi nhánh: chọn kho EzChat Bán hàng nhận tồn và giá vốn của từng chi nhánh</div>
                            <div class="overflow-x-auto rounded border">
                                <table class="w-full">
                                    <thead class="bg-muted/40 text-left text-xs text-muted-foreground">
                                        <tr><th class="px-2 py-1.5 font-medium">Chi nhánh trong file</th><th class="px-2 py-1.5 text-right font-medium">Tổng tồn</th><th class="px-2 py-1.5 font-medium">Đưa vào kho EzChat Bán hàng</th></tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="b in branches" :key="b.name" class="border-t">
                                            <td class="px-2 py-1.5">{{ b.name }}</td>
                                            <td class="px-2 py-1.5 text-right tabular-nums">{{ formatNumber(b.quantity) }}</td>
                                            <td class="px-2 py-1.5">
                                                <Select :model-value="branchChoice[b.name] ?? ''" class="h-8 min-w-44" :data-testid="`migrate-branch-${b.name}`"
                                                    :options="[{ value: '', label: 'Không chuyển tồn' }, ...sellingWarehouses.map((w) => ({ value: w.id, label: w.name }))]"
                                                    @update:model-value="(v: string | undefined) => chooseBranch(b.name, v ?? '')" />
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <p class="mt-1 text-xs text-muted-foreground">SKU đã có trong EzChat Bán hàng chỉ được cập nhật thông tin, không cộng thêm tồn.</p>
                        </div>
                        <div v-if="recognised.length" class="text-sm">
                            <div class="mb-1 text-muted-foreground">Cột đã nhận ra</div>
                            <div class="flex flex-wrap gap-1.5" data-testid="migrate-columns">
                                <span v-for="c in recognised" :key="c.header" class="rounded border bg-muted/40 px-2 py-0.5 text-xs">{{ c.header }} → <b>{{ KEYS[c.key!] ?? c.key }}</b></span>
                            </div>
                            <div v-if="ignored.length" class="mt-1 text-xs text-muted-foreground">Không dùng: {{ ignored.map((c) => c.header).join(', ') }}</div>
                        </div>
                        <Alert v-if="check.errors.length" tone="red" data-testid="migrate-errors">
                            {{ check.errors.length }} lỗi, chưa nhập dòng nào. Sửa file rồi tải lại, hoặc tick “Bỏ qua dòng lỗi”.
                        </Alert>
                        <Alert v-if="check.zero_cost_rows?.length" tone="amber">{{ check.zero_cost_rows.length }} dòng có tồn nhưng chưa có giá vốn: giá vốn sẽ bắt đầu từ 0 ₫.</Alert>
                        <div v-if="problems.length || check.warnings?.length" class="max-h-64 overflow-auto rounded border text-sm">
                            <table class="w-full">
                                <tbody>
                                    <tr v-for="(p, i) in problems" :key="`e${i}`" class="border-b last:border-0"><td class="w-16 px-2 py-1 text-muted-foreground">Dòng {{ p.row }}</td><td class="px-2 py-1 text-destructive">{{ p.message }}</td></tr>
                                    <tr v-for="(p, i) in check.warnings ?? []" :key="`w${i}`" class="border-b last:border-0"><td class="w-16 px-2 py-1 text-muted-foreground">Dòng {{ p.row }}</td><td class="px-2 py-1 text-amber-700"><TriangleAlert class="inline size-3.5" /> {{ p.message }}</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <Button v-if="problems.length" variant="outline" size="sm" @click="downloadProblems"><Download /> Tải danh sách lỗi</Button>
                    </template>
                </template>
            </Card>

            <Card class="flex flex-wrap items-center justify-between gap-3 p-4">
                <div>
                    <h2 class="font-semibold">3. Nhập vào EzChat Bán hàng</h2>
                    <p class="text-sm text-muted-foreground">SKU, mã khách hoặc số điện thoại đã có sẽ được cập nhật, không tạo trùng. Tồn đầu kỳ và nợ đầu kỳ chỉ ghi một lần.</p>
                </div>
                <Button :disabled="!ready" :loading="busy" data-testid="migrate-run" @click="send(false)">Nhập {{ check && ready ? formatNumber(check.created + check.updated) : '' }} dòng</Button>
            </Card>

            <Alert v-if="done" tone="green" :icon="CheckCircle2" data-testid="migrate-done">
                Đã tạo {{ formatNumber(done.created) }}, cập nhật {{ formatNumber(done.updated) }}<template v-if="done.products"> ({{ done.products }} sản phẩm)</template><template v-if="done.debts">, ghi nợ đầu kỳ cho {{ done.debts }} khách</template><template v-if="done.opening_receipts?.length">, phiếu tồn đầu kỳ {{ done.opening_receipts.join(', ') }}</template><template v-else-if="done.opening_receipt">, phiếu tồn đầu kỳ {{ done.opening_receipt }}</template>.<template v-if="done.images"> {{ formatNumber(done.images) }} ảnh đang được tải về, vài phút sau sẽ hiện trên sản phẩm.</template>
                <RouterLink :to="kind === 'products' ? '/products' : '/customers'" class="ml-1 underline">Xem {{ kind === 'products' ? 'sản phẩm' : 'khách hàng' }}</RouterLink>
            </Alert>
        </div>

        <Card class="h-fit space-y-2 p-4 text-sm">
            <h2 class="font-semibold">Nên chuyển theo thứ tự</h2>
            <ol class="list-decimal space-y-1 pl-5 text-muted-foreground">
                <li>Sản phẩm và tồn kho (tồn kho, giá vốn lấy từ file).</li>
                <li>Khách hàng, kèm nợ cần thu hiện tại thành nợ đầu kỳ.</li>
                <li>Kiểm tra lại vài sản phẩm, vài khách rồi mới bán.</li>
            </ol>
            <p class="text-muted-foreground">Phiên bản cùng tên sản phẩm được gộp làm một sản phẩm; giá trị thuộc tính thành tên phiên bản.</p>
            <p class="text-muted-foreground">Mỗi chi nhánh Sapo vào một kho EzChat Bán hàng bạn chọn. SKU có dấu cách được đổi dấu cách thành gạch nối; mã vạch trùng chỉ giữ ở SKU đầu tiên. Mọi thay đổi đều được báo trước khi nhập.</p>
            <p class="text-muted-foreground">Chưa hỗ trợ: nhà cung cấp, lịch sử đơn hàng, điểm tích lũy (tạo lại bằng cộng điểm tay).</p>
        </Card>
    </div>
</template>
