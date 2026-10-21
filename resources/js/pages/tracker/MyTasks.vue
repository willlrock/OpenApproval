<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { CheckCircle2, Circle, ChevronRight, CalendarDays, Flag } from 'lucide-vue-next';
import TrackerLayout from '@/layouts/tracker/TrackerLayout.vue';
import { api } from '@/lib/trackerApi';
import { toast } from '@/lib/trackerToast';
import { priorityMeta, formatDue } from '@/lib/trackerUi';

type MyTask = {
    id: number; title: string; task_number: number; priority: string;
    due_date: string | null; completed_at: string | null;
    project: { id: number; name: string; color: string } | null;
    status: { name: string; color: string } | null;
};
type MyTasks = { upcoming: MyTask[]; overdue: MyTask[]; completed: MyTask[]; completed_count: number };

const props = defineProps<{ myTasks: MyTasks }>();

type Section = { key: keyof Omit<MyTasks, 'completed_count'>; title: string; accent?: string };
const sections: Section[] = [
    { key: 'overdue', title: 'Просрочено', accent: 'text-red-500' },
    { key: 'upcoming', title: 'Предстоящие' },
    { key: 'completed', title: 'Завершено' },
];

// Completed collapsed by default — Asana hides done work out of the way.
const collapsed = ref<Record<string, boolean>>({ completed: true });

const busy = ref<Record<number, boolean>>({});
async function toggleComplete(t: MyTask) {
    if (busy.value[t.id]) return;
    busy.value[t.id] = true;
    try {
        const r = await api<{ completed_at: string | null }>('PATCH', `/tracker/tasks/${t.id}/complete`);
        router.reload({ only: ['myTasks'] });
        toast(r.completed_at ? 'Задача выполнена' : 'Задача возвращена в работу', {
            actionLabel: 'Отменить',
            onAction: async () => {
                await api('PATCH', `/tracker/tasks/${t.id}/complete`);
                router.reload({ only: ['myTasks'] });
            },
        });
    } finally {
        busy.value[t.id] = false;
    }
}
</script>

<template>
    <TrackerLayout>
        <Head title="Мои задачи" />
        <template #title>Мои задачи</template>

        <div class="mx-auto max-w-4xl">
            <h1 class="mb-4 text-2xl font-semibold">Мои задачи</h1>

            <div class="rounded-lg border border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                <div v-for="s in sections" :key="s.key">
                    <button
                        class="flex w-full items-center gap-1.5 border-b border-neutral-100 bg-neutral-50/60 px-2 py-2 text-sm font-medium hover:bg-neutral-100 dark:border-neutral-800 dark:bg-neutral-800/40 dark:hover:bg-neutral-800"
                        @click="collapsed[s.key] = !collapsed[s.key]"
                    >
                        <ChevronRight class="size-3.5 text-neutral-400 transition-transform" :class="{ 'rotate-90': !collapsed[s.key] }" />
                        <span :class="s.accent">{{ s.title }}</span>
                        <span
                            class="rounded-full px-1.5 text-xs font-normal"
                            :class="s.key === 'overdue' && myTasks[s.key].length ? 'bg-red-100 text-red-600 dark:bg-red-500/20 dark:text-red-300' : 'text-neutral-400'"
                        >{{ myTasks[s.key].length }}</span>
                    </button>

                    <template v-if="!collapsed[s.key]">
                        <div
                            v-for="t in myTasks[s.key]"
                            :key="t.id"
                            class="flex items-center gap-2 border-b border-neutral-100 px-3 py-2 text-sm hover:bg-neutral-50 dark:border-neutral-800 dark:hover:bg-neutral-800/50"
                        >
                            <button
                                class="shrink-0"
                                :class="t.completed_at ? 'text-green-500' : 'text-neutral-300 hover:text-green-500 dark:text-neutral-600'"
                                :title="t.completed_at ? 'Вернуть в работу' : 'Отметить выполненной'"
                                :disabled="busy[t.id]"
                                @click="toggleComplete(t)"
                            >
                                <component :is="t.completed_at ? CheckCircle2 : Circle" class="size-4" />
                            </button>

                            <Link
                                :href="`/tracker/projects/${t.project?.id}?task=${t.id}`"
                                class="min-w-0 flex-1 truncate"
                                :class="{ 'text-neutral-400 line-through': t.completed_at }"
                            >{{ t.title }}</Link>

                            <span v-if="t.status" class="hidden shrink-0 items-center gap-1 text-xs text-neutral-400 sm:inline-flex">
                                <span class="size-2 rounded-full" :style="{ backgroundColor: t.status.color }" /> {{ t.status.name }}
                            </span>
                            <span v-if="t.priority !== 'none'" class="hidden shrink-0 items-center gap-1 text-xs sm:inline-flex" :style="{ color: priorityMeta[t.priority].color }">
                                <Flag class="size-3" /> {{ priorityMeta[t.priority].label }}
                            </span>
                            <span
                                v-if="t.project"
                                class="inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 text-xs"
                                :style="{ backgroundColor: t.project.color + '22', color: t.project.color }"
                            >{{ t.project.name }}</span>
                            <span
                                v-if="t.due_date"
                                class="inline-flex w-16 shrink-0 items-center gap-0.5 text-xs"
                                :class="s.key === 'overdue' ? 'font-medium text-red-500' : 'text-neutral-400'"
                            >
                                <CalendarDays class="size-3" /> {{ formatDue(t.due_date) }}
                            </span>
                            <span v-else class="w-16 shrink-0" />
                        </div>
                        <p v-if="!myTasks[s.key].length" class="border-b border-neutral-100 px-9 py-3 text-xs text-neutral-400 dark:border-neutral-800">
                            {{ s.key === 'overdue' ? 'Нет просроченных задач 🎉' : 'Пусто' }}
                        </p>
                    </template>
                </div>
            </div>
        </div>
    </TrackerLayout>
</template>
