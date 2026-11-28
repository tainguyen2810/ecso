<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Check, CircleCheck, Lightbulb, Unplug } from 'lucide-vue-next';
import Alert from '@/shared/ui/Alert.vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Switch from '@/shared/ui/Switch.vue';
import { api, ApiError } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';
import { cn } from '@/shared/lib/utils';
import { einvoiceLogos, logoUrl } from '../settings/connectionsSummary';
import { issueModes, noSandbox, providerHelp, providerSteps, type EInvoiceAccountData, type EInvoiceSettings, type IssueMode } from './einvoice';

/**
 * "Hóa đơn điện tử": pick the provider, enter the shop's API keys (checked by the provider before saving), then choose
 * the default template/series and when invoices go out. The shop registers its invoice service with the provider first.
 */
const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{ settings: EInvoiceSettings; initial?: string | null; canBuy?: boolean }>();
const emit = defineEmits<{ changed: []; buy: [] }>();
const toast = useToast();

const account = ref<EInvoiceAccountData | null>(null);
const provider = ref('');
const environment = ref<'sandbox' | 'production'>('production');
const creds = ref<Record<string, string>>({});
const template = ref('');
const mode = ref<IssueMode>('manual');
const sendEmail = ref(true);
const error = ref<string | null>(null);
const busy = ref<'connect' | 'save' | 'disconnect' | null>(null);
const editKeys = ref(false);

const available = computed(() => props.settings.providers.filter((p) => p.available));
const current = computed(() => props.settings.providers.find((p) => p.provider === provider.value) ?? null);
const sandboxable = computed(() => !noSandbox.includes(provider.value));
watch(provider, (p) => {
    if (noSandbox.includes(p)) environment.value = 'production';
});
const connected = computed(() => account.value !== null && account.value.status !== 'disconnected' && account.value.provider === provider.value);

function reset() {
    const a = props.settings.account;
    account.value = a && a.status !== 'disconnected' ? a : null;
    provider.value = account.value?.provider ?? (props.initial && available.value.some((p) => p.provider === props.initial) ? props.initial : (available.value.find((p) => p.provider !== 'fake')?.provider ?? available.value[0]?.provider ?? ''));
    environment.value = account.value?.environment ?? 'production';
    template.value = account.value?.template ?? '';
    mode.value = account.value?.issue_mode ?? 'manual';
    sendEmail.value = account.value?.send_email ?? true;
    creds.value = {};
    error.value = null;
    editKeys.value = false;
}
watch(open, (v) => v && reset(), { immediate: true });

const templateOptions = computed(() => [
    ...(template.value ? [] : [{ value: '', label: '— Chọn mẫu số, ký hiệu —' }]),
    ...(account.value?.templates ?? []).map((t) => ({ value: `${t.template_code}|${t.invoice_series}`, label: t.label ?? `${t.template_code} - ${t.invoice_series}` })),
]);

async function connect() {
    busy.value = 'connect';
    error.value = null;
    try {
        const { data } = await api.post('/einvoice/account', { provider: provider.value, environment: environment.value, credentials: creds.value });
        account.value = data.data;
        template.value = data.data.template ?? '';
        creds.value = {};
        editKeys.value = false;
        emit('changed');
    } catch (e) {
        const err = e as ApiError;
        error.value = err.field('credentials') ?? err.field('provider') ?? err.message;
    } finally {
        busy.value = null;
    }
}

async function save() {
    if (!account.value) return;
    if (!template.value) {
        error.value = 'Chọn mẫu số, ký hiệu hóa đơn dùng mặc định.';
        return;
    }
    busy.value = 'save';
    error.value = null;
    try {
        const { data } = await api.put('/einvoice/account', { template: template.value, issue_mode: mode.value, send_email: sendEmail.value });
        account.value = data.data;
        toast.success('Đã lưu cài đặt hóa đơn điện tử');
        emit('changed');
        open.value = false;
    } catch (e) {
        const err = e as ApiError;
        error.value = err.field('template') ?? err.field('issue_mode') ?? err.message;
    } finally {
        busy.value = null;
    }
}

async function disconnect() {
    if (!window.confirm('Ngắt kết nối hóa đơn điện tử? Hóa đơn đã xuất vẫn giữ trên đơn; EzChat Bán hàng không xuất thêm hóa đơn nào.')) return;
    busy.value = 'disconnect';
    try {
        await api.delete('/einvoice/account');
        emit('changed');
        open.value = false;
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <Dialog v-model:open="open" title="Hóa đơn điện tử" description="Shop cần đăng ký dịch vụ hóa đơn với nhà cung cấp trước. EzChat Bán hàng chỉ gửi dữ liệu đơn sang để xuất." size="2xl">
        <div class="grid gap-6 lg:grid-cols-[1fr_320px]">
        <div class="min-w-0 space-y-4" data-testid="einvoice-dialog">
            <div>
                <p class="mb-1.5 text-sm text-muted-foreground">Nhà cung cấp</p>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <button
                        v-for="p in settings.providers"
                        :key="p.provider"
                        type="button"
                        :disabled="!p.available || (account !== null && account.provider !== p.provider)"
                        :title="p.note ?? undefined"
                        :class="cn('flex h-14 items-center gap-2.5 rounded-[12px] border px-3 text-left text-[14px]', provider === p.provider ? 'border-blue-500 bg-blue-50/60 font-medium ring-1 ring-blue-200' : 'border-slate-200 hover:border-slate-300', (!p.available || (account && account.provider !== p.provider)) && 'cursor-not-allowed opacity-50')"
                        :data-testid="`einvoice-pick-${p.provider}`"
                        @click="provider = p.provider"
                    >
                        <img v-if="einvoiceLogos[p.provider]" :src="logoUrl(einvoiceLogos[p.provider])!" alt="" class="h-6 w-14 shrink-0 object-contain" />
                        <span class="min-w-0 flex-1 truncate">{{ einvoiceLogos[p.provider] ? p.label.split(' ')[0] : p.label }}<span v-if="!p.available" class="block text-[11px] font-normal text-muted-foreground">sắp có</span></span>
                        <CircleCheck v-if="provider === p.provider" class="size-4 shrink-0 text-blue-600" />
                    </button>
                </div>
            </div>

            <form v-if="current?.available && (!connected || editKeys)" class="space-y-3" @submit.prevent="connect">
                <div v-if="sandboxable">
                    <p class="mb-1.5 text-sm text-muted-foreground">Môi trường</p>
                    <div class="inline-flex rounded-lg border p-0.5 text-sm">
                        <button v-for="e in [{ v: 'sandbox', l: 'Thử nghiệm' }, { v: 'production', l: 'Chạy thật' }] as const" :key="e.v" type="button" :class="cn('rounded-md px-3 py-1', environment === e.v && 'bg-primary text-primary-foreground')" @click="environment = e.v">{{ e.l }}</button>
                    </div>
                    <p v-if="environment === 'sandbox'" class="mt-1 text-xs text-muted-foreground">Thử nghiệm: hóa đơn tạo ra không có giá trị, dùng để kiểm tra trước khi chạy thật.</p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label v-for="(label, field) in current.fields" :key="field" class="block space-y-1 text-sm">
                        <span class="text-muted-foreground">{{ label }}</span>
                        <Input v-model="creds[field]" :type="String(field).includes('secret') || String(field).includes('password') ? 'password' : 'text'" autocomplete="off" :data-testid="`einvoice-cred-${field}`" />
                    </label>
                </div>
                <p v-if="providerHelp[provider] && !providerSteps[provider]" class="text-xs text-muted-foreground">{{ providerHelp[provider] }}</p>
                <Alert v-if="error" tone="red">{{ error }}</Alert>
                <div class="flex justify-end gap-2">
                    <Button v-if="editKeys" type="button" variant="outline" @click="editKeys = false">Thôi</Button>
                    <Button type="submit" :loading="busy === 'connect'" :disabled="busy !== null" data-testid="einvoice-connect">Kiểm tra và kết nối</Button>
                </div>
            </form>
            <p v-else-if="current && !current.available" class="text-sm text-muted-foreground">{{ current.note }}</p>

            <template v-if="connected && account && !editKeys">
                <div :class="cn('flex flex-wrap items-center gap-2 rounded-lg px-3 py-2 text-sm', account.status === 'connected' ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-700')" data-testid="einvoice-status">
                    <Check v-if="account.status === 'connected'" class="size-4" />
                    <span v-if="account.status === 'connected'">Đã kết nối{{ account.provider_name ? ` ${account.provider_name}` : '' }}{{ account.environment === 'sandbox' ? ' (thử nghiệm)' : '' }}. Lấy được {{ account.templates.length }} mẫu hóa đơn.</span>
                    <span v-else>{{ account.last_error ?? 'Kết nối đang lỗi' }}</span>
                    <button type="button" class="ml-auto text-xs underline" @click="editKeys = true">Đổi khóa API</button>
                </div>
                <label class="block space-y-1 text-sm">
                    <span class="text-muted-foreground">Mẫu số · ký hiệu dùng mặc định</span>
                    <Select v-model="template" :options="templateOptions" data-testid="einvoice-template" />
                </label>
                <div>
                    <p class="mb-1 text-sm text-muted-foreground">Xuất hóa đơn khi nào</p>
                    <label v-for="m in issueModes" :key="m.value" class="flex cursor-pointer items-start gap-2 py-1.5 text-sm">
                        <input v-model="mode" type="radio" :value="m.value" class="mt-1" :data-testid="`einvoice-mode-${m.value}`" />
                        <span>{{ m.label }}<span class="block text-xs text-muted-foreground">{{ m.hint }}</span></span>
                    </label>
                </div>
                <Switch v-model="sendEmail" label="Gửi hóa đơn qua email cho khách nếu có email" />
                <Alert v-if="error" tone="red">{{ error }}</Alert>
                <div class="flex flex-wrap items-center gap-2 border-t pt-3">
                    <Button type="button" variant="ghost" class="text-rose-600" :loading="busy === 'disconnect'" data-testid="einvoice-disconnect" @click="disconnect"><Unplug /> Ngắt kết nối</Button>
                    <span class="flex-1" />
                    <Button type="button" variant="outline" @click="open = false">Hủy</Button>
                    <Button type="button" :loading="busy === 'save'" :disabled="busy !== null" data-testid="einvoice-save" @click="save">Lưu</Button>
                </div>
            </template>
            <p v-if="canBuy" class="border-t pt-3 text-[14px] text-slate-500">
                Chưa có tài khoản? <button type="button" class="font-medium text-blue-600 hover:underline" data-testid="einvoice-dialog-buy" @click="emit('buy')">Mua gói hóa đơn</button>
            </p>
        </div>
        <aside v-if="providerSteps[provider]" class="h-fit rounded-[14px] bg-blue-50/70 p-5" data-testid="einvoice-help">
            <p class="flex items-center gap-2 text-[15px] font-semibold text-blue-700"><Lightbulb class="size-4" /> {{ providerSteps[provider].title }}</p>
            <ol class="mt-3 space-y-3">
                <li v-for="(step, i) in providerSteps[provider].steps" :key="i" class="flex gap-3 text-[14px] text-slate-700">
                    <span class="grid size-6 shrink-0 place-items-center rounded-full border border-blue-200 bg-white text-[12px] font-semibold text-blue-700">{{ i + 1 }}</span>
                    <!-- eslint-disable-next-line vue/no-v-html -- fixed help text from einvoice.ts, no user input -->
                    <span v-html="step" />
                </li>
            </ol>
        </aside>
        </div>
    </Dialog>
</template>
