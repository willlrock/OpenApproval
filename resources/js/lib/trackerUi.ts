// Shared presentation helpers for the tracker UI (board, list, drawer, my-tasks).

export type PriorityMeta = { color: string; label: string; rank: number };

export const priorityMeta: Record<string, PriorityMeta> = {
    urgent: { color: '#ef4444', label: 'Срочно', rank: 4 },
    high: { color: '#f97316', label: 'Высокий', rank: 3 },
    medium: { color: '#3b82f6', label: 'Средний', rank: 2 },
    low: { color: '#9ca3af', label: 'Низкий', rank: 1 },
    none: { color: 'transparent', label: '—', rank: 0 },
};

export function isOverdue(t: { completed_at: string | null; due_date: string | null }): boolean {
    return !t.completed_at && !!t.due_date && new Date(t.due_date) < new Date(new Date().toDateString());
}

export function formatDue(iso: string): string {
    return new Date(iso).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
}

export function initials(name: string): string {
    return (name || '?').charAt(0).toUpperCase();
}
