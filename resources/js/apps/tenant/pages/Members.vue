<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { Mail, MailPlus, Search, ShieldCheck, Trash2, UserMinus, UserCheck, Users, Warehouse as WarehouseIcon } from 'lucide-vue-next';
import SettingsShell from './settings/SettingsShell.vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDateTime, initials } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import type { Invitation, Member, Role, Warehouse } from '@/shared/types';

const tenant = useTenantStore();
const toast = useToast();
const canManage = computed(() => tenant.can('members.manage'));

const show = ref<'all' | 'active' | 'disabled'>('all');
const oldInvites = ref(false);
const limit = ref<number | null>(null);
const members = ref<Member[]>([]);
const invitations = ref<Invitation[]>([]);
const roles = ref<Role[]>([]);
const warehouses = ref<Warehouse[]>([]);
const warehouseName = (id: string) => warehouses.value.find((w) => w.id === id)?.name ?? id;
const loading = ref(true);
const loadError = ref<string | null>(null);
const search = ref('');

const assignable = computed(() => roles.value.filter((r) => r.assignable));
const roleOptions = computed(() => assignable.value.map((r) => ({ value: r.key, label: r.name })));
const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    return members.value.filter((m) => (show.value === 'all' || m.status === show.value) && (!q || m.name.toLowerCase().includes(q) || m.email.toLowerCase().includes(q)));
});
const activeCount = computed(() => members.value.filter((m) => m.status === 'active').length);
const pendingCount = computed(() => invitations.value.filter((i) => i.status === 'pending').length);
const shownInvites = computed(() => (oldInvites.value ? invitations.value : invitations.value.filter((i) => i.status === 'pending')));

/** Colour of a role chip, and what the built-in roles may do (custom roles show their name only). */
const roleTone: Record<string, string> = {
    TENANT_OWNER: 'bg-violet-50 text-violet-700',
    TENANT_ADMIN: 'bg-indigo-50 text-indigo-700',
    MANAGER: 'bg-blue-50 text-blue-700',
    ORDER_STAFF: 'bg-emerald-50 text-emerald-700',
    WAREHOUSE_STAFF: 'bg-amber-50 text-amber-700',
    ACCOUNTANT: 'bg-pink-50 text-pink-700',
    VIEWER: 'bg-slate-100 text-slate-600',
};
const roleHelp: [string, string][] = [
    ['TENANT_ADMIN', 'Mọi việc, trừ mua và đổi gói dịch vụ'],
    ['MANAGER', 'Đơn hàng, sản phẩm, kho, khách hàng và báo cáo vận hành'],
    ['ORDER_STAFF', 'Tạo và xử lý đơn, khách hàng; xem tồn kho'],
    ['WAREHOUSE_STAFF', 'Nhập, xuất, chuyển, kiểm kho ở kho được giao'],
    ['ACCOUNTANT', 'Thu chi, công nợ, hóa đơn và báo cáo tài chính'],
    ['VIEWER', 'Chỉ xem, không sửa được gì'],
];
const roleName = (key: string) => roles.value.find((r) => r.key === key)?.name ?? key;

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const [m, r, i] = await Promise.all([
            api.get('/members'),
            api.get('/roles'),
            canManage.value ? api.get('/members/invitations') : Promise.resolve({ data: { data: [] } }),
            tenant.can('inventory.view') ? api.get('/warehouses') : Promise.resolve({ data: { data: [] } }),
        ]).then(([m, r, i, w]) => {
            warehouses.value = (w.data.data as Warehouse[]).filter((x) => x.status === 'active');
            return [m, r, i] as const;
        });
        members.value = m.data.data;
        limit.value = m.data.meta?.limit ?? null;
        roles.value = r.data.data;
        invitations.value = i.data.data;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

const inviteOpen = ref(false);
const inviteForm = reactive({ email: '', role: 'VIEWER' });
const inviting = ref(false);
const inviteError = ref<ApiError | null>(null);

async function sendInvite() {
    inviting.value = true;
    inviteError.value = null;
    try {
        const { data } = await api.post('/members/invitations', inviteForm);
        invitations.value.unshift(data.data);
        toast.success(`Đã gửi lời mời tới ${inviteForm.email}`);
        inviteOpen.value = false;
        inviteForm.email = '';
    } catch (e) {
        inviteError.value = e as ApiError;
    } finally {
        inviting.value = false;
    }
}

async function updateMember(member: Member, payload: { role?: string; status?: string; warehouses?: string[] }) {
    try {
        const { data } = await api.patch(`/members/${member.id}`, payload);
        Object.assign(member, data.data);
        toast.success('Đã cập nhật nhân viên');
    } catch (e) {
        toast.error((e as ApiError).message);
        await load();
    }
}

async function removeMember(member: Member) {
    if (!window.confirm(`Xóa ${member.name} khỏi doanh nghiệp?`)) return;
    try {
        await api.delete(`/members/${member.id}`);
        members.value = members.value.filter((m) => m.id !== member.id);
        toast.success('Đã xóa nhân viên');
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}

async function revoke(inv: Invitation) {
    try {
        await api.delete(`/members/invitations/${inv.id}`);
        inv.status = 'revoked';
        toast.success('Đã thu hồi lời mời');
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}

// Warehouses a member moves stock in (receipts, counts, transfers, returns). None ticked = every warehouse.
const scopeFor = ref<Member | null>(null);
const scopeIds = ref<string[]>([]);
const scopeSaving = ref(false);
function openScope(m: Member) {
    scopeFor.value = m;
    scopeIds.value = [...(m.warehouses ?? [])];
}
async function saveScope() {
    if (!scopeFor.value) return;
    scopeSaving.value = true;
    await updateMember(scopeFor.value, { warehouses: scopeIds.value });
    scopeSaving.value = false;
    scopeFor.value = null;
}

function manageable(m: Member): boolean {
    return canManage.value && !m.is_me && m.role.key !== 'TENANT_OWNER' && assignable.value.some((r) => r.key === m.role.key);
}

const statusTone = { pending: 'bg-amber-50 text-amber-700', accepted: 'bg-emerald-50 text-emerald-700', revoked: 'bg-slate-100 text-slate-500', expired: 'bg-slate-100 text-slate-500' } as const;
const statusLabel = { pending: 'Đang chờ', accepted: 'Đã nhận', revoked: 'Đã thu hồi', expired: 'Hết hạn' };
</script>

<template>
    <SettingsShell title="Nhân viên và phân quyền" description="Mời nhân viên, chọn vai trò và kho được thao tác.">
        <template #actions>
            <button
                v-if="canManage"
                type="button"
                class="inline-flex h-11 items-center gap-2 rounded-[12px] bg-blue-600 px-5 text-[15px] font-medium text-white shadow-sm hover:bg-blue-700"
                data-testid="invite-button"
                @click="inviteOpen = true"
            >
                <MailPlus class="size-4" /> Mời nhân viên
            </button>
        </template>

        <div class="overflow-hidden rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
            <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-4">
                <div class="relative w-full sm:w-80">
                    <Search class="absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                    <input
                        v-model="search"
                        placeholder="Tìm theo tên hoặc email"
                        aria-label="Tìm nhân viên"
                        class="h-11 w-full rounded-[10px] border border-slate-200 bg-white pl-10 pr-3 text-[15px] outline-none placeholder:text-slate-400 focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                    />
                </div>
                <div class="flex rounded-[12px] bg-slate-100 p-1 text-[14px]" role="tablist">
                    <button
                        v-for="[k, label, n] in ([['all', 'Tất cả', members.length], ['active', 'Hoạt động', activeCount], ['disabled', 'Đã khóa', members.length - activeCount]] as const)"
                        :key="k"
                        type="button"
                        role="tab"
                        :aria-selected="show === k"
                        :class="cn('rounded-[9px] px-3.5 py-1.5', show === k ? 'bg-white font-semibold text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-900')"
                        @click="show = k"
                    >
                        {{ label }} · {{ n }}
                    </button>
                </div>
                <span v-if="limit !== null" class="ml-auto text-[13px] text-slate-500">Đang dùng <b class="text-slate-900">{{ members.length }}/{{ limit }}</b> tài khoản của gói</span>
            </div>

            <div v-if="loading" class="space-y-3 p-5"><Skeleton v-for="i in 4" :key="i" class="h-12" /></div>
            <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
            <EmptyState v-else-if="!filtered.length" :icon="Users" title="Không có thành viên phù hợp" />
            <div v-else class="overflow-x-auto">
                <table class="w-full text-[14px]">
                    <thead class="border-b border-slate-100 text-left text-[12px] font-semibold uppercase tracking-wider text-slate-400">
                        <tr>
                            <th class="px-4 py-3.5 pl-5 font-semibold">Nhân viên</th>
                            <th class="px-4 py-3.5 font-semibold">Vai trò</th>
                            <th v-if="warehouses.length > 1" class="px-4 py-3.5 font-semibold">Kho thao tác</th>
                            <th class="px-4 py-3.5 font-semibold">Tham gia</th>
                            <th class="px-4 py-3.5 font-semibold">Trạng thái</th>
                            <th class="px-4 py-3.5 pr-5" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700">
                        <tr v-for="m in filtered" :key="m.id" :class="cn('hover:bg-slate-50/70', m.status !== 'active' && 'text-slate-400')">
                            <td class="px-4 py-3.5 pl-5">
                                <div class="flex items-center gap-3">
                                    <span :class="cn('grid size-9 shrink-0 place-items-center rounded-full text-[12px] font-semibold', m.is_me ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-500')">{{ initials(m.name) }}</span>
                                    <div class="min-w-0">
                                        <p class="font-medium text-slate-900">{{ m.name }} <span v-if="m.is_me" class="text-[12px] font-normal text-slate-400">(bạn)</span></p>
                                        <p class="text-[13px] text-slate-500">{{ m.email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3.5">
                                <Select
                                    v-if="manageable(m)"
                                    :model-value="m.role.key"
                                    :options="roleOptions"
                                    class="h-9 w-52"
                                    :aria-label="`Phân quyền cho ${m.name}`"
                                    @update:model-value="(v) => v && updateMember(m, { role: v })"
                                />
                                <span v-else :class="cn('inline-flex rounded-md px-2 py-0.5 text-[12px] font-medium', roleTone[m.role.key] ?? 'bg-slate-100 text-slate-600')">{{ m.role.name }}</span>
                            </td>
                            <td v-if="warehouses.length > 1" class="px-4 py-3.5">
                                <component
                                    :is="manageable(m) ? 'button' : 'span'"
                                    :type="manageable(m) ? 'button' : undefined"
                                    :class="cn('inline-flex flex-wrap items-center gap-1.5 rounded-[8px] text-left', manageable(m) && '-mx-1.5 px-1.5 py-1 hover:bg-slate-100')"
                                    :data-testid="manageable(m) ? `member-warehouses-${m.email}` : undefined"
                                    @click="manageable(m) && openScope(m)"
                                >
                                    <template v-if="m.warehouses?.length">
                                        <span v-for="id in m.warehouses" :key="id" class="rounded-md bg-slate-100 px-2 py-0.5 text-[12px] text-slate-600">{{ warehouseName(id) }}</span>
                                    </template>
                                    <span v-else class="flex items-center gap-1.5 text-slate-500"><WarehouseIcon class="size-3.5" /> Mọi kho</span>
                                </component>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3.5 text-slate-500">{{ formatDateTime(m.joined_at) }}</td>
                            <td class="px-4 py-3.5">
                                <span v-if="m.status === 'active'" class="inline-flex items-center gap-1.5 rounded-md bg-emerald-50 px-2 py-0.5 text-[12px] font-medium text-emerald-700"><span class="size-1.5 rounded-full bg-emerald-500" /> Hoạt động</span>
                                <span v-else class="rounded-md bg-slate-100 px-2 py-0.5 text-[12px] font-medium text-slate-500">Đã khóa</span>
                            </td>
                            <td class="px-4 py-3.5 pr-5 text-right">
                                <div v-if="manageable(m)" class="flex justify-end gap-1">
                                    <button
                                        type="button"
                                        class="grid size-9 place-items-center rounded-[9px] text-slate-500 hover:bg-slate-100 hover:text-slate-900"
                                        :title="m.status === 'active' ? 'Khóa truy cập' : 'Mở khóa'"
                                        :aria-label="m.status === 'active' ? 'Khóa truy cập' : 'Mở khóa'"
                                        @click="updateMember(m, { status: m.status === 'active' ? 'disabled' : 'active' })"
                                    >
                                        <UserMinus v-if="m.status === 'active'" class="size-4" />
                                        <UserCheck v-else class="size-4" />
                                    </button>
                                    <button type="button" class="grid size-9 place-items-center rounded-[9px] text-red-500 hover:bg-red-50" title="Xóa khỏi doanh nghiệp" aria-label="Xóa khỏi doanh nghiệp" @click="removeMember(m)">
                                        <Trash2 class="size-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <section v-if="canManage && !loading" class="mt-5 overflow-hidden rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]" data-testid="invitations">
            <header class="flex items-center gap-3 border-b border-slate-100 px-5 py-4">
                <span class="grid size-8 place-items-center rounded-lg bg-amber-50 text-amber-600"><Mail class="size-4" /></span>
                <h2 class="text-[16px] font-semibold text-slate-900">Lời mời đang chờ</h2>
                <span class="rounded-md bg-amber-50 px-2 py-0.5 text-[12px] font-medium text-amber-700">{{ pendingCount }}</span>
                <label v-if="invitations.length > pendingCount" class="ml-auto flex items-center gap-2 text-[13px] text-slate-500">
                    <input v-model="oldInvites" type="checkbox" class="size-4 rounded border-slate-300" /> Hiện cả lời mời cũ
                </label>
            </header>
            <p v-if="!shownInvites.length" class="px-5 py-5 text-[14px] text-slate-500">Chưa có lời mời nào đang chờ. Mời nhân viên bằng email, liên kết tham gia có hiệu lực 7 ngày.</p>
            <div v-else class="divide-y divide-slate-100">
                <div v-for="inv in shownInvites" :key="inv.id" class="flex flex-wrap items-center gap-4 px-5 py-3.5 text-[14px]">
                    <span class="grid size-9 place-items-center rounded-full bg-slate-100 text-slate-500"><Mail class="size-4" /></span>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-slate-900">{{ inv.email }}</p>
                        <p class="text-[13px] text-slate-500">Mời bởi {{ inv.invited_by ?? '—' }} · hết hạn {{ formatDateTime(inv.expires_at) }}</p>
                    </div>
                    <span :class="cn('rounded-md px-2 py-0.5 text-[12px] font-medium', roleTone[inv.role.key] ?? 'bg-slate-100 text-slate-600')">{{ inv.role.name }}</span>
                    <span :class="cn('rounded-md px-2 py-0.5 text-[12px] font-medium', statusTone[inv.status])">{{ statusLabel[inv.status] }}</span>
                    <button v-if="inv.status === 'pending'" type="button" class="rounded-[9px] px-3 py-1.5 text-[13px] font-medium text-red-600 hover:bg-red-50" @click="revoke(inv)">Thu hồi</button>
                </div>
            </div>
        </section>

        <section class="mt-5 rounded-[14px] border border-slate-200/80 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
            <h2 class="flex items-center gap-2 text-[16px] font-semibold text-slate-900"><ShieldCheck class="size-5 text-blue-600" /> Vai trò làm được gì</h2>
            <div class="mt-3 grid gap-3 text-[13px] sm:grid-cols-2 2xl:grid-cols-6">
                <div v-for="[key, help] in roleHelp" :key="key" class="rounded-[12px] bg-slate-50 p-3.5">
                    <span :class="cn('inline-flex rounded-md px-2 py-0.5 text-[12px] font-medium', roleTone[key])">{{ roleName(key) }}</span>
                    <p class="mt-2 text-slate-500">{{ help }}</p>
                </div>
            </div>
            <p class="mt-3 text-[13px] text-slate-500">Chủ doanh nghiệp làm được mọi việc.</p>
        </section>
    </SettingsShell>

    <Dialog :open="!!scopeFor" :title="`Kho thao tác của ${scopeFor?.name ?? ''}`" description="Nhân viên chỉ nhập kho, kiểm kho, xuất chuyển, nhận hàng và nhận hàng hoàn ở các kho được chọn. Không chọn kho nào là được thao tác ở mọi kho. Xem tồn kho vẫn như cũ." @update:open="(v) => !v && (scopeFor = null)">
        <div class="space-y-2" data-testid="member-warehouse-dialog">
            <label v-for="w in warehouses" :key="w.id" class="flex items-center gap-2 rounded-md border px-3 py-2 text-sm hover:bg-muted/40">
                <input v-model="scopeIds" type="checkbox" :value="w.id" class="size-4 rounded border-input" />
                <span class="font-mono text-xs text-muted-foreground">{{ w.code }}</span>
                <span>{{ w.name }}</span>
            </label>
        </div>
        <template #footer>
            <Button variant="outline" @click="scopeFor = null">Hủy</Button>
            <Button :loading="scopeSaving" data-testid="member-warehouses-save" @click="saveScope">Lưu</Button>
        </template>
    </Dialog>

    <Dialog v-model:open="inviteOpen" title="Mời nhân viên" description="Người được mời cần đăng nhập đúng email này để tham gia.">
        <form id="invite-form" class="space-y-4" @submit.prevent="sendInvite">
            <FormField label="Email" for="invite-email" :error="inviteError?.field('email')">
                <Input id="invite-email" v-model="inviteForm.email" type="email" placeholder="nhanvien@congty.vn" required autofocus />
            </FormField>
            <FormField label="Vai trò" for="invite-role" :error="inviteError?.field('role')">
                <Select id="invite-role" v-model="inviteForm.role" :options="roleOptions" />
            </FormField>
            <p v-if="inviteError && !inviteError.body.errors" class="text-sm text-destructive">{{ inviteError.message }}</p>
        </form>
        <template #footer>
            <Button variant="outline" @click="inviteOpen = false">Hủy</Button>
            <Button type="submit" form="invite-form" :loading="inviting">Gửi lời mời</Button>
        </template>
    </Dialog>
</template>
