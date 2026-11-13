<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { RefreshCw, Unplug } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Alert from '@/shared/ui/Alert.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { loadZalo, templateState, type ZaloRule, type ZaloSettings } from './zalo';

/**
 * Zalo ZNS (C6): connect the shop's Official Account with its app keys, pick an approved template for each order
 * event and say what fills each template field; the messages sent are listed below.
 */
const toast = useToast();
const data = ref<ZaloSettings | null>(null);
const loading = ref(true);
const busy = ref<'connect' | 'templates' | 'rules' | 'disconnect' | null>(null);
const error = ref<ApiError | null>(null);
const keys = reactive({ app_id: '', secret_key: '', refresh_token: '' });
const rules = ref<Record<string, ZaloRule>>({});
const reconnect = ref(false);

function fill(d: ZaloSettings) {
    data.value = d;
    rules.value = Object.fromEntries(Object.keys(d.events).map((e) => [e, { template_id: d.account?.rules?.[e]?.template_id ?? '', enabled: d.account?.rules?.[e]?.enabled ?? false, params: { ...(d.account?.rules?.[e]?.params ?? {}) } }]));
    if (d.account) keys.app_id = d.account.app_id;
}
onMounted(async () => {
    try {
        fill(await loadZalo());
    } finally {
        loading.value = false;
    }
});

const connected = computed(() => !!data.value?.account && data.value.account.status !== 'disconnected' && !reconnect.value);
const templates = computed(() => data.value?.account?.templates ?? []);
const templateOptions = computed(() => [{ value: '', label: 'Chưa chọn mẫu' }, ...templates.value.map((t) => ({ value: t.id, label: `${t.name} (ID ${t.id})` }))]);
const fieldOptions = computed(() => [{ value: '', label: 'Để trống' }, ...Object.entries(data.value?.fields ?? {}).map(([value, label]) => ({ value, label }))]);
const templateOf = (id: string) => templates.value.find((t) => t.id === id) ?? null;

async function run<T>(what: typeof busy.value, call: () => Promise<T>, done: string) {
    busy.value = what;
    error.value = null;
    try {
        await call();
        toast.success(done);
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        busy.value = null;
    }
}
const connect = () =>
    run('connect', async () => {
        fill((await api.post('/zalo/account', keys)).data.data);
        keys.secret_key = '';
        keys.refresh_token = '';
        reconnect.value = false;
    }, 'Đã kết nối Zalo OA.');
const reloadTemplates = () => run('templates', async () => fill((await api.post('/zalo/templates/refresh')).data.data), 'Đã tải lại mẫu từ Zalo.');
const saveRules = () => run('rules', async () => fill((await api.put('/zalo/rules', { rules: rules.value })).data.data), 'Đã lưu.');
const disconnect = () => {
    if (!window.confirm('Ngắt kết nối Zalo? EzSell sẽ ngừng gửi tin.')) return;
    void run('disconnect', async () => fill((await api.delete('/zalo/account')).data.data), 'Đã ngắt kết nối Zalo.');
};
const firstError = computed(() => (error.value ? (Object.values(error.value.body.errors ?? {})[0]?.[0] ?? error.value.message) : null));
</script>

<template>
    <PageHeader title="Zalo ZNS" description="Gửi tin xác nhận đơn, giao hàng, nhắc thanh toán qua Zalo bằng Official Account của shop. Mẫu tin do shop đăng ký và Zalo duyệt; phí tin Zalo tính vào tài khoản ZBS của shop." :back="{ to: '/settings/connections', label: 'Kết nối dịch vụ' }">
        <template v-if="connected">
            <Button variant="outline" :loading="busy === 'templates'" data-testid="zalo-templates" @click="reloadTemplates"><RefreshCw /> Tải lại mẫu từ Zalo</Button>
            <Button variant="outline" @click="reconnect = true">Kết nối lại</Button>
            <Button variant="outline" :loading="busy === 'disconnect'" @click="disconnect"><Unplug /> Ngắt kết nối</Button>
        </template>
    </PageHeader>

    <div v-if="loading" class="space-y-3"><Skeleton class="h-40" /></div>
    <div v-else-if="data" class="space-y-4" data-testid="zalo-settings">
        <Alert v-if="firstError" tone="red">{{ firstError }}</Alert>
        <Alert v-if="data.account?.last_error && connected" tone="amber">{{ data.account.last_error }}</Alert>

        <Card v-if="!connected" caps title="Kết nối Official Account">
            <form class="grid gap-3 p-5 md:grid-cols-3" data-testid="zalo-connect-form" @submit.prevent="connect">
                <FormField label="App ID" for="z-app"><Input id="z-app" v-model="keys.app_id" inputmode="numeric" data-testid="zalo-app-id" /></FormField>
                <FormField label="Secret key của app" for="z-secret"><Input id="z-secret" v-model="keys.secret_key" type="password" autocomplete="off" data-testid="zalo-secret" /></FormField>
                <FormField label="Refresh token của OA" for="z-rt"><Input id="z-rt" v-model="keys.refresh_token" type="password" autocomplete="off" data-testid="zalo-refresh" /></FormField>
                <div class="text-xs text-muted-foreground md:col-span-3">
                    Cách lấy: tạo ứng dụng ở developers.zalo.me, liên kết với OA đã xác thực và bật quyền gửi ZNS; App ID và Secret key nằm ở trang ứng dụng. Refresh token lấy ở công cụ API Explorer của Zalo (chọn OA, loại token "OA Access Token"). EzSell tự gia hạn token sau đó.
                </div>
                <div class="md:col-span-3"><Button type="submit" :loading="busy === 'connect'" data-testid="zalo-connect">Kết nối</Button></div>
            </form>
        </Card>

        <template v-else-if="data.account">
            <p class="text-sm">OA: <b>{{ data.account.oa_name ?? '—' }}</b> · App ID {{ data.account.app_id }} · {{ templates.length }} mẫu ZNS</p>
            <Card caps title="Gửi tin theo đơn">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="zalo-rules">
                        <thead class="border-b text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                            <tr><th class="w-1/4 px-4 py-2.5 font-medium">Khi nào gửi</th><th class="w-1/4 px-4 py-2.5 font-medium">Mẫu ZNS</th><th class="px-4 py-2.5 font-medium">Điền vào mẫu</th><th class="w-20 px-4 py-2.5 font-medium">Bật</th></tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="(label, event) in data.events" :key="event" class="align-top" :data-testid="`zalo-rule-${event}`">
                                <td class="px-4 py-3 font-medium">{{ label }}<div v-if="event === 'confirmed'" class="text-xs font-normal text-muted-foreground">Đơn kênh riêng có số điện thoại, trừ bán tại quầy</div></td>
                                <td class="px-4 py-3">
                                    <Select v-model="rules[event]!.template_id" :options="templateOptions" aria-label="Mẫu ZNS" />
                                    <Badge v-if="templateOf(rules[event]!.template_id)" class="mt-1" :tone="templateState(templateOf(rules[event]!.template_id)!.status).tone">{{ templateState(templateOf(rules[event]!.template_id)!.status).label }}</Badge>
                                </td>
                                <td class="px-4 py-3">
                                    <div v-for="p in templateOf(rules[event]!.template_id)?.params ?? []" :key="p.name" class="mb-1.5 flex items-center gap-2 text-xs">
                                        <code class="w-32 shrink-0 truncate">{{ p.name }}<span v-if="p.required" class="text-destructive">*</span></code>
                                        <Select v-model="rules[event]!.params[p.name]" class="h-8 text-xs" :options="fieldOptions" :aria-label="p.name" />
                                    </div>
                                    <span v-if="rules[event]!.template_id && !templateOf(rules[event]!.template_id)?.params.length" class="text-xs text-muted-foreground">Mẫu chưa được duyệt nên chưa có trường để điền.</span>
                                </td>
                                <td class="px-4 py-3"><input v-model="rules[event]!.enabled" type="checkbox" class="size-4" :disabled="!rules[event]!.template_id" :aria-label="`Bật ${label}`" /></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="border-t px-5 py-3"><Button :loading="busy === 'rules'" data-testid="zalo-save" @click="saveRules">Lưu</Button></div>
            </Card>

            <Card caps title="Tin đã gửi gần đây">
                <table v-if="data.messages.length" class="w-full text-sm" data-testid="zalo-log">
                    <thead class="border-b text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                        <tr><th class="px-4 py-2.5 font-medium">Lúc</th><th class="px-4 py-2.5 font-medium">Khách</th><th class="px-4 py-2.5 font-medium">Đơn</th><th class="px-4 py-2.5 font-medium">Mẫu</th><th class="px-4 py-2.5 font-medium">Kết quả</th></tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="m in data.messages" :key="m.id">
                            <td class="whitespace-nowrap px-4 py-2 text-muted-foreground">{{ formatDateTime(m.created_at) }}</td>
                            <td class="px-4 py-2">{{ m.buyer_name }} · {{ m.phone }}</td>
                            <td class="px-4 py-2"><RouterLink v-if="m.order" :to="`/orders/${m.order.id}`" class="text-primary hover:underline">{{ m.order.number }}</RouterLink></td>
                            <td class="px-4 py-2">{{ m.template_name ?? data.events[m.event] }}</td>
                            <td class="px-4 py-2"><Badge :tone="m.status === 'sent' ? 'success' : 'danger'">{{ m.status === 'sent' ? 'Đã gửi' : `Lỗi: ${m.error}` }}</Badge></td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="px-5 py-4 text-sm text-muted-foreground">Chưa gửi tin nào.</p>
            </Card>
        </template>
    </div>
</template>
