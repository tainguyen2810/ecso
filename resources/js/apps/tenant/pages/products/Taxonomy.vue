<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { Check, CornerDownRight, FolderTree, PackageX, Pencil, Plus, Search, Tag, Trash2, X } from 'lucide-vue-next';
import SettingsShell from '../settings/SettingsShell.vue';
import ReportKpi from '../reports/ReportKpi.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';

interface Item {
    id: string;
    name: string;
    parent_id?: string | null;
    products_count?: number;
}
type Kind = 'categories' | 'brands';

const tenant = useTenantStore();
const toast = useToast();
const canManage = computed(() => tenant.can('products.manage'));

const lists = reactive<Record<Kind, Item[]>>({ categories: [], brands: [] });
const loading = ref(true);
const drafts = reactive<Record<Kind, { name: string; parent_id: string }>>({ categories: { name: '', parent_id: '' }, brands: { name: '', parent_id: '' } });
const editing = ref<{ kind: Kind; id: string; name: string } | null>(null);
const filter = reactive<Record<Kind, string>>({ categories: '', brands: '' });

const labels = {
    categories: { title: 'Danh mục', singular: 'danh mục', placeholder: 'Tên danh mục mới, ví dụ: Áo thun', hint: 'Danh mục con nằm dưới danh mục cha' },
    brands: { title: 'Thương hiệu', singular: 'thương hiệu', placeholder: 'Tên thương hiệu mới, ví dụ: Coolmate', hint: 'Lọc và xem báo cáo theo thương hiệu' },
} as const;

async function load() {
    const [c, b] = await Promise.all([api.get('/categories'), api.get('/brands')]);
    lists.categories = c.data.data;
    lists.brands = b.data.data;
    loading.value = false;
}
onMounted(load);

const roots = computed(() => lists.categories.filter((c) => !c.parent_id).length);
const empty = computed(() => [...lists.categories, ...lists.brands].filter((x) => !x.products_count).length);
const rootTones = ['bg-blue-50 text-blue-600', 'bg-violet-50 text-violet-600', 'bg-amber-50 text-amber-600', 'bg-emerald-50 text-emerald-600', 'bg-pink-50 text-pink-600'];

/** Categories shown as a tree: each parent followed by its children, indented. */
const categoryRows = computed(() => {
    const byParent = new Map<string | null, Item[]>();
    for (const c of lists.categories) {
        const key = c.parent_id ?? null;
        byParent.set(key, [...(byParent.get(key) ?? []), c]);
    }
    const rows: (Item & { depth: number })[] = [];
    const walk = (parent: string | null, depth: number) => {
        for (const c of byParent.get(parent) ?? []) {
            rows.push({ ...c, depth });
            if (depth < 4) walk(c.id, depth + 1);
        }
    };
    walk(null, 0);
    return rows;
});

function rows(kind: Kind): (Item & { depth: number })[] {
    const all = kind === 'categories' ? categoryRows.value : lists.brands.map((b) => ({ ...b, depth: 0 }));
    const term = filter[kind].trim().toLowerCase();
    return term ? all.filter((r) => r.name.toLowerCase().includes(term)) : all;
}

async function create(kind: Kind) {
    const draft = drafts[kind];
    if (!draft.name.trim()) return;
    try {
        await api.post(`/${kind}`, { name: draft.name, ...(kind === 'categories' && draft.parent_id ? { parent_id: draft.parent_id } : {}) });
        toast.success(`Đã thêm ${labels[kind].singular} "${draft.name.trim()}"`);
        draft.name = '';
        await load();
    } catch (e) {
        toast.error((e as ApiError).field('name') ?? (e as ApiError).message);
    }
}

async function rename() {
    const e = editing.value;
    if (!e || !e.name.trim()) return;
    try {
        await api.patch(`/${e.kind}/${e.id}`, { name: e.name });
        editing.value = null;
        await load();
    } catch (err) {
        toast.error((err as ApiError).field('name') ?? (err as ApiError).message);
    }
}

async function remove(kind: Kind, item: Item) {
    const used = item.products_count ? ` ${item.products_count} sản phẩm đang dùng sẽ không còn ${labels[kind].singular}.` : '';
    if (!window.confirm(`Xóa ${labels[kind].singular} "${item.name}"?${used}`)) return;
    try {
        await api.delete(`/${kind}/${item.id}`);
        toast.success('Đã xóa');
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}
</script>

<template>
    <SettingsShell wide title="Danh mục và thương hiệu" description="Phân nhóm sản phẩm để lọc, tìm và xem báo cáo theo nhóm.">
        <div class="mb-5 grid gap-4 sm:grid-cols-3">
            <ReportKpi label="Danh mục" :icon="FolderTree" tone="blue" plain :value="lists.categories.length" unit="">{{ roots }} danh mục gốc · {{ lists.categories.length - roots }} danh mục con</ReportKpi>
            <ReportKpi label="Thương hiệu" :icon="Tag" tone="violet" plain :value="lists.brands.length" unit="">Gắn trên sản phẩm để lọc và báo cáo</ReportKpi>
            <ReportKpi label="Chưa có sản phẩm" :icon="PackageX" tone="amber" plain :value="empty" unit="">Danh mục, thương hiệu chưa gắn sản phẩm nào</ReportKpi>
        </div>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
            <section v-for="kind in (['categories', 'brands'] as Kind[])" :key="kind" class="overflow-hidden rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
                <header class="flex items-center gap-3 border-b border-slate-100 px-6 py-4">
                    <span :class="cn('grid size-9 place-items-center rounded-[10px]', kind === 'categories' ? 'bg-blue-50 text-blue-600' : 'bg-violet-50 text-violet-600')">
                        <FolderTree v-if="kind === 'categories'" class="size-[18px]" />
                        <Tag v-else class="size-[18px]" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-[16px] font-semibold text-slate-900">{{ labels[kind].title }} <span class="font-normal text-slate-400">· {{ lists[kind].length }}</span></h2>
                        <p class="text-[13px] text-slate-500">{{ labels[kind].hint }}</p>
                    </div>
                    <div class="relative w-56">
                        <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                        <input
                            v-model="filter[kind]"
                            :placeholder="`Tìm ${labels[kind].singular}`"
                            class="h-10 w-full rounded-[10px] border border-slate-200 bg-white pl-9 pr-3 text-[14px] outline-none placeholder:text-slate-400 focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                        />
                    </div>
                </header>
                <form v-if="canManage" class="flex flex-wrap gap-2 border-b border-slate-100 bg-slate-50/60 px-6 py-3" :data-testid="`${kind}-form`" @submit.prevent="create(kind)">
                    <Input v-model="drafts[kind].name" :placeholder="labels[kind].placeholder" maxlength="120" class="min-w-40 flex-1" :aria-label="`Tên ${labels[kind].singular}`" />
                    <Select
                        v-if="kind === 'categories'"
                        v-model="drafts.categories.parent_id"
                        class="w-52"
                        aria-label="Danh mục cha"
                        :options="[{ value: '', label: 'Không có danh mục cha' }, ...categoryRows.map((c) => ({ value: c.id, label: `${'— '.repeat(c.depth)}${c.name}` }))]"
                    />
                    <button type="submit" class="inline-flex h-11 items-center gap-2 rounded-[10px] bg-blue-600 px-4 text-[14px] font-medium text-white hover:bg-blue-700 disabled:opacity-50" :disabled="!drafts[kind].name.trim()">
                        <Plus class="size-4" /> Thêm
                    </button>
                </form>
                <div v-if="loading" class="space-y-2 p-6"><Skeleton v-for="i in 5" :key="i" class="h-9" /></div>
                <p v-else-if="!rows(kind).length" class="px-6 py-10 text-center text-[14px] text-slate-500">
                    {{ filter[kind] ? 'Không tìm thấy.' : `Chưa có ${labels[kind].singular} nào.` }}
                </p>
                <ul v-else class="max-h-[640px] divide-y divide-slate-100 overflow-y-auto text-[14px]" :data-testid="`${kind}-list`">
                    <li v-for="(item, i) in rows(kind)" :key="item.id" class="group flex items-center gap-3 px-6 py-2.5 hover:bg-slate-50/70" :style="item.depth ? { paddingLeft: `${1.5 + item.depth * 1.75}rem` } : undefined">
                        <template v-if="editing?.id === item.id">
                            <Input v-model="editing.name" class="h-9 flex-1" maxlength="120" aria-label="Tên mới" @keydown.enter.prevent="rename" @keydown.esc="editing = null" />
                            <button type="button" class="grid size-9 place-items-center rounded-[9px] text-emerald-600 hover:bg-emerald-50" title="Lưu" @click="rename"><Check class="size-4" /></button>
                            <button type="button" class="grid size-9 place-items-center rounded-[9px] text-slate-500 hover:bg-slate-100" title="Hủy" @click="editing = null"><X class="size-4" /></button>
                        </template>
                        <template v-else>
                            <CornerDownRight v-if="item.depth" class="size-4 shrink-0 text-slate-300" />
                            <span v-else :class="cn('grid size-8 shrink-0 place-items-center rounded-lg text-[12px] font-bold', kind === 'categories' ? rootTones[i % rootTones.length] : 'bg-slate-100 text-slate-600')">{{ item.name.slice(0, 2).toUpperCase() }}</span>
                            <span :class="cn('min-w-0 flex-1 truncate', item.depth ? 'text-slate-700' : 'font-medium text-slate-900')">{{ item.name }}</span>
                            <RouterLink :to="{ path: '/products', query: { [kind === 'categories' ? 'category' : 'brand']: item.id } }" class="whitespace-nowrap text-[13px] text-slate-500 hover:text-blue-700 hover:underline">
                                {{ formatNumber(item.products_count ?? 0) }} sản phẩm
                            </RouterLink>
                            <template v-if="canManage">
                                <button type="button" class="grid size-8 place-items-center rounded-[8px] text-slate-400 opacity-60 hover:bg-slate-100 hover:text-slate-700 group-hover:opacity-100" title="Đổi tên" @click="editing = { kind, id: item.id, name: item.name }">
                                    <Pencil class="size-4" />
                                </button>
                                <button type="button" class="grid size-8 place-items-center rounded-[8px] text-slate-400 opacity-60 hover:bg-red-50 hover:text-red-600 group-hover:opacity-100" title="Xóa" @click="remove(kind, item)">
                                    <Trash2 class="size-4" />
                                </button>
                            </template>
                        </template>
                    </li>
                </ul>
            </section>
        </div>
    </SettingsShell>
</template>
