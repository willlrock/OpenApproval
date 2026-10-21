<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import draggable from 'vuedraggable';
import {
    Plus, Search, SlidersHorizontal, ArrowUpDown, Group, MessageSquare, Paperclip, CalendarDays, X,
    List, Columns3, CheckCircle2, Circle, ArrowLeft,
} from 'lucide-vue-next';
import TrackerLayout from '@/layouts/tracker/TrackerLayout.vue';
import TaskDrawer from '@/components/tracker/TaskDrawer.vue';
import ListView from '@/components/tracker/ListView.vue';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { api } from '@/lib/trackerApi';
import { toast } from '@/lib/trackerToast';
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
type Project = {
    id: number; name: string; color: string; icon: string | null;
    workspace_id: number; space_id: number;
    workspace_name: string | null; space_name: string | null;
    can_edit: boolean; statuses: Status[];
};

const props = defineProps<{ project: Project }>();

const canEdit = computed(() => props.project.can_edit);

// Local reactive copy so vuedraggable can mutate task arrays.
const statuses = reactive(props.project.statuses.map((s) => ({ ...s, tasks: [...s.tasks] })));
watch(
    () => props.project,
    (p) => {
        statuses.splice(0, statuses.length, ...p.statuses.map((s) => ({ ...s, tasks: [...s.tasks] })));
    },
);

// ── Toolbar: view mode / search / filter / sort / group (persisted) ─────────
type View = {
    mode: 'list' | 'board';
    search: string;
    fAssignee: number | null;
    fPriority: string;
    fLabel: number | null;
    sortBy: 'manual' | 'due_date' | 'priority' | 'title' | 'created';
    sortDir: 'asc' | 'desc';
    groupBy: 'status' | 'assignee' | 'priority' | 'label';
};
const viewKey = `tracker.view.${props.project.id}`;
const defaultView: View = { mode: 'list', search: '', fAssignee: null, fPriority: '', fLabel: null, sortBy: 'manual', sortDir: 'asc', groupBy: 'status' };
const view = reactive<View>({ ...defaultView, ...readView() });
function readView(): Partial<View> {
    try {
        return JSON.parse(localStorage.getItem(viewKey) ?? '{}');
    } catch {
        return {};
    }
}
watch(view, (v) => localStorage.setItem(viewKey, JSON.stringify(v)), { deep: true });

const showFilters = ref(false);
const hasActiveFilters = computed(() => !!view.search || view.fAssignee != null || !!view.fPriority || view.fLabel != null);

// reka-ui Select binds strings and rejects empty values — bridge the numeric /
// "all" filter state through string getters/setters with an `all` sentinel.
const fAssigneeStr = computed<string>({
    get: () => (view.fAssignee != null ? String(view.fAssignee) : 'all'),
    set: (v: string) => { view.fAssignee = v === 'all' ? null : Number(v); },
});
const fLabelStr = computed<string>({
    get: () => (view.fLabel != null ? String(view.fLabel) : 'all'),
    set: (v: string) => { view.fLabel = v === 'all' ? null : Number(v); },
});
const fPriorityStr = computed<string>({
    get: () => (view.fPriority || 'all'),
    set: (v: string) => { view.fPriority = v === 'all' ? '' : v; },
});
function clearFilters() {
    view.search = '';
    view.fAssignee = null;
    view.fPriority = '';
    view.fLabel = null;
}

// All members / labels present on the board, for filter pickers.
const allAssignees = computed<Assignee[]>(() => {
    const m = new Map<number, Assignee>();
    statuses.forEach((s) => s.tasks.forEach((t) => t.assignees.forEach((a) => m.set(a.id, a))));
    return [...m.values()];
});
const allLabels = computed<Label[]>(() => {
    const m = new Map<number, Label>();
    statuses.forEach((s) => s.tasks.forEach((t) => t.labels.forEach((l) => m.set(l.id, l))));
    return [...m.values()];
});

function matchesFilters(t: Task): boolean {
    if (view.search && !t.title.toLowerCase().includes(view.search.toLowerCase())) return false;
    if (view.fAssignee != null && !t.assignees.some((a) => a.id === view.fAssignee)) return false;
    if (view.fPriority && t.priority !== view.fPriority) return false;
    if (view.fLabel != null && !t.labels.some((l) => l.id === view.fLabel)) return false;
    return true;
}
function sortTasks(tasks: Task[]): Task[] {
    if (view.sortBy === 'manual') return tasks;
    const dir = view.sortDir === 'asc' ? 1 : -1;
    return [...tasks].sort((a, b) => {
        let r = 0;
        if (view.sortBy === 'due_date') r = (a.due_date ?? '9999').localeCompare(b.due_date ?? '9999');
        else if (view.sortBy === 'priority') r = priorityMeta[b.priority].rank - priorityMeta[a.priority].rank;
        else if (view.sortBy === 'title') r = a.title.localeCompare(b.title);
        else if (view.sortBy === 'created') r = a.id - b.id;
        return r * dir;
    });
}

// Drag is only safe in the natural status layout with no reordering applied.
const dragEnabled = computed(() => canEdit.value && view.groupBy === 'status' && view.sortBy === 'manual' && !hasActiveFilters.value);

// When draggable, bind the real reactive array so reorders persist; otherwise a
// derived filtered/sorted list (read-only, mutations don't matter).
function columnTasks(status: Status): Task[] {
    return dragEnabled.value ? status.tasks : sortTasks(status.tasks.filter(matchesFilters));
}

// ── Columns derived from group-by ───────────────────────────────────────────
type Column = { key: string; name: string; color: string; status?: Status; tasks: Task[] };
const columns = computed<Column[]>(() => {
    const allTasks = statuses.flatMap((s) => s.tasks).filter(matchesFilters);

    if (view.groupBy === 'status') {
        return statuses.map((s) => ({
            key: `s${s.id}`,
            name: s.name,
            color: s.color,
            status: s,
            tasks: columnTasks(s),
        }));
    }
    if (view.groupBy === 'priority') {
        return ['urgent', 'high', 'medium', 'low', 'none'].map((p) => ({
            key: `p${p}`,
            name: priorityMeta[p].label,
            color: priorityMeta[p].color === 'transparent' ? '#9ca3af' : priorityMeta[p].color,
            tasks: sortTasks(allTasks.filter((t) => t.priority === p)),
        }));
    }
    if (view.groupBy === 'assignee') {
        const cols: Column[] = allAssignees.value.map((a) => ({
            key: `a${a.id}`,
            name: a.full_name,
            color: '#6366f1',
            tasks: sortTasks(allTasks.filter((t) => t.assignees.some((x) => x.id === a.id))),
        }));
        cols.push({ key: 'a0', name: 'Не назначено', color: '#9ca3af', tasks: sortTasks(allTasks.filter((t) => !t.assignees.length)) });
        return cols;
    }
    // label
    const cols: Column[] = allLabels.value.map((l) => ({
        key: `l${l.id}`,
        name: l.name,
        color: l.color,
        tasks: sortTasks(allTasks.filter((t) => t.labels.some((x) => x.id === l.id))),
    }));
    cols.push({ key: 'l0', name: 'Без метки', color: '#9ca3af', tasks: sortTasks(allTasks.filter((t) => !t.labels.length)) });
    return cols;
});

// ── Drag persistence (status layout only) ───────────────────────────────────
function computePosition(tasks: Task[], index: number): number {
    const prev = tasks[index - 1]?.position;
    const next = tasks[index + 1]?.position;
    if (prev != null && next != null) return (prev + next) / 2;
    if (next != null) return next / 2;
    if (prev != null) return prev + 1;
    return 1;
}
async function persistMove(task: Task, statusId: number, position: number) {
    task.position = position;
    await api('PATCH', `/tracker/tasks/${task.id}/move`, { status_id: statusId, position });
}
function onChange(status: Status, evt: any) {
    const change = evt.added ?? evt.moved;
    if (!change) return;
    const position = computePosition(status.tasks, change.newIndex);
    persistMove(change.element, status.id, position);
}
async function persistStatusOrder() {
    await api('PATCH', `/tracker/projects/${props.project.id}/statuses/reorder`, { order: statuses.map((s: Status) => s.id) });
}

// ── Complete toggle (Asana-style ✓, with undo toast) ────────────────────────
async function toggleComplete(task: Task) {
    if (!canEdit.value) return;
    const r = await api<{ completed_at: string | null }>('PATCH', `/tracker/tasks/${task.id}/complete`);
    task.completed_at = r.completed_at;
    router.reload({ only: ['project'] });
    toast(r.completed_at ? 'Задача выполнена' : 'Задача возвращена в работу', {
        actionLabel: 'Отменить',
        onAction: async () => {
            await api('PATCH', `/tracker/tasks/${task.id}/complete`);
            router.reload({ only: ['project'] });
        },
    });
}

// ── Quick-add task / status ─────────────────────────────────────────────────
const addingTo = ref<number | null>(null);
const taskForm = useForm({ status_id: 0, title: '' });
function submitTask() {
    taskForm.post(`/tracker/projects/${props.project.id}/tasks`, {
        preserveScroll: true,
        onSuccess: () => {
            taskForm.reset();
            addingTo.value = null;
            router.reload({ only: ['project'] });
        },
    });
}
function quickAddTask(statusId: number, title: string) {
    taskForm.status_id = statusId;
    taskForm.title = title;
    taskForm.post(`/tracker/projects/${props.project.id}/tasks`, {
        preserveScroll: true,
        onSuccess: () => {
            taskForm.reset();
            router.reload({ only: ['project'] });
        },
    });
}

const addingStatus = ref(false);
const statusForm = useForm({ name: '' });
function submitStatus() {
    statusForm.post(`/tracker/projects/${props.project.id}/statuses`, {
        preserveScroll: true,
        onSuccess: () => {
            statusForm.reset();
            addingStatus.value = false;
            router.reload({ only: ['project'] });
        },
    });
}

// Task detail drawer. The open task lives in the URL (?task=N) so links are
// shareable and refresh/back restore the drawer — Asana-style deep links.
const initialTask = Number(new URLSearchParams(window.location.search).get('task')) || null;
const openTaskId = ref<number | null>(initialTask);
watch(openTaskId, (id) => {
    const url = new URL(window.location.href);
    if (id) url.searchParams.set('task', String(id));
    else url.searchParams.delete('task');
    window.history.replaceState(window.history.state, '', url);
});
function onDrawerChanged() {
    router.reload({ only: ['project'] });
}
</script>

<template>
    <TrackerLayout>
        <Head :title="project.name" />
        <template #title>
            <span class="inline-flex items-center gap-1.5">
                <span v-if="project.workspace_name" class="hidden text-neutral-400 sm:inline">{{ project.workspace_name }}</span>
                <span v-if="project.workspace_name" class="hidden text-neutral-300 sm:inline">/</span>
                <span class="size-2.5 rounded-sm" :style="{ backgroundColor: project.color }" />
                {{ project.name }}
            </span>
        </template>

        <!-- Project header: back + name + view tabs (Asana-style) -->
        <div class="mb-3">
            <div class="flex items-center gap-2.5">
                <Link
                    href="/tracker"
                    class="rounded-md p-1.5 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                    title="Назад к доскам"
                >
                    <ArrowLeft class="size-4" />
                </Link>
                <span class="flex size-8 items-center justify-center rounded-lg text-sm font-semibold text-white" :style="{ backgroundColor: project.color }">
                    {{ project.name.charAt(0).toUpperCase() }}
                </span>
                <h1 class="truncate text-xl font-semibold">{{ project.name }}</h1>
            </div>
            <div class="mt-2 flex items-center gap-1 border-b border-neutral-200 dark:border-neutral-800">
                <button
                    class="-mb-px inline-flex items-center gap-1.5 border-b-2 px-3 py-1.5 text-sm font-medium"
                    :class="view.mode === 'list' ? 'border-neutral-900 text-neutral-900 dark:border-white dark:text-white' : 'border-transparent text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200'"
                    @click="view.mode = 'list'"
                >
                    <List class="size-4" /> Список
                </button>
                <button
                    class="-mb-px inline-flex items-center gap-1.5 border-b-2 px-3 py-1.5 text-sm font-medium"
                    :class="view.mode === 'board' ? 'border-neutral-900 text-neutral-900 dark:border-white dark:text-white' : 'border-transparent text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200'"
                    @click="view.mode = 'board'"
                >
                    <Columns3 class="size-4" /> Доска
                </button>
            </div>
        </div>

        <!-- Toolbar (§8) -->
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <div class="relative">
                <Search class="pointer-events-none absolute left-2 top-1/2 size-3.5 -translate-y-1/2 text-neutral-400" />
                <input
                    v-model="view.search"
                    placeholder="Поиск задач…"
                    class="w-48 rounded-md border border-neutral-200 py-1.5 pl-7 pr-2 text-sm dark:border-neutral-700 dark:bg-neutral-800"
                />
            </div>

            <button
                class="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-sm"
                :class="hasActiveFilters ? 'border-indigo-300 bg-indigo-50 text-indigo-700 dark:border-indigo-500/40 dark:bg-indigo-500/10 dark:text-indigo-300' : 'border-neutral-200 text-neutral-600 dark:border-neutral-700 dark:text-neutral-300'"
                @click="showFilters = !showFilters"
            >
                <SlidersHorizontal class="size-3.5" /> Фильтр
            </button>

            <div class="inline-flex items-center gap-1">
                <Select v-model="view.sortBy">
                    <SelectTrigger class="h-9 w-40">
                        <span class="inline-flex items-center gap-1.5"><ArrowUpDown class="size-3.5 text-neutral-400" /><SelectValue /></span>
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="manual">Порядок</SelectItem>
                        <SelectItem value="due_date">Дедлайн</SelectItem>
                        <SelectItem value="priority">Приоритет</SelectItem>
                        <SelectItem value="title">Название</SelectItem>
                        <SelectItem value="created">Создано</SelectItem>
                    </SelectContent>
                </Select>
                <button v-if="view.sortBy !== 'manual'" class="rounded-md border border-neutral-200 px-2 py-1.5 text-xs text-neutral-500 dark:border-neutral-700" @click="view.sortDir = view.sortDir === 'asc' ? 'desc' : 'asc'">
                    {{ view.sortDir === 'asc' ? '↑' : '↓' }}
                </button>
            </div>

            <Select v-model="view.groupBy">
                <SelectTrigger class="h-9 w-44">
                    <span class="inline-flex items-center gap-1.5"><Group class="size-3.5 text-neutral-400" /><SelectValue /></span>
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="status">Группа: Статус</SelectItem>
                    <SelectItem value="assignee">Группа: Исполнитель</SelectItem>
                    <SelectItem value="priority">Группа: Приоритет</SelectItem>
                    <SelectItem value="label">Группа: Метка</SelectItem>
                </SelectContent>
            </Select>

            <button v-if="hasActiveFilters" class="inline-flex items-center gap-1 text-sm text-neutral-400 hover:text-neutral-600" @click="clearFilters">
                <X class="size-3.5" /> Сбросить
            </button>
        </div>

        <!-- Filter panel -->
        <div v-if="showFilters" class="mb-4 flex flex-wrap gap-3 rounded-lg border border-neutral-200 bg-white p-3 text-sm dark:border-neutral-800 dark:bg-neutral-900">
            <label class="flex items-center gap-1.5">
                <span class="text-neutral-500">Исполнитель</span>
                <Select v-model="fAssigneeStr">
                    <SelectTrigger class="h-8 w-44"><SelectValue /></SelectTrigger>
                    <SelectContent class="max-h-72">
                        <SelectItem value="all">Все</SelectItem>
                        <SelectItem v-for="a in allAssignees" :key="a.id" :value="String(a.id)">{{ a.full_name }}</SelectItem>
                    </SelectContent>
                </Select>
            </label>
            <label class="flex items-center gap-1.5">
                <span class="text-neutral-500">Приоритет</span>
                <Select v-model="fPriorityStr">
                    <SelectTrigger class="h-8 w-36"><SelectValue /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Все</SelectItem>
                        <SelectItem v-for="p in ['urgent','high','medium','low','none']" :key="p" :value="p">{{ priorityMeta[p].label }}</SelectItem>
                    </SelectContent>
                </Select>
            </label>
            <label class="flex items-center gap-1.5">
                <span class="text-neutral-500">Метка</span>
                <Select v-model="fLabelStr">
                    <SelectTrigger class="h-8 w-44"><SelectValue /></SelectTrigger>
                    <SelectContent class="max-h-72">
                        <SelectItem value="all">Все</SelectItem>
                        <SelectItem v-for="l in allLabels" :key="l.id" :value="String(l.id)">{{ l.name }}</SelectItem>
                    </SelectContent>
                </Select>
            </label>
        </div>

        <p v-if="!dragEnabled && canEdit && view.groupBy === 'status'" class="mb-2 text-xs text-neutral-400">
            Перетаскивание выключено при активном фильтре/сортировке.
        </p>

        <!-- List view -->
        <ListView
            v-if="view.mode === 'list'"
            :columns="columns"
            :can-edit="canEdit"
            :drag-enabled="dragEnabled"
            @open-task="(id) => (openTaskId = id)"
            @toggle-complete="toggleComplete"
            @add-task="quickAddTask"
            @task-change="onChange"
        />

        <!-- Board view -->
        <div v-else class="flex items-start gap-3">
            <draggable
                v-if="view.groupBy === 'status'"
                :list="statuses"
                group="statuses"
                item-key="id"
                handle=".status-drag-handle"
                class="flex items-start gap-3"
                :animation="150"
                :disabled="!canEdit"
                @end="persistStatusOrder"
            >
                <template #item="{ element: status }">
                    <div class="flex max-h-[calc(100vh-16rem)] w-72 shrink-0 flex-col rounded-lg bg-neutral-100 dark:bg-neutral-900">
                        <!-- Coloured header strip -->
                        <div
                            class="status-drag-handle mb-1 flex items-center justify-between rounded-t-lg border-t-2 px-2.5 py-2"
                            :style="{ borderColor: status.color }"
                            :class="canEdit ? 'cursor-grab active:cursor-grabbing' : ''"
                        >
                            <span class="flex items-center gap-1.5 text-sm font-medium">
                                <span class="size-2 rounded-full" :style="{ backgroundColor: status.color }" />
                                {{ status.name }}
                            </span>
                            <span
                                class="rounded-full px-1.5 text-xs"
                                :class="status.wip_limit && status.tasks.length > status.wip_limit ? 'bg-red-100 text-red-600 dark:bg-red-500/20' : 'text-neutral-400'"
                            >
                                {{ statuses.find((s) => s.id === status.id)?.tasks.filter(matchesFilters).length ?? 0 }}<span v-if="status.wip_limit">/{{ status.wip_limit }}</span>
                            </span>
                        </div>

                        <draggable
                            :list="columnTasks(status)"
                            group="tasks"
                            item-key="id"
                            class="flex min-h-2 flex-1 flex-col gap-2 overflow-y-auto px-2 pb-2"
                            :animation="150"
                            :disabled="!dragEnabled"
                            @change="(e) => onChange(status, e)"
                        >
                            <template #item="{ element }">
                                <article
                                    class="group cursor-pointer rounded-lg border border-neutral-200 bg-white p-2.5 shadow-sm transition hover:shadow-md dark:border-neutral-700 dark:bg-neutral-800"
                                    @click="openTaskId = element.id"
                                >
                                    <!-- Label chips -->
                                    <div v-if="element.labels.length" class="mb-1.5 flex flex-wrap gap-1">
                                        <span
                                            v-for="l in element.labels"
                                            :key="l.id"
                                            class="rounded px-1.5 py-0.5 text-[10px] font-medium text-white"
                                            :style="{ backgroundColor: l.color }"
                                        >{{ l.name }}</span>
                                    </div>

                                    <div class="flex items-start gap-2">
                                        <button
                                            class="mt-0.5 shrink-0"
                                            :class="element.completed_at ? 'text-green-500' : 'text-neutral-300 hover:text-green-500 dark:text-neutral-600'"
                                            :title="element.completed_at ? 'Вернуть в работу' : 'Отметить выполненной'"
                                            :disabled="!canEdit"
                                            @click.stop="toggleComplete(element)"
                                        >
                                            <component :is="element.completed_at ? CheckCircle2 : Circle" class="size-4" />
                                        </button>
                                        <p class="text-sm" :class="{ 'text-neutral-400 line-through': element.completed_at }">{{ element.title }}</p>
                                        <span
                                            v-if="element.priority !== 'none'"
                                            class="ml-auto mt-1 size-2 shrink-0 rounded-full"
                                            :style="{ backgroundColor: priorityMeta[element.priority].color }"
                                            :title="priorityMeta[element.priority].label"
                                        />
                                    </div>

                                    <!-- Meta row -->
                                    <div class="mt-2 flex items-center gap-2 text-xs text-neutral-400">
                                        <span>#{{ element.task_number }}</span>
                                        <span v-if="element.source === 'form'" class="rounded bg-indigo-100 px-1 text-[10px] text-indigo-600 dark:bg-indigo-500/20 dark:text-indigo-300">form</span>
                                        <span
                                            v-if="element.due_date"
                                            class="inline-flex items-center gap-0.5 rounded px-1"
                                            :class="isOverdue(element) ? 'bg-red-100 text-red-600 dark:bg-red-500/20 dark:text-red-300' : ''"
                                        >
                                            <CalendarDays class="size-3" /> {{ formatDue(element.due_date) }}
                                        </span>
                                        <span v-if="element.comments_count" class="inline-flex items-center gap-0.5"><MessageSquare class="size-3" />{{ element.comments_count }}</span>
                                        <span v-if="element.attachments_count" class="inline-flex items-center gap-0.5"><Paperclip class="size-3" />{{ element.attachments_count }}</span>
                                        <div class="ml-auto flex -space-x-1">
                                            <span
                                                v-for="a in element.assignees"
                                                :key="a.id"
                                                :title="a.full_name"
                                                class="flex size-5 items-center justify-center rounded-full bg-indigo-200 text-[10px] font-medium text-indigo-800 ring-1 ring-white dark:bg-indigo-500/40 dark:text-indigo-100 dark:ring-neutral-800"
                                            >{{ initials(a.full_name) }}</span>
                                        </div>
                                    </div>
                                </article>
                            </template>
                        </draggable>

                        <form v-if="addingTo === status.id" class="px-2 pb-2" @submit.prevent="submitTask">
                            <textarea
                                v-model="taskForm.title"
                                rows="2"
                                placeholder="Название задачи"
                                class="w-full resize-none rounded-md border border-neutral-300 p-2 text-sm dark:border-neutral-600 dark:bg-neutral-800"
                                @keydown.enter.prevent="submitTask"
                            />
                            <div class="mt-1 flex gap-2">
                                <button class="rounded-md bg-neutral-900 px-2.5 py-1 text-xs text-white dark:bg-white dark:text-neutral-900" :disabled="taskForm.processing">Добавить</button>
                                <button type="button" class="text-xs text-neutral-400" @click="addingTo = null">Отмена</button>
                            </div>
                        </form>
                        <button
                            v-else-if="canEdit"
                            class="mx-2 mb-2 flex items-center gap-1 rounded-md px-1.5 py-1 text-left text-xs text-neutral-500 hover:bg-neutral-200 dark:hover:bg-neutral-800"
                            @click="addingTo = status.id; taskForm.status_id = status.id"
                        >
                            <Plus class="size-3.5" /> Добавить задачу
                        </button>
                    </div>
                </template>
            </draggable>

            <!-- Non-status groupings: read-only coloured columns -->
            <template v-else>
                <div v-for="col in columns" :key="col.key" class="flex max-h-[calc(100vh-16rem)] w-72 shrink-0 flex-col rounded-lg bg-neutral-100 dark:bg-neutral-900">
                    <div class="mb-1 flex items-center justify-between rounded-t-lg border-t-2 px-2.5 py-2" :style="{ borderColor: col.color }">
                        <span class="flex items-center gap-1.5 text-sm font-medium">
                            <span class="size-2 rounded-full" :style="{ backgroundColor: col.color }" />
                            {{ col.name }}
                        </span>
                        <span class="text-xs text-neutral-400">{{ col.tasks.length }}</span>
                    </div>
                    <div class="flex flex-1 flex-col gap-2 overflow-y-auto px-2 pb-2">
                        <article
                            v-for="t in col.tasks"
                            :key="t.id"
                            class="cursor-pointer rounded-lg border border-neutral-200 bg-white p-2.5 shadow-sm transition hover:shadow-md dark:border-neutral-700 dark:bg-neutral-800"
                            @click="openTaskId = t.id"
                        >
                            <div v-if="t.labels.length" class="mb-1.5 flex flex-wrap gap-1">
                                <span v-for="l in t.labels" :key="l.id" class="rounded px-1.5 py-0.5 text-[10px] font-medium text-white" :style="{ backgroundColor: l.color }">{{ l.name }}</span>
                            </div>
                            <p class="text-sm" :class="{ 'text-neutral-400 line-through': t.completed_at }">{{ t.title }}</p>
                            <div class="mt-2 flex items-center gap-2 text-xs text-neutral-400">
                                <span>#{{ t.task_number }}</span>
                                <span v-if="t.due_date" class="inline-flex items-center gap-0.5 rounded px-1" :class="isOverdue(t) ? 'bg-red-100 text-red-600 dark:bg-red-500/20 dark:text-red-300' : ''">
                                    <CalendarDays class="size-3" /> {{ formatDue(t.due_date) }}
                                </span>
                            </div>
                        </article>
                        <p v-if="!col.tasks.length" class="px-1 py-6 text-center text-xs text-neutral-400">Пусто</p>
                    </div>
                </div>
            </template>

            <!-- Add status (status layout only) -->
            <div v-if="canEdit && view.groupBy === 'status'" class="w-72 shrink-0">
                <form v-if="addingStatus" class="rounded-lg bg-neutral-100 p-2 dark:bg-neutral-900" @submit.prevent="submitStatus">
                    <input v-model="statusForm.name" placeholder="Название колонки" class="w-full rounded-md border border-neutral-300 px-2 py-1.5 text-sm dark:border-neutral-600 dark:bg-neutral-800" />
                    <div class="mt-1 flex gap-2">
                        <button class="rounded-md bg-neutral-900 px-2.5 py-1 text-xs text-white dark:bg-white dark:text-neutral-900" :disabled="statusForm.processing">Добавить</button>
                        <button type="button" class="text-xs text-neutral-400" @click="addingStatus = false">Отмена</button>
                    </div>
                </form>
                <button
                    v-else
                    class="flex w-full items-center gap-1 rounded-lg bg-neutral-100/60 px-3 py-2 text-sm text-neutral-500 hover:bg-neutral-100 dark:bg-neutral-900/60 dark:hover:bg-neutral-900"
                    @click="addingStatus = true"
                >
                    <Plus class="size-4" /> Добавить колонку
                </button>
            </div>
        </div>

        <TaskDrawer :task-id="openTaskId" @close="openTaskId = null" @changed="onDrawerChanged" />
    </TrackerLayout>
</template>
