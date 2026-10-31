<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { MailPlus, Search, Trash2, UserMinus, UserCheck, Users } from 'lucide-vue-next';
import PageHeader from '../PageHeader.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
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
import type { Invitation, Member, Role } from '@/shared/types';

const tenant = useTenantStore();
const toast = useToast();
const canManage = computed(() => tenant.can('members.manage'));

const tab = ref<'members' | 'invitations'>('members');
const members = ref<Member[]>([]);
const invitations = ref<Invitation[]>([]);
const roles = ref<Role[]>([]);
const loading = ref(true);
const loadError = ref<string | null>(null);
const search = ref('');

const assignable = computed(() => roles.value.filter((r) => r.assignable));
const roleOptions = computed(() => assignable.value.map((r) => ({ value: r.key, label: r.name })));
const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    return q ? members.value.filter((m) => m.name.toLowerCase().includes(q) || m.email.toLowerCase().includes(q)) : members.value;
});
const pendingCount = computed(() => invitations.value.filter((i) => i.status === 'pending').length);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const [m, r, i] = await Promise.all([
            api.get('/members'),
            api.get('/roles'),
            canManage.value ? api.get('/members/invitations') : Promise.resolve({ data: { data: [] } }),
        ]);
        members.value = m.data.data;
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
        tab.value = 'invitations';
    } catch (e) {
        inviteError.value = e as ApiError;
    } finally {
        inviting.value = false;
    }
}

async function updateMember(member: Member, payload: { role?: string; status?: string }) {
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

function manageable(m: Member): boolean {
    return canManage.value && !m.is_me && m.role.key !== 'TENANT_OWNER' && assignable.value.some((r) => r.key === m.role.key);
}

const statusTone = { pending: 'warning', accepted: 'success', revoked: 'muted', expired: 'muted' } as const;
const statusLabel = { pending: 'Đang chờ', accepted: 'Đã nhận', revoked: 'Đã thu hồi', expired: 'Hết hạn' };
</script>

<template>
    <PageHeader title="Nhân viên" description="Mời thành viên, phân vai trò và kiểm soát quyền truy cập.">
        <Button v-if="canManage" data-testid="invite-button" @click="inviteOpen = true"><MailPlus /> Mời nhân viên</Button>
    </PageHeader>

    <Card>
        <div class="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3">
            <div class="flex gap-1 rounded-md bg-muted p-1 text-sm">
                <button :class="cn('rounded px-3 py-1', tab === 'members' && 'bg-card font-medium shadow-xs')" @click="tab = 'members'">
                    Thành viên <span class="text-muted-foreground">{{ members.length }}</span>
                </button>
                <button v-if="canManage" :class="cn('rounded px-3 py-1', tab === 'invitations' && 'bg-card font-medium shadow-xs')" @click="tab = 'invitations'">
                    Lời mời <span class="text-muted-foreground">{{ pendingCount }}</span>
                </button>
            </div>
            <div v-if="tab === 'members'" class="relative w-full sm:w-64">
                <Search class="absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                <Input v-model="search" placeholder="Tìm theo tên hoặc email" class="pl-8" />
            </div>
        </div>

        <div v-if="loading" class="space-y-3 p-4">
            <Skeleton v-for="i in 4" :key="i" class="h-10" />
        </div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />

        <template v-else-if="tab === 'members'">
            <EmptyState v-if="!filtered.length" :icon="Users" title="Không có thành viên phù hợp" />
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">Thành viên</th>
                            <th class="px-4 py-2.5 font-medium">Vai trò</th>
                            <th class="px-4 py-2.5 font-medium">Trạng thái</th>
                            <th class="px-4 py-2.5 font-medium">Tham gia</th>
                            <th class="px-4 py-2.5" />
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="m in filtered" :key="m.id" class="hover:bg-muted/30">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="flex size-8 items-center justify-center rounded-full bg-secondary text-xs font-semibold text-primary">{{ initials(m.name) }}</span>
                                    <div>
                                        <p class="font-medium">{{ m.name }} <Badge v-if="m.is_me" tone="muted" class="ml-1">Bạn</Badge></p>
                                        <p class="text-xs text-muted-foreground">{{ m.email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <Select
                                    v-if="manageable(m)"
                                    :model-value="m.role.key"
                                    :options="roleOptions"
                                    class="h-8 w-48"
                                    @update:model-value="(v) => v && updateMember(m, { role: v })"
                                />
                                <span v-else>{{ m.role.name }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <Badge :tone="m.status === 'active' ? 'success' : 'muted'">{{ m.status === 'active' ? 'Hoạt động' : 'Đã khóa' }}</Badge>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">{{ formatDateTime(m.joined_at) }}</td>
                            <td class="px-4 py-3 text-right">
                                <div v-if="manageable(m)" class="flex justify-end gap-1">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        :title="m.status === 'active' ? 'Khóa truy cập' : 'Mở khóa'"
                                        @click="updateMember(m, { status: m.status === 'active' ? 'disabled' : 'active' })"
                                    >
                                        <UserMinus v-if="m.status === 'active'" />
                                        <UserCheck v-else />
                                    </Button>
                                    <Button variant="ghost" size="icon" title="Xóa khỏi doanh nghiệp" @click="removeMember(m)"><Trash2 class="text-destructive" /></Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <template v-else>
            <EmptyState v-if="!invitations.length" :icon="MailPlus" title="Chưa có lời mời nào" description="Mời nhân viên bằng email, họ sẽ nhận liên kết tham gia có hiệu lực 7 ngày.">
                <Button @click="inviteOpen = true"><MailPlus /> Mời nhân viên</Button>
            </EmptyState>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">Email</th>
                            <th class="px-4 py-2.5 font-medium">Vai trò</th>
                            <th class="px-4 py-2.5 font-medium">Người mời</th>
                            <th class="px-4 py-2.5 font-medium">Hết hạn</th>
                            <th class="px-4 py-2.5 font-medium">Trạng thái</th>
                            <th class="px-4 py-2.5" />
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="inv in invitations" :key="inv.id">
                            <td class="px-4 py-3 font-medium">{{ inv.email }}</td>
                            <td class="px-4 py-3">{{ inv.role.name }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ inv.invited_by }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ formatDateTime(inv.expires_at) }}</td>
                            <td class="px-4 py-3"><Badge :tone="statusTone[inv.status]">{{ statusLabel[inv.status] }}</Badge></td>
                            <td class="px-4 py-3 text-right">
                                <Button v-if="inv.status === 'pending'" variant="ghost" size="sm" @click="revoke(inv)">Thu hồi</Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </Card>

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
