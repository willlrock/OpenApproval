<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Home, CheckCircle2, ChevronRight } from 'lucide-vue-next';

// Sidebar navigation body — rendered in the desktop aside and the mobile
// slide-over, so it carries no chrome of its own.

const page = usePage();

const tracker = computed(
    () => (page.props.auth as any)?.user?.tracker ?? { workspaces: [], is_admin: false, tree: null },
);
type TreeProject = { id: number; name: string; color: string };
type TreeSpace = { id: number; name: string; color: string; projects: TreeProject[] };
type TreeWorkspace = { id: number; name: string; spaces: TreeSpace[] };
const tree = computed<TreeWorkspace[]>(() => tracker.value.tree ?? []);

const currentUrl = computed(() => page.url);
function isActive(href: string): boolean {
    return currentUrl.value === href || currentUrl.value.startsWith(href + '?');
}
function isProjectActive(id: number): boolean {
    return currentUrl.value.startsWith(`/tracker/projects/${id}`);
}

// Collapsible workspaces, expanded by default; state survives navigation.
const collapsed = ref<Record<number, boolean>>(readCollapsed());
function readCollapsed(): Record<number, boolean> {
    try {
        return JSON.parse(localStorage.getItem('tracker.sidebar.collapsed') ?? '{}');
    } catch {
        return {};
    }
}
function toggleWorkspace(id: number) {
    collapsed.value[id] = !collapsed.value[id];
    localStorage.setItem('tracker.sidebar.collapsed', JSON.stringify(collapsed.value));
}
</script>

<template>
    <div>
        <nav class="space-y-0.5 text-sm">
            <Link
                href="/tracker"
                class="flex items-center gap-2.5 rounded-md px-2 py-1.5"
                :class="isActive('/tracker') ? 'bg-neutral-100 font-medium dark:bg-neutral-800' : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'"
            >
                <Home class="size-4" /> Главная
            </Link>
            <Link
                href="/tracker/my-tasks"
                class="flex items-center gap-2.5 rounded-md px-2 py-1.5"
                :class="isActive('/tracker/my-tasks') ? 'bg-neutral-100 font-medium dark:bg-neutral-800' : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'"
            >
                <CheckCircle2 class="size-4" /> Мои задачи
            </Link>
        </nav>

        <!-- Workspace → project tree -->
        <div v-if="tree.length" class="mt-5">
            <p class="mb-1 px-2 text-xs font-medium uppercase tracking-wide text-neutral-400">Пространства</p>
            <div v-for="w in tree" :key="w.id" class="mb-1">
                <button
                    class="flex w-full items-center gap-1 rounded-md px-2 py-1.5 text-sm font-medium hover:bg-neutral-100 dark:hover:bg-neutral-800"
                    @click="toggleWorkspace(w.id)"
                >
                    <ChevronRight class="size-3.5 shrink-0 text-neutral-400 transition-transform" :class="{ 'rotate-90': !collapsed[w.id] }" />
                    <span class="truncate">{{ w.name }}</span>
                </button>
                <div v-if="!collapsed[w.id]" class="ml-3 border-l border-neutral-200 pl-2 dark:border-neutral-800">
                    <template v-for="s in w.spaces" :key="s.id">
                        <p v-if="w.spaces.length > 1" class="mt-1 truncate px-2 text-[11px] font-medium uppercase tracking-wide text-neutral-400">
                            {{ s.name }}
                        </p>
                        <Link
                            v-for="p in s.projects"
                            :key="p.id"
                            :href="`/tracker/projects/${p.id}`"
                            class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm"
                            :class="isProjectActive(p.id) ? 'bg-neutral-100 font-medium dark:bg-neutral-800' : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                        >
                            <span class="size-2.5 shrink-0 rounded-sm" :style="{ backgroundColor: p.color }" />
                            <span class="truncate">{{ p.name }}</span>
                        </Link>
                        <p v-if="!s.projects.length" class="px-2 py-1 text-xs text-neutral-400">Нет проектов</p>
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>
