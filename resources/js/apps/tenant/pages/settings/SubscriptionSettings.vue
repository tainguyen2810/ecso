<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { BellRing, MessageCircle, Plus, Users, X } from 'lucide-vue-next';
import SettingsPage from './SettingsPage.vue';
import SettingsSection from './SettingsSection.vue';
import { useSettingsForm } from './useSettingsForm';
import Alert from '@/shared/ui/Alert.vue';
import Button from '@/shared/ui/Button.vue';
import Switch from '@/shared/ui/Switch.vue';
import { loadZalo, type ZaloSettings } from '../connections/zalo';

/**
 * Cấu hình › Thuê bao: the days the customer is reminded before a subscription ends (0 = on the end day), by Zalo ZNS
 * when the shop chose a template for "Nhắc gia hạn thuê bao", and the morning message to the staff phones.
 */
const s = useSettingsForm(
    'subscriptions',
    (data) => ({ ...data.subscriptions, remind_days: [...data.subscriptions.remind_days] }),
    (f) => ({ ...f, remind_days: f.remind_days.map(Number).filter((d) => Number.isFinite(d) && d >= 0) }),
);
const zalo = ref<ZaloSettings | null>(null);
onMounted(async () => (zalo.value = await loadZalo().catch(() => null)));
const zaloTemplate = computed(() => {
    const rule = zalo.value?.account?.rules?.renewal_reminder;
    return rule?.enabled && rule.template_id ? (zalo.value!.account!.templates.find((t) => t.id === rule.template_id)?.name ?? 'Đã chọn mẫu') : null;
});
const dayLabel = (d: number) => (Number(d) === 0 ? 'ngày (đúng ngày hết hạn)' : 'ngày trước khi hết hạn');
const PRESETS = [30, 14, 7, 3, 1, 0];

function addDay() {
    const f = s.form.value!;
    const next = PRESETS.find((d) => !f.remind_days.includes(d));
    if (next !== undefined) f.remind_days.push(next);
}
</script>

<template>
    <SettingsPage title="Thuê bao" :saving="s.saving.value" :dirty="s.dirty.value" :can-save="s.canSave.value" @save="s.save" @cancel="s.cancel">
        <template v-if="s.form.value">
            <SettingsSection title="Nhắc khách gia hạn" :icon="BellRing" description="Mỗi sáng hệ thống xem thuê bao nào còn đúng số ngày dưới đây thì nhắc khách, mỗi mốc một lần. Dịch vụ tắt nhắc ở trang sản phẩm thì không nhắc.">
                <div class="space-y-2" data-testid="remind-days">
                    <div v-for="(d, i) in s.form.value.remind_days" :key="i" class="flex items-center gap-2">
                        <input v-model.number="s.form.value.remind_days[i]" type="number" min="0" max="60" class="h-9 w-24 rounded-md border border-input bg-card px-2 text-right tabular-nums" :aria-label="`Mốc nhắc ${i + 1}`" :data-testid="`remind-day-${i}`" />
                        <span class="text-sm text-muted-foreground">{{ dayLabel(d) }}</span>
                        <button type="button" class="ml-auto rounded p-1 text-muted-foreground hover:text-destructive" aria-label="Bỏ mốc nhắc" @click="s.form.value.remind_days.splice(i, 1)"><X class="size-4" /></button>
                    </div>
                    <p v-if="!s.form.value.remind_days.length" class="text-sm text-muted-foreground">Chưa có mốc nhắc: hệ thống không nhắc khách.</p>
                    <p v-if="s.error.value?.field('remind_days') || s.error.value?.field('remind_days.0')" class="text-sm text-destructive">{{ s.error.value?.field('remind_days') ?? 'Mỗi mốc từ 0 đến 60 ngày, không trùng nhau.' }}</p>
                    <Button v-if="s.form.value.remind_days.length < 6" variant="ghost" size="sm" class="text-primary" data-testid="add-remind-day" @click="addDay"><Plus /> Thêm mốc nhắc</Button>
                </div>
            </SettingsSection>
            <SettingsSection title="Gửi qua Zalo" :icon="MessageCircle" tone="green" description="Tin Zalo ZNS gửi tới số điện thoại của khách, theo mẫu đã được Zalo duyệt.">
                <Switch v-model="s.form.value.zalo" label="Nhắc khách bằng Zalo ZNS" hint="Cần Zalo OA của shop và một mẫu tin cho sự kiện Nhắc gia hạn thuê bao." data-testid="subs-zalo" />
                <Alert v-if="s.form.value.zalo && zalo && !zaloTemplate" tone="amber" class="mt-2 text-sm" data-testid="zalo-missing">
                    Chưa chọn mẫu tin cho "Nhắc gia hạn thuê bao". Vào <RouterLink to="/settings/connections/zalo" class="font-medium text-primary hover:underline">Kết nối dịch vụ › Zalo</RouterLink> để chọn mẫu. Chưa có mẫu thì hệ thống chỉ ghi lịch sử, không gửi tin.
                </Alert>
                <p v-else-if="zaloTemplate" class="mt-1 text-sm text-muted-foreground">Mẫu đang dùng: <span class="font-medium text-foreground">{{ zaloTemplate }}</span></p>
            </SettingsSection>
            <SettingsSection title="Báo nhân viên" :icon="Users" tone="violet" description="Thông báo trên điện thoại của nhân viên có quyền xem đơn.">
                <Switch v-model="s.form.value.notify_staff" label="Mỗi sáng báo số thuê bao sắp hết hạn" hint="Gồm thuê bao hết hạn trong 7 ngày tới và đã hết hạn. Bấm vào thông báo để mở danh sách cần gia hạn." data-testid="subs-notify" />
            </SettingsSection>
        </template>
    </SettingsPage>
</template>
