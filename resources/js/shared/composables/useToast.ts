import { ref } from 'vue';

export interface Toast {
    id: number;
    type: 'success' | 'error' | 'info';
    message: string;
}

const toasts = ref<Toast[]>([]);
let seq = 0;

export function useToast() {
    function push(type: Toast['type'], message: string, timeout = 4000) {
        const id = ++seq;
        toasts.value.push({ id, type, message });
        setTimeout(() => dismiss(id), timeout);
    }

    function dismiss(id: number) {
        toasts.value = toasts.value.filter((t) => t.id !== id);
    }

    return {
        toasts,
        dismiss,
        success: (m: string) => push('success', m),
        error: (m: string) => push('error', m, 6000),
        info: (m: string) => push('info', m),
    };
}
