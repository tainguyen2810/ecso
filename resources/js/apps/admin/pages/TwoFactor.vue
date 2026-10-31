<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { KeyRound, ShieldCheck } from 'lucide-vue-next';
import QRCode from 'qrcode';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import { api, ApiError } from '@/shared/lib/api';
import { useAuthStore } from '@/shared/stores/auth';
import { twoFactorStatus, type TwoFactorStatus } from '../router';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const status = ref<TwoFactorStatus | null>(null);
const setup = ref<{ secret: string; uri: string } | null>(null);
const qr = ref<string | null>(null);
const code = ref('');
const recoveryCode = ref('');
const useRecovery = ref(false);
const recoveryCodes = ref<string[] | null>(null);
const error = ref<string | undefined>();
const busy = ref(false);

const mode = computed(() => (status.value && !status.value.enabled ? 'setup' : 'verify'));
const groupedSecret = computed(() => setup.value?.secret.match(/.{1,4}/g)?.join(' ') ?? '');

function next() {
    const target = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/admin') ? route.query.redirect : '/admin';
    router.replace(target);
}

onMounted(async () => {
    status.value = await twoFactorStatus();
    if (!status.value.required || (status.value.enabled && status.value.passed)) return next();
    if (!status.value.enabled) {
        setup.value = (await api.post('/admin/2fa/setup')).data.data;
        qr.value = await QRCode.toDataURL(setup.value!.uri, { margin: 1, width: 200 });
    }
});

async function submit() {
    busy.value = true;
    error.value = undefined;
    try {
        if (mode.value === 'setup') {
            recoveryCodes.value = (await api.post('/admin/2fa/confirm', { code: code.value })).data.data.recovery_codes;
        } else {
            await api.post('/admin/2fa/verify', useRecovery.value ? { recovery_code: recoveryCode.value } : { code: code.value });
            await twoFactorStatus();
            next();
        }
    } catch (e) {
        const err = e as ApiError;
        error.value = err.field('code') ?? err.field('recovery_code') ?? err.message;
    } finally {
        busy.value = false;
    }
}

async function finishSetup() {
    await twoFactorStatus();
    next();
}

async function logout() {
    await auth.logout();
    window.location.href = '/login';
}
</script>

<template>
    <div class="flex min-h-screen items-start justify-center bg-muted/40 px-4 pt-[10vh]">
        <Card class="w-full max-w-md p-6" data-testid="two-factor">
            <div class="mb-5 flex items-center gap-2 text-lg font-semibold"><ShieldCheck class="size-5 text-primary" /> Xác thực hai bước</div>

            <template v-if="recoveryCodes">
                <p class="text-sm">Đã bật xác thực hai bước. Lưu các mã khôi phục dưới đây ở nơi an toàn — mỗi mã dùng được một lần khi mất điện thoại. Mã chỉ hiện một lần.</p>
                <ul class="my-4 grid grid-cols-2 gap-2 rounded-md border bg-muted/40 p-3 font-mono text-sm" data-testid="recovery-codes">
                    <li v-for="c in recoveryCodes" :key="c">{{ c }}</li>
                </ul>
                <Button class="w-full" data-testid="finish-setup" @click="finishSetup">Tôi đã lưu, vào trang quản trị</Button>
            </template>

            <template v-else-if="status">
                <template v-if="mode === 'setup'">
                    <p class="text-sm text-muted-foreground">
                        Tài khoản quản trị hệ thống bắt buộc dùng xác thực hai bước. Quét mã QR bằng ứng dụng xác thực (Google Authenticator, Microsoft Authenticator, 1Password…), rồi nhập mã 6 số.
                    </p>
                    <div v-if="setup" class="my-4 flex flex-col items-center gap-2">
                        <img v-if="qr" :src="qr" alt="Mã QR cài đặt xác thực hai bước" class="size-48 rounded-md border bg-white p-1" />
                        <p class="text-xs text-muted-foreground">Hoặc nhập khóa thủ công:</p>
                        <code class="rounded bg-muted px-2 py-1 text-sm tracking-wider" data-testid="totp-secret" :data-secret="setup.secret">{{ groupedSecret }}</code>
                    </div>
                </template>
                <p v-else class="mb-4 text-sm text-muted-foreground">
                    {{ useRecovery ? 'Nhập một mã khôi phục chưa dùng.' : 'Nhập mã 6 số từ ứng dụng xác thực để tiếp tục.' }}
                </p>

                <form class="space-y-4" @submit.prevent="submit">
                    <FormField v-if="!useRecovery" label="Mã xác thực" for="totp-code" :error="error">
                        <Input id="totp-code" v-model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" :invalid="!!error" autofocus data-testid="totp-code" />
                    </FormField>
                    <FormField v-else label="Mã khôi phục" for="recovery-code" :error="error">
                        <Input id="recovery-code" v-model="recoveryCode" autocomplete="off" :invalid="!!error" data-testid="recovery-code" />
                    </FormField>
                    <Button type="submit" class="w-full" :loading="busy" data-testid="submit-2fa">{{ mode === 'setup' ? 'Bật xác thực hai bước' : 'Xác nhận' }}</Button>
                </form>
                <div class="mt-4 flex justify-between text-sm">
                    <button v-if="mode === 'verify'" class="flex items-center gap-1 text-muted-foreground hover:text-foreground" @click="useRecovery = !useRecovery; error = undefined">
                        <KeyRound class="size-4" /> {{ useRecovery ? 'Dùng mã từ ứng dụng' : 'Dùng mã khôi phục' }}
                    </button>
                    <span v-else />
                    <button class="text-muted-foreground hover:text-foreground" @click="logout">Đăng xuất</button>
                </div>
            </template>
        </Card>
    </div>
</template>
