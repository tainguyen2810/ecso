<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import { Camera, X } from 'lucide-vue-next';

/**
 * "Quét bằng camera" (Đợt 8): reads barcodes and QR codes with the phone camera (the browser's BarcodeDetector:
 * Chrome on Android, Edge, Samsung Internet). `continuous` keeps the camera open for the next code (packing items);
 * the same code is not read twice within 1.5 s.
 */
defineOptions({ inheritAttrs: false });
const props = withDefaults(defineProps<{ continuous?: boolean; label?: string }>(), { continuous: false, label: 'Quét bằng camera' });
const emit = defineEmits<{ scan: [code: string] }>();

interface Detected { rawValue: string }
interface Detector { detect: (source: HTMLVideoElement) => Promise<Detected[]> }
type DetectorClass = { new (opts?: { formats?: string[] }): Detector; getSupportedFormats?: () => Promise<string[]> };

const open = ref(false);
const error = ref('');
const last = ref('');
const video = ref<HTMLVideoElement | null>(null);
let stream: MediaStream | null = null;
let timer: number | undefined;
let lastAt = 0;

const Ctor = (typeof window !== 'undefined' ? (window as unknown as { BarcodeDetector?: DetectorClass }).BarcodeDetector : undefined);
const supported = !!Ctor && typeof navigator !== 'undefined' && !!navigator.mediaDevices?.getUserMedia;

async function start() {
    error.value = '';
    last.value = '';
    open.value = true;
    if (!supported || !Ctor) {
        error.value = 'Trình duyệt này chưa quét được bằng camera. Dùng Chrome trên Android, hoặc dùng máy quét / gõ mã.';
        return;
    }
    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
        if (!video.value) return;
        video.value.srcObject = stream;
        await video.value.play();
        const wanted = ['code_128', 'code_39', 'code_93', 'ean_13', 'ean_8', 'upc_a', 'upc_e', 'itf', 'qr_code', 'data_matrix', 'codabar'];
        const formats = Ctor.getSupportedFormats ? (await Ctor.getSupportedFormats()).filter((f) => wanted.includes(f)) : wanted;
        const detector = new Ctor({ formats });
        const tick = async () => {
            if (!open.value || !video.value) return;
            try {
                const found = await detector.detect(video.value);
                const code = found[0]?.rawValue?.trim();
                if (code && (code !== last.value || Date.now() - lastAt > 1500)) {
                    last.value = code;
                    lastAt = Date.now();
                    navigator.vibrate?.(80);
                    emit('scan', code);
                    if (!props.continuous) return stop();
                }
            } catch {
                // A frame that could not be read: try the next one.
            }
            timer = window.setTimeout(tick, 200);
        };
        void tick();
    } catch {
        error.value = 'Không mở được camera. Cho phép trang dùng camera trong cài đặt trình duyệt rồi thử lại.';
    }
}

function stop() {
    open.value = false;
    if (timer) window.clearTimeout(timer);
    stream?.getTracks().forEach((t) => t.stop());
    stream = null;
}

onBeforeUnmount(stop);
</script>

<template>
    <button type="button" :title="label" :aria-label="label" data-testid="camera-scan" v-bind="$attrs" @mousedown.prevent @click="start">
        <Camera class="size-5" />
    </button>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[80] flex flex-col bg-black" role="dialog" :aria-label="label" data-testid="camera-scan-dialog">
            <div class="flex items-center justify-between px-4 py-3 text-white">
                <span class="text-sm font-semibold">{{ label }}</span>
                <button type="button" class="grid size-9 place-items-center rounded-full bg-white/15" aria-label="Đóng" @click="stop"><X class="size-5" /></button>
            </div>
            <div class="relative flex-1 overflow-hidden">
                <video ref="video" class="absolute inset-0 size-full object-cover" playsinline muted />
                <div class="pointer-events-none absolute inset-x-8 top-1/2 h-40 -translate-y-1/2 rounded-2xl border-2 border-white/80 shadow-[0_0_0_9999px_rgba(0,0,0,0.45)]" />
                <p v-if="error" class="absolute inset-x-6 top-1/2 -translate-y-1/2 rounded-xl bg-white p-4 text-center text-sm text-slate-800">{{ error }}</p>
            </div>
            <p class="px-4 py-4 text-center text-sm text-white/90">
                <template v-if="last">Đã đọc: <b>{{ last }}</b>{{ continuous ? ' · đưa mã tiếp theo vào khung' : '' }}</template>
                <template v-else>Đưa mã vạch hoặc mã QR vào khung</template>
            </p>
        </div>
    </Teleport>
</template>
