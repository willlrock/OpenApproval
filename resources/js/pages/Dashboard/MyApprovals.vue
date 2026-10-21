<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { CheckCircle, XCircle, Clock } from 'lucide-vue-next';
import { ref, computed } from 'vue';
import FilterBar from '@/components/FilterBar.vue';
import type { FilterField } from '@/components/FilterBar.vue';
import AppPage from '@/components/patterns/AppPage.vue';
import ContentState from '@/components/patterns/ContentState.vue';
import MoneyAmount from '@/components/patterns/MoneyAmount.vue';
import PageHeader from '@/components/patterns/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useListFeedback } from '@/composables/useListFeedback';
import AppLayout from '@/layouts/AppLayout.vue';
import { requestDetailHref } from '@/lib/request-detail';
import type { BreadcrumbItem } from '@/types';

interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

interface ActiveItem {
    id: number;
    type: string;
    name: string;
    currency: string | null;
    total_price: number | null;
    status: string;
    created_at: string;
    project: string | null;
    applicant: string | null;
    approvals_count: number;
    approved_count: number;
}

interface HistoryItem {
    id: number;
    type: string;
    name: string;
    currency: string | null;
    total_price: number | null;
    status: string;
    project: string | null;
    applicant: string | null;
    approval_status: string;
    approval_date: string | null;
    approval_comment: string | null;
    level: number;
}

const requestListPage = usePage();

const props = defineProps<{
    active: PaginatedData<ActiveItem>;
    history: PaginatedData<HistoryItem>;
    filters: Record<string, string>;
    activeCount: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Обзор', href: '/dashboard' },
    { title: 'На согласование', href: '/dashboard/my-approvals' },
];

const tab = ref(props.filters.tab ?? 'active');
const search = ref(props.filters.search ?? '');

function applyFilters() {
    const params: Record<string, string> = {};
    if (search.value) params.search = search.value;
    if (tab.value !== 'active') params.tab = tab.value;
    router.get('/dashboard/my-approvals', params, {
        preserveState: true,
        replace: true,
    });
}

function switchTab(newTab: string) {
    tab.value = newTab;
    applyFilters();
}

function resetFilters() {
    search.value = '';
    tab.value = 'active';
    router.get(
        '/dashboard/my-approvals',
        {},
        { preserveState: true, replace: true },
    );
}

const filterFields = computed<FilterField[]>(() => [
    {
        key: 'search',
        label: 'Поиск',
        type: 'search',
        placeholder: 'ID или название',
    },
]);
const filterValues = computed({
    get: () => ({ search: search.value }),
    set: (values) => {
        search.value = values.search;
    },
});
const { loading: listLoading, error: listError } = useListFeedback(
    '/dashboard/my-approvals',
);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <AppPage>
            <PageHeader
                title="На согласование"
                :description="`Требуют решения: ${activeCount}`"
                ><template #meta
                    ><ContentState
                        v-if="listLoading"
                        kind="loading"
                        layout="inline"
                        message="Обновление списка…"
                    />
                    <p v-else role="status">
                        Найдено:
                        {{ tab === 'active' ? active.total : history.total }}
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
            <!-- Tabs + Search -->
            <FilterBar
                v-model:values="filterValues"
                :fields="filterFields"
                @apply="applyFilters"
                @reset="resetFilters"
                ><template #leading
                    ><div class="flex gap-2">
                        <Button
                            type="button"
                            :variant="tab === 'active' ? 'default' : 'outline'"
                            @click="switchTab('active')"
                            :aria-pressed="tab === 'active'"
                            class="gap-2"
                        >
                            <Clock class="h-4 w-4" />
                            Активные
                            <Badge
                                v-if="activeCount > 0"
                                variant="secondary"
                                class="ml-1"
                            >
                                {{ activeCount }}
                            </Badge>
                        </Button>
                        <Button
                            type="button"
                            :variant="tab === 'history' ? 'default' : 'outline'"
                            @click="switchTab('history')"
                            :aria-pressed="tab === 'history'"
                            class="gap-2"
                        >
                            <CheckCircle class="h-4 w-4" />
                            История
                        </Button>
                    </div></template
                ></FilterBar
            >

            <!-- Active Tab -->
            <Card v-if="tab === 'active'">
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
                                    Сумма
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium text-muted-foreground"
                                >
                                    Согласовано
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
                                v-for="req in active.data"
                                :key="req.id"
                                class="border-b border-border/50 transition-colors hover:bg-accent"
                            >
                                <td class="px-4 py-3">
                                    <Link
                                        :href="
                                            requestDetailHref(
                                                req.id,
                                                'approval',
                                                requestListPage.url,
                                            )
                                        "
                                        class="font-medium text-foreground hover:underline"
                                    >
                                        #{{ req.id }}
                                    </Link>
                                </td>
                                <td
                                    class="max-w-80 min-w-52 px-4 py-3 text-foreground"
                                >
                                    <Link
                                        :href="
                                            requestDetailHref(
                                                req.id,
                                                'approval',
                                                requestListPage.url,
                                            )
                                        "
                                        class="font-medium break-words hover:underline"
                                        >{{ req.name }}</Link
                                    >
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ req.project ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ req.applicant ?? '—' }}
                                </td>
                                <td
                                    class="px-4 py-3 font-medium whitespace-nowrap text-foreground"
                                >
                                    <template v-if="req.total_price != null">
                                        <MoneyAmount
                                            nowrap
                                            :amount="req.total_price"
                                            :currency="req.currency"
                                            currency-class="text-inherit"
                                        />
                                    </template>
                                    <template v-else>
                                        <span class="text-muted-foreground"
                                            >—</span
                                        >
                                    </template>
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
                            </tr>
                        </tbody>
                    </table>
                    <ContentState v-if="!active.data.length">
                        <p class="text-sm">
                            {{
                                search
                                    ? 'Нет результатов по вашему запросу'
                                    : 'Всё разобрано — активных согласований нет'
                            }}
                        </p>
                    </ContentState>
                </div>
                <div
                    v-if="active.last_page > 1"
                    class="flex items-center justify-between border-t border-border px-4 py-3"
                >
                    <p class="text-sm text-muted-foreground">
                        Всего: {{ active.total }} · Страница
                        {{ active.current_page }} из {{ active.last_page }}
                    </p>
                    <div class="flex gap-1">
                        <template
                            v-for="link in active.links"
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

            <!-- History Tab -->
            <Card v-if="tab === 'history'">
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
                                    Сумма
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium text-muted-foreground"
                                >
                                    Решение
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium text-muted-foreground"
                                >
                                    Уровень
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
                                v-for="item in history.data"
                                :key="`${item.id}-${item.level}`"
                                class="border-b border-border/50 transition-colors hover:bg-accent"
                            >
                                <td class="px-4 py-3">
                                    <Link
                                        v-if="item.id"
                                        :href="
                                            requestDetailHref(
                                                item.id,
                                                'approval',
                                                requestListPage.url,
                                            )
                                        "
                                        class="font-medium text-foreground hover:underline"
                                    >
                                        #{{ item.id }}
                                    </Link>
                                </td>
                                <td
                                    class="max-w-80 min-w-52 px-4 py-3 text-foreground"
                                >
                                    <Link
                                        v-if="item.id"
                                        :href="
                                            requestDetailHref(
                                                item.id,
                                                'approval',
                                                requestListPage.url,
                                            )
                                        "
                                        class="font-medium break-words hover:underline"
                                        >{{ item.name ?? '—' }}</Link
                                    >
                                    <span v-else>{{ item.name ?? '—' }}</span>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ item.project ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ item.applicant ?? '—' }}
                                </td>
                                <td
                                    class="px-4 py-3 font-medium whitespace-nowrap text-foreground"
                                >
                                    <template v-if="item.total_price != null">
                                        <MoneyAmount
                                            nowrap
                                            :amount="item.total_price"
                                            :currency="item.currency"
                                            currency-class="text-inherit"
                                        />
                                    </template>
                                    <template v-else>
                                        <span class="text-muted-foreground"
                                            >—</span
                                        >
                                    </template>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        v-if="
                                            item.approval_status === 'approved'
                                        "
                                        class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400"
                                    >
                                        <CheckCircle class="h-3 w-3" />
                                        Одобрено
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-950 dark:text-red-400"
                                    >
                                        <XCircle class="h-3 w-3" />
                                        Отклонено
                                    </span>
                                    <p
                                        v-if="item.approval_comment"
                                        class="mt-1 text-xs text-muted-foreground italic"
                                    >
                                        {{ item.approval_comment }}
                                    </p>
                                </td>
                                <td
                                    class="px-4 py-3 text-center text-xs text-muted-foreground"
                                >
                                    {{ item.level }}
                                </td>
                                <td
                                    class="px-4 py-3 text-xs whitespace-nowrap text-muted-foreground"
                                >
                                    {{ item.approval_date ?? '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <ContentState v-if="!history.data.length">
                        <p class="text-sm">История согласований пуста</p>
                    </ContentState>
                </div>
                <div
                    v-if="history.last_page > 1"
                    class="flex items-center justify-between border-t border-border px-4 py-3"
                >
                    <p class="text-sm text-muted-foreground">
                        Всего: {{ history.total }} · Страница
                        {{ history.current_page }} из {{ history.last_page }}
                    </p>
                    <div class="flex gap-1">
                        <template
                            v-for="link in history.links"
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
