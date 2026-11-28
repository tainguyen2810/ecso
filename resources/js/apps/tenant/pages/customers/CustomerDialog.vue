<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { ChevronRight, Info, MapPin, ShoppingBag, UserRound, X } from 'lucide-vue-next';
import SegmentedControl from '@/shared/ui/SegmentedControl.vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import AddressPicker from '../../components/AddressPicker.vue';
import { emptyAddress, type AddressParts } from '@/shared/lib/geo';
import { customerSources, customerTypes, priceTierLabels } from './labels';
import { cn } from '@/shared/lib/utils';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import { api, ApiError } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';
import type { Customer, CustomerGroup } from '@/shared/types';
import DateInput from '@/shared/ui/DateInput.vue';

const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{ customer?: Customer | null }>();
const emit = defineEmits<{ saved: [customer: Customer] }>();
const toast = useToast();

const empty = { name: '', code: '', phone: '', email: '', address: '', group_id: '', assigned_user_id: '', birthday: '', gender: '', tax_code: '', status: 'active', note: '', payment_terms_days: '', credit_limit: '', type: 'individual', source: '', invoice_name: '', invoice_address: '', invoice_email: '' };
const form = reactive({ ...empty });
// Standard address (province → ward → street); an old free-text address stays until one is picked.
const addr = ref<AddressParts>(emptyAddress());
const legacy = ref<string | null>(null);
const tags = ref<string[]>([]);
const tagInput = ref('');
const groups = ref<CustomerGroup[]>([]);
const staff = ref<{ id: string; name: string }[]>([]);
const knownTags = ref<string[]>([]);
const loaded = ref(false);
const saving = ref(false);
const error = ref<ApiError | null>(null);
const more = ref(false);
const typeOptions = Object.entries(customerTypes).map(([value, label]) => ({ value, label }));
/** "Giá sỉ · chiết khấu 3%" under the group picker, so staff see what the group gives. */
const groupHint = computed(() => {
    const g = groups.value.find((x) => x.id === form.group_id);
    if (!g) return 'Nhóm quyết định giá áp dụng và chiết khấu';
    return [priceTierLabels[g.price_tier], Number(g.discount_percent) ? `chiết khấu ${Number(g.discount_percent)}%` : ''].filter(Boolean).join(' · ');
});

watch(open, async (v) => {
    if (!v) return;
    error.value = null;
    const c = props.customer;
    Object.assign(
        form,
        empty,
        c
            ? {
                  name: c.name, code: c.code, phone: c.phone ?? '', email: c.email ?? '', address: c.address ?? '', group_id: c.group?.id ?? '',
                  assigned_user_id: c.assignee?.id ?? '', birthday: c.birthday ?? '', gender: c.gender ?? '', tax_code: c.tax_code ?? '', status: c.status, note: c.note ?? '',
                  payment_terms_days: c.payment_terms_days != null ? String(c.payment_terms_days) : '', credit_limit: c.credit_limit != null ? String(Number(c.credit_limit)) : '',
                  type: c.type ?? 'individual', source: c.source ?? '', invoice_name: c.invoice?.name ?? '', invoice_address: c.invoice?.address ?? '', invoice_email: c.invoice?.email ?? '',
              }
            : {},
    );
    addr.value = c?.province_code ? { street: c.street ?? '', province_code: c.province_code, ward_code: c.ward_code ?? '' } : emptyAddress();
    legacy.value = c && !c.province_code ? c.address || null : null;
    tags.value = [...(c?.tags ?? [])];
    tagInput.value = '';
    more.value = !!(form.birthday || form.gender || form.source || form.tax_code || form.invoice_name || form.note || tags.value.length || form.type === 'company');
    if (!loaded.value) {
        const [g, s, t] = await Promise.all([api.get('/customer-groups'), api.get('/customers/staff'), api.get('/customers/tags')]);
        groups.value = g.data.data;
        staff.value = s.data.data;
        knownTags.value = t.data.data;
        loaded.value = true;
    }
});

function addTag(raw = tagInput.value) {
    for (const t of raw.split(',').map((s) => s.trim()).filter(Boolean)) {
        if (!tags.value.some((x) => x.toLowerCase() === t.toLowerCase()) && tags.value.length < 20) tags.value.push(t.slice(0, 40));
    }
    tagInput.value = '';
}

async function save(again = false) {
    addTag();
    saving.value = true;
    error.value = null;
    const body: Record<string, unknown> = { ...Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v])), tags: tags.value };
    if (!props.customer) delete body.status;
    if (addr.value.province_code) {
        delete body.address;
        Object.assign(body, { street: addr.value.street || null, province_code: addr.value.province_code, ward_code: addr.value.ward_code || null });
    }
    try {
        const { data } = props.customer ? await api.put(`/customers/${props.customer.id}`, body) : await api.post('/customers', body);
        toast.success(props.customer ? 'Đã lưu khách hàng.' : `Đã thêm khách ${data.data.code}.`);
        emit('saved', data.data);
        if (again && !props.customer) {
            // "Lưu và thêm khách khác": keep the dialog open on an empty form.
            Object.assign(form, empty);
            addr.value = emptyAddress();
            tags.value = [];
            document.getElementById('c-name')?.focus();
        } else {
            open.value = false;
        }
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open" size="lg" :title="customer ? 'Sửa khách hàng' : 'Thêm khách hàng'" description="Số điện thoại và địa chỉ được mã hóa khi lưu. Mỗi số điện thoại chỉ thuộc một khách.">
        <form id="customer-form" class="divide-y divide-slate-100" @submit.prevent="save()">
            <section class="pb-5">
                <h3 class="mb-3.5 flex items-center gap-2.5 text-[14px] font-semibold text-slate-900"><span class="grid size-7 place-items-center rounded-[8px] bg-slate-100 text-slate-500"><UserRound class="size-4" /></span>Thông tin cơ bản</h3>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Loại khách" class="sm:col-span-2" :error="error?.field('type')">
                        <SegmentedControl v-model="form.type" :options="typeOptions" full testid="c-type" />
                    </FormField>
                    <FormField label="Tên khách" for="c-name" required :error="error?.field('name')"><Input id="c-name" v-model="form.name" required :invalid="!!error?.field('name')" data-testid="c-name" /></FormField>
                    <FormField label="Mã khách" for="c-code" :optional="!customer" :error="error?.field('code')"><Input id="c-code" v-model="form.code" :placeholder="customer ? '' : 'Tự đánh mã KH-…'" data-testid="c-code" /></FormField>
                    <FormField label="Số điện thoại" for="c-phone" :error="error?.field('phone')"><Input id="c-phone" v-model="form.phone" inputmode="tel" :invalid="!!error?.field('phone')" data-testid="c-phone" /></FormField>
                    <FormField label="Email" for="c-email" :error="error?.field('email')"><Input id="c-email" v-model="form.email" type="email" placeholder="ten@example.com" :invalid="!!error?.field('email')" /></FormField>
                </div>
            </section>
            <section class="py-5">
                <h3 class="mb-3.5 flex items-center gap-2.5 text-[14px] font-semibold text-slate-900"><span class="grid size-7 place-items-center rounded-[8px] bg-slate-100 text-slate-500"><MapPin class="size-4" /></span>Địa chỉ</h3>
                <AddressPicker
                    v-model="addr"
                    id-prefix="c-addr"
                    :columns="2"
                    :quick="!customer || !addr.ward_code"
                    :legacy="legacy"
                    :errors="{ street: error?.field('street'), province_code: error?.field('province_code'), ward_code: error?.field('ward_code') }"
                />
                <p v-if="error?.field('address')" class="mt-1.5 text-[12.5px] text-red-600">{{ error.field('address') }}</p>
            </section>
            <section class="py-5">
                <h3 class="mb-3.5 flex items-center gap-2.5 text-[14px] font-semibold text-slate-900"><span class="grid size-7 place-items-center rounded-[8px] bg-slate-100 text-slate-500"><ShoppingBag class="size-4" /></span>Mua hàng và công nợ</h3>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Nhóm khách hàng" for="c-group" :error="error?.field('group_id')" :hint="groupHint">
                        <Select id="c-group" v-model="form.group_id" data-testid="c-group" :options="[{ value: '', label: 'Chưa xếp nhóm' }, ...groups.filter((g) => g.is_active !== false || g.id === props.customer?.group?.id).map((g) => ({ value: g.id, label: g.is_active === false ? `${g.name} (ngừng sử dụng)` : g.name }))]" />
                    </FormField>
                    <FormField label="Nhân viên phụ trách" for="c-staff" :error="error?.field('assigned_user_id')">
                        <Select id="c-staff" v-model="form.assigned_user_id" :options="[{ value: '', label: 'Chưa giao' }, ...staff.map((s) => ({ value: s.id, label: s.name }))]" />
                    </FormField>
                    <FormField label="Hạn thanh toán" for="c-terms" :error="error?.field('payment_terms_days')" hint="Để trống thì theo nhóm khách"><Input id="c-terms" v-model="form.payment_terms_days" type="number" min="0" max="365" suffix="ngày" data-testid="c-terms" /></FormField>
                    <FormField label="Hạn mức nợ" for="c-credit" :error="error?.field('credit_limit')" hint="Để trống thì theo nhóm khách"><Input id="c-credit" v-model="form.credit_limit" type="number" min="0" step="any" suffix="₫" data-testid="c-credit" /></FormField>
                    <FormField v-if="customer" label="Trạng thái" class="sm:col-span-2">
                        <SegmentedControl v-model="form.status" full :options="[{ value: 'active', label: 'Đang giao dịch' }, { value: 'inactive', label: 'Ngừng giao dịch' }]" testid="c-status" />
                    </FormField>
                </div>
            </section>
            <section class="pt-5">
                <button type="button" class="flex w-full items-center justify-between gap-3 rounded-[12px] border border-slate-200 px-4 py-3 text-left hover:bg-slate-50" :aria-expanded="more" data-testid="c-more" @click="more = !more">
                    <span class="flex items-center gap-2.5 text-[14px] font-medium text-slate-700"><ChevronRight :class="cn('size-4 text-slate-400 transition-transform', more && 'rotate-90')" />Thông tin thêm</span>
                    <span class="truncate text-[12.5px] text-slate-400">Ngày sinh, giới tính, nguồn khách, mã số thuế, hóa đơn, tags, ghi chú</span>
                </button>
                <div v-show="more" class="mt-4 grid gap-4 sm:grid-cols-2">
                    <FormField label="Ngày sinh" for="c-birthday" :error="error?.field('birthday')"><DateInput id="c-birthday" v-model="form.birthday" /></FormField>
                    <FormField label="Giới tính" for="c-gender">
                        <Select id="c-gender" v-model="form.gender" :options="[{ value: '', label: 'Không rõ' }, { value: 'male', label: 'Nam' }, { value: 'female', label: 'Nữ' }, { value: 'other', label: 'Khác' }]" />
                    </FormField>
                    <FormField label="Nguồn khách" for="c-source" :error="error?.field('source')">
                        <Select id="c-source" v-model="form.source" :options="[{ value: '', label: 'Không rõ' }, ...Object.entries(customerSources).map(([value, label]) => ({ value, label }))]" />
                    </FormField>
                    <FormField label="Mã số thuế" for="c-tax" :error="error?.field('tax_code')"><Input id="c-tax" v-model="form.tax_code" placeholder="Khi khách cần xuất hóa đơn" /></FormField>
                    <FormField label="Tên đơn vị / người mua trên hóa đơn" for="c-inv-name" class="sm:col-span-2" :error="error?.field('invoice_name')"><Input id="c-inv-name" v-model="form.invoice_name" maxlength="200" data-testid="c-invoice-name" /></FormField>
                    <FormField label="Địa chỉ xuất hóa đơn" for="c-inv-address" :error="error?.field('invoice_address')"><Input id="c-inv-address" v-model="form.invoice_address" maxlength="500" /></FormField>
                    <FormField label="Email nhận hóa đơn" for="c-inv-email" :error="error?.field('invoice_email')"><Input id="c-inv-email" v-model="form.invoice_email" type="email" maxlength="150" /></FormField>
                    <FormField label="Tags" for="c-tags" class="sm:col-span-2" :error="error?.field('tags')">
                        <div class="flex min-h-11 flex-wrap items-center gap-1.5 rounded-[10px] border border-slate-200 bg-white px-2.5 py-1.5 focus-within:border-blue-500 focus-within:ring-4 focus-within:ring-blue-100">
                            <span v-for="(t, i) in tags" :key="t" class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2 py-0.5 text-[13px] text-slate-700">
                                {{ t }}
                                <button type="button" class="text-slate-400 hover:text-slate-700" :aria-label="`Bỏ tag ${t}`" @click="tags.splice(i, 1)"><X class="size-3" /></button>
                            </span>
                            <input
                                id="c-tags"
                                v-model="tagInput"
                                list="customer-tag-suggestions"
                                class="h-8 min-w-32 flex-1 bg-transparent px-1 text-[15px] outline-none placeholder:text-slate-400 [&::-webkit-calendar-picker-indicator]:hidden"
                                placeholder="Gõ tag rồi nhấn Enter"
                                data-testid="c-tags"
                                @keydown.enter.prevent="addTag()"
                                @keydown.,.prevent="addTag()"
                                @blur="addTag()"
                            />
                            <datalist id="customer-tag-suggestions"><option v-for="t in knownTags.filter((k) => !tags.includes(k))" :key="t" :value="t" /></datalist>
                        </div>
                    </FormField>
                    <FormField label="Ghi chú" for="c-note" class="sm:col-span-2"><Input id="c-note" v-model="form.note" /></FormField>
                </div>
            </section>
            <p v-if="error && !error.body.errors" class="pt-4 text-[13px] text-red-600">{{ error.message }}</p>
        </form>
        <template #footer>
            <span class="mr-auto flex items-center gap-1.5 text-[13px] text-slate-500"><Info class="size-3.5" /> Có dấu <span class="text-red-500">*</span> là bắt buộc</span>
            <Button variant="outline" @click="open = false">Hủy</Button>
            <Button v-if="!customer" variant="outline" :loading="saving" data-testid="c-save-again" @click="save(true)">Lưu và thêm khách khác</Button>
            <Button type="submit" form="customer-form" :loading="saving" data-testid="c-save">Lưu</Button>
        </template>
    </Dialog>
</template>
