<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowRight, Check, Sparkles, Upload } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import AddressPicker from '../../components/AddressPicker.vue';
import { api, ApiError } from '@/shared/lib/api';
import type { AddressParts } from '@/shared/lib/geo';
import { cn } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import { CHANNELS, INDUSTRIES, TEAM_SIZES, startTarget, type StartChoice } from './setup';

/**
 * A2: setup after sign-up, in three steps. Step 1 makes the business (or names the one that exists), step 2 asks
 * where it sells, step 3 how to start: a separate sample store with fake data, moving data from Sapo/KiotViet,
 * or an empty store.
 */
const tenant = useTenantStore();
const route = useRoute();
const router = useRouter();
const toast = useToast();

const step = ref(0);
const busy = ref(false);
const error = ref<ApiError | null>(null);
// A new business is made at step 1; otherwise the steps finish the current one.
const creating = ref(true);

const info = reactive({ name: '', phone: '', industry: 'fashion' });
const addr = ref<AddressParts>({ street: '', province_code: '', ward_code: '' });
const channels = ref<string[]>([]);
const teamSize = ref<string>('1');
const choice = ref<StartChoice>('demo');

onMounted(async () => {
    // After a reload the current business is not loaded yet: finish it rather than making a second one.
    if (route.query.new !== '1' && !tenant.current) await tenant.restore().catch(() => false);
    if (!tenant.tenants.length) await tenant.fetchTenants().catch(() => []);
    creating.value = route.query.new === '1' || !tenant.current || !!tenant.current.setup_completed;
    if (!creating.value && tenant.current) {
        info.name = tenant.current.name;
        channels.value = tenant.current.channels ?? [];
        teamSize.value = tenant.current.team_size ?? '1';
        try {
            const { data } = await api.get('/tenant/settings');
            const s = data.data;
            info.phone = s.store?.phone ?? '';
            info.industry = s.setup?.industry ?? info.industry;
            addr.value = { street: s.store?.address ?? '', province_code: s.store?.province_code ?? '', ward_code: s.store?.ward_code ?? '' };
        } catch {
            // Empty form: the user fills it in again.
        }
    }
    // An existing user (with other businesses) may already have a sample store: offer the other two first.
    if (tenant.tenants.some((t) => t.is_demo)) choice.value = 'empty';
});

const industryLabel = computed(() => INDUSTRIES.find((i) => i.value === info.industry)?.label ?? null);
const canNext = computed(() => (step.value === 0 ? info.name.trim().length >= 2 && info.phone.trim().length >= 8 : true));
const finishLabel = computed(() => ({ demo: 'Tạo cửa hàng mẫu và vào xem', migrate: 'Mở trang Chuyển dữ liệu', empty: 'Vào trang Tổng quan' })[choice.value]);

function toggleChannel(value: string) {
    channels.value = channels.value.includes(value) ? channels.value.filter((c) => c !== value) : [...channels.value, value];
}

async function run(fn: () => Promise<void>) {
    busy.value = true;
    error.value = null;
    try {
        await fn();
    } catch (e) {
        error.value = e as ApiError;
        if (!error.value.body?.errors) toast.error(error.value.message);
    } finally {
        busy.value = false;
    }
}

const saveInfo = () =>
    run(async () => {
        if (creating.value) {
            await tenant.create(info.name.trim());
            creating.value = false;
        }
        await api.put('/tenant/settings/store', {
            name: info.name.trim(),
            phone: info.phone.trim(),
            business_type: industryLabel.value,
            address: addr.value.street || null,
            province_code: addr.value.province_code || null,
            ward_code: addr.value.ward_code || null,
        });
        await api.put('/tenant/setup', { industry: info.industry });
        step.value = 1;
    });

const saveChannels = () =>
    run(async () => {
        await api.put('/tenant/setup', { channels: channels.value, team_size: teamSize.value });
        step.value = 2;
    });

const finish = () =>
    run(async () => {
        await api.put('/tenant/setup', { complete: true });
        await tenant.refresh();
        if (choice.value === 'demo') {
            const demo = await tenant.createDemo();
            await tenant.select(demo.id);
            toast.success('Đã tạo Cửa hàng mẫu. Dữ liệu mẫu đang được thêm vào.');
        }
        router.replace(startTarget(choice.value));
    });

const steps = ['Thông tin cửa hàng', 'Bạn bán ở đâu', 'Bắt đầu'];
</script>

<template>
    <div class="min-h-screen bg-background px-4 py-8 sm:py-10">
        <div class="mx-auto w-full max-w-3xl" data-testid="setup">
            <div class="mb-6 flex items-center justify-center gap-2.5 text-xl font-extrabold">
                <span class="grid size-9 place-items-center rounded-lg bg-primary text-sm text-white">Ez</span> EzSell
            </div>

            <ol class="mb-5 flex items-center gap-2 text-sm">
                <li v-for="(label, i) in steps" :key="label" :class="cn('flex flex-1 items-center gap-2', i === step ? 'font-semibold text-foreground' : 'text-muted-foreground')">
                    <span :class="cn('grid size-6 shrink-0 place-items-center rounded-full text-xs font-bold', i < step ? 'bg-emerald-600 text-white' : i === step ? 'bg-primary text-white' : 'bg-slate-200 text-slate-600')">
                        <Check v-if="i < step" class="size-3.5" />
                        <template v-else>{{ i + 1 }}</template>
                    </span>
                    <span class="hidden truncate sm:inline">{{ label }}</span>
                    <span v-if="i < steps.length - 1" class="h-0.5 flex-1 bg-slate-200" />
                </li>
            </ol>

            <div class="rounded-xl border bg-card shadow-sm">
                <!-- 1. Store -->
                <template v-if="step === 0">
                    <div class="px-5 pt-5 sm:px-7">
                        <h1 class="text-xl font-bold">Cửa hàng của bạn</h1>
                        <p class="mt-1 text-sm text-muted-foreground">Dùng để in phiếu giao hàng, hóa đơn và tạo kho đầu tiên. Sửa lại được trong Cấu hình.</p>
                    </div>
                    <form id="setup-info" class="space-y-4 px-5 py-5 sm:px-7" @submit.prevent="saveInfo">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <FormField label="Tên cửa hàng *" for="setup-name" :error="error?.field('name')">
                                <Input id="setup-name" v-model="info.name" placeholder="Ví dụ: Thời Trang An Nhiên" autofocus data-testid="setup-name" />
                            </FormField>
                            <FormField label="Số điện thoại cửa hàng *" for="setup-phone" :error="error?.field('phone')">
                                <Input id="setup-phone" v-model="info.phone" inputmode="tel" data-testid="setup-phone" />
                            </FormField>
                        </div>
                        <FormField label="Ngành hàng chính" hint="Ghi vào Thông tin cửa hàng; giúp EzSell gợi ý cho đúng ngành.">
                            <div class="flex flex-wrap gap-2" data-testid="setup-industry">
                                <button
                                    v-for="i in INDUSTRIES"
                                    :key="i.value"
                                    type="button"
                                    :class="cn('rounded-full border px-3.5 py-1.5 text-sm', info.industry === i.value ? 'border-blue-300 bg-blue-50 font-semibold text-blue-700' : 'bg-card text-slate-700 hover:bg-muted')"
                                    :aria-pressed="info.industry === i.value"
                                    @click="info.industry = i.value"
                                >
                                    {{ i.label }}
                                </button>
                            </div>
                        </FormField>
                        <div>
                            <p class="mb-1.5 text-sm font-medium">Địa chỉ kho / lấy hàng</p>
                            <AddressPicker v-model="addr" id-prefix="setup-addr" :quick="false" :errors="{ street: error?.field('address'), province_code: error?.field('province_code'), ward_code: error?.field('ward_code') }" />
                            <p class="mt-1.5 text-xs text-muted-foreground">Gán cho "Kho chính"; đơn vị vận chuyển lấy hàng tại đây. Có thể bỏ trống, điền sau.</p>
                        </div>
                    </form>
                    <div class="flex items-center justify-between rounded-b-xl border-t bg-slate-50 px-5 py-3.5 sm:px-7">
                        <span class="text-xs text-muted-foreground">Bước 1/3</span>
                        <Button type="submit" form="setup-info" :loading="busy" :disabled="!canNext" data-testid="setup-next">Tiếp tục</Button>
                    </div>
                </template>

                <!-- 2. Channels -->
                <template v-else-if="step === 1">
                    <div class="px-5 pt-5 sm:px-7">
                        <h1 class="text-xl font-bold">Bạn đang bán hàng ở đâu?</h1>
                        <p class="mt-1 text-sm text-muted-foreground">Chọn tất cả kênh đang dùng. EzSell gợi ý việc cần làm theo đúng các kênh này; không tính năng nào bị khóa.</p>
                    </div>
                    <div class="px-5 py-5 sm:px-7">
                        <div class="grid gap-3 sm:grid-cols-3" data-testid="setup-channels">
                            <button
                                v-for="c in CHANNELS"
                                :key="c.value"
                                type="button"
                                :class="cn('relative rounded-xl border p-3.5 text-left transition', channels.includes(c.value) ? 'border-blue-300 bg-blue-50/50 ring-2 ring-blue-100' : 'bg-card hover:bg-muted/50')"
                                :aria-pressed="channels.includes(c.value)"
                                @click="toggleChannel(c.value)"
                            >
                                <span :class="cn('absolute right-3 top-3 grid size-5 place-items-center rounded-md border', channels.includes(c.value) ? 'border-primary bg-primary text-white' : 'border-slate-300')">
                                    <Check v-if="channels.includes(c.value)" class="size-3.5" />
                                </span>
                                <MarketplaceLogo :marketplace="c.logo" :name="c.label" size="lg" />
                                <span class="mt-2 block text-sm font-semibold">{{ c.label }}</span>
                                <span class="mt-0.5 block text-xs leading-relaxed text-muted-foreground">{{ c.detail }}</span>
                            </button>
                        </div>
                        <FormField label="Có bao nhiêu người cùng làm?" class="mt-5" hint="Từ 2 người trở lên, danh sách “Bắt đầu” có thêm bước mời nhân viên và phân quyền.">
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="t in TEAM_SIZES"
                                    :key="t.value"
                                    type="button"
                                    :class="cn('rounded-full border px-3.5 py-1.5 text-sm', teamSize === t.value ? 'border-blue-300 bg-blue-50 font-semibold text-blue-700' : 'bg-card text-slate-700 hover:bg-muted')"
                                    :aria-pressed="teamSize === t.value"
                                    @click="teamSize = t.value"
                                >
                                    {{ t.label }}
                                </button>
                            </div>
                        </FormField>
                    </div>
                    <div class="flex items-center justify-between rounded-b-xl border-t bg-slate-50 px-5 py-3.5 sm:px-7">
                        <Button variant="outline" @click="step = 0">Quay lại</Button>
                        <Button :loading="busy" data-testid="setup-next" @click="saveChannels">Tiếp tục</Button>
                    </div>
                </template>

                <!-- 3. Start -->
                <template v-else>
                    <div class="px-5 pt-5 sm:px-7">
                        <h1 class="text-xl font-bold">Bạn muốn bắt đầu thế nào?</h1>
                        <p class="mt-1 text-sm text-muted-foreground">Cửa hàng <b class="text-foreground">{{ info.name }}</b> đã sẵn sàng. Chọn một cách, đổi ý lúc nào cũng được.</p>
                    </div>
                    <div class="grid gap-3 px-5 py-5 sm:grid-cols-3 sm:px-7" role="radiogroup" data-testid="setup-start">
                        <button
                            v-for="o in [
                                { value: 'demo', icon: Sparkles, tone: 'bg-amber-50 text-amber-600', title: 'Xem thử với cửa hàng mẫu', text: 'Tạo thêm “Cửa hàng mẫu” có sẵn 60 ngày đơn hàng, sản phẩm, khách, thu chi để bấm thử mọi thứ. Không đụng tới cửa hàng thật. Xóa bằng 1 nút.' },
                                { value: 'migrate', icon: Upload, tone: 'bg-blue-50 text-blue-600', title: 'Chuyển dữ liệu từ Sapo, KiotViet', text: 'Tải file sản phẩm, khách hàng (kèm công nợ) xuất từ phần mềm cũ.' },
                                { value: 'empty', icon: ArrowRight, tone: 'bg-emerald-50 text-emerald-600', title: 'Bắt đầu với cửa hàng trống', text: 'Vào thẳng trang Tổng quan, làm theo danh sách “Bắt đầu”: thêm sản phẩm, kết nối sàn, tạo đơn đầu tiên.' },
                            ] as const"
                            :key="o.value"
                            type="button"
                            role="radio"
                            :aria-checked="choice === o.value"
                            :class="cn('relative rounded-xl border p-4 text-left transition', choice === o.value ? 'border-blue-300 bg-blue-50/50 ring-2 ring-blue-100' : 'bg-card hover:bg-muted/50')"
                            @click="choice = o.value"
                        >
                            <span :class="cn('absolute right-3 top-3 size-5 rounded-full border', choice === o.value ? 'border-primary bg-primary shadow-[inset_0_0_0_4px_white]' : 'border-slate-300')" />
                            <span :class="cn('grid size-10 place-items-center rounded-full', o.tone)"><component :is="o.icon" class="size-5" :stroke-width="1.75" /></span>
                            <span class="mt-2.5 block text-[15px] font-semibold">{{ o.title }}</span>
                            <span class="mt-1 block text-xs leading-relaxed text-muted-foreground">{{ o.text }}</span>
                            <span v-if="o.value === 'demo' && tenant.tenants.some((t) => t.is_demo)" class="mt-2.5 inline-block rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-600">Bạn đã có cửa hàng mẫu: mở lại</span>
                            <span v-else-if="o.value === 'demo'" class="mt-2.5 inline-block rounded-full bg-emerald-100 px-2.5 py-0.5 text-[11px] font-bold text-emerald-700">Nên chọn nếu mới dùng lần đầu</span>
                        </button>
                    </div>
                    <div class="flex items-center justify-between rounded-b-xl border-t bg-slate-50 px-5 py-3.5 sm:px-7">
                        <Button variant="outline" @click="step = 1">Quay lại</Button>
                        <Button :loading="busy" data-testid="setup-finish" @click="finish">{{ finishLabel }}</Button>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
