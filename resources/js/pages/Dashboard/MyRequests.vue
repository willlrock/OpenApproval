<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Search,
    FilterX,
    Package,
    Receipt,
    Trash2,
    FileText,
} from 'lucide-vue-next';
import { ref, watch } from 'vue';
import AppPage from '@/components/patterns/AppPage.vue';
import ContentState from '@/components/patterns/ContentState.vue';
import MoneyAmount from '@/components/patterns/MoneyAmount.vue';
import PageHeader from '@/components/patterns/PageHeader.vue';
import RequestStatusBadge from '@/components/patterns/RequestStatusBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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
}

const requestListPage = usePage();

const props = defineProps<{
    requests: PaginatedData<RequestItem>;
    filters: Record<string, string>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Обзор', href: '/dashboard' },
    { title: 'Мои заявки', href: '/dashboard/my-requests' },
];

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? 'all');
const type = ref(props.filters.type ?? 'all');

let searchTimeout: ReturnType<typeof setTimeout>;

function applyFilters() {
    const params: Record<string, string> = {};
    if (search.value) params.search = search.value;
    if (status.value && status.value !== 'all') params.status = status.value;
    if (type.value && type.value !== 'all') params.type = type.value;
    router.get('/dashboard/my-requests', params, {
        preserveState: true,
        replace: true,
    });
}

function onSearchInput() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(applyFilters, 400);
}

watch(status, applyFilters);
watch(type, applyFilters);

function resetFilters() {
    search.value = '';
    status.value = 'all';
    type.value = 'all';
    router.get(
        '/dashboard/my-requests',
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

const { loading: listLoading, error: listError } = useListFeedback(
    '/dashboard/my-requests',
);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <AppPage
            ><PageHeader title="Мои заявки"
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
            <Card>
                <CardContent class="pt-6">
                    <div
                        class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4"
                    >
                        <div>
                            <label
                                class="mb-1 block text-xs font-medium text-muted-foreground"
                                >Поиск</label
                            >
                            <div class="relative">
                                <Search
                                    class="absolute top-2.5 left-2.5 h-4 w-4 text-muted-foreground"
                                />
                                <Input
                                    v-model="search"
                                    @input="onSearchInput"
                                    type="text"
                                    placeholder="ID, название, поставщик..."
                                    class="pl-8"
                                />
                            </div>
                        </div>
                        <div>
                            <label
                                class="mb-1 block text-xs font-medium text-muted-foreground"
                                >Статус</label
                            >
                            <Select v-model="status">
                                <SelectTrigger class="w-full">
                                    <SelectValue placeholder="Все" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Все</SelectItem>
                                    <SelectItem value="approval_paused">{{
                                        requestStatusLabels.approval_paused
                                    }}</SelectItem>
                                    <SelectItem value="pending_approval"
                                        >На согласовании</SelectItem
                                    >
                                    <SelectItem value="approved">{{
                                        requestStatusLabels.approved
                                    }}</SelectItem>
                                    <SelectItem value="rejected"
                                        >Отклонена</SelectItem
                                    >
                                    <SelectItem value="pending_supply"
                                        >Снабжение</SelectItem
                                    >
                                    <SelectItem value="cancelled"
                                        >Отменена</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <label
                                class="mb-1 block text-xs font-medium text-muted-foreground"
                                >Тип</label
                            >
                            <Select v-model="type">
                                <SelectTrigger class="w-full">
                                    <SelectValue placeholder="Все" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Все</SelectItem>
                                    <SelectItem value="approval"
                                        >Расход</SelectItem
                                    >
                                    <SelectItem value="supply"
                                        >Снабжение</SelectItem
                                    >
                                    <SelectItem value="management_document"
                                        >Документ руководства</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="flex items-end">
                            <Button variant="outline" @click="resetFilters">
                                <FilterX class="h-4 w-4" />
                                Сбросить
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Table -->
            <Card>
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
                                    Тип
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
                                    Согл.
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium text-muted-foreground"
                                >
                                    Дата
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
                                        class="font-medium break-words hover:underline"
                                        >{{ req.name }}</Link
                                    >
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ req.project ?? '—' }}
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
                                    class="px-4 py-3 text-right whitespace-nowrap"
                                >
                                    <template v-if="req.can_delete">
                                        <Button
                                            v-if="showDeleteConfirm === req.id"
                                            variant="destructive"
                                            size="sm"
                                            @click="deleteRequest(req.id)"
                                        >
                                            Подтвердить
                                        </Button>
                                        <Button
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
                        <p class="text-sm">
                            {{
                                search || status !== 'all' || type !== 'all'
                                    ? 'По выбранным фильтрам заявки не найдены'
                                    : 'У вас пока нет заявок'
                            }}
                        </p>
                        <template
                            v-if="search || status !== 'all' || type !== 'all'"
                            #actions
                        >
                            <Button variant="outline" @click="resetFilters"
                                >Сбросить фильтры</Button
                            >
                        </template>
                    </ContentState>
                </div>
                <div
                    v-if="requests.last_page > 1"
                    class="flex min-w-0 flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3"
                >
                    <p class="text-sm text-muted-foreground">
                        Всего: {{ requests.total }} · Страница
                        {{ requests.current_page }} из {{ requests.last_page }}
                    </p>
                    <div class="flex max-w-full flex-wrap gap-1">
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
