<script setup lang="ts">
import { ref } from 'vue';
import draggable from 'vuedraggable';
import { CheckCircle2, ChevronRight, Circle, Plus, MessageSquare, Paperclip, CalendarDays, Flag } from 'lucide-vue-next';
import { priorityMeta, isOverdue, formatDue, initials } from '@/lib/trackerUi';

type Assignee = { id: number; full_name: string };
type Label = { id: number; name: string; color: string };
type Task = {
    id: number;
    task_number: number;
    title: string;
    priority: string;
    position: number;
    due_date: string | null;
    completed_at: string | null;
    source: string;
    labels: Label[];
    assignees: Assignee[];
    comments_count: number;
    attachments_count: number;
};
type Status = { id: number; name: string; color: string; status_category: string; wip_limit: number | null; tasks: Task[] };
type Column = { key: string; name: string; color: string; status?: Status; tasks: Task[] };

defineProps<{
    columns: Column[];
    canEdit: boolean;
    dragEnabled: boolean;
}>();

const emit = defineEmits<{
    (e: 'open-task', id: number): void;
    (e: 'toggle-complete', task: Task): void;
    (e: 'add-task', statusId: number, title: string): void;
    (e: 'task-change', status: Status, evt: any): void;
}>();

const collapsed = ref<Record<string, boolean>>({});

// Inline quick-add per section.
const addingTo = ref<string | null>(null);
const newTitle = ref('');
function startAdd(key: string) {
    addingTo.value = key;
    newTitle.value = '';
}
function submitAdd(col: Column) {
    const title = newTitle.value.trim();
    if (!title || !col.status) return;
    emit('add-task', col.status.id, title);
    newTitle.value = '';
}
</script>

<template>
    <div class="min-w-[640px] rounded-lg border border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
        <!-- Table header -->
        <div class="grid grid-cols-[minmax(0,1fr)_150px_90px_100px] gap-2 border-b border-neutral-200 px-3 py-2 text-xs font-medium uppercase tracking-wide text-neutral-400 dark:border-neutral-800">
            <span>Задача</span>
            <span>Исполнители</span>
            <span>Срок</span>
            <span>Приоритет</span>
        </div>

        <div v-for="col in columns" :key="col.key">
            <!-- Section header -->
            <button
                class="flex w-full items-center gap-1.5 border-b border-neutral-100 bg-neutral-50/60 px-2 py-1.5 text-sm font-medium hover:bg-neutral-100 dark:border-neutral-800 dark:bg-neutral-800/40 dark:hover:bg-neutral-800"
                @click="collapsed[col.key] = !collapsed[col.key]"
            >
                <ChevronRight class="size-3.5 text-neutral-400 transition-transform" :class="{ 'rotate-90': !collapsed[col.key] }" />
                <span class="size-2 rounded-full" :style="{ backgroundColor: col.color }" />
                {{ col.name }}
                <span class="text-xs font-normal text-neutral-400">{{ col.tasks.length }}</span>
            </button>

            <template v-if="!collapsed[col.key]">
                <draggable
                    :list="col.tasks"
                    group="list-tasks"
                    item-key="id"
                    :animation="150"
                    :disabled="!dragEnabled || !col.status"
                    @change="(e: any) => col.status && emit('task-change', col.status, e)"
                >
                    <template #item="{ element: t }">
                        <div
                            class="group grid cursor-pointer grid-cols-[minmax(0,1fr)_150px_90px_100px] items-center gap-2 border-b border-neutral-100 px-3 py-1.5 text-sm hover:bg-neutral-50 dark:border-neutral-800 dark:hover:bg-neutral-800/50"
                            @click="emit('open-task', t.id)"
                        >
                            <!-- Title cell -->
                            <div class="flex min-w-0 items-center gap-2">
                                <button
                                    class="shrink-0"
                                    :class="t.completed_at ? 'text-green-500' : 'text-neutral-300 hover:text-green-500 dark:text-neutral-600'"
                                    :title="t.completed_at ? 'Вернуть в работу' : 'Отметить выполненной'"
                                    :disabled="!canEdit"
                                    @click.stop="emit('toggle-complete', t)"
                                >
                                    <component :is="t.completed_at ? CheckCircle2 : Circle" class="size-4" />
                                </button>
                                <span class="truncate" :class="{ 'text-neutral-400 line-through': t.completed_at }">{{ t.title }}</span>
                                <span
                                    v-for="l in t.labels"
                                    :key="l.id"
                                    class="shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium text-white"
                                    :style="{ backgroundColor: l.color }"
                                >{{ l.name }}</span>
                                <span v-if="t.comments_count" class="inline-flex shrink-0 items-center gap-0.5 text-xs text-neutral-400"><MessageSquare class="size-3" />{{ t.comments_count }}</span>
                                <span v-if="t.attachments_count" class="inline-flex shrink-0 items-center gap-0.5 text-xs text-neutral-400"><Paperclip class="size-3" />{{ t.attachments_count }}</span>
                            </div>

                            <!-- Assignees -->
                            <div class="flex items-center -space-x-1">
                                <span
                                    v-for="a in t.assignees.slice(0, 4)"
                                    :key="a.id"
                                    :title="a.full_name"
                                    class="flex size-5 items-center justify-center rounded-full bg-indigo-200 text-[10px] font-medium text-indigo-800 ring-1 ring-white dark:bg-indigo-500/40 dark:text-indigo-100 dark:ring-neutral-900"
                                >{{ initials(a.full_name) }}</span>
                                <span v-if="t.assignees.length > 4" class="pl-2 text-xs text-neutral-400">+{{ t.assignees.length - 4 }}</span>
                                <span v-if="!t.assignees.length" class="text-xs text-neutral-300 dark:text-neutral-600">—</span>
                            </div>

                            <!-- Due -->
                            <span
                                v-if="t.due_date"
                                class="inline-flex items-center gap-1 text-xs"
                                :class="isOverdue(t) ? 'font-medium text-red-500' : 'text-neutral-500'"
                            >
                                <CalendarDays class="size-3" /> {{ formatDue(t.due_date) }}
                            </span>
                            <span v-else class="text-xs text-neutral-300 dark:text-neutral-600">—</span>

                            <!-- Priority -->
                            <span v-if="t.priority !== 'none'" class="inline-flex items-center gap-1 text-xs" :style="{ color: priorityMeta[t.priority].color }">
                                <Flag class="size-3" /> {{ priorityMeta[t.priority].label }}
                            </span>
                            <span v-else class="text-xs text-neutral-300 dark:text-neutral-600">—</span>
                        </div>
                    </template>
                </draggable>

                <p v-if="!col.tasks.length && addingTo !== col.key" class="border-b border-neutral-100 px-9 py-2 text-xs text-neutral-400 dark:border-neutral-800">
                    Нет задач
                </p>

                <!-- Quick add -->
                <form
                    v-if="addingTo === col.key && col.status"
                    class="flex items-center gap-2 border-b border-neutral-100 px-3 py-1.5 dark:border-neutral-800"
                    @submit.prevent="submitAdd(col)"
                >
                    <Circle class="size-4 shrink-0 text-neutral-200 dark:text-neutral-700" />
                    <input
                        v-model="newTitle"
                        v-focus
                        placeholder="Название задачи — Enter для сохранения"
                        class="flex-1 bg-transparent text-sm outline-none placeholder:text-neutral-400"
                        @keydown.esc="addingTo = null"
                        @blur="!newTitle.trim() && (addingTo = null)"
                    />
                </form>
                <button
                    v-else-if="canEdit && col.status"
                    class="flex w-full items-center gap-1.5 border-b border-neutral-100 px-3 py-1.5 text-left text-xs text-neutral-400 hover:bg-neutral-50 hover:text-neutral-600 dark:border-neutral-800 dark:hover:bg-neutral-800/50"
                    @click="startAdd(col.key)"
                >
                    <Plus class="size-3.5" /> Добавить задачу
                </button>
            </template>
        </div>
    </div>
</template>

<script lang="ts">
// Tiny autofocus directive for the quick-add input.
export default {
    directives: {
        focus: { mounted: (el: HTMLElement) => el.focus() },
    },
};
</script>
