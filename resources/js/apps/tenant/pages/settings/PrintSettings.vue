<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Eye } from 'lucide-vue-next';
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
import { invoiceSizes } from '../orders/invoice';

/** Mẫu in: the business-wide default template for sales invoices and what is printed on them. */
const { form, dirty, saving, canSave, save, cancel } = useSettingsForm('print', (s) => ({ ...(JSON.parse(JSON.stringify(s.print)) as typeof s.print), footer: s.print.footer ?? '' }));
const toast = useToast();

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
            <SettingsSection title="Khổ in hóa đơn bán hàng" description="Chọn sẵn khi in hóa đơn, cho mọi nhân viên và mọi máy. Khi in vẫn đổi được khổ khác.">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4" data-testid="print-default-sale">
                    <button
                        v-for="s in invoiceSizes"
                        :key="s.value"
                        type="button"
                        :class="cn('rounded-md border px-3 py-3 text-left text-sm hover:bg-muted/40', form.defaults.sale === s.value && 'border-blue-300 bg-blue-50/70')"
                        @click="form.defaults.sale = s.value"
                    >
                        <span class="block font-medium">{{ s.label }}</span>
                        <span class="block text-xs text-muted-foreground">{{ s.hint }}</span>
                    </button>
                </div>
            </SettingsSection>
            <SettingsSection title="Nội dung trên phiếu" description="Lấy từ Thông tin cửa hàng và Thanh toán.">
                <div class="divide-y">
                    <Switch v-model="form.show_logo" label="In logo, địa chỉ, số điện thoại, mã số thuế ở đầu phiếu" />
                    <Switch v-model="form.show_qr" label="In mã VietQR khi hóa đơn còn phải trả" hint="Dùng tài khoản ngân hàng mặc định; mã có sẵn số tiền còn phải trả và mã đơn." data-testid="print-qr" />
                    <Switch v-model="form.show_signatures" label="In ô chữ ký người mua, người bán (khổ A4, A5)" />
                </div>
                <FormField label="Lời chào cuối phiếu" for="pr-footer" class="mt-3"><Input id="pr-footer" v-model="form.footer" maxlength="255" placeholder="Cảm ơn quý khách!" data-testid="print-footer" /></FormField>
            </SettingsSection>
            <SettingsSection
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
    </SettingsPage>
</template>
