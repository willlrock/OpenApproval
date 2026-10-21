<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps<{
    entries: {
        date: string;
        level: string;
        message: string;
        details: string;
    }[];
    unavailable: boolean;
    limited: boolean;
    filters: { search?: string; date?: string; level?: string };
}>();
const search = ref(props.filters.search ?? '');
const date = ref(props.filters.date ?? '');
const level = ref(props.filters.level ?? '');
const busy = ref(false);
const levels = [
    'DEBUG',
    'INFO',
    'NOTICE',
    'WARNING',
    'ERROR',
    'CRITICAL',
    'ALERT',
    'EMERGENCY',
];
function refresh() {
    router.get(
        '/dashboard/system-logs',
        { search: search.value, date: date.value, level: level.value },
        {
            preserveState: true,
            replace: true,
            preserveScroll: true,
            onStart: () => {
                busy.value = true;
            },
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}
function reset() {
    search.value = '';
    date.value = '';
    level.value = '';
    refresh();
}
</script>

<template>
    <Head title="Системные логи" />
    <AppLayout
        :breadcrumbs="[
            { title: 'Логи', href: '/dashboard/logs' },
            { title: 'Системные логи', href: '/dashboard/system-logs' },
        ]"
    >
        <div class="flex min-w-0 flex-1 flex-col gap-6 p-6">
            <nav aria-label="Журналы" class="flex gap-4 text-sm">
                <Link
                    href="/dashboard/logs"
                    class="text-muted-foreground hover:underline"
                    >Согласования</Link
                >
                <Link
                    href="/dashboard/system-logs"
                    aria-current="page"
                    class="font-semibold"
                    >Системные логи</Link
                >
            </nav>
            <div>
                <h1 class="text-2xl font-semibold">Системные логи</h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    До 200 записей из журналов приложения, новые сверху. Поиск и
                    фильтры действуют в пределах последнего 1 МБ выбранных
                    журналов. Время указано как в журнале сервера.
                </p>
            </div>
            <form
                class="flex flex-wrap items-end gap-4 rounded-xl border p-4"
                @submit.prevent="refresh"
            >
                <div class="min-w-48 flex-1">
                    <label for="log-search" class="mb-2 block text-sm"
                        >Текст ошибки</label
                    ><Input
                        id="log-search"
                        v-model="search"
                        maxlength="200"
                        placeholder="Например: Approval request save failed"
                    />
                </div>
                <div>
                    <label for="log-date" class="mb-2 block text-sm">Дата</label
                    ><Input id="log-date" v-model="date" type="date" />
                </div>
                <div>
                    <label for="log-level" class="mb-2 block text-sm"
                        >Уровень</label
                    ><select
                        id="log-level"
                        v-model="level"
                        class="h-9 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="">Все уровни</option>
                        <option v-for="item in levels" :key="item">
                            {{ item }}
                        </option>
                    </select>
                </div>
                <Button type="submit" :disabled="busy">{{
                    busy ? 'Загрузка…' : 'Обновить'
                }}</Button>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="busy"
                    @click="reset"
                    >Сбросить</Button
                >
            </form>
            <p v-if="unavailable" role="status" class="rounded-lg border p-4">
                Журнал отсутствует или недоступен для чтения. Проверьте наличие
                файла и права доступа на сервере.
            </p>
            <template v-else>
                <p v-if="limited" class="text-sm text-muted-foreground">
                    Показан ограниченный фрагмент журнала. Более старые записи
                    могут не попасть в результаты поиска.
                </p>
                <p
                    v-if="!entries.length"
                    role="status"
                    class="rounded-lg border p-6 text-muted-foreground"
                >
                    Записей по выбранным условиям нет.
                </p>
                <div v-else class="space-y-3">
                    <details
                        v-for="(entry, index) in entries"
                        :key="`${entry.date}-${index}-${entry.message}`"
                        class="min-w-0 rounded-lg border p-4"
                    >
                        <summary class="cursor-pointer text-sm break-words">
                            <time class="mr-3 text-muted-foreground">{{
                                entry.date
                            }}</time>
                            <span
                                class="mr-3 font-semibold"
                                :class="
                                    [
                                        'ERROR',
                                        'CRITICAL',
                                        'ALERT',
                                        'EMERGENCY',
                                    ].includes(entry.level)
                                        ? 'text-red-600 dark:text-red-400'
                                        : 'text-foreground'
                                "
                                >{{ entry.level }}</span
                            >
                            <span>{{ entry.message }}</span>
                        </summary>
                        <pre
                            class="mt-4 max-h-96 overflow-auto rounded-md bg-muted p-4 text-xs break-all whitespace-pre-wrap"
                            >{{ entry.details }}</pre
                        >
                    </details>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
