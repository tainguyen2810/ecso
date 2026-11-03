<script setup lang="ts">
import { ref, watch } from 'vue';
import { CheckCircle2, Download, FileSpreadsheet, XCircle } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import { api, ApiError, downloadFile } from '@/shared/lib/api';
import { formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';

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

watch(open, (v) => {
    if (!v) return;
    file.value = null;
    preview.value = null;
});

async function send(dryRun: boolean) {
    if (!file.value) return;
    busy.value = true;
    const body = new FormData();
    body.append('file', file.value);
    body.append('dry_run', dryRun ? '1' : '0');
    try {
        const { data } = await api.post('/customers/import', body);
        if (dryRun) {
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
    if (file.value) send(true);
}
</script>

<template>
    <Dialog v-model:open="open" size="lg" title="Nhập khách hàng từ file" description="Excel (.xlsx) hoặc CSV, tối đa 5.000 dòng. Kiểm tra toàn bộ trước, có lỗi thì không nhập dòng nào.">
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

            <div v-if="busy && !preview" class="text-sm text-muted-foreground">Đang kiểm tra file…</div>
            <div v-if="preview" data-testid="customer-import-preview">
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
            <Button :disabled="!preview || preview.errors.length > 0 || !preview.total" :loading="busy" data-testid="customer-import-confirm" @click="send(false)">Nhập dữ liệu</Button>
        </template>
    </Dialog>
</template>
