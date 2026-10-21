<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    Check,
    X,
    FilterX,
    ScrollText,
    FolderOpen,
    Pin,
    User,
} from 'lucide-vue-next';
import { ref, watch } from 'vue';
import AppPage from '@/components/patterns/AppPage.vue';
import PageHeader from '@/components/patterns/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

interface LogEntry {
    id: number;
    level: number;
    status: string;
    comment: string | null;
    action_date: string | null;
    approver: {
        id: number;
        full_name: string;
        title: string | null;
    } | null;
    request: {
        id: number;
        name: string;
        project: string | null;
        applicant: string | null;
    } | null;
}

interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

const props = defineProps<{
    logs: PaginatedData<LogEntry>;
    filters: Record<string, string>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Обзор', href: '/dashboard' },
    { title: 'Логи', href: '/dashboard/logs' },
];

const status = ref(props.filters.status ?? 'all');
const dateFrom = ref(props.filters.date_from ?? '');
const dateTo = ref(props.filters.date_to ?? '');

function applyFilters() {
    const params: Record<string, string> = {};
    if (status.value && status.value !== 'all') params.status = status.value;
    if (dateFrom.value) params.date_from = dateFrom.value;
    if (dateTo.value) params.date_to = dateTo.value;
    router.get('/dashboard/logs', params, {
        preserveState: true,
        replace: true,
    });
}

watch(status, applyFilters);

function resetFilters() {
    status.value = 'all';
    dateFrom.value = '';
    dateTo.value = '';
    router.get('/dashboard/logs', {}, { preserveState: true, replace: true });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <AppPage>
            <PageHeader title="Логи согласований" />
            <nav aria-label="Журналы" class="flex gap-4 text-sm">
                <Link
                    href="/dashboard/logs"
                    aria-current="page"
                    class="font-semibold"
                    >Согласования</Link
                >
                <Link
                    href="/dashboard/system-logs"
                    class="text-muted-foreground hover:underline"
                    >Системные логи</Link
                >
            </nav>
            <!-- Filters -->
            <Card class="p-5">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                    <div>
                        <label
                            for="log-action"
                            class="mb-1 block text-xs font-medium text-muted-foreground"
                            >Действие</label
                        >
                        <Select v-model="status">
                            <SelectTrigger id="log-action" class="w-full">
                                <SelectValue placeholder="Все" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Все</SelectItem>
                                <SelectItem value="approved"
                                    >Одобрено</SelectItem
                                >
                                <SelectItem value="rejected"
                                    >Отклонено</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>
                    <div>
                        <label
                            for="log-date-from"
                            class="mb-1 block text-xs font-medium text-muted-foreground"
                            >Дата от</label
                        >
                        <Input
                            id="log-date-from"
                            v-model="dateFrom"
                            @change="applyFilters"
                            type="date"
                        />
                    </div>
                    <div>
                        <label
                            for="log-date-to"
                            class="mb-1 block text-xs font-medium text-muted-foreground"
                            >Дата до</label
                        >
                        <Input
                            id="log-date-to"
                            v-model="dateTo"
                            @change="applyFilters"
                            type="date"
                        />
                    </div>
                    <div class="flex items-end">
                        <Button variant="outline" @click="resetFilters">
                            <FilterX class="h-4 w-4" />
                            Сбросить
                        </Button>
                    </div>
                </div>
            </Card>

            <!-- Logs -->
            <Card class="overflow-hidden">
                <div class="divide-y divide-border">
                    <div
                        v-for="log in logs.data"
                        :key="log.id"
                        class="p-4 transition-colors hover:bg-accent/50"
                    >
                        <div class="flex items-start gap-4">
                            <div
                                :class="[
                                    log.status === 'approved'
                                        ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900 dark:text-emerald-400'
                                        : 'bg-red-100 text-red-600 dark:bg-red-900 dark:text-red-400',
                                ]"
                                class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
                            >
                                <Check
                                    v-if="log.status === 'approved'"
                                    class="h-4 w-4"
                                />
                                <X v-else class="h-4 w-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div
                                    class="mb-1 flex items-center justify-between gap-4"
                                >
                                    <p class="text-sm text-foreground">
                                        <span class="font-medium">{{
                                            log.approver
                                                ? log.approver.full_name
                                                : 'Неизвестно'
                                        }}</span>
                                        <span
                                            v-if="log.approver?.title"
                                            class="text-muted-foreground"
                                        >
                                            · {{ log.approver.title }}</span
                                        >
                                        <span
                                            :class="
                                                log.status === 'approved'
                                                    ? 'text-emerald-600 dark:text-emerald-400'
                                                    : 'text-red-600 dark:text-red-400'
                                            "
                                            >{{
                                                log.status === 'approved'
                                                    ? ' одобрил(а)'
                                                    : ' отклонил(а)'
                                            }}</span
                                        >
                                        <span class="text-muted-foreground">
                                            заявку
                                        </span>
                                        <Link
                                            v-if="log.request"
                                            :href="`/dashboard/requests/${log.request.id}`"
                                            class="font-medium text-foreground hover:underline"
                                            >#{{ log.request.id }}</Link
                                        >
                                        <span class="text-muted-foreground">
                                            (Уровень {{ log.level }})</span
                                        >
                                    </p>
                                    <span
                                        class="text-xs whitespace-nowrap text-muted-foreground"
                                        >{{ log.action_date }}</span
                                    >
                                </div>
                                <div
                                    v-if="log.request"
                                    class="mb-1 flex items-center gap-3 text-xs text-muted-foreground"
                                >
                                    <span
                                        v-if="log.request.project"
                                        class="inline-flex items-center gap-1"
                                    >
                                        <FolderOpen class="h-3 w-3" />
                                        {{ log.request.project }}
                                    </span>
                                    <span
                                        class="inline-flex items-center gap-1"
                                    >
                                        <Pin class="h-3 w-3" />
                                        {{ log.request.name }}
                                    </span>
                                    <span
                                        v-if="log.request.applicant"
                                        class="inline-flex items-center gap-1"
                                    >
                                        <User class="h-3 w-3" />
                                        {{ log.request.applicant }}
                                    </span>
                                </div>
                                <div
                                    v-if="log.comment"
                                    class="mt-2 rounded-lg bg-muted px-3 py-2 text-sm text-foreground"
                                >
                                    💬 {{ log.comment }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    v-if="!logs.data.length"
                    class="py-16 text-center text-muted-foreground"
                >
                    <ScrollText class="mx-auto mb-3 h-10 w-10" />
                    <p class="text-sm">Нет записей в логах</p>
                </div>

                <div
                    v-if="logs.last_page > 1"
                    class="flex items-center justify-between border-t border-border px-4 py-3"
                >
                    <p class="text-sm text-muted-foreground">
                        Всего: {{ logs.total }} · Страница
                        {{ logs.current_page }} из {{ logs.last_page }}
                    </p>
                    <div class="flex gap-1">
                        <template v-for="link in logs.links" :key="link.label">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                :class="
                                    link.active
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:bg-accent'
                                "
                                class="rounded px-3 py-1.5 text-xs font-medium transition-colors"
                                preserve-state
                                ><span v-html="link.label"
                            /></Link>
                            <span
                                v-else
                                class="px-3 py-1.5 text-xs text-muted-foreground/50"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </div>
            </Card>
        </AppPage>
    </AppLayout>
</template>
