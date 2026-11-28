<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { CircleCheck } from 'lucide-vue-next';
import Alert from '@/shared/ui/Alert.vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatNumber } from '@/shared/lib/utils';
import { providerNames, type EInvoiceCompany, type EInvoiceOrder, type EInvoicePackage } from './einvoice';

/**
 * "Mua gói hóa đơn điện tử" (mẫu 3): pick a package, the remote signing, the shop's company; the order then waits for
 * the bank transfer with its own code. The shop stays the seller on its invoices.
 */
const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{ packages: EInvoicePackage[]; defaults?: Partial<EInvoiceCompany> }>();
const emit = defineEmits<{ ordered: [order: EInvoiceOrder] }>();

const pick = ref('');
const signing = ref(true);
const company = ref<EInvoiceCompany & { address: string }>({ type: 'company', tax_code: '', name: '', address: '', representative: '', email: '', phone: '' });
const errors = ref<Record<string, string>>({});
const error = ref<string | null>(null);
const busy = ref(false);

watch(
    open,
    (v) => {
        if (!v) return;
        pick.value = (props.packages.find((p) => p.featured) ?? props.packages[0])?.id ?? '';
        company.value = { type: 'company', tax_code: '', name: '', representative: '', email: '', phone: '', ...(props.defaults ?? {}), address: props.defaults?.address ?? '' };
        signing.value = true;
        errors.value = {};
        error.value = null;
    },
    { immediate: true },
);

const pkg = computed(() => props.packages.find((p) => p.id === pick.value) ?? null);
const canSign = computed(() => pkg.value?.signing_price !== null && pkg.value?.signing_price !== undefined);
const withSigning = computed(() => canSign.value && signing.value);
const providers = computed(() => [...new Set(props.packages.map((p) => providerNames[p.provider] ?? p.provider))].join(', '));
const total = computed(() => (pkg.value ? Number(pkg.value.price) + Number(pkg.value.setup_fee) + (withSigning.value ? Number(pkg.value.signing_price) : 0) : 0));
const money = (v: number | string) => `${formatNumber(v)}đ`;
const perInvoice = (p: EInvoicePackage) => money(Math.round(Number(p.price) / Math.max(1, p.invoices)));

async function submit() {
    if (!pkg.value) return;
    busy.value = true;
    errors.value = {};
    error.value = null;
    try {
        const { data } = await api.post('/einvoice/orders', { package: pkg.value.id, signing: withSigning.value, company: company.value });
        emit('ordered', data.data);
        open.value = false;
    } catch (e) {
        const err = e as ApiError;
        const fields = ['type', 'tax_code', 'name', 'address', 'representative', 'email', 'phone'];
        for (const f of fields) {
            const m = err.field(`company.${f}`);
            if (m) errors.value[f] = m;
        }
        error.value = err.field('package') ?? (Object.keys(errors.value).length ? null : err.message);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open" title="Mua gói hóa đơn điện tử" description="Shop vẫn là người bán đứng tên trên hóa đơn. Bên mình lo đăng ký và kết nối." size="2xl">
        <form class="grid gap-6 lg:grid-cols-[1fr_330px]" data-testid="einvoice-buy" @submit.prevent="submit">
            <div class="space-y-6">
                <section>
                    <p class="text-[15px] font-semibold text-slate-900">1. Chọn gói hóa đơn <span class="text-[13px] font-normal text-slate-500">· nhà cung cấp: {{ providers }}</span></p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <button
                            v-for="p in packages"
                            :key="p.id"
                            type="button"
                            :class="cn('relative rounded-[12px] border p-4 text-left transition-colors', pick === p.id ? 'border-blue-500 bg-blue-50/60 ring-1 ring-blue-200' : 'border-slate-200 hover:border-slate-300')"
                            :data-testid="`einvoice-package-${p.invoices}`"
                            @click="pick = p.id"
                        >
                            <span v-if="p.featured" class="absolute -top-2.5 left-4 rounded-md bg-amber-400 px-2 py-0.5 text-[12px] font-semibold text-slate-900">Shop nhỏ hay chọn</span>
                            <span class="flex items-start justify-between gap-2">
                                <span>
                                    <span class="block text-[22px] font-bold text-slate-900">{{ formatNumber(p.invoices) }}</span>
                                    <span class="block text-[13px] text-slate-500">hóa đơn</span>
                                </span>
                                <CircleCheck v-if="pick === p.id" class="size-5 text-blue-600" />
                                <span v-else class="size-5 rounded-full border border-slate-300" />
                            </span>
                            <span class="mt-3 block text-[18px] font-bold text-slate-900">{{ money(p.price) }}</span>
                            <span class="mt-0.5 block text-[13px] text-slate-500">≈ {{ perInvoice(p) }}/hóa đơn{{ p.note ? ` · ${p.note}` : '' }}</span>
                        </button>
                    </div>
                </section>

                <section v-if="canSign && pkg">
                    <p class="text-[15px] font-semibold text-slate-900">2. Chữ ký số</p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <label :class="cn('flex cursor-pointer gap-3 rounded-[12px] border p-4', signing ? 'border-blue-500 bg-blue-50/60' : 'border-slate-200')">
                            <input v-model="signing" type="radio" :value="true" class="mt-1" data-testid="einvoice-signing-yes" />
                            <span class="text-[14px]">
                                <span class="block font-medium text-slate-900">Mua kèm ký số từ xa (1 năm)</span>
                                <span class="block text-[13px] text-slate-500">Ký trên máy chủ, không cần USB token. +{{ money(pkg.signing_price!) }}</span>
                            </span>
                        </label>
                        <label :class="cn('flex cursor-pointer gap-3 rounded-[12px] border p-4', !signing ? 'border-blue-500 bg-blue-50/60' : 'border-slate-200')">
                            <input v-model="signing" type="radio" :value="false" class="mt-1" data-testid="einvoice-signing-no" />
                            <span class="text-[14px]">
                                <span class="block font-medium text-slate-900">Shop đã có chữ ký số</span>
                                <span class="block text-[13px] text-slate-500">Dùng USB token hoặc ký số từ xa đang có</span>
                            </span>
                        </label>
                    </div>
                </section>

                <section>
                    <p class="text-[15px] font-semibold text-slate-900">{{ canSign ? 3 : 2 }}. Thông tin đơn vị bán</p>
                    <div class="mt-3 grid gap-x-4 gap-y-3 sm:grid-cols-2">
                        <label class="block space-y-1 text-sm">
                            <span class="text-slate-600">Mã số thuế</span>
                            <Input v-model="company.tax_code" inputmode="numeric" placeholder="0312345678" data-testid="einvoice-company-tax_code" />
                            <span v-if="errors.tax_code" class="block text-xs text-rose-600">{{ errors.tax_code }}</span>
                        </label>
                        <label class="block space-y-1 text-sm">
                            <span class="text-slate-600">Loại</span>
                            <Select
                                v-model="company.type"
                                :options="[
                                    { value: 'company', label: 'Công ty (hóa đơn GTGT)' },
                                    { value: 'household', label: 'Hộ kinh doanh (hóa đơn bán hàng)' },
                                ]"
                            />
                        </label>
                        <label class="block space-y-1 text-sm">
                            <span class="text-slate-600">Tên đơn vị</span>
                            <Input v-model="company.name" placeholder="CÔNG TY TNHH ..." data-testid="einvoice-company-name" />
                            <span v-if="errors.name" class="block text-xs text-rose-600">{{ errors.name }}</span>
                        </label>
                        <label class="block space-y-1 text-sm">
                            <span class="text-slate-600">Người đại diện</span>
                            <Input v-model="company.representative" data-testid="einvoice-company-representative" />
                            <span v-if="errors.representative" class="block text-xs text-rose-600">{{ errors.representative }}</span>
                        </label>
                        <label class="block space-y-1 text-sm sm:col-span-2">
                            <span class="text-slate-600">Địa chỉ đăng ký kinh doanh</span>
                            <Input v-model="company.address" data-testid="einvoice-company-address" />
                        </label>
                        <label class="block space-y-1 text-sm">
                            <span class="text-slate-600">Email nhận hồ sơ</span>
                            <Input v-model="company.email" type="email" data-testid="einvoice-company-email" />
                            <span v-if="errors.email" class="block text-xs text-rose-600">{{ errors.email }}</span>
                        </label>
                        <label class="block space-y-1 text-sm">
                            <span class="text-slate-600">Số điện thoại</span>
                            <Input v-model="company.phone" inputmode="tel" data-testid="einvoice-company-phone" />
                            <span v-if="errors.phone" class="block text-xs text-rose-600">{{ errors.phone }}</span>
                        </label>
                    </div>
                </section>
            </div>

            <aside class="h-fit space-y-4 rounded-[14px] border border-slate-200 p-5 lg:sticky lg:top-0">
                <p class="text-[16px] font-semibold text-slate-900">Tóm tắt</p>
                <dl v-if="pkg" class="space-y-2 text-[14px]">
                    <div class="flex justify-between"><dt class="text-slate-500">Gói {{ formatNumber(pkg.invoices) }} hóa đơn</dt><dd>{{ money(pkg.price) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Phí khởi tạo</dt><dd>{{ money(pkg.setup_fee) }}</dd></div>
                    <div v-if="withSigning" class="flex justify-between"><dt class="text-slate-500">Ký số từ xa 1 năm</dt><dd>{{ money(pkg.signing_price!) }}</dd></div>
                    <div class="flex justify-between border-t pt-3 text-[16px] font-semibold"><dt>Tổng (đã gồm VAT)</dt><dd class="text-blue-600" data-testid="einvoice-buy-total">{{ money(total) }}</dd></div>
                </dl>
                <div class="rounded-[10px] bg-slate-50 p-3 text-[13px] text-slate-600">
                    <p class="font-medium text-slate-800">Sau khi chuyển khoản</p>
                    <ol class="mt-1 list-decimal space-y-0.5 pl-4">
                        <li>Bên mình gửi hồ sơ cho nhà cung cấp, họ tạo tài khoản.</li>
                        <li>Shop ký tờ khai 01/ĐKTĐ-HĐĐT gửi thuế (bên mình làm sẵn, shop chỉ ký).</li>
                        <li>Thuế chấp nhận thì hóa đơn tự kết nối vào đây.</li>
                    </ol>
                </div>
                <Alert v-if="error" tone="red">{{ error }}</Alert>
                <Button type="submit" class="h-11 w-full text-[15px]" :loading="busy" :disabled="!pkg || busy" data-testid="einvoice-buy-submit">Đặt mua {{ money(total) }}</Button>
                <p class="text-center text-[12px] text-slate-500">Chuyển khoản theo mã đơn ở bước sau. Chưa chuyển thì hủy được.</p>
            </aside>
        </form>
    </Dialog>
</template>
