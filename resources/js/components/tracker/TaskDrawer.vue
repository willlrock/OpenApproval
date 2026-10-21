<script setup lang="ts">
import { ref, watch, computed, onUnmounted } from 'vue';
import {
    X, Check, Plus, Trash2, Eye, EyeOff, Tag, ListChecks, User as UserIcon, Paperclip, Download,
    CheckCircle2, Flag, CalendarDays, CircleDot, History, Send,
} from 'lucide-vue-next';
import { api, upload } from '@/lib/trackerApi';
import { toast } from '@/lib/trackerToast';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { initials } from '@/lib/trackerUi';

const props = defineProps<{ taskId: number | null }>();
const emit = defineEmits<{ (e: 'close'): void; (e: 'changed'): void }>();

const detail = ref<any>(null);
const loading = ref(false);

const priorities = ['none', 'low', 'medium', 'high', 'urgent'];
const labelPalette = ['#ef4444', '#f97316', '#eab308', '#22c55e', '#3b82f6', '#8b5cf6', '#ec4899', '#6b7280'];

async function load() {
    if (!props.taskId) return;
    loading.value = true;
    try {
        detail.value = await api('GET', `/tracker/tasks/${props.taskId}`);
    } catch {
        emit('close'); // deleted task or lost access — don't strand the user on a spinner
    } finally {
        loading.value = false;
    }
}
watch(() => props.taskId, load, { immediate: true });

// Esc closes the drawer (Asana/Trello behaviour).
function onKeydown(e: KeyboardEvent) {
    if (e.key !== 'Escape') return;
    const el = e.target as HTMLElement;
    if (el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA')) {
        el.blur(); // first Esc leaves the field, second closes
        return;
    }
    emit('close');
}
watch(
    () => props.taskId,
    (id) => {
        if (id !== null) window.addEventListener('keydown', onKeydown);
        else window.removeEventListener('keydown', onKeydown);
    },
    { immediate: true },
);
onUnmounted(() => window.removeEventListener('keydown', onKeydown));

const canEdit = computed(() => detail.value?.can_edit ?? false);

const priorityLabel: Record<string, string> = {
    none: 'Без приоритета', low: 'Низкий', medium: 'Средний', high: 'Высокий', urgent: 'Срочно',
};
// Writable bridge so the Select persists straight through saveField.
const priorityModel = computed<string>({
    get: () => detail.value?.priority ?? 'none',
    set: (v: string) => saveField('priority', v),
});

// Status select — moving appends the task to the end of the target column.
const statusModel = computed<string>({
    get: () => (detail.value?.status_id != null ? String(detail.value.status_id) : ''),
    set: async (v: string) => {
        if (!v || Number(v) === detail.value.status_id) return;
        await api('PATCH', `/tracker/tasks/${detail.value.id}/move`, { status_id: Number(v) });
        await load();
        emit('changed');
    },
});

// Asana-style one-click complete (with undo toast).
const completing = ref(false);
async function toggleComplete() {
    if (!canEdit.value || completing.value) return;
    completing.value = true;
    const taskId = detail.value.id;
    try {
        const r = await api<{ completed_at: string | null }>('PATCH', `/tracker/tasks/${taskId}/complete`);
        await load();
        emit('changed');
        toast(r.completed_at ? 'Задача выполнена' : 'Задача возвращена в работу', {
            actionLabel: 'Отменить',
            onAction: async () => {
                await api('PATCH', `/tracker/tasks/${taskId}/complete`);
                if (detail.value?.id === taskId) await load();
                emit('changed');
            },
        });
    } finally {
        completing.value = false;
    }
}

function toLocal(iso: string | null): string {
    return iso ? iso.slice(0, 10) : '';
}

async function saveField(field: string, value: any) {
    await api('PUT', `/tracker/tasks/${detail.value.id}`, { [field]: value });
    detail.value[field] = value;
    emit('changed');
}

async function addAssignee(userId: number) {
    await api('POST', `/tracker/tasks/${detail.value.id}/assignees`, { user_id: userId });
    await load();
    emit('changed');
}
async function removeAssignee(userId: number) {
    await api('DELETE', `/tracker/tasks/${detail.value.id}/assignees/${userId}`);
    await load();
    emit('changed');
}
const assigneeIds = computed<number[]>(() => (detail.value?.assignees ?? []).map((a: any) => a.id));

async function toggleWatch() {
    const r = await api<{ watching: boolean }>('POST', `/tracker/tasks/${detail.value.id}/watch`);
    detail.value.is_watching = r.watching;
    await load();
}

// Labels
const newLabelName = ref('');
async function createLabel() {
    if (!newLabelName.value.trim()) return;
    const color = labelPalette[(detail.value.project_labels?.length ?? 0) % labelPalette.length];
    await api('POST', `/tracker/projects/${detail.value.project_id}/labels`, { name: newLabelName.value, color });
    newLabelName.value = '';
    await load();
}
const labelIds = computed<number[]>(() => (detail.value?.labels ?? []).map((l: any) => l.id));
async function toggleLabel(labelId: number) {
    if (labelIds.value.includes(labelId)) {
        await api('DELETE', `/tracker/tasks/${detail.value.id}/labels/${labelId}`);
    } else {
        await api('POST', `/tracker/tasks/${detail.value.id}/labels`, { label_id: labelId });
    }
    await load();
    emit('changed');
}

// Checklists
const newChecklistTitle = ref('');
async function addChecklist() {
    if (!newChecklistTitle.value.trim()) return;
    await api('POST', `/tracker/tasks/${detail.value.id}/checklists`, { title: newChecklistTitle.value });
    newChecklistTitle.value = '';
    await load();
}
const newItem = ref<Record<number, string>>({});
async function addItem(checklistId: number) {
    const content = newItem.value[checklistId];
    if (!content?.trim()) return;
    await api('POST', `/tracker/checklists/${checklistId}/items`, { content });
    newItem.value[checklistId] = '';
    await load();
}
async function toggleItem(item: any) {
    await api('PUT', `/tracker/checklist-items/${item.id}`, { is_completed: !item.is_completed });
    item.is_completed = !item.is_completed;
}
async function deleteItem(id: number) {
    if (!window.confirm('Удалить пункт чек-листа?')) return;
    await api('DELETE', `/tracker/checklist-items/${id}`);
    await load();
}
function checklistProgress(cl: any): string {
    const total = cl.items.length;
    const done = cl.items.filter((i: any) => i.is_completed).length;
    return `${done}/${total}`;
}

// Comments
const commentBody = ref('');
const commentMentions = ref<number[]>([]);
const showMentions = ref(false);
function toggleMention(id: number) {
    const i = commentMentions.value.indexOf(id);
    if (i >= 0) commentMentions.value.splice(i, 1);
    else commentMentions.value.push(id);
}
async function addComment() {
    if (!commentBody.value.trim()) return;
    await api('POST', `/tracker/tasks/${detail.value.id}/comments`, {
        body: commentBody.value,
        mentions: commentMentions.value,
    });
    commentBody.value = '';
    commentMentions.value = [];
    showMentions.value = false;
    await load();
}
async function deleteComment(id: number) {
    if (!window.confirm('Удалить комментарий?')) return;
    await api('DELETE', `/tracker/tasks/${detail.value.id}/comments/${id}`);
    await load();
}
function formatWhen(iso: string | null): string {
    if (!iso) return '';
    return new Date(iso).toLocaleString('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

// Attachments
const uploading = ref(false);
async function uploadFiles(e: Event) {
    const input = e.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    if (!files.length) return;
    uploading.value = true;
    try {
        for (const file of files) {
            await upload(`/tracker/tasks/${detail.value.id}/attachments`, file);
        }
        await load();
        emit('changed');
    } finally {
        uploading.value = false;
        input.value = '';
    }
}
async function deleteAttachment(id: number) {
    if (!window.confirm('Удалить вложение?')) return;
    await api('DELETE', `/tracker/tasks/${detail.value.id}/attachments/${id}`);
    await load();
}
function formatSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

// Activity (collapsed by default — comments carry the conversation)
const showActivity = ref(false);
function activityText(a: any): string {
    const who = a.actor?.full_name ?? 'Кто-то';
    const map: Record<string, string> = {
        task_created: 'создал(а) задачу',
        task_updated: 'изменил(а) задачу',
        task_moved: 'переместил(а) задачу',
        assignee_added: 'назначил(а) исполнителя',
        assignee_removed: 'снял(а) исполнителя',
        comment_added: 'оставил(а) комментарий',
        attachment_added: 'добавил(а) вложение',
        attachment_removed: 'удалил(а) вложение',
    };
    return `${who} ${map[a.type] ?? a.type}`;
}
</script>

<template>
    <Teleport to="body">
        <div v-if="taskId !== null" class="fixed inset-0 z-50 flex justify-end">
            <div class="absolute inset-0 bg-black/30" @click="emit('close')" />

            <div class="relative z-10 flex h-full w-full max-w-2xl flex-col bg-white shadow-xl dark:bg-neutral-900">
                <div v-if="loading || !detail" class="p-6 text-sm text-neutral-400">Загрузка…</div>

                <template v-else>
                    <!-- Action bar -->
                    <div class="flex shrink-0 items-center gap-2 border-b border-neutral-200 px-4 py-2.5 dark:border-neutral-800">
                        <button
                            v-if="canEdit"
                            class="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-sm font-medium transition"
                            :class="detail.completed_at
                                ? 'border-green-500 bg-green-500 text-white hover:bg-green-600'
                                : 'border-neutral-300 text-neutral-600 hover:border-green-500 hover:text-green-600 dark:border-neutral-600 dark:text-neutral-300'"
                            :disabled="completing"
                            @click="toggleComplete"
                        >
                            <CheckCircle2 class="size-4" />
                            {{ detail.completed_at ? 'Выполнено' : 'Отметить выполненной' }}
                        </button>
                        <span class="text-xs text-neutral-400">#{{ detail.task_number }}</span>
                        <span v-if="detail.source === 'form'" class="rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-medium text-indigo-600 dark:bg-indigo-500/20 dark:text-indigo-300">via form</span>

                        <div class="ml-auto flex items-center gap-1">
                            <button
                                class="rounded-md p-1.5 text-neutral-500 hover:bg-neutral-100 dark:hover:bg-neutral-800"
                                :title="detail.is_watching ? 'Перестать следить' : 'Следить'"
                                @click="toggleWatch"
                            >
                                <component :is="detail.is_watching ? Eye : EyeOff" class="size-4" />
                            </button>
                            <button class="rounded-md p-1.5 text-neutral-500 hover:bg-neutral-100 dark:hover:bg-neutral-800" @click="emit('close')">
                                <X class="size-4" />
                            </button>
                        </div>
                    </div>

                    <!-- Scrollable body -->
                    <div class="min-h-0 flex-1 overflow-y-auto">
                        <div class="space-y-5 p-4">
                            <!-- Title -->
                            <input
                                v-model="detail.title"
                                :disabled="!canEdit"
                                class="w-full bg-transparent text-xl font-semibold outline-none"
                                :class="{ 'text-neutral-400 line-through': detail.completed_at }"
                                @blur="saveField('title', detail.title)"
                                @keydown.enter="($event.target as HTMLInputElement).blur()"
                            />

                            <!-- Fields grid (Asana-style) -->
                            <div class="grid grid-cols-[110px_minmax(0,1fr)] items-center gap-x-3 gap-y-2.5 text-sm">
                                <span class="flex items-center gap-1.5 text-neutral-400"><UserIcon class="size-3.5" /> Исполнители</span>
                                <div class="flex flex-wrap items-center gap-1">
                                    <span v-for="a in detail.assignees" :key="a.id" class="inline-flex items-center gap-1 rounded-full bg-neutral-100 py-0.5 pl-1 pr-2 text-xs dark:bg-neutral-800">
                                        <span class="flex size-4 items-center justify-center rounded-full bg-indigo-200 text-[9px] font-medium text-indigo-800 dark:bg-indigo-500/40 dark:text-indigo-100">{{ initials(a.full_name) }}</span>
                                        {{ a.full_name }}
                                        <button v-if="canEdit" @click="removeAssignee(a.id)"><X class="size-3" /></button>
                                    </span>
                                    <Select v-if="canEdit" :model-value="''" @update:model-value="(v) => v && addAssignee(Number(v))">
                                        <SelectTrigger class="h-6 w-auto gap-1 rounded-full border-dashed px-2 text-xs text-neutral-400"><Plus class="size-3" /> добавить</SelectTrigger>
                                        <SelectContent class="max-h-72">
                                            <SelectItem
                                                v-for="m in detail.project_members.filter((x: any) => !assigneeIds.includes(x.id))"
                                                :key="m.id"
                                                :value="String(m.id)"
                                            >{{ m.full_name }}</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                <span class="flex items-center gap-1.5 text-neutral-400"><CircleDot class="size-3.5" /> Статус</span>
                                <Select v-model="statusModel" :disabled="!canEdit">
                                    <SelectTrigger class="h-8 w-52"><SelectValue placeholder="—" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem v-for="s in detail.project_statuses" :key="s.id" :value="String(s.id)">
                                            <span class="inline-flex items-center gap-1.5">
                                                <span class="size-2 rounded-full" :style="{ backgroundColor: s.color }" /> {{ s.name }}
                                            </span>
                                        </SelectItem>
                                    </SelectContent>
                                </Select>

                                <span class="flex items-center gap-1.5 text-neutral-400"><CalendarDays class="size-3.5" /> Срок</span>
                                <div class="flex items-center gap-2">
                                    <input type="date" :value="toLocal(detail.start_date)" :disabled="!canEdit" title="Начало" class="rounded-md border border-neutral-200 px-2 py-1 text-sm dark:border-neutral-700 dark:bg-neutral-800" @change="saveField('start_date', ($event.target as HTMLInputElement).value || null)" />
                                    <span class="text-neutral-300">→</span>
                                    <input type="date" :value="toLocal(detail.due_date)" :disabled="!canEdit" title="Дедлайн" class="rounded-md border border-neutral-200 px-2 py-1 text-sm dark:border-neutral-700 dark:bg-neutral-800" @change="saveField('due_date', ($event.target as HTMLInputElement).value || null)" />
                                </div>

                                <span class="flex items-center gap-1.5 text-neutral-400"><Flag class="size-3.5" /> Приоритет</span>
                                <Select v-model="priorityModel" :disabled="!canEdit">
                                    <SelectTrigger class="h-8 w-52"><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem v-for="p in priorities" :key="p" :value="p">{{ priorityLabel[p] }}</SelectItem>
                                    </SelectContent>
                                </Select>

                                <span class="flex items-center gap-1.5 self-start pt-1 text-neutral-400"><Tag class="size-3.5" /> Метки</span>
                                <div>
                                    <div class="flex flex-wrap gap-1">
                                        <button
                                            v-for="l in detail.project_labels"
                                            :key="l.id"
                                            :disabled="!canEdit"
                                            class="rounded px-2 py-0.5 text-xs text-white"
                                            :style="{ backgroundColor: l.color, opacity: labelIds.includes(l.id) ? 1 : 0.4 }"
                                            @click="toggleLabel(l.id)"
                                        >
                                            {{ l.name }}
                                        </button>
                                    </div>
                                    <form v-if="canEdit" class="mt-1 flex gap-1" @submit.prevent="createLabel">
                                        <input v-model="newLabelName" placeholder="новая метка" class="w-36 rounded-md border border-neutral-200 px-2 py-1 text-xs dark:border-neutral-700 dark:bg-neutral-800" />
                                        <button class="rounded-md bg-neutral-900 px-2 text-xs text-white dark:bg-white dark:text-neutral-900"><Plus class="size-3.5" /></button>
                                    </form>
                                </div>
                            </div>

                            <!-- Description -->
                            <section>
                                <h4 class="mb-1.5 text-xs font-medium uppercase tracking-wide text-neutral-400">Описание</h4>
                                <textarea
                                    v-model="detail.description"
                                    :disabled="!canEdit"
                                    rows="4"
                                    placeholder="Добавьте описание…"
                                    class="w-full resize-y rounded-md border border-neutral-200 p-2 text-sm dark:border-neutral-700 dark:bg-neutral-800"
                                    @blur="saveField('description', detail.description)"
                                />
                            </section>

                            <!-- Checklists -->
                            <section>
                                <h4 class="mb-2 flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-neutral-400">
                                    <ListChecks class="size-3.5" /> Чек-листы
                                </h4>
                                <div v-for="cl in detail.checklists" :key="cl.id" class="mb-3">
                                    <p class="mb-1 text-sm font-medium">{{ cl.title }} <span class="text-xs text-neutral-400">{{ checklistProgress(cl) }}</span></p>
                                    <ul class="space-y-1">
                                        <li v-for="it in cl.items" :key="it.id" class="group flex items-center gap-2 text-sm">
                                            <button
                                                class="flex size-4 shrink-0 items-center justify-center rounded border"
                                                :class="it.is_completed ? 'border-green-500 bg-green-500 text-white' : 'border-neutral-300 dark:border-neutral-600'"
                                                :disabled="!canEdit"
                                                @click="toggleItem(it)"
                                            >
                                                <Check v-if="it.is_completed" class="size-3" />
                                            </button>
                                            <span :class="{ 'text-neutral-400 line-through': it.is_completed }">{{ it.content }}</span>
                                            <span v-if="it.assignee" class="text-xs text-neutral-400">· {{ it.assignee.full_name }}</span>
                                            <button v-if="canEdit" class="ml-auto opacity-0 group-hover:opacity-100" @click="deleteItem(it.id)">
                                                <Trash2 class="size-3.5 text-neutral-400 hover:text-red-500" />
                                            </button>
                                        </li>
                                    </ul>
                                    <input
                                        v-if="canEdit"
                                        v-model="newItem[cl.id]"
                                        placeholder="+ пункт"
                                        class="mt-1 w-full rounded-md border border-neutral-200 px-2 py-1 text-sm dark:border-neutral-700 dark:bg-neutral-800"
                                        @keydown.enter="addItem(cl.id)"
                                    />
                                </div>
                                <form v-if="canEdit" class="flex gap-2" @submit.prevent="addChecklist">
                                    <input v-model="newChecklistTitle" placeholder="Новый чек-лист" class="flex-1 rounded-md border border-neutral-200 px-2 py-1 text-sm dark:border-neutral-700 dark:bg-neutral-800" />
                                    <button class="rounded-md bg-neutral-900 px-2 text-xs text-white dark:bg-white dark:text-neutral-900"><Plus class="size-4" /></button>
                                </form>
                            </section>

                            <!-- Attachments -->
                            <section>
                                <h4 class="mb-2 flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-neutral-400">
                                    <Paperclip class="size-3.5" /> Вложения
                                </h4>
                                <ul class="mb-2 space-y-1">
                                    <li v-for="att in detail.attachments" :key="att.id" class="group flex items-center gap-2 rounded-md border border-neutral-200 px-2 py-1.5 text-sm dark:border-neutral-700">
                                        <Paperclip class="size-3.5 shrink-0 text-neutral-400" />
                                        <a :href="att.url" class="min-w-0 flex-1 truncate hover:underline">{{ att.name }}</a>
                                        <span class="shrink-0 text-xs text-neutral-400">{{ formatSize(att.size) }}</span>
                                        <a :href="att.url" class="shrink-0 text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200" title="Скачать"><Download class="size-3.5" /></a>
                                        <button v-if="canEdit" class="shrink-0 opacity-0 group-hover:opacity-100" title="Удалить" @click="deleteAttachment(att.id)">
                                            <Trash2 class="size-3.5 text-neutral-400 hover:text-red-500" />
                                        </button>
                                    </li>
                                </ul>
                                <label v-if="canEdit" class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border border-dashed border-neutral-300 px-3 py-1.5 text-xs text-neutral-500 hover:border-neutral-400 dark:border-neutral-600">
                                    <Plus class="size-3.5" />
                                    <span>{{ uploading ? 'Загрузка…' : 'Прикрепить файл' }}</span>
                                    <input type="file" multiple class="hidden" :disabled="uploading" @change="uploadFiles" />
                                </label>
                            </section>

                            <!-- Comments feed -->
                            <section class="border-t border-neutral-100 pt-4 dark:border-neutral-800">
                                <div class="mb-2 flex items-center justify-between">
                                    <h4 class="text-xs font-medium uppercase tracking-wide text-neutral-400">Комментарии</h4>
                                    <button class="inline-flex items-center gap-1 text-xs text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300" @click="showActivity = !showActivity">
                                        <History class="size-3.5" /> {{ showActivity ? 'Скрыть активность' : 'Показать активность' }}
                                    </button>
                                </div>
                                <ul v-if="showActivity" class="mb-3 space-y-1 border-l-2 border-neutral-100 pl-3 text-xs text-neutral-500 dark:border-neutral-800">
                                    <li v-for="a in detail.activities" :key="a.id">
                                        {{ activityText(a) }} <span class="text-neutral-300 dark:text-neutral-600">· {{ formatWhen(a.created_at) }}</span>
                                    </li>
                                </ul>
                                <p v-if="!detail.comments.length" class="py-2 text-sm text-neutral-400">Комментариев пока нет.</p>
                                <ul class="space-y-3">
                                    <li v-for="c in detail.comments" :key="c.id" class="text-sm">
                                        <div class="flex items-center gap-2">
                                            <span class="flex size-6 items-center justify-center rounded-full bg-neutral-300 text-[10px] font-medium dark:bg-neutral-600">{{ initials(c.author?.full_name) }}</span>
                                            <span class="font-medium">{{ c.author?.full_name }}</span>
                                            <span class="text-xs text-neutral-400">{{ formatWhen(c.created_at) }}</span>
                                            <button v-if="c.can_delete" class="ml-auto text-neutral-400 hover:text-red-500" @click="deleteComment(c.id)">
                                                <Trash2 class="size-3.5" />
                                            </button>
                                        </div>
                                        <p class="ml-8 whitespace-pre-wrap text-neutral-700 dark:text-neutral-300">{{ c.body }}</p>
                                    </li>
                                </ul>
                            </section>
                        </div>
                    </div>

                    <!-- Pinned comment composer -->
                    <form v-if="canEdit" class="shrink-0 border-t border-neutral-200 bg-neutral-50 p-3 dark:border-neutral-800 dark:bg-neutral-900" @submit.prevent="addComment">
                        <div class="rounded-lg border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-800">
                            <textarea
                                v-model="commentBody"
                                rows="2"
                                placeholder="Написать комментарий…"
                                class="w-full resize-none rounded-t-lg bg-transparent p-2 text-sm outline-none"
                                @keydown.meta.enter="addComment"
                                @keydown.ctrl.enter="addComment"
                            />
                            <div class="flex items-center gap-2 border-t border-neutral-100 px-2 py-1.5 dark:border-neutral-700">
                                <button type="button" class="text-xs text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300" @click="showMentions = !showMentions">
                                    @ упомянуть<span v-if="commentMentions.length"> ({{ commentMentions.length }})</span>
                                </button>
                                <button class="ml-auto inline-flex items-center gap-1.5 rounded-md bg-neutral-900 px-3 py-1 text-xs text-white disabled:opacity-50 dark:bg-white dark:text-neutral-900" :disabled="!commentBody.trim()">
                                    <Send class="size-3" /> Отправить
                                </button>
                            </div>
                            <div v-if="showMentions" class="flex flex-wrap gap-1 border-t border-neutral-100 p-2 dark:border-neutral-700">
                                <button
                                    v-for="m in detail.project_members"
                                    :key="m.id"
                                    type="button"
                                    class="rounded-full border px-2 py-0.5 text-xs"
                                    :class="commentMentions.includes(m.id) ? 'border-indigo-400 bg-indigo-50 text-indigo-600 dark:border-indigo-500/40 dark:bg-indigo-500/20 dark:text-indigo-300' : 'border-neutral-200 text-neutral-500 dark:border-neutral-700'"
                                    @click="toggleMention(m.id)"
                                >@{{ m.full_name }}</button>
                            </div>
                        </div>
                    </form>
                </template>
            </div>
        </div>
    </Teleport>
</template>
