<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import {
    Banknote,
    CreditCard,
    Calendar,
    ArrowRight,
    FileText,
    FilterX,
} from 'lucide-vue-next';
import { ref, computed } from 'vue';
import FilterBar from '@/components/FilterBar.vue';
import type { FilterField } from '@/components/FilterBar.vue';
import AppPage from '@/components/patterns/AppPage.vue';
import ContentState from '@/components/patterns/ContentState.vue';
import MoneyAmount from '@/components/patterns/MoneyAmount.vue';
import PageHeader from '@/components/patterns/PageHeader.vue';
import SupplierInfo from '@/components/patterns/SupplierInfo.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useListFeedback } from '@/composables/useListFeedback';
import AppLayout from '@/layouts/AppLayout.vue';
import { requestDetailHref } from '@/lib/request-detail';
import type { BreadcrumbItem } from '@/types';

interface Project {
    id: number;
    name: string;
}
interface RequestRow {
    id: number;
    name: string;
    currency: string;
    total_price: number;
    transaction_type: string;
    supplier_name: string | null;
    status: string;
    deadline_date: string | null;
    created_at: string;
    project: string | null;
    applicant: string | null;
    tranches_total: number;
    tranches_paid: number;
    tranches_pending: number;
}

const props = defineProps<{
    requests: {
        data: RequestRow[];
        links: any[];
        total: number;
        last_page: number;
    };
    projects: Project[];
    filters: Record<string, string>;
    role: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Обзор', href: '/dashboard' },
    { title: 'Оплаты', href: '/finance/requests' },
];

const search = ref(props.filters.search ?? '');
const projectId = ref(props.filters.project_id ?? 'all');
const trancheStatus = ref(props.filters.tranche_status ?? 'all');
const transactionType = ref(props.filters.transaction_type ?? 'all');

// Бухгалтер заблокирован на перечислениях; казначей и админы могут переключать тип оплаты.
const canFilterType = computed(() => props.role !== 'accountant');

function applyFilters() {
    router.get(
        '/finance/requests',
        {
            search: search.value || undefined,
            project_id:
                projectId.value && projectId.value !== 'all'
                    ? projectId.value
                    : undefined,
            tranche_status:
                trancheStatus.value && trancheStatus.value !== 'all'
                    ? trancheStatus.value
                    : undefined,
            transaction_type:
                transactionType.value && transactionType.value !== 'all'
                    ? transactionType.value
                    : undefined,
        },
        { preserveState: true, replace: true },
    );
}

// Есть ли хоть один активный фильтр — чтобы показать кнопку сброса
// и отличить «нет данных» от «нет результатов по фильтру».
const hasActiveFilters = computed(
    () =>
        !!search.value ||
        projectId.value !== 'all' ||
        trancheStatus.value !== 'all' ||
        transactionType.value !== 'all',
);

function resetFilters() {
    search.value = '';
    projectId.value = 'all';
    trancheStatus.value = 'all';
    transactionType.value = 'all';
    router.get('/finance/requests', {}, { preserveState: true, replace: true });
}

function openRequest(id: number) {
    const back = window.location.pathname + window.location.search;
    router.visit(requestDetailHref(id, 'payments', back));
}

const roleLabel = computed(() => {
    if (props.role === 'treasurer') return 'Казначей · Наличные и перечисление';
    if (props.role === 'accountant') return 'Бухгалтер · Перечисление';
    return 'Все типы оплат';
});

function trancheProgress(req: RequestRow) {
    if (req.tranches_total === 0) return 0;
    return Math.round((req.tranches_paid / req.tranches_total) * 100);
}

function statusLabel(req: RequestRow) {
    if (req.status === 'paid')
        return { label: 'Оплачено', variant: 'default' as const };
    if (req.tranches_total > 0 && req.tranches_paid > 0)
        return { label: 'Частично', variant: 'secondary' as const };
    return { label: 'Ожидает оплаты', variant: 'outline' as const };
}
const filterFields = computed<FilterField[]>(() => [
    {
        key: 'search',
        label: 'Поиск',
        type: 'search',
        placeholder: 'ID, название, поставщик',
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
        key: 'tranche_status',
        label: 'Статус оплаты',
        options: [
            { value: 'all', label: 'Все статусы' },
            { value: 'pending', label: 'Ожидает оплаты' },
            { value: 'paid', label: 'Оплачен' },
            { value: 'failed', label: 'Оплата отклонена' },
        ],
    },
    ...(canFilterType.value
        ? [
              {
                  key: 'transaction_type',
                  label: 'Способ оплаты',
                  options: [
                      { value: 'all', label: 'Все способы' },
                      { value: 'cash', label: 'Наличные' },
                      { value: 'bank', label: 'Перечисление' },
                  ],
              },
          ]
        : []),
]);
const filterValues = computed({
    get: () => ({
        search: search.value,
        project_id: projectId.value,
        tranche_status: trancheStatus.value,
        transaction_type: transactionType.value,
    }),
    set: (values) => {
        search.value = values.search;
        projectId.value = values.project_id;
        trancheStatus.value = values.tranche_status;
        transactionType.value = values.transaction_type;
    },
});
const { loading: listLoading, error: listError } =
    useListFeedback('/finance/requests');
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <AppPage>
            <!-- Header -->
            <PageHeader title="Оплаты" :description="roleLabel"
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
            ></FilterBar>

            <!-- Request cards -->
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Card
                    v-for="req in requests.data"
                    :key="req.id"
                    role="link"
                    tabindex="0"
                    :aria-label="'Открыть оплату: ' + req.name"
                    @keydown.enter="openRequest(req.id)"
                    class="group cursor-pointer transition-colors hover:border-primary/30"
                    @click="openRequest(req.id)"
                >
                    <CardContent class="pt-5">
                        <!-- Top: ID + status -->
                        <div class="mb-3 flex items-center justify-between">
                            <span
                                class="font-mono text-xs text-muted-foreground"
                                >#{{ req.id }}</span
                            >
                            <Badge :variant="statusLabel(req).variant">
                                {{ statusLabel(req).label }}
                            </Badge>
                        </div>

                        <!-- Name -->
                        <p
                            class="mb-1 line-clamp-2 min-h-10 text-sm font-medium break-words text-foreground"
                        >
                            {{ req.name }}
                        </p>

                        <!-- Project + applicant -->
                        <div class="mb-3 text-xs text-muted-foreground">
                            <span v-if="req.project">{{ req.project }}</span>
                            <span v-if="req.project && req.applicant"> · </span>
                            <span v-if="req.applicant">{{
                                req.applicant
                            }}</span>
                        </div>

                        <!-- Amount + type -->
                        <div class="mb-3 flex items-center justify-between">
                            <p class="text-lg font-bold text-foreground">
                                <MoneyAmount
                                    :amount="req.total_price"
                                    :currency="req.currency"
                                />
                            </p>
                            <span
                                class="flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                            >
                                <Banknote
                                    v-if="req.transaction_type === 'cash'"
                                    class="h-3 w-3"
                                />
                                <CreditCard v-else class="h-3 w-3" />
                                {{
                                    req.transaction_type === 'cash'
                                        ? 'Наличные'
                                        : 'Перевод'
                                }}
                            </span>
                        </div>

                        <!-- Supplier -->
                        <div
                            v-if="req.supplier_name"
                            class="mb-3 flex items-center gap-1 text-xs text-muted-foreground"
                        >
                            <FileText
                                class="h-3 w-3 shrink-0"
                                aria-hidden="true"
                            /><SupplierInfo :name="req.supplier_name" compact />
                        </div>

                        <!-- Tranches progress -->
                        <div v-if="req.tranches_total > 0" class="mb-3">
                            <div
                                class="mb-1 flex items-center justify-between text-xs"
                            >
                                <span class="text-muted-foreground"
                                    >Транши</span
                                >
                                <span>
                                    <span
                                        class="font-medium text-emerald-600"
                                        >{{ req.tranches_paid }}</span
                                    >
                                    <span class="text-muted-foreground">
                                        / {{ req.tranches_total }}</span
                                    >
                                </span>
                            </div>
                            <div class="h-1.5 rounded-full bg-muted">
                                <div
                                    class="h-1.5 rounded-full transition-all"
                                    :class="
                                        trancheProgress(req) === 100
                                            ? 'bg-emerald-500'
                                            : 'bg-amber-500'
                                    "
                                    :style="{
                                        width: trancheProgress(req) + '%',
                                    }"
                                />
                            </div>
                        </div>
                        <div v-else class="mb-3 text-xs text-muted-foreground">
                            Единовременная оплата
                        </div>

                        <!-- Footer: deadline + action -->
                        <div
                            class="flex items-center justify-between border-t border-border pt-3"
                        >
                            <span
                                v-if="req.deadline_date"
                                class="flex items-center gap-1 text-xs text-muted-foreground"
                            >
                                <Calendar class="h-3 w-3" />
                                {{ req.deadline_date }}
                            </span>
                            <span
                                v-else
                                class="text-xs text-muted-foreground"
                                >{{ req.created_at }}</span
                            >

                            <span
                                class="flex items-center gap-1 text-xs font-medium text-primary opacity-0 transition-opacity group-hover:opacity-100"
                            >
                                Открыть <ArrowRight class="h-3 w-3" />
                            </span>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- Empty -->
            <ContentState v-if="!requests.data.length">
                <template v-if="hasActiveFilters">
                    <p class="text-sm">
                        По выбранным фильтрам ничего не найдено
                    </p>
                    <Button
                        variant="outline"
                        class="mt-3"
                        @click="resetFilters"
                    >
                        <FilterX class="h-4 w-4" />
                        Сбросить фильтры
                    </Button>
                </template>
                <p v-else class="text-sm">
                    Нет заявок на оплату. Здесь появятся согласованные расходы,
                    доступные вашей роли.
                </p>
            </ContentState>

            <!-- Pagination -->
            <div
                v-if="requests.last_page > 1"
                class="flex justify-center gap-1"
            >
                <template v-for="link in requests.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        preserve-scroll
                        preserve-state
                        class="rounded px-3 py-1.5 text-xs font-medium transition-colors"
                        :class="
                            link.active
                                ? 'bg-primary text-primary-foreground'
                                : 'border border-border bg-card text-muted-foreground hover:bg-accent'
                        "
                    >
                        <span />
                        <span v-html="link.label"
                    /></Link>
                </template>
            </div>
        </AppPage>
    </AppLayout>
</template>
