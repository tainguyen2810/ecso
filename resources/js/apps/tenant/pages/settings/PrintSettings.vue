<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Check, Eye, FileText, LayoutList, Printer, Tag } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import { api } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';
import SettingsPage from './SettingsPage.vue';
import SettingsSection from './SettingsSection.vue';
import { useSettingsForm } from './useSettingsForm';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Switch from '@/shared/ui/Switch.vue';
import { cn } from '@/shared/lib/utils';
import { invoiceHtml, printInvoice, type InvoiceSize } from '../orders/invoice';
import { printShopFrom } from '../orders/printShop';
import { sampleReceipt } from './printSample';
import { useTenantStore } from '@/shared/stores/tenant';

/** Mẫu in: the business-wide default template for sales invoices and what is printed on them. */
const { form, dirty, saving, canSave, save, cancel, store } = useSettingsForm('print', (s) => ({ ...(JSON.parse(JSON.stringify(s.print)) as typeof s.print), footer: s.print.footer ?? '' }));
const toast = useToast();
const tenant = useTenantStore();

const sizeCards: { value: InvoiceSize; label: string; hint: string }[] = [
    { value: 'k80', label: 'K80', hint: 'Máy in nhiệt 80 mm' },
    { value: 'k57', label: 'K57', hint: 'Máy in nhiệt 57 mm' },
    { value: 'a5', label: 'A5', hint: 'Nửa tờ A4, mẫu tiêu chuẩn' },
    { value: 'a4', label: 'A4', hint: 'Giấy văn phòng' },
];

/* Preview: the real printed receipt (same HTML as when printing) for a made-up sale, with the settings on screen. */
const sample = sampleReceipt();
const shop = computed(() => {
    const s = store.data;
    if (!s || !form.value) return { name: tenant.current?.name ?? '' };
    return printShopFrom({ ...s, print: { ...s.print, ...form.value, footer: form.value.footer || null } }, tenant.current?.name ?? '');
});
const size = computed<InvoiceSize>(() => form.value?.defaults.sale ?? 'k80');
const previewHtml = computed(() => invoiceHtml(sample, shop.value, size.value));
const roll = computed(() => size.value === 'k80' || size.value === 'k57');
// Paper width on screen; sheets are scaled down to fit the column.
const baseWidth = computed(() => ({ k80: 300, k57: 230, a5: 794, a4: 794 })[size.value]);
const contentWidth = ref(0);
const frameWidth = computed(() => Math.max(baseWidth.value, contentWidth.value));
const scale = computed(() => (roll.value ? 1 : 290 / frameWidth.value));
const frameHeight = ref(600);
function fit(e: Event) {
    const doc = (e.target as HTMLIFrameElement).contentDocument;
    const measure = () => {
        if (!doc) return;
        // Sheets: as wide as the printed content (tables can pass the page width on screen).
        contentWidth.value = roll.value ? 0 : doc.documentElement.scrollWidth + 16;
        frameHeight.value = Math.max(doc.documentElement.scrollHeight, 200);
    };
    measure();
    // The logo and QR come in a bit later.
    setTimeout(measure, 700);
}
const printTest = () => printInvoice(sample, { ...shop.value, copies: 1 }, size.value);

/* Chữ in thêm lên phiếu sàn */
const variables = ['ten_khach', 'ma_don', 'tong_tien', 'ngay_dat', 'ten_shop'];
const sizes = [
    { value: 'sm', label: 'Nhỏ' },
    { value: 'md', label: 'Vừa' },
    { value: 'lg', label: 'Lớn' },
] as const;
const shops = ref<{ id: string; shop_name: string; marketplace: string }[]>([]);
onMounted(async () => {
    try {
        const { data } = await api.get('/marketplace-accounts');
        shops.value = (data.data as { id: string; shop_name: string; marketplace: string }[]).filter((a) => a.marketplace === 'shopee' || a.marketplace === 'tiktok');
    } catch {
        /* no access to the shop list: "Tất cả shop" only */
    }
});
const extras = computed(() => form.value!.label_extras);
function insertVar(v: string) {
    const e = extras.value;
    e.notice_text = `${e.notice_text ?? ''}{${v}}`;
}
function toggleShop(id: string) {
    const e = extras.value;
    const list = e.accounts ?? [];
    e.accounts = list.includes(id) ? list.filter((x) => x !== id) : [...list, id];
}
const previewing = ref(false);
async function preview() {
    previewing.value = true;
    // Opened right away (still the click), filled once the PDF is ready: a window opened later is often blocked.
    const tab = window.open('', '_blank');
    try {
        const { sampleLabel, sampleExtras } = await import('../orders/labelSample');
        const { stampLabel } = await import('../orders/labelExtras');
        const bytes = await stampLabel(await sampleLabel(), sampleExtras(extras.value));
        const url = URL.createObjectURL(new Blob([bytes as BlobPart], { type: 'application/pdf' }));
        if (tab) tab.location.href = url;
        else window.open(url, '_blank');
    } catch {
        tab?.close();
        toast.error('Không tạo được phiếu mẫu.');
    } finally {
        previewing.value = false;
    }
}
</script>

<template>
    <SettingsPage title="Mẫu in" :saving="saving" :dirty="dirty" :can-save="canSave" @save="save" @cancel="cancel">
        <template v-if="form">
            <SettingsSection title="Khổ in hóa đơn bán hàng" description="Chọn sẵn khi in, cho mọi nhân viên và mọi máy. Khi in vẫn đổi được." :icon="FileText">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4" data-testid="print-default-sale">
                    <button
                        v-for="s in sizeCards"
                        :key="s.value"
                        type="button"
                        :aria-pressed="form.defaults.sale === s.value"
                        :class="
                            cn(
                                'relative rounded-[12px] border px-4 py-3.5 text-left transition-colors',
                                form.defaults.sale === s.value ? 'border-blue-600 bg-blue-50/50 ring-1 ring-blue-600' : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50',
                            )
                        "
                        @click="form.defaults.sale = s.value"
                    >
                        <span v-if="form.defaults.sale === s.value" class="absolute right-2.5 top-2.5 grid size-5 place-items-center rounded-full bg-blue-600 text-white"><Check class="size-3" :stroke-width="3" /></span>
                        <span class="block text-[17px] font-semibold text-slate-900">{{ s.label }}</span>
                        <span class="mt-0.5 block text-[13px] text-slate-500">{{ s.hint }}</span>
                    </button>
                </div>
            </SettingsSection>
            <SettingsSection title="Nội dung trên phiếu" description="Lấy từ Thông tin cửa hàng và Thanh toán." :icon="LayoutList" tone="green">
                <div class="divide-y divide-slate-100">
                    <Switch v-model="form.show_logo" label="Logo và thông tin cửa hàng" hint="Logo, địa chỉ, số điện thoại, mã số thuế ở đầu phiếu" />
                    <Switch v-model="form.show_qr" label="Mã VietQR khi còn phải trả" hint="Tài khoản ngân hàng mặc định, có sẵn số tiền còn phải trả và mã đơn" data-testid="print-qr" />
                    <Switch v-model="form.show_signatures" label="Chữ ký người mua, người bán" hint="Chỉ in ở khổ A4, A5" />
                </div>
                <FormField label="Lời chào cuối phiếu" for="pr-footer" class="mt-2"><Input id="pr-footer" v-model="form.footer" maxlength="255" placeholder="Cảm ơn quý khách!" data-testid="print-footer" /></FormField>
            </SettingsSection>
            <SettingsSection
                :icon="Tag"
                tone="violet"
                title="Chữ in thêm lên phiếu sàn"
                description="Phiếu Shopee, TikTok giữ nguyên mẫu của sàn. ERP chỉ in thêm chữ vào chỗ trống, không che mã vạch, mã vận đơn. Chữ dài quá chỗ trống sẽ được thu nhỏ hoặc rút gọn."
            >
                <div class="divide-y" data-testid="label-extras">
                    <Switch v-model="form.label_extras.picklist" label="Danh sách soạn hàng theo SKU" hint="Mã SKU, tên, số lượng, có ô đánh dấu khi soạn." data-testid="lx-picklist" />
                    <Switch v-model="form.label_extras.note" label="Ghi chú đơn" hint="Lời nhắn của khách và ghi chú nội bộ mới nhất." data-testid="lx-note" />
                    <Switch v-model="form.label_extras.warehouse" label="Kho xuất hàng" hint="Ví dụ: Kho: Kho chính." data-testid="lx-warehouse" />
                    <div class="py-1">
                        <Switch v-model="form.label_extras.notice" label="Thông báo cho khách" hint="Ví dụ: quay video khi khui hàng. Dòng đầu in đậm." data-testid="lx-notice" />
                        <div v-if="form.label_extras.notice" class="mb-3 ml-12 space-y-2">
                            <textarea
                                v-model="form.label_extras.notice_text"
                                rows="3"
                                maxlength="300"
                                class="w-full rounded-md border bg-background px-3 py-2 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                data-testid="lx-notice-text"
                            />
                            <div class="flex flex-wrap gap-1.5">
                                <button v-for="v in variables" :key="v" type="button" class="rounded bg-muted px-2 py-0.5 font-mono text-xs hover:bg-muted/70" @click="insertVar(v)">{{ '{' + v + '}' }}</button>
                            </div>
                            <label class="flex items-center gap-2 text-sm"><input v-model="form.label_extras.notice_box" type="checkbox" class="size-4" /> Đóng khung, có biểu tượng máy quay cho nổi bật</label>
                        </div>
                    </div>
                </div>
                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <FormField label="Cỡ chữ">
                        <div class="flex overflow-hidden rounded-md border" data-testid="lx-size">
                            <button
                                v-for="z in sizes"
                                :key="z.value"
                                type="button"
                                :class="cn('flex-1 px-3 py-1.5 text-sm', form.label_extras.size === z.value ? 'bg-primary text-primary-foreground' : 'hover:bg-muted/40')"
                                @click="form.label_extras.size = z.value"
                            >
                                {{ z.label }}
                            </button>
                        </div>
                    </FormField>
                    <FormField label="Áp dụng cho">
                        <div class="flex overflow-hidden rounded-md border">
                            <button type="button" :class="cn('flex-1 px-3 py-1.5 text-sm', form.label_extras.accounts === null ? 'bg-primary text-primary-foreground' : 'hover:bg-muted/40')" @click="form.label_extras.accounts = null">Tất cả shop</button>
                            <button
                                type="button"
                                :disabled="!shops.length"
                                :class="cn('flex-1 px-3 py-1.5 text-sm disabled:opacity-50', form.label_extras.accounts !== null ? 'bg-primary text-primary-foreground' : 'hover:bg-muted/40')"
                                @click="form.label_extras.accounts ??= []"
                            >
                                Chọn shop
                            </button>
                        </div>
                    </FormField>
                </div>
                <div v-if="form.label_extras.accounts !== null" class="mt-2 space-y-1.5 text-sm">
                    <label v-for="a in shops" :key="a.id" class="flex items-center gap-2">
                        <input type="checkbox" class="size-4" :checked="form.label_extras.accounts.includes(a.id)" @change="toggleShop(a.id)" />
                        {{ a.shop_name }} <span class="text-xs text-muted-foreground">{{ a.marketplace === 'shopee' ? 'Shopee' : 'TikTok' }}</span>
                    </label>
                </div>
                <div class="mt-4 flex justify-end">
                    <Button variant="outline" :loading="previewing" data-testid="lx-preview" @click="preview"><Eye /> Xem thử trên phiếu mẫu</Button>
                </div>
            </SettingsSection>
        </template>
        <template #aside>
            <div v-if="form" class="rounded-[14px] border border-slate-200/80 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.04)]" data-testid="print-preview">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-[12px] font-semibold uppercase tracking-wider text-slate-400">Xem trước · {{ size.toUpperCase() }}</p>
                    <button type="button" class="flex items-center gap-1.5 rounded-[10px] border border-slate-200 px-3 py-1.5 text-[13px] font-medium text-slate-700 hover:bg-slate-50" data-testid="print-test" @click="printTest">
                        <Printer class="size-4" /> In thử
                    </button>
                </div>
                <div class="mt-4 overflow-hidden rounded-[10px] bg-slate-100 p-3">
                    <div class="mx-auto overflow-hidden bg-white shadow-md" :style="{ width: `${frameWidth * scale}px`, height: `${frameHeight * scale}px` }">
                        <iframe
                            :srcdoc="previewHtml"
                            title="Xem trước phiếu in"
                            class="pointer-events-none origin-top-left border-0"
                            :style="{ width: `${frameWidth}px`, height: `${frameHeight}px`, transform: `scale(${scale})` }"
                            tabindex="-1"
                            @load="fit"
                        />
                    </div>
                </div>
                <p class="mt-3 text-[13px] text-slate-500">Đơn ví dụ, in đúng như phiếu thật. Đổi bên trái là phiếu cập nhật ngay.</p>
            </div>
        </template>
    </SettingsPage>
</template>
