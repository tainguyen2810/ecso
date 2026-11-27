<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { AlertTriangle, Info } from 'lucide-vue-next';
import SettingsPage from './SettingsPage.vue';
import SettingsSection from './SettingsSection.vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { useSettingsStore } from '@/shared/stores/settings';

/**
 * Cấu hình › Mã đơn hàng, like Sapo: prefix + running number (SON50892) for every order, the marketplace / Woo
 * number kept as a secondary code. Off until "Bắt đầu dùng mã mới", which numbers the orders already here.
 */
type Source = 'manual' | 'shopee' | 'tiktok' | 'woocommerce';
interface CodeData {
    enabled: boolean;
    mode: 'shared' | 'per_source';
    prefix: string;
    prefixes: Record<Source, string | null>;
    started_at: string | null;
    started: { count: number; first: string | null; last: string | null } | null;
    next: Record<string, number>;
    highest: Record<string, number>;
    counts: { imported: number; imported_with_code: number; manual: number; marketplace: number; without_code: number };
}
const SOURCES: { key: Source; label: string; example: string }[] = [
    { key: 'manual', label: 'Đơn tạo tay, tại quầy', example: 'SON' },
    { key: 'shopee', label: 'Shopee', example: 'SPE' },
    { key: 'tiktok', label: 'TikTok Shop', example: 'TTS' },
    { key: 'woocommerce', label: 'Website (Woo)', example: 'WEB' },
];

const tenant = useTenantStore();
const settingsStore = useSettingsStore();
const toast = useToast();
const canEdit = computed(() => tenant.can('settings.manage'));
const data = ref<CodeData | null>(null);
const loadError = ref<string | null>(null);
const form = ref({ mode: 'shared' as 'shared' | 'per_source', prefix: 'SON', prefixes: { manual: '', shopee: '', tiktok: '', woocommerce: '' } as Record<Source, string>, next: '' as string | number, nextBy: {} as Record<string, string | number> });
const initial = ref('');
const saving = ref(false);
const error = ref<ApiError | null>(null);
const startOpen = ref(false);
const starting = ref(false);

function fill(d: CodeData) {
    data.value = d;
    form.value = {
        mode: d.mode,
        prefix: d.prefix,
        prefixes: { manual: d.prefixes.manual ?? '', shopee: d.prefixes.shopee ?? '', tiktok: d.prefixes.tiktok ?? '', woocommerce: d.prefixes.woocommerce ?? '' },
        next: d.next[d.prefix] ?? 1,
        nextBy: Object.fromEntries(Object.entries(d.next).map(([p, n]) => [p, n])),
    };
    initial.value = JSON.stringify(form.value);
}
async function load() {
    loadError.value = null;
    try {
        const { data: res } = await api.get('/order-codes');
        fill(res.data);
    } catch (e) {
        loadError.value = (e as ApiError).message;
    }
}
onMounted(load);

const dirty = computed(() => !!data.value && JSON.stringify(form.value) !== initial.value);
const prefix = computed(() => form.value.prefix.trim().toUpperCase());
const nextNumber = computed(() => Number(form.value.next) || data.value?.next[prefix.value] || 1);
const preview = computed(() => [0, 1, 2].map((i) => `${prefix.value}${nextNumber.value + i}`).join(', '));
/** Per source: its own prefix (or the shared one) and that prefix's next number. */
function sourcePrefix(s: Source) {
    return (form.value.prefixes[s] || prefix.value).trim().toUpperCase();
}
function sourceNext(s: Source) {
    const p = sourcePrefix(s);
    return Number(form.value.nextBy[p]) || data.value?.next[p] || (data.value?.highest[p] ?? 0) + 1;
}
const toNumber = computed(() => (data.value ? data.value.counts.without_code : 0));
const range = computed(() => {
    if (!data.value || form.value.mode !== 'shared') return null;
    const n = toNumber.value;
    return n > 0 ? { first: `${prefix.value}${nextNumber.value}`, last: `${prefix.value}${nextNumber.value + n - 1}`, after: `${prefix.value}${nextNumber.value + n}` } : null;
});

async function save(): Promise<boolean> {
    saving.value = true;
    error.value = null;
    try {
        const nextBy: Record<string, number> = {};
        if (form.value.mode === 'per_source') {
            for (const s of SOURCES) {
                const p = sourcePrefix(s.key);
                if (p && p !== prefix.value && Number(form.value.nextBy[p]) && Number(form.value.nextBy[p]) !== data.value?.next[p]) nextBy[p] = Number(form.value.nextBy[p]);
            }
        }
        const { data: res } = await api.put('/order-codes', {
            mode: form.value.mode,
            prefix: prefix.value,
            prefixes: form.value.mode === 'per_source' ? form.value.prefixes : {},
            next: Number(form.value.next) && Number(form.value.next) !== data.value?.next[prefix.value] ? Number(form.value.next) : null,
            next_by_prefix: nextBy,
        });
        fill(res.data);
        await settingsStore.load(true);
        toast.success('Đã lưu mã đơn hàng.');
        return true;
    } catch (e) {
        error.value = e as ApiError;
        toast.error(error.value.message);
        return false;
    } finally {
        saving.value = false;
    }
}
async function start() {
    starting.value = true;
    try {
        if (dirty.value && !(await save())) return;
        const { data: res } = await api.post('/order-codes/start');
        fill(res.data);
        await settingsStore.load(true);
        startOpen.value = false;
        const s = res.data.started;
        toast.success(s?.count ? `Đã cấp mã cho ${formatNumber(s.count)} đơn (${s.first} → ${s.last}).` : 'Đã bật mã đơn mới.');
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        starting.value = false;
    }
}
function cancel() {
    if (data.value) fill(data.value);
}
const field = (name: string) => error.value?.field(name);
</script>

<template>
    <SettingsPage title="Mã đơn hàng" :saving="saving" :dirty="dirty" :can-save="canEdit" @save="save" @cancel="cancel">
        <ErrorState v-if="loadError" :message="loadError" @retry="load" />
        <div v-else-if="!data" class="space-y-3 py-6"><Skeleton class="h-32" /><Skeleton class="h-32" /></div>
        <template v-else>
            <SettingsSection title="Kí hiệu và số" description="Mã đơn = kí hiệu + số chạy liên tục, giống Sapo (VD: SON50892). Mã đơn của sàn, Woo vẫn giữ, hiện dưới mã chính và tìm được trong danh sách đơn.">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Kí hiệu" for="oc-prefix" hint="Chữ in hoa không dấu, tối đa 6 kí tự" :error="field('prefix')">
                        <Input id="oc-prefix" v-model="form.prefix" maxlength="6" class="font-mono uppercase" :disabled="!canEdit" data-testid="oc-prefix" />
                    </FormField>
                    <FormField label="Số tiếp theo" for="oc-next" :hint="data.highest[prefix] ? `Mã lớn nhất đã có: ${prefix}${data.highest[prefix]}` : 'Đơn mới bắt đầu từ số này'" :error="field('next')">
                        <Input id="oc-next" v-model="form.next" type="number" min="1" class="tabular-nums" :disabled="!canEdit" data-testid="oc-next" />
                    </FormField>
                </div>
                <div class="mt-4 rounded-md bg-muted/60 px-4 py-3">
                    <p class="text-xs text-muted-foreground">Đơn mới sẽ có mã</p>
                    <p class="mt-0.5 font-mono text-lg font-semibold" data-testid="oc-preview">{{ preview }}…</p>
                </div>

                <p class="mb-2 mt-5 text-sm font-medium">Áp dụng cho</p>
                <div class="space-y-2">
                    <label :class="['flex cursor-pointer gap-3 rounded-md border p-3', form.mode === 'shared' ? 'border-primary bg-primary/5' : '']">
                        <input v-model="form.mode" type="radio" value="shared" class="mt-1" :disabled="!canEdit" data-testid="oc-mode-shared" />
                        <span><span class="block text-sm font-medium">Mọi đơn dùng chung một dãy số</span><span class="block text-xs text-muted-foreground">Tạo tay, tại quầy, Shopee, TikTok, Website (Woo) đều lấy số tiếp theo, giống Sapo</span></span>
                    </label>
                    <label :class="['flex cursor-pointer gap-3 rounded-md border p-3', form.mode === 'per_source' ? 'border-primary bg-primary/5' : '']">
                        <input v-model="form.mode" type="radio" value="per_source" class="mt-1" :disabled="!canEdit" data-testid="oc-mode-per-source" />
                        <span><span class="block text-sm font-medium">Mỗi kênh một kí hiệu riêng</span><span class="block text-xs text-muted-foreground">VD: SON cho đơn tạo tay, SPE cho Shopee, TTS cho TikTok. Kênh để trống dùng kí hiệu chung.</span></span>
                    </label>
                </div>
                <div v-if="form.mode === 'per_source'" class="mt-3 divide-y rounded-md border">
                    <div v-for="s in SOURCES" :key="s.key" class="grid grid-cols-[1fr_7rem_7rem] items-center gap-3 px-3 py-2 text-sm">
                        <span class="flex items-center gap-2"><MarketplaceLogo :marketplace="s.key" :name="s.label" size="sm" />{{ s.label }}</span>
                        <Input v-model="form.prefixes[s.key]" :placeholder="prefix" maxlength="6" class="font-mono uppercase" :disabled="!canEdit" :data-testid="`oc-prefix-${s.key}`" />
                        <span class="truncate text-right font-mono text-xs text-muted-foreground">{{ sourcePrefix(s.key) }}{{ sourceNext(s.key) }}…</span>
                    </div>
                </div>
                <p v-if="field('prefixes')" class="mt-2 text-xs text-destructive">{{ field('prefixes') }}</p>
            </SettingsSection>

            <SettingsSection title="Cấp mã cho đơn đã có" description="Chỉ làm một lần. Từ lúc bấm, mọi đơn mới (tạo tay, sàn, Woo) đều tự nhận mã.">
                <div v-if="data.enabled" data-testid="oc-started">
                    <p class="flex items-center gap-2 text-sm font-medium text-emerald-700"><Info class="size-4" /> Đang dùng mã đơn mới</p>
                    <p v-if="data.started" class="mt-1 text-sm text-muted-foreground">
                        Đã cấp mã cho {{ formatNumber(data.started.count) }} đơn<template v-if="data.started.first"> ({{ data.started.first }} → {{ data.started.last }})</template><template v-if="data.started_at"> lúc {{ new Date(data.started_at).toLocaleString('vi-VN') }}</template>.
                    </p>
                </div>
                <template v-else>
                    <dl class="divide-y text-sm">
                        <div class="flex justify-between gap-4 py-2"><dt>Đơn chuyển từ Sapo</dt><dd class="text-right"><b>{{ formatNumber(data.counts.imported) }}</b> đơn<br /><span class="text-xs text-muted-foreground">Giữ nguyên mã SON…</span></dd></div>
                        <div class="flex justify-between gap-4 py-2"><dt>Đơn tạo trong ERP</dt><dd class="text-right"><b>{{ formatNumber(data.counts.manual) }}</b> đơn<br /><span class="text-xs text-muted-foreground">Mã cũ SO-… vẫn tìm được</span></dd></div>
                        <div class="flex justify-between gap-4 py-2"><dt class="flex items-center gap-1.5"><MarketplaceLogo marketplace="shopee" name="Shopee" size="sm" /><MarketplaceLogo marketplace="tiktok" name="TikTok" size="sm" /> Đơn sàn, Woo</dt><dd class="text-right"><b>{{ formatNumber(data.counts.marketplace) }}</b> đơn<br /><span class="text-xs text-muted-foreground">Cấp theo ngày tạo</span></dd></div>
                    </dl>
                    <p v-if="range" class="mt-3 rounded-md bg-muted/60 px-3 py-2 text-sm" data-testid="oc-range">
                        {{ formatNumber(toNumber) }} đơn sẽ nhận mã <b class="font-mono">{{ range.first }} → {{ range.last }}</b>, đơn mới bắt đầu từ <b class="font-mono">{{ range.after }}</b>. Mã cũ vẫn tìm được.
                    </p>
                    <p class="mt-3 flex gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        <AlertTriangle class="mt-0.5 size-4 shrink-0" /> Nếu anh/chị còn tạo đơn trên Sapo, nên bấm vào ngày ngừng dùng Sapo để hai bên không cấp trùng số. Đơn Sapo lấy về sau đó mà trùng số sẽ giữ mã Sapo làm mã phụ.
                    </p>
                    <div v-if="canEdit" class="mt-4 flex justify-end">
                        <Button data-testid="oc-start" @click="startOpen = true">{{ toNumber > 0 ? `Cấp mã cho ${formatNumber(toNumber)} đơn` : 'Bắt đầu dùng mã mới' }}</Button>
                    </div>
                </template>
            </SettingsSection>
        </template>
    </SettingsPage>

    <Dialog v-model:open="startOpen" title="Bắt đầu dùng mã đơn mới?">
        <p class="text-sm">
            <template v-if="toNumber > 0">{{ formatNumber(toNumber) }} đơn chưa có mã sẽ được cấp mã theo thứ tự ngày tạo<template v-if="range"> ({{ range.first }} → {{ range.last }})</template>. </template>
            Từ giờ mọi đơn mới tự nhận mã {{ prefix }}…. Việc này chỉ làm một lần, không hoàn tác được.
        </p>
        <template #footer>
            <Button variant="outline" @click="startOpen = false">Để sau</Button>
            <Button :loading="starting" data-testid="oc-start-confirm" @click="start">Bắt đầu</Button>
        </template>
    </Dialog>
</template>
