<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';
import { LayoutGrid, ArrowLeftRight, Bell, Menu, X } from 'lucide-vue-next';
import { api } from '@/lib/trackerApi';
import { toasts, dismissToast, runToastAction } from '@/lib/trackerToast';
import SidebarNav from '@/components/tracker/SidebarNav.vue';

// Mobile slide-over sidebar. Closes on any navigation.
const mobileOpen = ref(false);
let offNavigate: (() => void) | undefined;

// Notifications (in-app, polling — websockets land in V2 §26).
const notifOpen = ref(false);
const unread = ref(0);
const notifications = ref<any[]>([]);
let timer: number | undefined;

async function loadNotifications() {
    try {
        const r = await api<{ unread: number; items: any[] }>('GET', '/tracker/notifications');
        unread.value = r.unread;
        notifications.value = r.items;
    } catch {
        /* ignore polling errors */
    }
}
async function markAllRead() {
    await api('POST', '/tracker/notifications/read');
    unread.value = 0;
    notifications.value = notifications.value.map((n) => ({ ...n, read_at: n.read_at ?? new Date().toISOString() }));
}
function notifText(n: any): string {
    const t = n.data?.task_title ?? n.data?.workspace_name ?? '';
    const map: Record<string, string> = {
        assigned: `Вас назначили: ${t}`,
        mention: `Вас упомянули: ${t}`,
        comment: `Новый комментарий: ${t}`,
        task_moved: `Задача перемещена: ${t}`,
        workspace_invite: `Приглашение в пространство: ${t}`,
    };
    return map[n.type] ?? n.type;
}
// Task notifications navigate straight to the task via the go/ deep link.
function notifHref(n: any): string | null {
    return n.data?.task_id ? `/tracker/go/task/${n.data.task_id}` : null;
}

onMounted(() => {
    loadNotifications();
    timer = window.setInterval(loadNotifications, 30000);
    offNavigate = router.on('navigate', () => {
        mobileOpen.value = false;
        notifOpen.value = false;
    });
});
onUnmounted(() => {
    if (timer) window.clearInterval(timer);
    offNavigate?.();
});
</script>

<template>
    <div class="flex min-h-screen bg-neutral-50 text-neutral-900 dark:bg-neutral-950 dark:text-neutral-100">
        <!-- Desktop sidebar (own sidebar — not the approval AppSidebar) -->
        <aside class="hidden w-64 shrink-0 flex-col border-r border-neutral-200 bg-white md:flex dark:border-neutral-800 dark:bg-neutral-900">
            <Link href="/tracker" class="flex h-14 items-center gap-2 border-b border-neutral-200 px-4 text-base font-semibold dark:border-neutral-800">
                <LayoutGrid class="size-5" /> Task Tracker
            </Link>
            <div class="flex-1 overflow-y-auto p-3">
                <SidebarNav />
            </div>
        </aside>

        <!-- Mobile slide-over sidebar -->
        <Teleport to="body">
            <div v-if="mobileOpen" class="fixed inset-0 z-50 md:hidden">
                <div class="absolute inset-0 bg-black/30" @click="mobileOpen = false" />
                <div class="relative z-10 flex h-full w-72 flex-col bg-white shadow-xl dark:bg-neutral-900">
                    <div class="flex h-14 items-center justify-between border-b border-neutral-200 px-4 dark:border-neutral-800">
                        <Link href="/tracker" class="flex items-center gap-2 text-base font-semibold">
                            <LayoutGrid class="size-5" /> Task Tracker
                        </Link>
                        <button class="rounded-md p-1.5 text-neutral-500 hover:bg-neutral-100 dark:hover:bg-neutral-800" @click="mobileOpen = false">
                            <X class="size-4" />
                        </button>
                    </div>
                    <div class="flex-1 overflow-y-auto p-3">
                        <SidebarNav />
                    </div>
                </div>
            </div>
        </Teleport>

        <div class="flex min-w-0 flex-1 flex-col">
            <!-- Top bar with product switcher -->
            <header class="flex h-14 shrink-0 items-center justify-between border-b border-neutral-200 bg-white px-4 dark:border-neutral-800 dark:bg-neutral-900">
                <div class="flex min-w-0 items-center gap-2">
                    <button
                        class="rounded-md p-1.5 text-neutral-500 hover:bg-neutral-100 md:hidden dark:hover:bg-neutral-800"
                        title="Меню"
                        @click="mobileOpen = true"
                    >
                        <Menu class="size-5" />
                    </button>
                    <h1 class="truncate text-sm font-medium text-neutral-500">
                        <slot name="title">Task Tracker</slot>
                    </h1>
                </div>
                <div class="flex items-center gap-2">
                    <!-- Notification bell -->
                    <div class="relative">
                        <button
                            class="relative rounded-md p-1.5 text-neutral-500 hover:bg-neutral-100 dark:hover:bg-neutral-800"
                            @click="notifOpen = !notifOpen; notifOpen && loadNotifications()"
                        >
                            <Bell class="size-4" />
                            <span
                                v-if="unread > 0"
                                class="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-medium text-white"
                            >
                                {{ unread > 9 ? '9+' : unread }}
                            </span>
                        </button>
                        <div
                            v-if="notifOpen"
                            class="absolute right-0 top-full z-50 mt-1 w-80 rounded-lg border border-neutral-200 bg-white p-2 shadow-lg dark:border-neutral-700 dark:bg-neutral-800"
                        >
                            <div class="mb-1 flex items-center justify-between px-1">
                                <span class="text-xs font-medium uppercase text-neutral-400">Уведомления</span>
                                <button class="text-xs text-neutral-500 hover:underline" @click="markAllRead">Прочитать все</button>
                            </div>
                            <p v-if="!notifications.length" class="px-1 py-3 text-center text-xs text-neutral-400">Нет уведомлений</p>
                            <ul v-else class="max-h-80 space-y-0.5 overflow-y-auto">
                                <li v-for="n in notifications" :key="n.id">
                                    <component
                                        :is="notifHref(n) ? 'a' : 'div'"
                                        :href="notifHref(n) ?? undefined"
                                        class="block rounded-md px-2 py-1.5 text-sm"
                                        :class="[
                                            n.read_at ? 'text-neutral-400' : 'bg-neutral-50 dark:bg-neutral-700/40',
                                            notifHref(n) ? 'cursor-pointer hover:bg-neutral-100 dark:hover:bg-neutral-700' : '',
                                        ]"
                                    >
                                        {{ notifText(n) }}
                                    </component>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <Link
                        href="/dashboard"
                        class="inline-flex items-center gap-1.5 rounded-md border border-neutral-200 px-2.5 py-1.5 text-xs font-medium hover:bg-neutral-100 dark:border-neutral-700 dark:hover:bg-neutral-800"
                    >
                        <ArrowLeftRight class="size-3.5" />
                        <span class="hidden sm:inline">Панель согласований</span>
                    </Link>
                </div>
            </header>

            <main class="min-w-0 flex-1 overflow-x-auto p-4">
                <slot />
            </main>
        </div>

        <!-- Toast stack (Asana-style, bottom-left, with optional undo action) -->
        <Teleport to="body">
            <div class="pointer-events-none fixed bottom-4 left-4 z-[60] flex flex-col gap-2">
                <div
                    v-for="t in toasts"
                    :key="t.id"
                    class="pointer-events-auto flex items-center gap-3 rounded-lg bg-neutral-900 px-4 py-2.5 text-sm text-white shadow-lg dark:bg-neutral-100 dark:text-neutral-900"
                >
                    <span>{{ t.message }}</span>
                    <button
                        v-if="t.actionLabel"
                        class="font-medium text-indigo-300 hover:text-indigo-200 dark:text-indigo-600 dark:hover:text-indigo-500"
                        @click="runToastAction(t)"
                    >
                        {{ t.actionLabel }}
                    </button>
                    <button class="text-neutral-400 hover:text-neutral-200 dark:hover:text-neutral-600" @click="dismissToast(t.id)">
                        <X class="size-3.5" />
                    </button>
                </div>
            </div>
        </Teleport>
    </div>
</template>
