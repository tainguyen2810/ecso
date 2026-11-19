<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Bot, CheckCircle2, ExternalLink, Link2, Unlink } from 'lucide-vue-next';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Select from '@/shared/ui/Select.vue';
import { api } from '@/shared/lib/api';
import { formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import type { SalesChannel } from '@/shared/types';

interface EzChatLinkView {
    available: boolean;
    ezchat_url: string;
    linked: boolean;
    workspace_name: string | null;
    order_stage: 'pending' | 'confirmed';
    channel: { id: string; name: string } | null;
    warehouse: string | null;
    orders: number;
    last_used_at: string | null;
    can_manage: boolean;
}

const props = defineProps<{ channels: SalesChannel[] }>();
const emit = defineEmits<{ changed: [] }>();
const toast = useToast();
const view = ref<EzChatLinkView | null>(null);
const busy = ref(false);

async function load() {
    view.value = (await api.get('/ezchat-link')).data.data;
}
onMounted(load);

/** The link starts in EzChat (it keeps the state), which comes back to the "Cho phép" page here. */
const connectUrl = computed(() => (view.value ? `${view.value.ezchat_url}/assistant/orders/store?banhang=connect` : '#'));

async function save(patch: Record<string, string>) {
    busy.value = true;
    try {
        view.value = (await api.put('/ezchat-link', patch)).data.data;
        toast.success('Đã lưu.');
        emit('changed');
    } finally {
        busy.value = false;
    }
}

async function unlink() {
    if (!window.confirm('Ngắt kết nối EzChat? Đơn chốt trong chat sẽ không vào đây nữa, đơn đã nhận vẫn giữ nguyên.')) return;
    busy.value = true;
    try {
        view.value = (await api.delete('/ezchat-link')).data.data;
        toast.success('Đã ngắt kết nối EzChat. Đơn đã nhận vẫn giữ nguyên.');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Card v-if="view && (view.available || view.linked)" class="mb-4 p-4" data-testid="ezchat-link">
        <div class="flex flex-wrap items-start gap-3">
            <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-violet-100 text-violet-700"><Bot class="size-5" /></span>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="font-semibold">Nối với EzChat</h2>
                    <Badge v-if="view.linked" tone="success"><CheckCircle2 class="size-3" /> Đã nối {{ view.workspace_name }}</Badge>
                    <Badge v-else tone="muted">Chưa nối</Badge>
                </div>
                <p class="mt-0.5 text-sm text-muted-foreground">
                    Đơn chốt trong chat (trợ lý AI hoặc nhân viên) tự vào đây và giữ hàng trong kho. Trợ lý EzChat đọc giá và tồn kho thật của bạn.
                </p>
                <div v-if="view.linked" class="mt-3 grid gap-3 sm:grid-cols-3">
                    <label class="text-xs text-muted-foreground">Đơn từ chat vào kênh
                        <Select class="mt-1" :model-value="view.channel?.id ?? ''" :disabled="!view.can_manage || busy" data-testid="ezchat-channel"
                            :options="props.channels.filter((c) => c.is_active).map((c) => ({ value: c.id, label: c.name }))" @update:model-value="(v?: string) => v && save({ channel_id: v })" />
                    </label>
                    <label class="text-xs text-muted-foreground">Trạng thái khi vào
                        <Select class="mt-1" :model-value="view.order_stage" :disabled="!view.can_manage || busy" data-testid="ezchat-stage"
                            :options="[{ value: 'pending', label: 'Chờ xác nhận' }, { value: 'confirmed', label: 'Đã xác nhận (chờ đóng gói)' }]" @update:model-value="(v?: string) => v && save({ order_stage: v })" />
                    </label>
                    <div class="text-xs text-muted-foreground">Kho xuất hàng
                        <p class="mt-2 text-sm font-medium text-foreground">{{ view.warehouse ?? 'Kho mặc định' }}</p>
                        <p>{{ formatNumber(view.orders) }} đơn đã nhận từ chat</p>
                    </div>
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2">
                <a v-if="!view.linked && view.can_manage" :href="connectUrl" data-testid="ezchat-connect">
                    <Button><Link2 /> Nối với EzChat</Button>
                </a>
                <a v-if="view.linked" :href="`${view.ezchat_url}/assistant/orders/store`" target="_blank" rel="noopener">
                    <Button variant="outline"><ExternalLink /> Mở EzChat</Button>
                </a>
                <Button v-if="view.linked && view.can_manage" variant="ghost" :loading="busy" data-testid="ezchat-unlink" @click="unlink"><Unlink /> Ngắt</Button>
            </div>
        </div>
    </Card>
</template>
