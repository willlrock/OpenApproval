import { ref } from 'vue';

// Tracker-wide toast bus. State is module-level so any page/component can
// fire a toast; TrackerLayout renders the stack.

export type TrackerToast = {
    id: number;
    message: string;
    actionLabel?: string;
    onAction?: () => void;
};

export const toasts = ref<TrackerToast[]>([]);

let seq = 1;
const timers = new Map<number, number>();

export function toast(
    message: string,
    opts: { actionLabel?: string; onAction?: () => void; duration?: number } = {},
): number {
    const id = seq++;
    toasts.value.push({ id, message, actionLabel: opts.actionLabel, onAction: opts.onAction });
    timers.set(id, window.setTimeout(() => dismissToast(id), opts.duration ?? 6000));
    return id;
}

export function dismissToast(id: number): void {
    const t = timers.get(id);
    if (t) window.clearTimeout(t);
    timers.delete(id);
    toasts.value = toasts.value.filter((x) => x.id !== id);
}

export function runToastAction(t: TrackerToast): void {
    dismissToast(t.id);
    t.onAction?.();
}
