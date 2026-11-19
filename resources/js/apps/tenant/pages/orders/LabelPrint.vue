<script setup lang="ts">
import { ref } from 'vue';
import { Printer } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { useAuthStore } from '@/shared/stores/auth';
import { downloadPickList } from './packing';
import { labelPapers, mergeLabels, printPdf, type LabelPaper } from './shippingLabels';

/**
 * "In phiếu sàn": the shipping labels Shopee / TikTok issued for the orders, merged into one PDF on A6, A5 or A4.
 * The chosen paper is remembered in this browser.
 */
const props = defineProps<{ ids: string[] }>();
const emit = defineEmits<{ printed: [] }>();
const toast = useToast();
const tenant = useTenantStore();
const auth = useAuthStore();

const KEY = 'ecso.label-paper';
const read = (): LabelPaper => {
    try {
        const v = localStorage.getItem(KEY);
        return v === 'a5' || v === 'a4' ? v : 'a6';
    } catch {
        return 'a6';
    }
};
const paper = ref<LabelPaper>(read());
const byProduct = ref(true);
const withPickList = ref(false);
const busy = ref(false);
const failed = ref<{ id: string; external_order_id: string; message: string }[]>([]);

async function print() {
    busy.value = true;
    failed.value = [];
    try {
        localStorage.setItem(KEY, paper.value);
    } catch {
        /* private window: not remembered */
    }
    try {
        // The server makes at most 50 labels a call.
        const files: { id: string; pdf: string }[] = [];
        for (let i = 0; i < props.ids.length; i += 50) {
            const { data } = await api.post('/orders/shipping-labels', { ids: props.ids.slice(i, i + 50), sort: byProduct.value ? 'product' : null });
            files.push(...data.data.files);
            failed.value.push(...data.data.failed);
        }
        if (files.length) {
            printPdf(await mergeLabels(files.map((f) => f.pdf), paper.value));
            toast.success(`Đã mở ${files.length} phiếu sàn để in.`);
            emit('printed');
        }
        if (withPickList.value && files.length) {
            const pick = await api.post('/orders/pick-list', { ids: files.map((f) => f.id) });
            if (pick.data.data.rows.length) await downloadPickList(pick.data.data, { shop: tenant.current?.name ?? '', by: auth.user?.name ?? '', size: 'A4' });
        }
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Không tạo được file phiếu sàn.');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="rounded-xl border p-4" data-testid="label-print">
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <Printer class="size-4 text-muted-foreground" />
            <span class="font-semibold">In phiếu sàn cho {{ ids.length }} đơn</span>
            <span class="ml-auto text-xs text-muted-foreground">Phiếu do sàn cấp, có mã vạch vận đơn</span>
        </div>
        <div class="grid grid-cols-3 gap-2" data-testid="label-papers">
            <button
                v-for="p in labelPapers"
                :key="p.value"
                type="button"
                :class="cn('rounded-xl border px-2 py-2.5 text-center hover:bg-muted/40', paper === p.value && 'border-primary bg-blue-50/50 ring-2 ring-blue-100')"
                :data-testid="`label-paper-${p.value}`"
                @click="paper = p.value"
            >
                <div class="font-semibold">{{ p.label }}</div>
                <div class="mt-0.5 text-[11px] text-muted-foreground">{{ p.hint }}</div>
            </button>
        </div>
        <div class="mt-3 space-y-1.5 text-sm">
            <label class="flex items-center gap-2"><input v-model="byProduct" type="checkbox" class="size-4" /> Sắp xếp theo sản phẩm (dễ nhặt hàng)</label>
            <label class="flex items-center gap-2"><input v-model="withPickList" type="checkbox" class="size-4" /> Tải kèm phiếu nhặt hàng</label>
        </div>
        <ul v-if="failed.length" class="mt-3 space-y-1 rounded-md bg-rose-50 p-2.5 text-xs text-rose-700" data-testid="label-failed">
            <li v-for="f in failed" :key="f.id"><b class="tabular-nums">{{ f.external_order_id }}</b>: {{ f.message }}</li>
        </ul>
        <div class="mt-3 flex justify-end">
            <Button :loading="busy" data-testid="label-print-go" @click="print"><Printer /> In {{ ids.length }} phiếu</Button>
        </div>
    </div>
</template>
