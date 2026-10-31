import { reactive } from 'vue';

/** Short pop-up messages at the corner of the command room, e.g. "New check-in to confirm". */
export const toasts = reactive([]);
let next = 0;

export function toast(t) {
    const id = ++next;
    toasts.push({ id, tone: 'info', ...t });
    if (toasts.length > 4) toasts.splice(0, toasts.length - 4);
    setTimeout(() => dismiss(id), t.ms ?? 10000);
    return id;
}

export function dismiss(id) {
    const i = toasts.findIndex((t) => t.id === id);
    if (i >= 0) toasts.splice(i, 1);
}
