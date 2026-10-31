<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { CheckCircle2, Download, FileSpreadsheet, XCircle } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import FormField from '@/shared/ui/FormField.vue';
import Select from '@/shared/ui/Select.vue';
import { api, ApiError, downloadFile } from '@/shared/lib/api';
import { formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import type { Warehouse } from '@/shared/types';

interface ImportResult {
    message: string;
    total?: number;
    created: number;
    updated: number;
    products?: number;
    errors: { row: number; message: string }[];
    opening_receipt?: string | null;
    dry_run?: boolean;
}

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ imported: [] }>();
const tenant = useTenantStore();
const toast = useToast();

const file = ref<File | null>(null);
const warehouse = ref('');
const warehouses = ref<Warehouse[]>([]);
const preview = ref<ImportResult | null>(null);
const busy = ref(false);
const canReceive = computed(() => tenant.can('inventory.receive'));

watch(open, async (v) => {
    if (!v) return;
    file.value = null;
    preview.value = null;
    if (canReceive.value && !warehouses.value.length) {
        warehouses.value = (await api.get('/warehouses')).data.data;
    }
});

watch(warehouse, () => {
    if (file.value) send(true);
});

async function send(dryRun: boolean) {
    if (!file.value) return;
    busy.value = true;
    const body = new FormData();
    body.append('file', file.value);
    body.append('dry_run', dryRun ? '1' : '0');
    if (warehouse.value) body.append('warehouse_id', warehouse.value);
    try {
        const { data } = await api.post('/products/import', body);
        if (dryRun) {
            preview.value = data;
        } else {
            toast.success(`Đã nhập ${data.created} SKU mới, cập nhật ${data.updated} SKU${data.opening_receipt ? ` · ${data.opening_receipt}` : ''}`);
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
    <Dialog v-model:open="open" size="lg" title="Import sản phẩm" description="CSV hoặc Excel (.xlsx), tối đa 20.000 dòng. Kiểm tra toàn bộ trước, có lỗi thì không nhập dòng nào.">
        <div class="space-y-4">
            <div class="rounded-md border border-dashed p-4 text-sm">
                <ul class="list-disc space-y-1 pl-5 text-muted-foreground">
                    <li>Cột bắt buộc: <code>product_name</code>, <code>sku</code>. SKU đã có sẽ được <b>cập nhật</b> (khớp chính xác SKU).</li>
                    <li>Các dòng cùng <code>product_key</code> là biến thể của một sản phẩm. Hệ thống <b>không tự ghép theo tên</b>.</li>
                    <li><code>opening_qty</code>/<code>opening_cost</code> tạo phiếu tồn đầu kỳ (chỉ cho SKU mới).</li>
                </ul>
                <button type="button" class="mt-3 inline-flex items-center gap-1 text-primary hover:underline" @click="downloadFile('/products/import-template', 'mau-import-san-pham.xlsx')">
                    <Download class="size-4" /> Tải file mẫu
                </button>
            </div>

            <FormField v-if="canReceive" label="Kho nhập tồn đầu kỳ" for="import-wh" hint="Chỉ dùng khi file có cột opening_qty.">
                <Select id="import-wh" v-model="warehouse" :options="[{ value: '', label: 'Không nhập tồn' }, ...warehouses.map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` }))]" />
            </FormField>

            <label class="flex cursor-pointer items-center gap-3 rounded-md border bg-muted/40 p-4 hover:bg-muted">
                <FileSpreadsheet class="size-6 text-primary" />
                <span class="flex-1 text-sm">{{ file ? file.name : 'Chọn file .xlsx hoặc .csv' }}</span>
                <input type="file" accept=".csv,.xlsx" class="hidden" data-testid="import-file" @change="onFile" />
                <span class="text-sm font-medium text-primary">Chọn file</span>
            </label>

            <div v-if="busy && !preview" class="text-sm text-muted-foreground">Đang kiểm tra file…</div>
            <div v-if="preview" data-testid="import-preview">
                <div v-if="!preview.errors.length" class="flex items-start gap-3 rounded-md bg-success/10 p-3 text-sm">
                    <CheckCircle2 class="mt-0.5 size-5 text-success" />
                    <div>
                        <p class="font-medium">File hợp lệ: {{ formatNumber(preview.total ?? 0) }} dòng</p>
                        <p class="text-muted-foreground">Sẽ tạo {{ formatNumber(preview.created) }} SKU mới và cập nhật {{ formatNumber(preview.updated) }} SKU.</p>
                    </div>
                </div>
                <div v-else class="rounded-md border border-destructive/30">
                    <div class="flex items-center gap-2 border-b border-destructive/20 bg-destructive/5 px-3 py-2 text-sm font-medium text-destructive">
                        <XCircle class="size-4" /> {{ preview.errors.length }} lỗi, chưa nhập dòng nào
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
            <Button :disabled="!preview || preview.errors.length > 0" :loading="busy" data-testid="import-confirm" @click="send(false)">Nhập dữ liệu</Button>
        </template>
    </Dialog>
</template>
