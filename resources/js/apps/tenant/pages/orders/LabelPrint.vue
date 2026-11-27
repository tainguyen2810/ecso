<script setup lang="ts">
import { orderCode } from '@/shared/orderCode';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { CircleCheck, Download, Printer, RotateCcw } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { useAuthStore } from '@/shared/stores/auth';
import { downloadPickList } from './packing';
import { labelPapers, mergeLabels, printPdf, runPool, type LabelFile, type LabelPaper } from './shippingLabels';

/**
 * "In phiếu sàn": the shipping labels Shopee / TikTok issued for the orders, merged into one PDF on A6, A5 or A4.
 * Each label is asked separately, a few at a time (one marketplace label takes several calls, a whole batch in one
 * request ran past the server's time limit), with progress; what came is printed, what did not is listed with why.
 * The chosen paper is remembered in this browser.
 */
/** auto: start at once (right after "Chuẩn bị hàng" with "In phiếu sàn ngay khi xong" ticked). */
const props = defineProps<{ ids: string[]; auto?: boolean }>();
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
const progress = ref<{ done: number; total: number } | null>(null);
type Failure = { id: string; external_order_id: string; code?: string | null; message: string };
const failed = ref<Failure[]>([]);
/** The merged PDF of the last run, for "Mở phiếu để in" and "Tải file PDF". */
const ready = ref<{ url: string; count: number; key: string } | null>(null);
/** Labels already fetched, by order: changing the paper or the order only rebuilds the file, it never asks the marketplace again. */
const cache = new Map<string, LabelFile & { id: string }>();
const optionKey = computed(() => `${paper.value}|${byProduct.value}`);
/** The file on screen still matches the options: the main button prints it as it is. */
const current = computed(() => !!ready.value && ready.value.key === optionKey.value);
const CONCURRENCY = 4;
type Answer = { id: string; file?: LabelFile & { id: string }; fail?: Failure; cached?: boolean };
/** Failures that usually clear in seconds (tracking number or document not issued yet, slow answer). */
const WAIT_AND_RETRY = /chưa cấp|chưa tạo xong|đang xử lý|chậm|Thử in lại/i;
const waiting = ref(false);

function release() {
    if (ready.value) URL.revokeObjectURL(ready.value.url);
    ready.value = null;
}
onBeforeUnmount(release);
onMounted(() => props.auto && print());

const percent = computed(() => (progress.value ? Math.round((progress.value.done / Math.max(1, progress.value.total)) * 100) : 0));

async function print(only?: string[]) {
    busy.value = true;
    failed.value = [];
    release();
    try {
        localStorage.setItem(KEY, paper.value);
    } catch {
        /* private window: not remembered */
    }
    const ids = only ?? props.ids;
    try {
        const sort = byProduct.value ? 'product' : null;
        const plan: string[] = (await api.post('/orders/shipping-labels/plan', { ids, sort })).data.data.ids;
        progress.value = { done: 0, total: plan.length };
        const fetchOne = async (id: string): Promise<Answer> => {
            const hit = cache.get(id);
            if (hit) return { id, file: hit, cached: true };
            try {
                const { data } = await api.post('/orders/shipping-labels', { ids: [id] });
                return { id, file: data.data.files[0], fail: data.data.failed[0], cached: false };
            } catch (e) {
                return { id, fail: { id, external_order_id: '', message: e instanceof ApiError && e.status !== 504 && e.status !== 502 ? e.message : 'Sàn trả lời chậm, chưa lấy được phiếu. Bấm "In lại đơn lỗi" để thử lại.' } };
            }
        };
        let answers = await runPool(plan, CONCURRENCY, fetchOne, (done) => (progress.value = { done, total: plan.length }));
        // Right after "Chuẩn bị hàng" the marketplace takes a few seconds to issue tracking numbers: wait once, ask again.
        const later = answers.filter((a) => a.fail && WAIT_AND_RETRY.test(a.fail.message)).map((a) => a.id);
        if (later.length) {
            waiting.value = true;
            await new Promise((r) => setTimeout(r, 4000));
            waiting.value = false;
            const again = new Map((await runPool(later, CONCURRENCY, fetchOne)).map((a) => [a.id, a]));
            answers = answers.map((a) => again.get(a.id) ?? a);
        }
        const files = answers.flatMap((a) => (a.file ? [a.file] : []));
        files.forEach((f) => cache.set(f.id, f));
        failed.value = answers.flatMap((a) => (a.fail ? [a.fail] : []));
        if (files.length) {
            const url = printPdf(await mergeLabels(files, paper.value));
            ready.value = { url, count: files.length, key: optionKey.value };
            // Only labels newly taken from the marketplace count as a print.
            if (answers.some((a) => a.file && !a.cached)) emit('printed');
        } else {
            toast.error('Chưa lấy được phiếu nào từ sàn.');
        }
        if (withPickList.value && files.length) {
            const pick = await api.post('/orders/pick-list', { ids: files.map((f) => f.id) });
            if (pick.data.data.rows.length) await downloadPickList(pick.data.data, { shop: tenant.current?.name ?? '', by: auth.user?.name ?? '', size: 'A4' });
        }
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Không tạo được file phiếu sàn.');
    } finally {
        busy.value = false;
        progress.value = null;
    }
}

/** Main button: the file already made opens at once (on the user's click, so it is never blocked); otherwise make it. */
function go() {
    if (current.value && ready.value) return void window.open(ready.value.url, '_blank');
    void print();
}
const fileName = computed(() => `phieu-san-${new Date().toISOString().slice(0, 10)}.pdf`);
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
        <div v-if="progress" class="mt-3 rounded-lg border bg-muted/30 p-3" data-testid="label-progress">
            <div class="mb-1.5 flex items-center justify-between text-xs">
                <span class="font-medium">{{ waiting ? 'Đợi sàn cấp mã vận đơn cho vài đơn…' : 'Đang lấy phiếu từ sàn' }}</span>
                <span class="tabular-nums text-muted-foreground">{{ progress.done }}/{{ progress.total }}</span>
            </div>
            <div class="h-1.5 overflow-hidden rounded-full bg-muted"><div class="h-full rounded-full bg-primary transition-all" :style="{ width: `${percent}%` }" /></div>
        </div>
        <div v-if="ready && !busy" class="mt-3 flex flex-wrap items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm" data-testid="label-ready">
            <CircleCheck class="size-4 shrink-0 text-emerald-600" />
            <span class="min-w-40 flex-1"><b>{{ ready.count }} phiếu</b> đã sẵn sàng.<template v-if="current"> Không thấy hộp in thì bấm "In {{ ready.count }} phiếu" bên dưới.</template><template v-else> Bấm bên dưới để làm lại file theo lựa chọn mới.</template></span>
            <a v-if="current" :href="ready.url" :download="fileName" class="inline-flex items-center gap-1 text-sm font-medium text-emerald-800 hover:underline"><Download class="size-4" /> Tải PDF</a>
        </div>
        <div v-if="failed.length && !busy" class="mt-3 rounded-lg bg-rose-50 p-3 text-xs text-rose-700" data-testid="label-failed">
            <div class="mb-1 flex items-center gap-2">
                <b class="text-sm">{{ failed.length }} đơn chưa có phiếu</b>
                <button type="button" class="ml-auto inline-flex items-center gap-1 font-medium text-rose-700 hover:underline" data-testid="label-retry" @click="print(failed.map((f) => f.id))"><RotateCcw class="size-3.5" /> In lại đơn lỗi</button>
            </div>
            <ul class="space-y-0.5">
                <li v-for="f in failed" :key="f.id"><b v-if="f.external_order_id" class="tabular-nums">{{ orderCode(f) }}: </b>{{ f.message }}</li>
            </ul>
        </div>
        <div class="mt-3 flex justify-end">
            <!-- While labels come in, the progress bar above is the only status: the button waits out of sight. -->
            <Button v-if="!progress" :loading="busy" data-testid="label-print-go" @click="go"><Printer /> In {{ current && ready ? ready.count : ids.length }} phiếu</Button>
        </div>
    </div>
</template>
