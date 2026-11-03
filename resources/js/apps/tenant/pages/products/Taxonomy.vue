<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { Check, Pencil, Plus, Trash2, X } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import ProductsNav from './ProductsNav.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatNumber } from '@/shared/lib/utils';
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
    categories: { title: 'Danh mục sản phẩm', singular: 'danh mục', placeholder: 'Ví dụ: Áo thun' },
    brands: { title: 'Thương hiệu', singular: 'thương hiệu', placeholder: 'Ví dụ: Coolmate' },
} as const;

async function load() {
    const [c, b] = await Promise.all([api.get('/categories'), api.get('/brands')]);
    lists.categories = c.data.data;
    lists.brands = b.data.data;
    loading.value = false;
}
onMounted(load);

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
    <PageHeader title="Danh mục và thương hiệu" description="Danh mục và thương hiệu dùng để phân nhóm, lọc và báo cáo sản phẩm." />
    <ProductsNav />

    <div class="grid gap-5 lg:grid-cols-2">
        <Card v-for="kind in (['categories', 'brands'] as Kind[])" :key="kind" :title="labels[kind].title" :description="`${lists[kind].length} ${labels[kind].singular}`">
            <form v-if="canManage" class="flex flex-wrap gap-2 border-b px-5 py-3" :data-testid="`${kind}-form`" @submit.prevent="create(kind)">
                <Input v-model="drafts[kind].name" :placeholder="labels[kind].placeholder" maxlength="120" class="min-w-40 flex-1" :aria-label="`Tên ${labels[kind].singular}`" />
                <Select
                    v-if="kind === 'categories'"
                    v-model="drafts.categories.parent_id"
                    class="w-44"
                    aria-label="Danh mục cha"
                    :options="[{ value: '', label: 'Không có danh mục cha' }, ...categoryRows.map((c) => ({ value: c.id, label: `${'— '.repeat(c.depth)}${c.name}` }))]"
                />
                <Button type="submit" :disabled="!drafts[kind].name.trim()"><Plus /> Thêm</Button>
            </form>
            <div class="px-5 pt-3">
                <Input v-model="filter[kind]" :placeholder="`Tìm ${labels[kind].singular}`" class="h-8" />
            </div>
            <div v-if="loading" class="space-y-2 p-5"><Skeleton v-for="i in 4" :key="i" class="h-8" /></div>
            <p v-else-if="!rows(kind).length" class="px-5 py-8 text-center text-sm text-muted-foreground">
                {{ filter[kind] ? 'Không tìm thấy.' : `Chưa có ${labels[kind].singular} nào.` }}
            </p>
            <ul v-else class="max-h-[60vh] divide-y overflow-y-auto py-1 text-sm" :data-testid="`${kind}-list`">
                <li v-for="item in rows(kind)" :key="item.id" class="group flex items-center gap-2 px-5 py-2">
                    <template v-if="editing?.id === item.id">
                        <Input v-model="editing.name" class="h-8 flex-1" maxlength="120" aria-label="Tên mới" @keydown.enter.prevent="rename" @keydown.esc="editing = null" />
                        <Button size="icon" variant="ghost" class="size-8" title="Lưu" @click="rename"><Check /></Button>
                        <Button size="icon" variant="ghost" class="size-8" title="Hủy" @click="editing = null"><X /></Button>
                    </template>
                    <template v-else>
                        <span class="flex-1 truncate" :style="{ paddingLeft: `${item.depth * 1.25}rem` }">
                            <span v-if="item.depth" class="text-muted-foreground">└ </span>{{ item.name }}
                        </span>
                        <RouterLink :to="{ path: '/products', query: { [kind === 'categories' ? 'category' : 'brand']: item.id } }" class="text-xs text-muted-foreground hover:text-primary hover:underline">
                            {{ formatNumber(item.products_count ?? 0) }} sản phẩm
                        </RouterLink>
                        <template v-if="canManage">
                            <Button size="icon" variant="ghost" class="size-8 opacity-60 group-hover:opacity-100" title="Đổi tên" @click="editing = { kind, id: item.id, name: item.name }"><Pencil /></Button>
                            <Button size="icon" variant="ghost" class="size-8 opacity-60 group-hover:opacity-100" title="Xóa" @click="remove(kind, item)"><Trash2 class="text-destructive" /></Button>
                        </template>
                    </template>
                </li>
            </ul>
        </Card>
    </div>
</template>
