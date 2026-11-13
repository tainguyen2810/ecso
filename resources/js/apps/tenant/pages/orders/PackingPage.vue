<script setup lang="ts">
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
} from "vue";
import { useRouter } from "vue-router";
import {
    Check,
    CircleAlert,
    ClipboardList,
    ListChecks,
    ScanBarcode,
} from "lucide-vue-next";
import PageHeader from "../../PageHeader.vue";
import Badge from "@/shared/ui/Badge.vue";
import Button from "@/shared/ui/Button.vue";
import Card from "@/shared/ui/Card.vue";
import Dialog from "@/shared/ui/Dialog.vue";
import FormField from "@/shared/ui/FormField.vue";
import Input from "@/shared/ui/Input.vue";
import Switch from "@/shared/ui/Switch.vue";
import { api, ApiError } from "@/shared/lib/api";
import { cn, formatMoney } from "@/shared/lib/utils";
import { useToast } from "@/shared/composables/useToast";
import { useAuthStore } from "@/shared/stores/auth";
import { useTenantStore } from "@/shared/stores/tenant";
import { marketplaceNames } from "./labels";
import { downloadPackingSlips } from "./packingSlip";
import {
    beep,
    downloadPickList,
    matchScan,
    packProgress,
    readPackSettings,
    savePackSettings,
    shortLines,
    type Counts,
    type PackOrder,
} from "./packing";

/**
 * Packing screen (C1): scan the order code or tracking number, then every item. Enter on an empty box finishes the
 * order (status "Chờ bàn giao") and prints its packing slip. Esc drops the order, Ctrl+Z takes back the last scan.
 */
interface Stats {
    packed_today: number;
    errors_today: number;
    remaining: number;
    recent: {
        id: string;
        external_order_id: string;
        items: number;
        by: string | null;
        at: string;
    }[];
}

const toast = useToast();
const auth = useAuthStore();
const tenant = useTenantStore();
const router = useRouter();

const input = ref<HTMLInputElement | null>(null);
const code = ref("");
const order = ref<PackOrder | null>(null);
const counts = ref<Counts>({});
const history = ref<string[]>([]);
const flash = ref<{ tone: "ok" | "error" | "info"; text: string } | null>(null);
const lastLine = ref<string | null>(null);
const busy = ref<string | null>(null);
const stats = ref<Stats | null>(null);
const settings = reactive(readPackSettings());
watch(settings, () => savePackSettings({ ...settings }));

const shortOpen = ref(false);
const shortReason = ref("");
const switchTo = ref<PackOrder | null>(null);

const progress = computed(() =>
    order.value
        ? packProgress(order.value.lines, counts.value)
        : { done: 0, total: 0, complete: false },
);
const placeholder = computed(() =>
    !order.value
        ? "Quét hoặc gõ mã đơn, mã vận đơn…"
        : progress.value.complete
          ? "Đủ hàng: bấm Enter để hoàn tất"
          : `Quét sản phẩm tiếp theo của đơn ${order.value.external_order_id}…`,
);

function focus() {
    if (shortOpen.value || switchTo.value) return;
    nextTick(() => input.value?.focus());
}

async function loadStats() {
    try {
        stats.value = (await api.get("/orders/packing/stats")).data.data;
    } catch {
        // The side panel is a convenience; packing still works.
    }
}

function say(tone: "ok" | "error" | "info", text: string) {
    flash.value = { tone, text };
    if (settings.sound && tone !== "info") beep(tone === "ok");
}

function open(o: PackOrder) {
    order.value = o;
    counts.value = {};
    history.value = [];
    lastLine.value = null;
    say("ok", `Đã mở đơn ${o.external_order_id}: quét từng sản phẩm.`);
}

function drop() {
    order.value = null;
    counts.value = {};
    history.value = [];
    lastLine.value = null;
    flash.value = null;
    focus();
}

/** Looks a code up as an order. Returns the order, or the message when it is not one waiting to be packed. */
async function lookup(c: string): Promise<PackOrder | string> {
    try {
        return (
            await api.get("/orders/packing/lookup", { params: { code: c } })
        ).data.data;
    } catch (e) {
        return e instanceof ApiError
            ? (e.field("code") ?? e.message)
            : "Không tìm được đơn.";
    }
}

function count(lineId: string) {
    counts.value = {
        ...counts.value,
        [lineId]: (counts.value[lineId] ?? 0) + 1,
    };
    history.value.push(lineId);
    lastLine.value = lineId;
}

function logError(c: string, kind: "wrong" | "extra") {
    if (!order.value) return;
    if (stats.value) stats.value.errors_today++;
    api.post(`/orders/${order.value.id}/packing/errors`, {
        code: c,
        kind,
    }).catch(() => {});
}

async function submit() {
    const c = code.value.trim();
    code.value = "";
    if (busy.value) return;
    if (!c) {
        if (order.value) await tryFinish();
        return;
    }
    if (!order.value) {
        busy.value = "lookup";
        const found = await lookup(c);
        busy.value = null;
        if (typeof found === "string") say("error", found);
        else open(found);
        return;
    }

    const result = matchScan(order.value.lines, counts.value, c);
    if (result.kind === "ok") {
        count(result.line.id);
        const p = packProgress(order.value.lines, counts.value);
        say(
            "ok",
            p.complete
                ? "Đủ hàng. Bấm Enter để hoàn tất đơn."
                : `+1 ${result.line.sku ?? result.line.name}`,
        );
        return;
    }
    if (result.kind === "extra") {
        say(
            "error",
            `Thừa: ${result.line.sku ?? result.line.name} đã đủ ${result.line.quantity}. Bỏ món này ra, không tính.`,
        );
        logError(c, "extra");
        return;
    }
    // Not an item of this order: maybe the packer scanned another order's code.
    busy.value = "lookup";
    const other = await lookup(c);
    busy.value = null;
    if (typeof other !== "string") {
        if (other.id !== order.value.id) switchTo.value = other;
        return;
    }
    say(
        "error",
        `Sai hàng: mã ${c} không có trong đơn ${order.value.external_order_id}. Không tính.`,
    );
    logError(c, "wrong");
}

async function tryFinish() {
    if (!order.value) return;
    if (progress.value.complete) return finish(null);
    if (settings.allowShort) {
        shortReason.value = "";
        shortOpen.value = true;
        return;
    }
    say(
        "error",
        `Còn thiếu ${progress.value.total - progress.value.done} sản phẩm. Quét đủ, hoặc bật "Cho phép đóng thiếu".`,
    );
}

async function finish(reason: string | null) {
    const o = order.value;
    if (!o) return;
    busy.value = "finish";
    try {
        await api.post(`/orders/${o.id}/packing/finish`, {
            scanned: counts.value,
            reason,
        });
        shortOpen.value = false;
        toast.success(`Đã đóng gói đơn ${o.external_order_id}.`);
        drop();
        say("info", `Xong đơn ${o.external_order_id}. Quét đơn tiếp theo.`);
        if (settings.autoPrint && tenant.can("orders.print")) {
            try {
                const { data } = await api.post("/orders/packing-slips", {
                    ids: [o.id],
                });
                await downloadPackingSlips(data.data);
            } catch {
                toast.error(
                    "Đơn đã đóng gói nhưng chưa in được phiếu. In lại ở danh sách đơn.",
                );
            }
        }
        await loadStats();
    } catch (e) {
        say(
            "error",
            e instanceof ApiError ? e.message : "Không hoàn tất được đơn.",
        );
    } finally {
        busy.value = null;
        focus();
    }
}

function undo() {
    const id = history.value.pop();
    if (!id) return;
    counts.value = {
        ...counts.value,
        [id]: Math.max(0, (counts.value[id] ?? 0) - 1),
    };
    lastLine.value = null;
    say("info", "Đã trừ lần quét vừa rồi.");
}

/** A line without a barcode (gift bag, freebie) is ticked by tapping it. */
function tap(lineId: string, quantity: number) {
    if ((counts.value[lineId] ?? 0) >= quantity) return;
    count(lineId);
    say(
        "ok",
        progress.value.complete
            ? "Đủ hàng. Bấm Enter để hoàn tất đơn."
            : "Đã đánh dấu 1 sản phẩm.",
    );
    focus();
}

function confirmSwitch() {
    const next = switchTo.value;
    switchTo.value = null;
    if (next) open(next);
    focus();
}

function onKey(e: KeyboardEvent) {
    if (shortOpen.value || switchTo.value) return;
    if (e.key === "Escape" && order.value) {
        e.preventDefault();
        drop();
        say("info", "Đã bỏ đơn đang gói. Quét đơn khác.");
    } else if (
        (e.ctrlKey || e.metaKey) &&
        e.key.toLowerCase() === "z" &&
        order.value
    ) {
        e.preventDefault();
        undo();
    }
}

async function printPickList() {
    busy.value = "pick";
    try {
        const { data } = await api.post("/orders/pick-list", { waiting: true });
        if (!data.data.rows.length) {
            toast.info("Không có đơn nào đang chờ đóng gói.");
            return;
        }
        await downloadPickList(data.data, {
            shop: tenant.current?.name ?? "",
            by: auth.user?.name ?? "",
            size: "A4",
        });
    } catch (e) {
        toast.error(
            e instanceof ApiError
                ? e.message
                : "Không tạo được phiếu nhặt hàng.",
        );
    } finally {
        busy.value = null;
        focus();
    }
}

const time = (iso: string) =>
    new Date(iso).toLocaleTimeString("vi-VN", {
        hour: "2-digit",
        minute: "2-digit",
        hour12: false,
    });

onMounted(() => {
    window.addEventListener("keydown", onKey);
    loadStats();
    focus();
});
onBeforeUnmount(() => window.removeEventListener("keydown", onKey));
</script>

<template>
    <PageHeader
        title="Đóng gói"
        description='Dùng máy quét mã vạch hoặc gõ mã. Mỗi lần quét đúng nghe "tít", sai nghe "bíp bíp".'
    >
        <Button
            v-if="tenant.can('orders.print')"
            variant="outline"
            :loading="busy === 'pick'"
            data-testid="pack-picklist"
            @click="printPickList"
            ><ClipboardList /> In phiếu nhặt hàng</Button
        >
        <Button variant="outline" @click="router.push('/orders?tab=to_pack')"
            ><ListChecks /> Danh sách chờ đóng gói<template v-if="stats">
                ({{ stats.remaining }})</template
            ></Button
        >
    </PageHeader>

    <form class="relative mb-2" @submit.prevent="submit">
        <ScanBarcode
            class="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-slate-500"
        />
        <input
            ref="input"
            v-model="code"
            :placeholder="placeholder"
            autocomplete="off"
            spellcheck="false"
            aria-label="Mã quét"
            data-testid="pack-scan"
            class="h-14 w-full rounded-xl border-2 border-blue-300 bg-card pl-12 pr-4 text-lg shadow-[0_0_0_4px_rgba(37,99,235,0.08)] outline-none focus:border-primary"
            @blur="focus"
        />
    </form>
    <p
        v-if="flash"
        :class="
            cn(
                'mb-4 flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium',
                flash.tone === 'ok' && 'bg-green-50 text-green-700',
                flash.tone === 'error' && 'bg-red-50 text-red-700',
                flash.tone === 'info' && 'bg-slate-100 text-slate-700',
            )
        "
        role="status"
        data-testid="pack-flash"
        :data-tone="flash.tone"
    >
        <CircleAlert
            v-if="flash.tone === 'error'"
            class="size-4 shrink-0"
        /><Check v-else-if="flash.tone === 'ok'" class="size-4 shrink-0" />{{
            flash.text
        }}
    </p>
    <div v-else class="mb-4" />

    <div class="grid gap-4 lg:grid-cols-[1fr_300px]">
        <Card class="overflow-hidden p-0">
            <template v-if="order">
                <div
                    class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b px-5 py-3.5"
                >
                    <RouterLink
                        :to="`/orders/${order.id}`"
                        class="text-lg font-bold text-primary hover:underline"
                        data-testid="pack-order"
                        >{{ order.external_order_id }}</RouterLink
                    >
                    <Badge tone="warning">Chờ đóng gói</Badge>
                    <span class="text-sm text-muted-foreground">
                        {{
                            [
                                order.shop ??
                                    marketplaceNames[order.marketplace] ??
                                    order.marketplace,
                                order.buyer_name,
                                order.carrier,
                                order.is_cod
                                    ? `COD ${formatMoney(order.total)}`
                                    : null,
                            ]
                                .filter(Boolean)
                                .join(" · ")
                        }}
                    </span>
                    <span
                        v-if="order.note"
                        class="ml-auto text-sm text-muted-foreground"
                        >Ghi chú:
                        <b class="text-foreground">{{ order.note }}</b></span
                    >
                </div>
                <button
                    v-for="l in order.lines"
                    :key="l.id"
                    type="button"
                    :class="
                        cn(
                            'flex w-full items-center gap-4 border-b px-5 py-3 text-left',
                            (counts[l.id] ?? 0) >= l.quantity
                                ? 'bg-green-50/70'
                                : lastLine === l.id
                                  ? 'bg-blue-50/70'
                                  : 'hover:bg-slate-50',
                        )
                    "
                    data-testid="pack-line"
                    :data-done="(counts[l.id] ?? 0) >= l.quantity"
                    @click="tap(l.id, l.quantity)"
                >
                    <img
                        v-if="l.image"
                        :src="l.image"
                        alt=""
                        class="size-11 shrink-0 rounded-lg border object-cover"
                    />
                    <span
                        v-else
                        class="size-11 shrink-0 rounded-lg border bg-slate-50"
                    />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium"
                            >{{ l.name
                            }}<template v-if="l.variant_name">
                                · {{ l.variant_name }}</template
                            ></span
                        >
                        <span
                            class="block truncate font-mono text-xs text-muted-foreground"
                            >{{
                                [
                                    l.sku,
                                    l.barcode ?? "không có mã vạch",
                                    l.location ? `kệ ${l.location}` : null,
                                ]
                                    .filter(Boolean)
                                    .join(" · ")
                            }}</span
                        >
                    </span>
                    <span class="w-20 text-right tabular-nums"
                        ><b class="text-xl">{{ counts[l.id] ?? 0 }}</b
                        ><span class="text-sm text-muted-foreground">
                            / {{ l.quantity }}</span
                        ></span
                    >
                    <span class="hidden w-40 sm:block">
                        <span
                            class="block h-1.5 overflow-hidden rounded-full bg-slate-100"
                            ><span
                                :class="
                                    cn(
                                        'block h-full rounded-full',
                                        (counts[l.id] ?? 0) >= l.quantity
                                            ? 'bg-green-600'
                                            : 'bg-primary',
                                    )
                                "
                                :style="{
                                    width: `${Math.min(100, ((counts[l.id] ?? 0) / l.quantity) * 100)}%`,
                                }"
                        /></span>
                        <span
                            v-if="(counts[l.id] ?? 0) >= l.quantity"
                            class="mt-1 block text-center text-xs font-semibold text-green-700"
                            >✓ Đủ</span
                        >
                        <span
                            v-else-if="!l.barcode && !(counts[l.id] ?? 0)"
                            class="mt-1 block text-center text-xs font-medium text-blue-600"
                            >Bấm để đánh dấu</span
                        >
                        <span
                            v-else
                            class="mt-1 block text-center text-xs font-medium text-blue-600"
                            >Còn {{ l.quantity - (counts[l.id] ?? 0) }}</span
                        >
                    </span>
                </button>
                <div
                    class="flex flex-wrap items-center gap-3 px-5 py-3 text-sm text-muted-foreground"
                >
                    <span data-testid="pack-progress"
                        >Đã quét {{ progress.done }}/{{ progress.total }} sản
                        phẩm</span
                    >
                    <span class="ml-auto hidden md:inline"
                        >Phím tắt:
                        <kbd class="rounded border px-1 text-xs">Esc</kbd> bỏ
                        đơn này ·
                        <kbd class="rounded border px-1 text-xs">Ctrl</kbd>+<kbd
                            class="rounded border px-1 text-xs"
                            >Z</kbd
                        >
                        trừ lần quét vừa rồi</span
                    >
                    <Button
                        :disabled="!progress.complete && !settings.allowShort"
                        :loading="busy === 'finish'"
                        data-testid="pack-finish"
                        @click="tryFinish"
                        >Hoàn tất (Enter)</Button
                    >
                </div>
            </template>
            <div v-else class="grid place-items-center px-6 py-16 text-center">
                <ScanBarcode class="mb-3 size-10 text-slate-300" />
                <p class="font-medium">
                    Quét mã đơn hoặc mã vận đơn để bắt đầu
                </p>
                <p class="mt-1 max-w-md text-sm text-muted-foreground">
                    Chỉ mở được đơn đang "Chờ đóng gói". Đủ hàng thì bấm Enter:
                    đơn chuyển sang "Chờ bàn giao" và tự in phiếu đóng gói.
                </p>
            </div>
        </Card>

        <div class="space-y-4">
            <Card
                class="grid grid-cols-3 gap-2 p-3 text-center"
                data-testid="pack-stats"
            >
                <div>
                    <p class="text-xs text-muted-foreground">Đã đóng hôm nay</p>
                    <p class="text-2xl font-bold tabular-nums">
                        {{ stats?.packed_today ?? "—" }}
                    </p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">Quét sai</p>
                    <p
                        :class="
                            cn(
                                'text-2xl font-bold tabular-nums',
                                stats?.errors_today && 'text-red-600',
                            )
                        "
                    >
                        {{ stats?.errors_today ?? "—" }}
                    </p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">Còn chờ</p>
                    <p class="text-2xl font-bold tabular-nums">
                        {{ stats?.remaining ?? "—" }}
                    </p>
                </div>
            </Card>
            <Card class="px-4 py-3">
                <p class="mb-1 text-sm font-semibold">Tùy chọn</p>
                <Switch
                    v-if="tenant.can('orders.print')"
                    v-model="settings.autoPrint"
                    label="Tự in phiếu đóng gói khi đủ hàng"
                />
                <Switch v-model="settings.sound" label="Âm thanh khi quét" />
                <Switch
                    v-model="settings.allowShort"
                    label="Cho phép đóng thiếu (cần ghi lý do)"
                />
            </Card>
            <Card class="px-4 py-3">
                <p class="mb-1 text-sm font-semibold">Vừa đóng xong</p>
                <p
                    v-if="!stats?.recent.length"
                    class="py-2 text-sm text-muted-foreground"
                >
                    Chưa có đơn nào hôm nay.
                </p>
                <RouterLink
                    v-for="r in stats?.recent ?? []"
                    :key="r.id + r.at"
                    :to="`/orders/${r.id}`"
                    class="flex items-center justify-between border-t py-2 text-sm first:border-t-0"
                >
                    <span class="font-semibold text-primary">{{
                        r.external_order_id
                    }}</span
                    ><span class="text-xs text-muted-foreground"
                        >{{ r.items }} SP · {{ time(r.at) }}</span
                    >
                </RouterLink>
            </Card>
        </div>
    </div>

    <Dialog
        :open="shortOpen"
        title="Đóng thiếu hàng"
        :description="
            order
                ? `Còn thiếu: ${shortLines(order.lines, counts).join(', ')}. Lý do được lưu vào ghi chú của đơn.`
                : ''
        "
        @update:open="(v) => ((shortOpen = v), focus())"
    >
        <FormField label="Lý do đóng thiếu" for="pack-short-reason"
            ><Input
                id="pack-short-reason"
                v-model="shortReason"
                placeholder="Hết hàng, đã báo khách…"
                @keydown.enter.prevent="
                    shortReason.trim() && finish(shortReason.trim())
                "
        /></FormField>
        <template #footer>
            <Button variant="outline" @click="((shortOpen = false), focus())"
                >Quay lại quét</Button
            >
            <Button
                :disabled="!shortReason.trim()"
                :loading="busy === 'finish'"
                data-testid="pack-short-confirm"
                @click="finish(shortReason.trim())"
                >Đóng thiếu</Button
            >
        </template>
    </Dialog>

    <Dialog
        :open="!!switchTo"
        title="Chuyển sang đơn khác?"
        :description="
            switchTo && order
                ? `Bạn vừa quét mã đơn ${switchTo.external_order_id}. Đơn ${order.external_order_id} đang gói dở (${progress.done}/${progress.total}) sẽ bị bỏ, chưa đóng gói.`
                : ''
        "
        @update:open="(v) => !v && ((switchTo = null), focus())"
    >
        <template #footer>
            <Button variant="outline" @click="((switchTo = null), focus())"
                >Gói tiếp đơn này</Button
            >
            <Button data-testid="pack-switch" @click="confirmSwitch"
                >Chuyển sang {{ switchTo?.external_order_id }}</Button
            >
        </template>
    </Dialog>
</template>
