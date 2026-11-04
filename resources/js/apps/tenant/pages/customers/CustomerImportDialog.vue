<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { CheckCircle2, Download, FileSpreadsheet, Loader2, XCircle } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import { api, ApiError, downloadFile } from '@/shared/lib/api';
import { formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import type { CustomerImportJob } from '@/shared/types';

interface ImportResult {
    message: string;
    total?: number;
    created: number;
    updated: number;
    errors: { row: number; message: string }[];
}

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ imported: [] }>();
const toast = useToast();

const file = ref<File | null>(null);
const preview = ref<ImportResult | null>(null);
const busy = ref(false);

// A file with more rows than an import done at once is checked and imported in the background; the dialog follows it
// and can be closed meanwhile (the customer list shows how far it got).
const job = ref<CustomerImportJob | null>(null);
const running = computed(() => !!job.value && ['pending_check', 'checking', 'queued', 'importing'].includes(job.value.status));
const percent = computed(() => (job.value && job.value.total ? Math.min(100, Math.round((job.value.processed / job.value.total) * 100)) : 0));
let timer: ReturnType<typeof setTimeout> | undefined;

async function follow() {
    clearTimeout(timer);
    if (!job.value || !open.value) return;
    try {
        const { data } = await api.get(`/customers/imports/${job.value.id}`);
        const before = job.value.status;
        job.value = data.data;
        if (before !== 'done' && job.value!.status === 'done') {
            toast.success(`Đã thêm ${formatNumber(job.value!.created)} khách mới, cập nhật ${formatNumber(job.value!.updated)} khách.`);
            emit('imported');
        }
    } catch {
        // try again on the next tick
    }
    if (running.value) timer = setTimeout(follow, 2000);
}
onBeforeUnmount(() => clearTimeout(timer));

watch(open, async (v) => {
    clearTimeout(timer);
    if (!v) return;
    file.value = null;
    preview.value = null;
    job.value = null;
    // Opened again while a large file is still being worked on: show it.
    try {
        const { data } = await api.get('/customers/imports/latest');
        if (data.data && ['pending_check', 'checking', 'checked', 'queued', 'importing'].includes(data.data.status)) {
            job.value = data.data;
            follow();
        }
    } catch {
        // no access or no import: the dialog starts empty
    }
});

async function run() {
    if (!job.value) return;
    busy.value = true;
    try {
        const { data } = await api.post(`/customers/imports/${job.value.id}/run`);
        job.value = data.data;
        follow();
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = false;
    }
}

async function send(dryRun: boolean) {
    if (job.value) return run();
    if (!file.value) return;
    busy.value = true;
    const body = new FormData();
    body.append('file', file.value);
    body.append('dry_run', dryRun ? '1' : '0');
    try {
        const { data } = await api.post('/customers/import', body);
        if (data.queued) {
            job.value = data.import;
            follow();
        } else if (dryRun) {
            preview.value = data;
        } else {
            toast.success(`Đã thêm ${data.created} khách mới, cập nhật ${data.updated} khách.`);
            open.value = false;
            emit('imported');
        }
    } catch (e) {
        const err = e as ApiError;
        preview.value = { created: 0, updated: 0, errors: [], ...(err.body as Partial<ImportResult>), message: err.message } as ImportResult;
    } finally {
        busy.value = false;
    }
}

function onFile(e: Event) {
    file.value = (e.target as HTMLInputElement).files?.[0] ?? null;
    preview.value = null;
    job.value = null;
    if (file.value) send(true);
}
</script>

<template>
    <Dialog v-model:open="open" size="lg" title="Nhập khách hàng từ file" description="Excel (.xlsx) hoặc CSV, tối đa 30.000 dòng (trên 5.000 dòng thì chạy ngầm). Kiểm tra toàn bộ trước, có lỗi thì không nhập dòng nào.">
        <div class="space-y-4">
            <div class="rounded-md border border-dashed p-4 text-sm">
                <ul class="list-disc space-y-1 pl-5 text-muted-foreground">
                    <li>Bắt buộc có cột <b>Tên khách hàng</b>. Các cột khác theo file mẫu, có thể bỏ bớt cột không dùng.</li>
                    <li>Dòng có <b>Mã khách hàng</b> đã có sẽ cập nhật khách đó; không có mã thì tìm theo <b>số điện thoại</b>. Hệ thống không ghép theo tên.</li>
                    <li>Khi cập nhật, ô để trống giữ nguyên thông tin cũ. Nhóm ghi theo mã hoặc tên nhóm đã tạo; ngày sinh dạng dd/mm/yyyy.</li>
                    <li>File xuất từ danh sách khách hàng có thể sửa rồi nhập lại.</li>
                </ul>
                <button type="button" class="mt-3 inline-flex items-center gap-1 text-primary hover:underline" @click="downloadFile('/customers/import-template', 'mau-nhap-khach-hang.xlsx')">
                    <Download class="size-4" /> Tải file mẫu
                </button>
            </div>

            <label class="flex cursor-pointer items-center gap-3 rounded-md border bg-muted/40 p-4 hover:bg-muted">
                <FileSpreadsheet class="size-6 text-primary" />
                <span class="flex-1 text-sm">{{ file ? file.name : 'Chọn file .xlsx hoặc .csv' }}</span>
                <input type="file" accept=".csv,.xlsx" class="hidden" data-testid="customer-import-file" @change="onFile" />
                <span class="text-sm font-medium text-primary">Chọn file</span>
            </label>

            <div v-if="busy && !preview && !job" class="text-sm text-muted-foreground">Đang kiểm tra file…</div>

            <div v-if="job" class="space-y-2 rounded-md border p-3 text-sm" data-testid="customer-import-job">
                <p class="font-medium">{{ job.file_name }}</p>
                <template v-if="job.status === 'pending_check' || job.status === 'checking'">
                    <p class="flex items-center gap-2 text-muted-foreground"><Loader2 class="size-4 animate-spin" /> Đang kiểm tra toàn bộ file (chạy ngầm, có thể đóng cửa sổ này)…</p>
                </template>
                <template v-else-if="job.status === 'checked'">
                    <div class="flex items-start gap-3 rounded-md bg-success/10 p-3">
                        <CheckCircle2 class="mt-0.5 size-5 text-success" />
                        <div>
                            <p class="font-medium">File hợp lệ: {{ formatNumber(job.total) }} dòng</p>
                            <p class="text-muted-foreground">Sẽ thêm {{ formatNumber(job.created) }} khách mới và cập nhật {{ formatNumber(job.updated) }} khách. Bấm "Nhập dữ liệu" để nhập (chạy ngầm, tệp được giữ 24 giờ).</p>
                        </div>
                    </div>
                </template>
                <template v-else-if="job.status === 'queued' || job.status === 'importing'">
                    <p class="flex items-center gap-2 text-muted-foreground"><Loader2 class="size-4 animate-spin" /> Đang nhập {{ formatNumber(job.processed) }} / {{ formatNumber(job.total) }} dòng. Có thể đóng cửa sổ này; dữ liệu chỉ hiện khi đã nhập xong toàn bộ.</p>
                    <div class="h-2 overflow-hidden rounded-full bg-muted"><div class="h-full bg-primary transition-all" :style="{ width: `${percent}%` }" /></div>
                </template>
                <template v-else-if="job.status === 'done'">
                    <div class="flex items-start gap-3 rounded-md bg-success/10 p-3" data-testid="customer-import-done">
                        <CheckCircle2 class="mt-0.5 size-5 text-success" />
                        <p>Đã nhập xong: thêm {{ formatNumber(job.created) }} khách mới, cập nhật {{ formatNumber(job.updated) }} khách.</p>
                    </div>
                </template>
                <template v-else>
                    <div class="rounded-md border border-destructive/30">
                        <div class="flex items-center gap-2 border-b border-destructive/20 bg-destructive/5 px-3 py-2 font-medium text-destructive">
                            <XCircle class="size-4" /> {{ job.message ?? 'Không nhập được.' }}<template v-if="job.error_count"> ({{ formatNumber(job.error_count) }} lỗi)</template>
                        </div>
                        <ul v-if="job.errors.length" class="max-h-48 divide-y overflow-y-auto">
                            <li v-for="(e, i) in job.errors" :key="i" class="flex gap-3 px-3 py-1.5">
                                <span class="w-16 shrink-0 text-muted-foreground">{{ e.row ? `Dòng ${e.row}` : 'Tệp' }}</span><span>{{ e.message }}</span>
                            </li>
                        </ul>
                    </div>
                </template>
            </div>

            <div v-if="preview && !job" data-testid="customer-import-preview">
                <div v-if="!preview.errors.length && preview.total" class="flex items-start gap-3 rounded-md bg-success/10 p-3 text-sm">
                    <CheckCircle2 class="mt-0.5 size-5 text-success" />
                    <div>
                        <p class="font-medium">File hợp lệ: {{ formatNumber(preview.total ?? 0) }} dòng</p>
                        <p class="text-muted-foreground">Sẽ thêm {{ formatNumber(preview.created) }} khách mới và cập nhật {{ formatNumber(preview.updated) }} khách.</p>
                    </div>
                </div>
                <div v-else class="rounded-md border border-destructive/30">
                    <div class="flex items-center gap-2 border-b border-destructive/20 bg-destructive/5 px-3 py-2 text-sm font-medium text-destructive">
                        <XCircle class="size-4" /> {{ preview.errors.length ? `${preview.errors.length} lỗi, chưa nhập dòng nào` : preview.message }}
                    </div>
                    <ul class="max-h-48 divide-y overflow-y-auto text-sm">
                        <li v-for="(e, i) in preview.errors.slice(0, 200)" :key="i" class="flex gap-3 px-3 py-1.5">
                            <span class="w-16 shrink-0 text-muted-foreground">Dòng {{ e.row }}</span><span>{{ e.message }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        <template #footer>
            <Button variant="outline" @click="open = false">Đóng</Button>
            <Button
                v-if="!job || job.status === 'checked'"
                :disabled="job ? false : !preview || preview.errors.length > 0 || !preview.total"
                :loading="busy"
                data-testid="customer-import-confirm"
                @click="send(false)"
                >Nhập dữ liệu</Button
            >
        </template>
    </Dialog>
</template>
