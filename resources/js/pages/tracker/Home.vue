<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Plus, UserPlus, CalendarDays, Flag, CheckCircle2, AlertTriangle, Clock } from 'lucide-vue-next';
import TrackerLayout from '@/layouts/tracker/TrackerLayout.vue';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

type Project = { id: number; name: string; color: string; icon: string | null; tasks_count: number };
type Space = { id: number; name: string; color: string; icon: string | null; projects: Project[] };
type Workspace = { id: number; name: string; can_manage?: boolean; spaces: Space[] };
type InvitableUser = { id: number; full_name: string; phone: string | null };
type MyTask = {
    id: number; title: string; task_number: number; priority: string;
    due_date: string | null; completed_at: string | null;
    project: { id: number; name: string; color: string } | null;
    status: { name: string; color: string } | null;
};
type MyTasks = { upcoming: MyTask[]; overdue: MyTask[]; completed: MyTask[]; completed_count: number };

const props = defineProps<{
    workspaces: Workspace[];
    myTasks: MyTasks;
    isTrackerAdmin: boolean;
    invitableUsers: InvitableUser[];
}>();

// ── Greeting / date header ──────────────────────────────────────────────────
const now = new Date();
const dateLine = now.toLocaleDateString('ru-RU', { weekday: 'long', day: 'numeric', month: 'long' });
const greeting = computed(() => {
    const h = now.getHours();
    if (h < 6) return 'Доброй ночи';
    if (h < 12) return 'Доброе утро';
    if (h < 18) return 'Добрый день';
    return 'Добрый вечер';
});

const priorityColor: Record<string, string> = {
    urgent: '#ef4444', high: '#f97316', medium: '#3b82f6', low: '#9ca3af', none: 'transparent',
};
function formatDue(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }) : '';
}

// ── My Tasks tabs ───────────────────────────────────────────────────────────
type Tab = 'upcoming' | 'overdue' | 'completed';
const tabs: Tab[] = ['upcoming', 'overdue', 'completed'];
const tab = ref<Tab>(props.myTasks.overdue.length ? 'overdue' : 'upcoming');
const tabTasks = computed<MyTask[]>(() => props.myTasks[tab.value]);

// ── Create workspace / project / invite ─────────────────────────────────────
const showNewWorkspace = ref(false);
const workspaceForm = useForm({ name: '', description: '' });
const submitWorkspace = () => {
    workspaceForm.post('/tracker/workspaces', {
        preserveScroll: true,
        onSuccess: () => { workspaceForm.reset(); showNewWorkspace.value = false; },
    });
};

const projectPalette = ['#6366f1', '#ec4899', '#f59e0b', '#10b981', '#3b82f6', '#8b5cf6', '#ef4444', '#14b8a6'];
const newProjectFor = ref<number | null>(null);
const projectForm = useForm({ workspace_id: 0, name: '', color: projectPalette[0] });
const openNewProject = (workspaceId: number) => {
    newProjectFor.value = workspaceId;
    projectForm.workspace_id = workspaceId;
    projectForm.name = '';
    projectForm.color = projectPalette[0];
};
const submitProject = () => {
    projectForm.post('/tracker/projects', {
        preserveScroll: true,
        onSuccess: () => { projectForm.reset(); newProjectFor.value = null; },
    });
};

const inviteFor = ref<number | null>(null);
const inviteForm = useForm({ user_id: null as number | null, role: 'workspace_member' });
const inviteUserId = computed<string>({
    get: () => (inviteForm.user_id != null ? String(inviteForm.user_id) : ''),
    set: (v: string) => { inviteForm.user_id = v ? Number(v) : null; },
});
const openInvite = (workspaceId: number) => { inviteFor.value = workspaceId; inviteForm.reset(); };
const submitInvite = (workspaceId: number) => {
    inviteForm.post(`/tracker/workspaces/${workspaceId}/invitations`, {
        preserveScroll: true,
        onSuccess: () => { inviteForm.reset(); inviteFor.value = null; },
    });
};

const hasProjects = computed(() => props.workspaces.some((w) => w.spaces.some((s) => s.projects.length)));
</script>

<template>
    <TrackerLayout>
        <Head title="Task Tracker" />
        <template #title>Главная</template>

        <div class="mx-auto max-w-5xl space-y-6">
            <!-- Header band -->
            <header>
                <p class="text-sm capitalize text-neutral-400">{{ dateLine }}</p>
                <div class="flex items-end justify-between">
                    <h1 class="text-2xl font-semibold">{{ greeting }} 👋</h1>
                    <span class="text-sm text-neutral-500">{{ myTasks.completed_count }} задач завершено</span>
                </div>
            </header>

            <!-- My Tasks widget -->
            <section class="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                <div class="mb-3 flex items-center gap-1 border-b border-neutral-100 dark:border-neutral-800">
                    <Link href="/tracker/my-tasks" class="order-last ml-auto pb-2 text-xs text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300">
                        Все мои задачи →
                    </Link>
                    <button
                        v-for="t in tabs"
                        :key="t"
                        class="-mb-px flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium"
                        :class="tab === t ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200'"
                        @click="tab = t"
                    >
                        <Clock v-if="t === 'upcoming'" class="size-3.5" />
                        <AlertTriangle v-else-if="t === 'overdue'" class="size-3.5" />
                        <CheckCircle2 v-else class="size-3.5" />
                        {{ t === 'upcoming' ? 'Предстоящие' : t === 'overdue' ? 'Просрочено' : 'Завершено' }}
                        <span
                            v-if="myTasks[t].length"
                            class="rounded-full px-1.5 text-[11px]"
                            :class="t === 'overdue' ? 'bg-red-100 text-red-600 dark:bg-red-500/20 dark:text-red-300' : 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800'"
                        >{{ myTasks[t].length }}</span>
                    </button>
                </div>

                <ul v-if="tabTasks.length" class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <li v-for="t in tabTasks" :key="t.id">
                        <Link :href="`/tracker/projects/${t.project?.id}?task=${t.id}`" class="flex items-center gap-3 py-2 text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                            <span v-if="t.priority !== 'none'" class="size-2 shrink-0 rounded-full" :style="{ backgroundColor: priorityColor[t.priority] }" />
                            <span class="min-w-0 flex-1 truncate" :class="{ 'text-neutral-400 line-through': t.completed_at }">{{ t.title }}</span>
                            <span v-if="t.project" class="inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 text-xs" :style="{ backgroundColor: t.project.color + '22', color: t.project.color }">
                                {{ t.project.name }}
                            </span>
                            <span v-if="t.due_date" class="inline-flex shrink-0 items-center gap-0.5 text-xs" :class="tab === 'overdue' ? 'text-red-500' : 'text-neutral-400'">
                                <CalendarDays class="size-3" /> {{ formatDue(t.due_date) }}
                            </span>
                        </Link>
                    </li>
                </ul>

                <!-- Empty state -->
                <div v-else class="py-10 text-center">
                    <CheckCircle2 class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
                    <p class="text-sm text-neutral-500">
                        {{ tab === 'overdue' ? 'Нет просроченных задач' : tab === 'completed' ? 'Пока ничего не завершено' : 'Всё под контролем 🎉' }}
                    </p>
                </div>
            </section>

            <!-- Workspaces / Spaces / Projects -->
            <section>
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-lg font-semibold">Рабочие пространства</h2>
                    <button
                        v-if="isTrackerAdmin"
                        class="inline-flex items-center gap-1.5 rounded-md bg-neutral-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-white dark:text-neutral-900"
                        @click="showNewWorkspace = !showNewWorkspace"
                    >
                        <Plus class="size-4" /> Пространство
                    </button>
                </div>

                <form v-if="showNewWorkspace" class="mb-4 flex gap-2 rounded-lg border border-neutral-200 bg-white p-3 dark:border-neutral-800 dark:bg-neutral-900" @submit.prevent="submitWorkspace">
                    <input v-model="workspaceForm.name" placeholder="Название пространства" class="flex-1 rounded-md border border-neutral-300 px-3 py-1.5 text-sm dark:border-neutral-700 dark:bg-neutral-800" />
                    <button class="rounded-md bg-neutral-900 px-3 py-1.5 text-sm text-white dark:bg-white dark:text-neutral-900" :disabled="workspaceForm.processing">Создать</button>
                </form>

                <div v-if="!workspaces.length" class="rounded-lg border border-dashed border-neutral-300 p-10 text-center text-sm text-neutral-500 dark:border-neutral-700">
                    Пока нет пространств.
                </div>

                <div v-for="w in workspaces" :key="w.id" class="mb-6">
                    <div class="mb-2 flex items-center justify-between">
                        <h3 class="font-medium">{{ w.name }}</h3>
                        <div class="flex items-center gap-3">
                            <button v-if="w.can_manage" class="inline-flex items-center gap-1 text-sm text-neutral-500 hover:text-neutral-900 dark:hover:text-neutral-100" @click="openInvite(w.id)">
                                <UserPlus class="size-4" /> Пригласить
                            </button>
                            <button class="inline-flex items-center gap-1 text-sm text-neutral-500 hover:text-neutral-900 dark:hover:text-neutral-100" @click="openNewProject(w.id)">
                                <Plus class="size-4" /> Проект
                            </button>
                        </div>
                    </div>

                    <!-- Invite form -->
                    <form v-if="inviteFor === w.id" class="mb-3 flex flex-wrap gap-2 rounded-lg border border-neutral-200 bg-white p-3 dark:border-neutral-800 dark:bg-neutral-900" @submit.prevent="submitInvite(w.id)">
                        <Select v-model="inviteUserId">
                            <SelectTrigger class="min-w-56 flex-1"><SelectValue placeholder="Выберите пользователя…" /></SelectTrigger>
                            <SelectContent class="max-h-72">
                                <SelectItem v-for="u in invitableUsers" :key="u.id" :value="String(u.id)">
                                    {{ u.full_name }}<span v-if="u.phone" class="text-neutral-400"> · {{ u.phone }}</span>
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <Select v-model="inviteForm.role">
                            <SelectTrigger class="w-44"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="workspace_admin">Админ пространства</SelectItem>
                                <SelectItem value="workspace_member">Участник</SelectItem>
                                <SelectItem value="workspace_guest">Гость</SelectItem>
                            </SelectContent>
                        </Select>
                        <button class="rounded-md bg-neutral-900 px-3 py-1.5 text-sm text-white disabled:opacity-50 dark:bg-white dark:text-neutral-900" :disabled="inviteForm.processing || !inviteForm.user_id">Пригласить</button>
                        <p v-if="inviteForm.errors.user_id" class="w-full text-xs text-red-500">{{ inviteForm.errors.user_id }}</p>
                    </form>

                    <!-- New project form -->
                    <form v-if="newProjectFor === w.id" class="mb-3 flex flex-wrap items-center gap-2 rounded-lg border border-neutral-200 bg-white p-3 dark:border-neutral-800 dark:bg-neutral-900" @submit.prevent="submitProject">
                        <input v-model="projectForm.name" placeholder="Название проекта" class="flex-1 rounded-md border border-neutral-300 px-3 py-1.5 text-sm dark:border-neutral-700 dark:bg-neutral-800" />
                        <div class="flex items-center gap-1">
                            <button
                                v-for="c in projectPalette"
                                :key="c"
                                type="button"
                                class="size-5 rounded-full ring-offset-1 transition"
                                :class="projectForm.color === c ? 'ring-2 ring-neutral-900 dark:ring-white' : ''"
                                :style="{ backgroundColor: c }"
                                @click="projectForm.color = c"
                            />
                        </div>
                        <button class="rounded-md bg-neutral-900 px-3 py-1.5 text-sm text-white dark:bg-white dark:text-neutral-900" :disabled="projectForm.processing">Создать</button>
                    </form>

                    <!-- Spaces + projects -->
                    <div v-for="s in w.spaces" :key="s.id" class="mb-3">
                        <p class="mb-1.5 flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-neutral-400">
                            <span class="size-2.5 rounded-sm" :style="{ backgroundColor: s.color }" /> {{ s.name }}
                        </p>
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                            <Link
                                v-for="p in s.projects"
                                :key="p.id"
                                :href="`/tracker/projects/${p.id}`"
                                class="flex h-24 flex-col justify-between rounded-lg border border-neutral-200 bg-white p-3 transition hover:shadow-md dark:border-neutral-800 dark:bg-neutral-900"
                            >
                                <span class="flex size-7 items-center justify-center rounded-md text-sm font-semibold text-white" :style="{ backgroundColor: p.color }">
                                    {{ p.name.charAt(0).toUpperCase() }}
                                </span>
                                <div>
                                    <span class="block truncate text-sm font-medium">{{ p.name }}</span>
                                    <span class="text-xs text-neutral-400">{{ p.tasks_count }} задач</span>
                                </div>
                            </Link>
                            <p v-if="!s.projects.length" class="col-span-full text-sm text-neutral-400">Нет проектов в этом спейсе.</p>
                        </div>
                    </div>
                    <p v-if="!w.spaces.length" class="text-sm text-neutral-400">Нет спейсов.</p>
                </div>
            </section>
        </div>
    </TrackerLayout>
</template>
