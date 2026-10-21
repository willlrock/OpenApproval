<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowUpDown,
    ArrowUp,
    ArrowDown,
    Package,
    Receipt,
    Trash2,
    Undo2,
    FileText,
} from 'lucide-vue-next';
import { ref, computed } from 'vue';
import CreateRequestMenu from '@/components/CreateRequestMenu.vue';
import FilterBar from '@/components/FilterBar.vue';
import type { FilterField } from '@/components/FilterBar.vue';
import AppPage from '@/components/patterns/AppPage.vue';
import ContentState from '@/components/patterns/ContentState.vue';
import MoneyAmount from '@/components/patterns/MoneyAmount.vue';
import PageHeader from '@/components/patterns/PageHeader.vue';
import RequestStatusBadge from '@/components/patterns/RequestStatusBadge.vue';
import { Badge } from '@/components/ui/badge';
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
    description: string;
    currency: string | null;
    total_price: number | null;
    status: string;
    supplier_name: string | null;
    transaction_type: string | null;
    created_at: string;
    project: string | null;
    applicant: string | null;
    approvals_count: number;
    approved_count: number;
    can_delete: boolean;
    deleted_at: string | null;
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
    showingDeleted: boolean;
    canSeeDeleted: boolean;
    deletedCount: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Обзор', href: '/dashboard' },
    { title: 'Заявки', href: '/dashboard/requests' },
];

const attention = ref(props.filters.attention ?? '');
const attentionLabels: Record<string, string> = {
    'overdue-payment': 'Просрочена оплата',
    'overdue-supply': 'Задержана поставка',
    'stuck-approval': 'Зависло согласование',
};
const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? 'all');
const projectId = ref(props.filters.project_id ?? 'all');
const type = ref(props.filters.type ?? 'all');
const dateFrom = ref(props.filters.date_from ?? '');
const dateTo = ref(props.filters.date_to ?? '');
const sortField = ref(props.filters.sort ?? 'created_at');
const sortDir = ref(props.filters.direction ?? 'desc');
const showDeleted = ref(props.showingDeleted);

function applyFilters() {
    const params: Record<string, string> = {};
    if (attention.value) params.attention = attention.value;
    if (search.value) params.search = search.value;
    if (status.value && status.value !== 'all') params.status = status.value;
    if (projectId.value && projectId.value !== 'all')
        params.project_id = projectId.value;
    if (type.value && type.value !== 'all') params.type = type.value;
    if (dateFrom.value) params.date_from = dateFrom.value;
    if (dateTo.value) params.date_to = dateTo.value;
    if (sortField.value !== 'created_at') params.sort = sortField.value;
    if (sortDir.value !== 'desc') params.direction = sortDir.value;
    if (showDeleted.value) params.deleted = '1';
    router.get('/dashboard/requests', params, {
        preserveState: true,
        replace: true,
    });
}

function toggleDeleted() {
    showDeleted.value = !showDeleted.value;
    applyFilters();
}

function toggleSort(field: string) {
    if (sortField.value === field) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortField.value = field;
        sortDir.value = 'desc';
    }
    applyFilters();
}

function resetFilters() {
    attention.value = '';
    search.value = '';
    status.value = 'all';
    projectId.value = 'all';
    type.value = 'all';
    dateFrom.value = '';
    dateTo.value = '';
    sortField.value = 'created_at';
    sortDir.value = 'desc';
    showDeleted.value = false;
    router.get(
        '/dashboard/requests',
        {},
        { preserveState: true, replace: true },
    );
}

// Two-step delete: first tap arms the row, second tap confirms.
const showDeleteConfirm = ref<number | null>(null);

function confirmDelete(id: number) {
    showDeleteConfirm.value = id;
}

function deleteRequest(id: number) {
    router.delete(`/dashboard/requests/${id}`, {
        preserveState: false,
        onFinish: () => {
            showDeleteConfirm.value = null;
        },
    });
}

function restoreRequest(id: number) {
    router.post(
        `/dashboard/requests/${id}/restore`,
        {},
        { preserveState: false },
    );
}

function SortIcon(field: string) {
    if (sortField.value !== field) return ArrowUpDown;
    return sortDir.value === 'asc' ? ArrowUp : ArrowDown;
}
const filterFields = computed<FilterField[]>(() => [
    {
        key: 'search',
        label: 'Поиск',
        type: 'search',
        placeholder: 'ID, название, поставщик',
    },
    {
        key: 'status',
        label: 'Статус',
        options: [
            { value: 'all', label: 'Все статусы' },
            { value: 'pending_approval', label: 'На согласовании' },
            {
                value: 'approval_paused',
                label: requestStatusLabels.approval_paused,
            },
            { value: 'approved', label: requestStatusLabels.approved },
            { value: 'revision_requested', label: 'На доработке' },
            { value: 'rejected', label: 'Отклонена' },
            { value: 'pending_supply', label: 'В снабжении' },
            { value: 'paid', label: 'Оплачена' },
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
    {
        key: 'type',
        label: 'Тип заявки',
        options: [
            { value: 'all', label: 'Все типы' },
            { value: 'approval', label: 'Расход' },
            { value: 'supply', label: 'Снабжение' },
            { value: 'management_document', label: 'Приказ / постановление' },
        ],
    },
    { key: 'date_from', label: 'Созданы с', type: 'date', advanced: true },
    { key: 'date_to', label: 'Созданы по', type: 'date', advanced: true },
]);
const filterValues = computed({
    get: () => ({
        search: search.value,
        status: status.value,
        project_id: projectId.value,
        type: type.value,
        date_from: dateFrom.value,
        date_to: dateTo.value,
    }),
    set: (values) => {
        search.value = values.search;
        status.value = values.status;
        projectId.value = values.project_id;
        type.value = values.type;
        dateFrom.value = values.date_from;
        dateTo.value = values.date_to;
    },
});
const { loading: listLoading, error: listError } = useListFeedback(
    '/dashboard/requests',
);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <div v-if="attention" class="mx-6 mt-4 flex items-center gap-3 text-sm">
            <span>{{ attentionLabels[attention] }}</span
            ><button
                class="underline"
                @click="
                    attention = '';
                    applyFilters();
                "
            >
                Снять фильтр
            </button>
        </div>
        <AppPage>
            <PageHeader
                :title="showingDeleted ? 'Удалённые заявки' : 'Все заявки'"
                ><template #actions><CreateRequestMenu /></template
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
            >
            <ContentState
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
            <!-- Filters -->
            <FilterBar
                v-model:values="filterValues"
                :fields="filterFields"
                @apply="applyFilters"
                @reset="resetFilters"
                ><template #actions
                    ><Button
                        type="button"
                        v-if="canSeeDeleted"
                        variant="outline"
                        @click="toggleDeleted"
                        ><Trash2 class="size-4" />{{
                            showingDeleted
                                ? 'К активным'
                                : `Удалённые (${deletedCount})`
                        }}</Button
                    ></template
                ></FilterBar
            >

            <!-- Table -->
            <Card>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-border bg-muted/50">
                                <th
                                    :aria-sort="
                                        sortField === 'id'
                                            ? sortDir === 'asc'
                                                ? 'ascending'
                                                : 'descending'
                                            : undefined
                                    "
                                    class="px-4 py-1 text-left text-xs font-medium text-muted-foreground"
                                >
                                    <button
                                        type="button"
                                        aria-label="Сортировать по ID"
                                        class="inline-flex min-h-11 items-center gap-1 rounded-sm text-left hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                        @click="toggleSort('id')"
                                    >
                                        ID
                                        <component
                                            :is="SortIcon('id')"
                                            class="h-3 w-3"
                                            aria-hidden="true"
                                        />
                                    </button>
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium text-muted-foreground"
                                >
                                    Тип
                                </th>
                                <th
                                    :aria-sort="
                                        sortField === 'name'
                                            ? sortDir === 'asc'
                                                ? 'ascending'
                                                : 'descending'
                                            : undefined
                                    "
                                    class="px-4 py-1 text-left text-xs font-medium text-muted-foreground"
                                >
                                    <button
                                        type="button"
                                        aria-label="Сортировать по наименованию"
                                        class="inline-flex min-h-11 items-center gap-1 rounded-sm text-left hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                        @click="toggleSort('name')"
                                    >
                                        Наименование
                                        <component
                                            :is="SortIcon('name')"
                                            class="h-3 w-3"
                                            aria-hidden="true"
                                        />
                                    </button>
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
                                    :aria-sort="
                                        sortField === 'total_price'
                                            ? sortDir === 'asc'
                                                ? 'ascending'
                                                : 'descending'
                                            : undefined
                                    "
                                    class="px-4 py-1 text-left text-xs font-medium text-muted-foreground"
                                >
                                    <button
                                        type="button"
                                        aria-label="Сортировать по сумме"
                                        class="inline-flex min-h-11 items-center gap-1 rounded-sm text-left hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                        @click="toggleSort('total_price')"
                                    >
                                        Сумма
                                        <component
                                            :is="SortIcon('total_price')"
                                            class="h-3 w-3"
                                            aria-hidden="true"
                                        />
                                    </button>
                                </th>
                                <th
                                    :aria-sort="
                                        sortField === 'status'
                                            ? sortDir === 'asc'
                                                ? 'ascending'
                                                : 'descending'
                                            : undefined
                                    "
                                    class="px-4 py-1 text-left text-xs font-medium text-muted-foreground"
                                >
                                    <button
                                        type="button"
                                        aria-label="Сортировать по статусу"
                                        class="inline-flex min-h-11 items-center gap-1 rounded-sm text-left hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                        @click="toggleSort('status')"
                                    >
                                        Статус
                                        <component
                                            :is="SortIcon('status')"
                                            class="h-3 w-3"
                                            aria-hidden="true"
                                        />
                                    </button>
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium text-muted-foreground"
                                >
                                    Согласовано
                                </th>
                                <th
                                    :aria-sort="
                                        sortField === 'created_at'
                                            ? sortDir === 'asc'
                                                ? 'ascending'
                                                : 'descending'
                                            : undefined
                                    "
                                    class="px-4 py-1 text-left text-xs font-medium text-muted-foreground"
                                >
                                    <button
                                        type="button"
                                        aria-label="Сортировать по дате создания"
                                        class="inline-flex min-h-11 items-center gap-1 rounded-sm text-left hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                        @click="toggleSort('created_at')"
                                    >
                                        Дата создания<component
                                            :is="SortIcon('created_at')"
                                            class="h-3 w-3"
                                            aria-hidden="true"
                                        />
                                    </button>
                                </th>
                                <th
                                    v-if="showingDeleted"
                                    class="px-4 py-3 text-left text-xs font-medium text-muted-foreground"
                                >
                                    Удалена
                                </th>
                                <th
                                    class="px-4 py-3 text-right text-xs font-medium text-muted-foreground"
                                >
                                    Действия
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
                                                'overview',
                                                requestListPage.url,
                                            )
                                        "
                                        class="font-medium text-foreground hover:underline"
                                        >#{{ req.id }}</Link
                                    >
                                </td>
                                <td class="px-4 py-3">
                                    <Badge variant="outline" class="gap-1">
                                        <Package
                                            v-if="req.type === 'supply'"
                                            class="h-3 w-3"
                                        />
                                        <FileText
                                            v-else-if="
                                                req.type ===
                                                'management_document'
                                            "
                                            class="h-3 w-3"
                                        />
                                        <Receipt v-else class="h-3 w-3" />
                                        {{
                                            req.type === 'supply'
                                                ? 'Снабжение'
                                                : req.type ===
                                                    'management_document'
                                                  ? 'Документ руководства'
                                                  : 'Расход'
                                        }}
                                    </Badge>
                                </td>
                                <td
                                    class="max-w-80 min-w-52 px-4 py-3 text-foreground"
                                >
                                    <Link
                                        :href="
                                            requestDetailHref(
                                                req.id,
                                                'overview',
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
                                    {{ req.applicant ?? '—' }}
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
                                    class="px-4 py-3 text-xs text-muted-foreground"
                                >
                                    {{ req.approved_count }}/{{
                                        req.approvals_count
                                    }}
                                </td>
                                <td
                                    class="px-4 py-3 text-xs whitespace-nowrap text-muted-foreground"
                                >
                                    {{ req.created_at }}
                                </td>
                                <td
                                    v-if="showingDeleted"
                                    class="px-4 py-3 text-xs whitespace-nowrap text-muted-foreground"
                                >
                                    {{ req.deleted_at ?? '—' }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right whitespace-nowrap"
                                >
                                    <Button
                                        type="button"
                                        v-if="showingDeleted"
                                        variant="outline"
                                        size="sm"
                                        class="gap-1.5"
                                        title="Восстановить заявку"
                                        @click="restoreRequest(req.id)"
                                    >
                                        <Undo2 class="h-3.5 w-3.5" />
                                        Восстановить
                                    </Button>
                                    <template v-else-if="req.can_delete">
                                        <Button
                                            type="button"
                                            v-if="showDeleteConfirm === req.id"
                                            variant="destructive"
                                            size="sm"
                                            @click="deleteRequest(req.id)"
                                        >
                                            Подтвердить
                                        </Button>
                                        <Button
                                            type="button"
                                            v-else
                                            variant="outline"
                                            size="icon-sm"
                                            class="text-destructive hover:bg-destructive/10"
                                            title="Удалить заявку"
                                            @click="confirmDelete(req.id)"
                                        >
                                            <Trash2 class="h-3.5 w-3.5" />
                                        </Button>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <ContentState v-if="!requests.data.length">
                        <p class="text-base font-medium">
                            {{
                                Object.values(filterValues).some(
                                    (v) => v && v !== 'all',
                                ) || attention
                                    ? 'Нет заявок по выбранным условиям'
                                    : showingDeleted
                                      ? 'Нет удалённых заявок'
                                      : 'Заявок пока нет'
                            }}
                        </p>
                        <p class="mt-2 text-sm text-muted-foreground">
                            {{
                                Object.values(filterValues).some(
                                    (v) => v && v !== 'all',
                                ) || attention
                                    ? 'Измените фильтры или сбросьте их, чтобы увидеть остальные заявки.'
                                    : 'Отправленные на согласование заявки появятся здесь. Новую можно подготовить в разделе «Заявки и черновики».'
                            }}
                        </p>
                        <Button
                            type="button"
                            v-if="
                                Object.values(filterValues).some(
                                    (v) => v && v !== 'all',
                                ) || attention
                            "
                            variant="outline"
                            class="mt-4"
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
