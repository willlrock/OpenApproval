import { requestStatusLabels } from '@/lib/requestLabels';

const moneyFormatter = new Intl.NumberFormat('ru-RU', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

export function formatMoney(value: number | string | null | undefined): string {
    if (value == null || (typeof value === 'string' && !value.trim()))
        return '—';
    const amount = Number(value);
    return Number.isFinite(amount) ? moneyFormatter.format(amount) : '—';
}

export type StatusTone =
    | 'neutral'
    | 'pending'
    | 'success'
    | 'danger'
    | 'supply'
    | 'paid';

export const statusToneClasses: Record<StatusTone, string> = {
    neutral: 'bg-muted text-muted-foreground',
    pending: 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-400',
    success:
        'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400',
    danger: 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-400',
    supply: 'bg-violet-50 text-violet-700 dark:bg-violet-950 dark:text-violet-400',
    paid: 'bg-sky-50 text-sky-700 dark:bg-sky-950 dark:text-sky-400',
};

const requestTones: Record<string, StatusTone> = {
    draft: 'neutral',
    approval_paused: 'pending',
    pending_approval: 'pending',
    approved: 'success',
    revision_requested: 'pending',
    pending_supply: 'supply',
    rejected: 'danger',
    cancelled: 'neutral',
    paid: 'paid',
};

export function requestStatusPresentation(status: string) {
    return {
        label: requestStatusLabels[status] ?? 'Статус не определён',
        tone: requestTones[status] ?? 'neutral',
    };
}
