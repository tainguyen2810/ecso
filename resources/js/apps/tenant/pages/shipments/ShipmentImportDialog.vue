<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { FileUp } from 'lucide-vue-next';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import { api, ApiError, idempotent, newIdempotencyKey } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';

/** "Nhập mã vận đơn từ file": for parcels made in bulk on a carrier's site. Preview, then save row by row. */
interface ImportRow { order: string; tracking: string; carrier: string | null; weight_g: number | null }
interface PlanRow { line: number; order: string; tracking: string | null; carrier: string | null; action: 'create' | 'update' | 'skip' | 'error'; note: string | null }

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [] }>();
const toast = useToast();

const file = ref<File | null>(null);
const rows = ref<ImportRow[]>([]);
const plan = ref<PlanRow[] | null>(null);
const busy = ref(false);
const error = ref<string | null>(null);
let key = newIdempotencyKey();

const actions: Record<PlanRow['action'], { label: string; tone: 'success' | 'info' | 'muted' | 'danger' }> = {
    create: { label: 'Tạo vận đơn', tone: 'success' },
    update: { label: 'Sửa mã', tone: 'info' },
    skip: { label: 'Bỏ qua', tone: 'muted' },
    error: { label: 'Lỗi', tone: 'danger' },
};

watch(open, (v) => {
    if (!v) return;
    file.value = null;
    rows.value = [];
    plan.value = null;
    error.value = null;
    key = newIdempotencyKey();
});

const count = (a: PlanRow['action']) => (plan.value ?? []).filter((p) => p.action === a).length;
const writable = computed(() => count('create') + count('update'));

async function onFile(e: Event) {
    file.value = (e.target as HTMLInputElement).files?.[0] ?? null;
    plan.value = null;
    error.value = null;
    if (!file.value) return;
    busy.value = true;
    const body = new FormData();
    body.append('file', file.value);
    try {
        const { data } = await api.post('/shipments/import/preview', body);
        rows.value = data.data.rows;
        plan.value = data.data.plan;
        key = newIdempotencyKey();
    } catch (err) {
        const x = err as ApiError;
        error.value = x.field('file') ?? x.message;
    } finally {
        busy.value = false;
    }
}

async function confirm() {
    busy.value = true;
    error.value = null;
    try {
        const { data } = await api.post('/shipments/import', { rows: rows.value }, idempotent(key));
        const r = data.data as { created: number; updated: number; skipped: number; failed: number; rows: PlanRow[] };
        toast.success(`Đã tạo ${r.created} vận đơn, sửa ${r.updated} mã.` + (r.failed ? ` ${r.failed} dòng lỗi.` : ''));
        emit('saved');
        if (r.failed) plan.value = r.rows; // keep the dialog open on what did not go in
        else open.value = false;
    } catch (err) {
        error.value = (err as ApiError).message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open" title="Nhập mã vận đơn từ file" description="Dùng khi tạo vận đơn hàng loạt trên trang của hãng. Xem trước từng dòng rồi mới lưu. Chỉ áp dụng cho đơn bán riêng và đơn website; đơn sàn lấy mã từ sàn." size="xl">
        <div class="space-y-4 text-sm" data-testid="shipment-import-dialog">
            <label class="flex cursor-pointer items-center justify-center gap-2 rounded-md border border-dashed px-4 py-5 text-muted-foreground hover:bg-muted/40">
                <FileUp class="size-5" />
                <span>{{ file ? file.name : 'Chọn file (.csv, .xlsx)' }}</span>
                <input type="file" accept=".csv,.xlsx" class="hidden" data-testid="shipment-import-file" @change="onFile" />
            </label>
            <p class="text-xs text-muted-foreground">Cần cột <b>Mã đơn hàng</b> và cột <b>Mã vận đơn</b>. Cột <b>Đơn vị vận chuyển</b> và <b>Khối lượng</b> (gram) là không bắt buộc. Tối đa 2.000 dòng.</p>
            <p v-if="error" class="text-destructive" data-testid="shipment-import-error">{{ error }}</p>

            <template v-if="plan">
                <div class="flex flex-wrap gap-2" data-testid="shipment-import-counts">
                    <Badge tone="success">{{ count('create') }} tạo mới</Badge>
                    <Badge tone="info">{{ count('update') }} sửa mã</Badge>
                    <Badge tone="muted">{{ count('skip') }} bỏ qua</Badge>
                    <Badge :tone="count('error') ? 'danger' : 'muted'">{{ count('error') }} lỗi</Badge>
                </div>
                <div class="max-h-[45vh] overflow-auto rounded-md border">
                    <table class="w-full text-sm" data-testid="shipment-import-preview">
                        <thead class="sticky top-0 border-b bg-slate-50 text-left text-xs font-semibold text-foreground/80">
                            <tr><th class="px-3 py-2">Dòng</th><th class="px-3 py-2">Mã đơn</th><th class="px-3 py-2">Mã vận đơn</th><th class="px-3 py-2">Hãng</th><th class="px-3 py-2">Kết quả</th></tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="p in plan" :key="p.line">
                                <td class="px-3 py-2 tabular-nums text-muted-foreground">{{ p.line }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ p.order || '—' }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ p.tracking ?? '—' }}</td>
                                <td class="px-3 py-2">{{ p.carrier ?? '—' }}</td>
                                <td class="px-3 py-2"><Badge :tone="actions[p.action].tone">{{ actions[p.action].label }}</Badge><div v-if="p.note" class="text-xs text-muted-foreground">{{ p.note }}</div></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>
        <template #footer>
            <Button variant="outline" @click="open = false">Đóng</Button>
            <Button :disabled="!writable" :loading="busy" data-testid="shipment-import-confirm" @click="confirm">Lưu {{ writable }} vận đơn</Button>
        </template>
    </Dialog>
</template>
