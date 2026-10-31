<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { AlertTriangle, Check, CheckCircle2, CircleAlert, Copy, Server, X } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import { call, InstallError, passwordProblem, STEPS, suggestedUrl, workerCommands, type StepKey } from './installApi';

interface Requirement {
    key: string;
    label: string;
    ok: boolean;
    required: boolean;
    detail: string;
}
interface Check {
    ok: boolean;
    message: string;
}
interface InstallResult {
    env_path: string;
    queue: string;
    admin_email: string;
    base_path: string;
    php_binary: string;
}

const loading = ref(true);
const installed = ref(false);
const tokenFile = ref('');
const tokenError = ref<string | null>(null);
const step = ref<StepKey>('token');
const busy = ref(false);
const error = ref<string | null>(null);
const fieldErrors = ref<Record<string, string[]>>({});

const token = ref('');
const requirements = ref<{ ok: boolean; items: Requirement[]; base_path: string } | null>(null);
const db = reactive({ host: '127.0.0.1', port: '3306', database: '', username: '', password: '' });
const dbCheck = ref<Check | null>(null);
const redis = reactive({ enabled: true, host: '127.0.0.1', port: '6379', password: '' });
const redisCheck = ref<Check | null>(null);
const site = reactive({ name: 'ECSO', url: suggestedUrl(window.location) });
const bank = reactive({ name: '', account_number: '', account_name: '', branch: '' });
const account = ref({ name: '', email: '', password: '', password_confirmation: '' });
const result = ref<InstallResult | null>(null);

const stepIndex = computed(() => STEPS.findIndex((s) => s.key === step.value));
const adminProblem = computed(() => (account.value.password ? passwordProblem(account.value.password, account.value.password_confirmation) : null));
const workers = computed(() => (result.value ? workerCommands(result.value.base_path, result.value.php_binary, result.value.queue) : []));

onMounted(async () => {
    try {
        const status = await call<{ installed: boolean; token_file?: string; token_error?: string | null }>('GET', 'status', null);
        installed.value = status.installed;
        tokenFile.value = status.token_file ?? '';
        tokenError.value = status.token_error ?? null;
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        loading.value = false;
    }
});

function fail(e: unknown) {
    const err = e as InstallError;
    error.value = err.message;
    fieldErrors.value = err.body?.errors ?? {};
}

function fieldError(name: string): string | undefined {
    return fieldErrors.value[name]?.[0];
}

async function run<T>(fn: () => Promise<T>): Promise<T | undefined> {
    busy.value = true;
    error.value = null;
    fieldErrors.value = {};
    try {
        return await fn();
    } catch (e) {
        fail(e);
        return undefined;
    } finally {
        busy.value = false;
    }
}

async function submitToken() {
    const data = await run(() => call<{ ok: boolean; items: Requirement[]; base_path: string }>('POST', 'requirements', token.value.trim()));
    if (data) {
        requirements.value = data;
        step.value = 'requirements';
    }
}

async function recheck() {
    const data = await run(() => call<{ ok: boolean; items: Requirement[]; base_path: string }>('POST', 'requirements', token.value.trim()));
    if (data) requirements.value = data;
}

const dbPayload = () => ({ database: { ...db, port: Number(db.port) } });
const redisPayload = () => ({ redis: { ...redis, port: Number(redis.port) } });

async function testDb() {
    const data = await run(() => call<Check>('POST', 'database', token.value.trim(), dbPayload()));
    if (data) dbCheck.value = data;
}

async function testRedis() {
    const data = await run(() => call<Check>('POST', 'redis', token.value.trim(), redisPayload()));
    if (data) redisCheck.value = data;
}

async function install() {
    const data = await run(() =>
        call<InstallResult>('POST', 'run', token.value.trim(), {
            ...dbPayload(),
            ...redisPayload(),
            app: site,
            bank,
            admin: account.value,
        }),
    );
    if (data) {
        result.value = data;
        step.value = 'done';
    }
}

function go(key: StepKey) {
    error.value = null;
    fieldErrors.value = {};
    step.value = key;
}

async function copy(text: string) {
    try {
        await navigator.clipboard.writeText(text);
    } catch {
        /* clipboard unavailable (http): the text stays selectable */
    }
}
</script>

<template>
    <div class="min-h-screen bg-muted/40 px-4 py-10">
        <div class="mx-auto max-w-2xl">
            <div class="mb-6 flex items-center gap-2 text-xl font-semibold"><Server class="size-6 text-primary" /> Cài đặt ECSO</div>

            <Card v-if="loading" class="p-6 text-sm text-muted-foreground">Đang kiểm tra…</Card>

            <Card v-else-if="installed" class="p-6" data-testid="already-installed">
                <p class="font-medium">Hệ thống đã được cài đặt.</p>
                <p class="mt-1 text-sm text-muted-foreground">Trình cài đặt đã bị khóa. Muốn cài lại phải xóa cấu hình trên máy chủ.</p>
                <a href="/login" class="mt-4 inline-block text-sm font-medium text-primary hover:underline">Đến trang đăng nhập</a>
            </Card>

            <template v-else>
                <ol class="mb-6 flex flex-wrap gap-x-4 gap-y-2 text-xs" data-testid="steps">
                    <li
                        v-for="(s, i) in STEPS"
                        :key="s.key"
                        :class="['flex items-center gap-1.5', i === stepIndex ? 'font-semibold text-foreground' : i < stepIndex ? 'text-success' : 'text-muted-foreground']"
                    >
                        <span
                            :class="[
                                'flex size-5 items-center justify-center rounded-full border text-[10px]',
                                i < stepIndex ? 'border-success bg-success text-white' : i === stepIndex ? 'border-primary text-primary' : '',
                            ]"
                        >
                            <Check v-if="i < stepIndex" class="size-3" />
                            <template v-else>{{ i + 1 }}</template>
                        </span>
                        {{ s.label }}
                    </li>
                </ol>

                <div v-if="error" class="mb-4 flex items-start gap-2 rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive" data-testid="install-error">
                    <CircleAlert class="mt-0.5 size-4 shrink-0" /> {{ error }}
                </div>

                <!-- 1. Token -->
                <Card v-if="step === 'token'" class="p-6">
                    <h2 class="font-semibold">Xác nhận bạn là người quản lý máy chủ</h2>
                    <p class="mt-2 text-sm text-muted-foreground">
                        Để người lạ không cài đặt thay bạn, ECSO vừa tạo một mã trong file dưới đây. Mở file bằng File Manager của aaPanel (hoặc SSH), sao chép mã và dán vào ô bên dưới.
                    </p>
                    <p v-if="tokenError" class="mt-3 text-sm text-destructive">{{ tokenError }}</p>
                    <code v-else class="mt-3 block rounded bg-muted px-3 py-2 text-sm" data-testid="token-file">{{ tokenFile }}</code>
                    <form class="mt-4 space-y-4" @submit.prevent="submitToken">
                        <FormField label="Mã cài đặt" for="install-token">
                            <Input id="install-token" v-model="token" autocomplete="off" spellcheck="false" placeholder="32 ký tự" data-testid="install-token" />
                        </FormField>
                        <Button type="submit" :loading="busy" :disabled="!token.trim()" data-testid="submit-token">Tiếp tục</Button>
                    </form>
                </Card>

                <!-- 2. Requirements -->
                <Card v-else-if="step === 'requirements' && requirements" class="p-6">
                    <h2 class="font-semibold">Kiểm tra máy chủ</h2>
                    <ul class="mt-4 divide-y text-sm" data-testid="requirements">
                        <li v-for="r in requirements.items" :key="r.key" class="flex items-start gap-2 py-2">
                            <CheckCircle2 v-if="r.ok" class="mt-0.5 size-4 shrink-0 text-success" />
                            <X v-else-if="r.required" class="mt-0.5 size-4 shrink-0 text-destructive" />
                            <AlertTriangle v-else class="mt-0.5 size-4 shrink-0 text-warning" />
                            <div>
                                <p :class="!r.ok && r.required && 'font-medium text-destructive'">{{ r.label }}{{ r.required ? '' : ' (không bắt buộc)' }}</p>
                                <p v-if="!r.ok || r.key === 'php'" class="text-xs text-muted-foreground">{{ r.detail }}</p>
                            </div>
                        </li>
                    </ul>
                    <div class="mt-5 flex gap-2">
                        <Button variant="outline" :loading="busy" @click="recheck">Kiểm tra lại</Button>
                        <Button :disabled="!requirements.ok" data-testid="to-database" @click="go('database')">Tiếp tục</Button>
                    </div>
                </Card>

                <!-- 3. Database -->
                <Card v-else-if="step === 'database'" class="p-6">
                    <h2 class="font-semibold">Database MySQL</h2>
                    <p class="mt-2 text-sm text-muted-foreground">Tạo database trống trong aaPanel (mục Databases → Add database), rồi nhập thông tin vào đây. Cần MySQL 8.0 trở lên.</p>
                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        <FormField class="sm:col-span-2" label="Máy chủ" for="db-host" :error="fieldError('database.host')">
                            <Input id="db-host" v-model="db.host" data-testid="db-host" />
                        </FormField>
                        <FormField label="Cổng" for="db-port" :error="fieldError('database.port')">
                            <Input id="db-port" v-model="db.port" inputmode="numeric" data-testid="db-port" />
                        </FormField>
                        <FormField label="Tên database" for="db-name" :error="fieldError('database.database')">
                            <Input id="db-name" v-model="db.database" data-testid="db-name" />
                        </FormField>
                        <FormField label="User" for="db-user" :error="fieldError('database.username')">
                            <Input id="db-user" v-model="db.username" autocomplete="off" data-testid="db-user" />
                        </FormField>
                        <FormField label="Mật khẩu" for="db-password">
                            <Input id="db-password" v-model="db.password" type="password" autocomplete="new-password" data-testid="db-password" />
                        </FormField>
                    </div>
                    <p v-if="dbCheck" :class="['mt-4 text-sm', dbCheck.ok ? 'text-success' : 'text-destructive']" data-testid="db-check">{{ dbCheck.message }}</p>
                    <div class="mt-5 flex gap-2">
                        <Button variant="ghost" @click="go('requirements')">Quay lại</Button>
                        <Button variant="outline" :loading="busy" data-testid="test-db" @click="testDb">Thử kết nối</Button>
                        <Button :disabled="!dbCheck?.ok" data-testid="to-redis" @click="go('redis')">Tiếp tục</Button>
                    </div>
                </Card>

                <!-- 4. Redis -->
                <Card v-else-if="step === 'redis'" class="p-6">
                    <h2 class="font-semibold">Redis (hàng đợi và cache)</h2>
                    <p class="mt-2 text-sm text-muted-foreground">Khuyến nghị dùng Redis cho production (cài từ App Store của aaPanel). Không có Redis thì hàng đợi chạy bằng database, chậm hơn khi nhiều đơn.</p>
                    <div class="mt-4 space-y-2 text-sm">
                        <label class="flex items-center gap-2"><input v-model="redis.enabled" type="radio" :value="true" data-testid="redis-on" /> Dùng Redis</label>
                        <label class="flex items-center gap-2"><input v-model="redis.enabled" type="radio" :value="false" data-testid="redis-off" /> Không dùng Redis</label>
                    </div>
                    <div v-if="redis.enabled" class="mt-4 grid gap-4 sm:grid-cols-3">
                        <FormField label="Máy chủ" for="redis-host" :error="fieldError('redis.host')">
                            <Input id="redis-host" v-model="redis.host" data-testid="redis-host" />
                        </FormField>
                        <FormField label="Cổng" for="redis-port" :error="fieldError('redis.port')">
                            <Input id="redis-port" v-model="redis.port" inputmode="numeric" />
                        </FormField>
                        <FormField label="Mật khẩu" for="redis-password" hint="Để trống nếu không đặt">
                            <Input id="redis-password" v-model="redis.password" type="password" autocomplete="new-password" />
                        </FormField>
                    </div>
                    <p v-if="redis.enabled && redisCheck" :class="['mt-4 text-sm', redisCheck.ok ? 'text-success' : 'text-destructive']" data-testid="redis-check">
                        {{ redisCheck.message }}
                    </p>
                    <div class="mt-5 flex gap-2">
                        <Button variant="ghost" @click="go('database')">Quay lại</Button>
                        <Button v-if="redis.enabled" variant="outline" :loading="busy" data-testid="test-redis" @click="testRedis">Thử kết nối</Button>
                        <Button :disabled="redis.enabled && !redisCheck?.ok" data-testid="to-site" @click="go('site')">Tiếp tục</Button>
                    </div>
                </Card>

                <!-- 5. Site -->
                <Card v-else-if="step === 'site'" class="p-6">
                    <h2 class="font-semibold">Thông tin website</h2>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <FormField label="Tên hệ thống" for="site-name" :error="fieldError('app.name')">
                            <Input id="site-name" v-model="site.name" data-testid="site-name" />
                        </FormField>
                        <FormField label="Địa chỉ website" for="site-url" :error="fieldError('app.url')" hint="Nên dùng https:// sau khi bật SSL trong aaPanel">
                            <Input id="site-url" v-model="site.url" data-testid="site-url" />
                        </FormField>
                    </div>
                    <h3 class="mt-6 text-sm font-semibold">Tài khoản nhận tiền thuê bao <span class="font-normal text-muted-foreground">(không bắt buộc, sửa sau trong .env)</span></h3>
                    <div class="mt-3 grid gap-4 sm:grid-cols-2">
                        <FormField label="Ngân hàng" for="bank-name"><Input id="bank-name" v-model="bank.name" /></FormField>
                        <FormField label="Số tài khoản" for="bank-number"><Input id="bank-number" v-model="bank.account_number" /></FormField>
                        <FormField label="Chủ tài khoản" for="bank-holder"><Input id="bank-holder" v-model="bank.account_name" /></FormField>
                        <FormField label="Chi nhánh" for="bank-branch"><Input id="bank-branch" v-model="bank.branch" /></FormField>
                    </div>
                    <div class="mt-5 flex gap-2">
                        <Button variant="ghost" @click="go('redis')">Quay lại</Button>
                        <Button :disabled="!site.name || !site.url" data-testid="to-admin" @click="go('admin')">Tiếp tục</Button>
                    </div>
                </Card>

                <!-- 6. Admin -->
                <Card v-else-if="step === 'admin'" class="p-6">
                    <h2 class="font-semibold">Tài khoản quản trị hệ thống</h2>
                    <p class="mt-2 text-sm text-muted-foreground">Tài khoản này quản lý toàn bộ nền tảng (gói, hóa đơn, giám sát). Lần đăng nhập đầu tiên sẽ bắt buộc cài xác thực hai bước.</p>
                    <form class="mt-4 grid gap-4 sm:grid-cols-2" @submit.prevent="install">
                        <FormField label="Họ tên" for="admin-name" :error="fieldError('admin.name')">
                            <Input id="admin-name" v-model="account.name" data-testid="admin-name" />
                        </FormField>
                        <FormField label="Email" for="admin-email" :error="fieldError('admin.email')">
                            <Input id="admin-email" v-model="account.email" type="email" autocomplete="username" data-testid="admin-email" />
                        </FormField>
                        <FormField label="Mật khẩu" for="admin-password" :error="fieldError('admin.password') ?? adminProblem ?? undefined" hint="Tối thiểu 12 ký tự, có chữ và số">
                            <Input id="admin-password" v-model="account.password" type="password" autocomplete="new-password" data-testid="admin-password" />
                        </FormField>
                        <FormField label="Nhập lại mật khẩu" for="admin-password2">
                            <Input id="admin-password2" v-model="account.password_confirmation" type="password" autocomplete="new-password" data-testid="admin-password2" />
                        </FormField>
                        <div class="flex gap-2 sm:col-span-2">
                            <Button variant="ghost" @click="go('site')">Quay lại</Button>
                            <Button type="submit" :loading="busy" :disabled="!account.name || !account.email || !!adminProblem || !account.password" data-testid="run-install">
                                {{ busy ? 'Đang cài đặt…' : 'Cài đặt' }}
                            </Button>
                        </div>
                    </form>
                </Card>

                <!-- 7. Done -->
                <Card v-else-if="step === 'done' && result" class="p-6" data-testid="install-done">
                    <div class="flex items-center gap-2 font-semibold text-success"><CheckCircle2 class="size-5" /> Cài đặt thành công</div>
                    <p class="mt-2 text-sm">
                        Đã tạo database, file cấu hình <code class="rounded bg-muted px-1">.env</code> và tài khoản <strong>{{ result.admin_email }}</strong>. Trình cài đặt đã tự khóa.
                    </p>

                    <h3 class="mt-6 text-sm font-semibold">Việc còn lại trong aaPanel</h3>
                    <ol class="mt-2 list-decimal space-y-3 pl-5 text-sm">
                        <li>
                            Plugin <strong>Supervisor</strong> → thêm các tiến trình sau, chạy bằng user <code>www</code>:
                            <div v-for="w in workers" :key="w.name" class="mt-2 flex items-start gap-2">
                                <code class="flex-1 rounded bg-muted px-2 py-1 text-xs break-all" data-testid="worker-command">{{ w.name }}: {{ w.command }}</code>
                                <button class="text-muted-foreground hover:text-foreground" :aria-label="`Sao chép ${w.name}`" @click="copy(w.command)"><Copy class="size-4" /></button>
                            </div>
                        </li>
                        <li>Kiểm tra lại máy chủ bằng terminal: <code class="rounded bg-muted px-1 text-xs">php artisan ecso:check-env --production</code></li>
                        <li>Bật SSL (Let's Encrypt) và Force HTTPS cho website nếu chưa bật.</li>
                    </ol>

                    <a href="/login" class="mt-6 inline-flex h-9 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90" data-testid="go-login">
                        Đăng nhập trang quản trị
                    </a>
                </Card>
            </template>
        </div>
    </div>
</template>
