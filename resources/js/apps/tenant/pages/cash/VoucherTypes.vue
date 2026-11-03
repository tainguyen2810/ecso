<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { Check, Pencil, Plus, X } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import CashNav from './CashNav.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';
import { formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import type { Direction, VoucherType } from './voucher';

const tenant = useTenantStore();
const toast = useToast();
const types = ref<VoucherType[] | null>(null);
const loadError = ref<string | null>(null);
const drafts = reactive<Record<Direction, string>>({ receipt: '', payment: '' });
const editing = ref<string | null>(null);
const editName = ref('');
const busy = ref<string | null>(null);
const canEdit = computed(() => tenant.can('finance.vouchers'));
const columns: { direction: Direction; title: string; hint: string }[] = [
    { direction: 'receipt', title: 'Loại phiếu thu', hint: 'Thu nhập khác, bồi thường, thanh lý tài sản…' },
    { direction: 'payment', title: 'Loại phiếu chi', hint: 'Quảng cáo, vận chuyển, lương, mặt bằng…' },
];

async function load() {
    loadError.value = null;
    try {
        types.value = (await api.get('/cash-voucher-types')).data.data;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    }
}
onMounted(load);

const of = (d: Direction) => (types.value ?? []).filter((t) => t.direction === d);

function replace(t: VoucherType) {
    types.value = (types.value ?? []).map((x) => (x.id === t.id ? t : x));
}

async function add(direction: Direction) {
    const name = drafts[direction].trim();
    if (!name) return;
    busy.value = direction;
    try {
        const { data } = await api.post('/cash-voucher-types', { direction, name });
        types.value = [...(types.value ?? []), { ...data.data, vouchers_count: 0 }];
        drafts[direction] = '';
        toast.success(`Đã thêm loại “${name}”.`);
    } catch (e) {
        toast.error((e as ApiError).field('name') ?? (e as ApiError).message);
    } finally {
        busy.value = null;
    }
}

async function update(t: VoucherType, changes: Partial<VoucherType>) {
    busy.value = t.id;
    try {
        replace((await api.patch(`/cash-voucher-types/${t.id}`, changes)).data.data);
        editing.value = null;
    } catch (e) {
        toast.error((e as ApiError).field('name') ?? (e as ApiError).message);
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <PageHeader title="Thu chi" description="Loại phiếu giúp gom các khoản thu chi cùng tính chất để xem báo cáo." />
    <CashNav />
    <ErrorState v-if="loadError" :message="loadError" @retry="load" />
    <div v-else-if="!types" class="grid gap-4 lg:grid-cols-2"><Skeleton v-for="i in 2" :key="i" class="h-80" /></div>
    <div v-else class="grid gap-4 lg:grid-cols-2">
        <Card v-for="col in columns" :key="col.direction" :data-testid="`types-${col.direction}`">
            <div class="border-b p-4">
                <h2 class="font-semibold">{{ col.title }}</h2>
                <p class="text-xs text-muted-foreground">{{ col.hint }}</p>
            </div>
            <ul class="divide-y">
                <li v-for="t in of(col.direction)" :key="t.id" class="flex flex-wrap items-center gap-3 px-4 py-2.5 text-sm" :class="!t.is_active && 'text-muted-foreground'">
                    <form v-if="editing === t.id" class="flex flex-1 gap-1" @submit.prevent="update(t, { name: editName })">
                        <Input v-model="editName" class="h-8" maxlength="100" required aria-label="Tên loại phiếu" />
                        <Button type="submit" size="icon" variant="ghost" class="size-8" aria-label="Lưu tên" :loading="busy === t.id"><Check /></Button>
                        <Button type="button" size="icon" variant="ghost" class="size-8" aria-label="Thôi sửa" @click="editing = null"><X /></Button>
                    </form>
                    <div v-else class="min-w-0 flex-1">
                        <span class="font-medium">{{ t.name }}</span>
                        <Badge v-if="!t.is_active" tone="muted" class="ml-2">Ngừng dùng</Badge>
                        <span class="ml-2 text-xs text-muted-foreground">{{ formatNumber(t.vouchers_count ?? 0) }} phiếu</span>
                    </div>
                    <label class="flex items-center gap-1.5 text-xs" :title="'Có tính vào báo cáo kết quả kinh doanh hay không'">
                        <input type="checkbox" class="size-4" :checked="t.counts_in_pnl" :disabled="!canEdit || busy === t.id" @change="update(t, { counts_in_pnl: !t.counts_in_pnl })" />
                        Tính KQKD
                    </label>
                    <template v-if="canEdit && editing !== t.id">
                        <Button size="icon" variant="ghost" class="size-8" aria-label="Đổi tên" @click="((editing = t.id), (editName = t.name))"><Pencil /></Button>
                        <Button size="sm" variant="ghost" :loading="busy === t.id" @click="update(t, { is_active: !t.is_active })">{{ t.is_active ? 'Ngừng dùng' : 'Dùng lại' }}</Button>
                    </template>
                </li>
            </ul>
            <form v-if="canEdit" class="flex gap-2 border-t p-3" @submit.prevent="add(col.direction)">
                <Input v-model="drafts[col.direction]" maxlength="100" :placeholder="`Tên ${col.title.toLowerCase()} mới`" :data-testid="`new-type-${col.direction}`" />
                <Button type="submit" variant="outline" :loading="busy === col.direction"><Plus /> Thêm</Button>
            </form>
        </Card>
    </div>
    <p class="mt-4 text-xs text-muted-foreground">Loại đã có phiếu không xóa được, chỉ ngừng dùng để giữ đúng lịch sử. “Tính KQKD” là mặc định cho phiếu mới, đổi ở đây không làm thay đổi phiếu cũ.</p>
</template>
