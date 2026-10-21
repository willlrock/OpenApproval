<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import FilterBar from '@/components/FilterBar.vue';
import type { FilterField } from '@/components/FilterBar.vue';
import AppPage from '@/components/patterns/AppPage.vue';
import ContentState from '@/components/patterns/ContentState.vue';
import MoneyAmount from '@/components/patterns/MoneyAmount.vue';
import PageHeader from '@/components/patterns/PageHeader.vue';
import RequestStatusBadge from '@/components/patterns/RequestStatusBadge.vue';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useListFeedback } from '@/composables/useListFeedback';
import AppLayout from '@/layouts/AppLayout.vue';
import { requestDetailHref } from '@/lib/request-detail';
import { requestStatusLabels } from '@/lib/requestLabels';
import type { BreadcrumbItem } from '@/types';

interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

interface RequestItem {
    id: number;
    type: string;
    name: string;
    status: string;
    currency: string | null;
    total_price: number | null;
    supplier_name: string | null;
    created_at: string;
    project: string | null;
    applicant: string | null;
    applicant_phone: string | null;
    items_count: number;
}

interface ProjectOption {
    id: number;
    name: string;
}

const requestListPage = usePage();

const props = defineProps<{
    requests: PaginatedData<RequestItem>;
    projects: ProjectOption[];
    filters: Record<string, string>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Снабжение', href: '/supply' },
    { title: 'Заявки', href: '/supply/requests' },
];

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? 'all');
const projectId = ref(props.filters.project_id ?? 'all');

const hasActiveFilters = computed(() =>
    Boolean(
        search.value || status.value !== 'all' || projectId.value !== 'all',
    ),
);

function applyFilters() {
    const params: Record<string, string> = {};
    if (search.value) params.search = search.value;
    if (status.value && status.value !== 'all') params.status = status.value;
    if (projectId.value && projectId.value !== 'all')
        params.project_id = projectId.value;
    router.get('/supply/requests', params, {
        preserveState: true,
        replace: true,
    });
}

function resetFilters() {
    search.value = '';
    status.value = 'all';
    projectId.value = 'all';
    router.get(
        '/supply/requests',
        {},
        {
            preserveState: true,
            replace: true,
        },
    );
}

const filterFields = computed<FilterField[]>(() => [
    {
        key: 'search',
        label: 'Поиск',
        type: 'search',
        placeholder: 'ID или название',
    },
    {
        key: 'status',
        label: 'Статус',
        options: [
            { value: 'all', label: 'Все' },
            ...[
                'pending_supply',
                'approval_paused',
                'pending_approval',
                'approved',
                'rejected',
                'paid',
            ].map((value) => ({ value, label: requestStatusLabels[value] })),
        ],
    },
    {
        key: 'project_id',
        label: 'Проект',
        options: [
            { value: 'all', label: 'Все проекты' },
            ...props.projects.map((p) => ({
                value: String(p.id),
                label: p.name,
            })),
        ],
    },
]);
const filterValues = computed({
    get: () => ({
        search: search.value,
        status: status.value,
        project_id: projectId.value,
    }),
    set: (values) => {
        search.value = values.search;
        status.value = values.status;
        projectId.value = values.project_id;
    },
});
const { loading: listLoading, error: listError } =
    useListFeedback('/supply/requests');
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <AppPage>
            <PageHeader
                title="Очередь снабжения"
                description="Заявки для обработки, передачи на согласование и просмотра результата."
                ><template #meta
                    ><ContentState
                        v-if="listLoading"
                        kind="loading"
                        layout="inline"
                        message="Обновление списка…"
                    />
                    <p v-else role="status">
                        Найдено: {{ requests.total }}
                    </p></template
                ></PageHeader
            ><ContentState
                v-if="listError"
                kind="error"
                layout="notice"
                :message="listError"
                ><template #actions
                    ><Button variant="outline" @click="applyFilters"
                        >Повторить</Button
                    ></template
                ></ContentState
            >
            <FilterBar
                v-model:values="filterValues"
                :fields="filterFields"
                @apply="applyFilters"
                @reset="resetFilters"
            />

            <!-- Table -->
            <Card :aria-busy="listLoading">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-border bg-muted/50">
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium text-muted-foreground"
                                >
                                    ID
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium text-muted-foreground"
                                >
                                    Наименование
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium text-muted-foreground"
                                >
                                    Проект
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium text-muted-foreground"
                                >
                                    Заявитель
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium text-muted-foreground"
                                >
                                    Позиций
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium text-muted-foreground"
                                >
                                    Сумма
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium text-muted-foreground"
                                >
                                    Статус
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium text-muted-foreground"
                                >
                                    Дата
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="req in requests.data"
                                :key="req.id"
                                class="border-b border-border/50 transition-colors hover:bg-accent"
                            >
                                <td class="px-4 py-3">
                                    <Link
                                        :href="
                                            requestDetailHref(
                                                req.id,
                                                'supply',
                                                requestListPage.url,
                                            )
                                        "
                                        class="font-medium text-foreground hover:underline"
                                        >#{{ req.id }}</Link
                                    >
                                </td>
                                <td
                                    class="max-w-80 min-w-52 px-4 py-3 text-foreground"
                                >
                                    <Link
                                        :href="
                                            requestDetailHref(
                                                req.id,
                                                'supply',
                                                requestListPage.url,
                                            )
                                        "
                                        class="block font-medium break-words hover:underline"
                                        >{{ req.name }}</Link
                                    >
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ req.project ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    <div>{{ req.applicant ?? '—' }}</div>
                                    <div
                                        v-if="req.applicant_phone"
                                        class="text-xs"
                                    >
                                        {{ req.applicant_phone }}
                                    </div>
                                </td>
                                <td
                                    class="px-4 py-3 text-center text-muted-foreground"
                                >
                                    {{ req.items_count }}
                                </td>
                                <td class="px-4 py-3 font-medium">
                                    <MoneyAmount
                                        nowrap
                                        :amount="req.total_price"
                                        :currency="req.currency"
                                        currency-class="text-inherit"
                                    />
                                </td>
                                <td class="px-4 py-3">
                                    <RequestStatusBadge :status="req.status" />
                                </td>
                                <td
                                    class="px-4 py-3 text-xs whitespace-nowrap text-muted-foreground"
                                >
                                    {{ req.created_at }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <ContentState v-if="!requests.data.length">
                        <p class="text-sm font-medium text-foreground">
                            {{
                                hasActiveFilters
                                    ? 'По этим фильтрам заявок нет'
                                    : 'Заявок для снабжения пока нет'
                            }}
                        </p>
                        <Button
                            v-if="hasActiveFilters"
                            variant="link"
                            class="mt-2"
                            @click="resetFilters"
                            >Сбросить фильтры</Button
                        >
                    </ContentState>
                </div>
                <div
                    v-if="requests.last_page > 1"
                    class="flex items-center justify-between border-t border-border px-4 py-3"
                >
                    <p class="text-sm text-muted-foreground">
                        Всего: {{ requests.total }} · Страница
                        {{ requests.current_page }} из {{ requests.last_page }}
                    </p>
                    <div class="flex gap-1">
                        <template
                            v-for="link in requests.links"
                            :key="link.label"
                        >
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
