<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    Check,
    Clock,
    X,
    BarChart3,
    FolderOpen,
    ClipboardList,
} from 'lucide-vue-next';
import { reactive } from 'vue';
import CreateRequestMenu from '@/components/CreateRequestMenu.vue';
import AppPage from '@/components/patterns/AppPage.vue';
import MoneyAmount from '@/components/patterns/MoneyAmount.vue';
import PageHeader from '@/components/patterns/PageHeader.vue';
import RequestStatusBadge from '@/components/patterns/RequestStatusBadge.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney as formatNumber } from '@/lib/requestPresentation';

import type { BreadcrumbItem } from '@/types';

interface Stats {
    totalRequests: number;
    approvedRequests: number;
    paidRequests: number;
    rejectedRequests: number;
    revisionRequests: number;
    pendingRequests: number;
    supplyRequests: number;
    totalUsers: number;
    approverCount: number;
}

interface MonthStat {
    month: string;
    total: number;
    approved: number;
    rejected: number;
}

interface ProjectStat {
    id: number;
    name: string;
    total_count: number;
    approved_count: number;
    rejected_count: number;
    pending_count: number;
    approved_sums: Record<string, number>;
}

interface RecentRequest {
    id: number;
    type: string;
    name: string;
    status: string;
    total_price: number | null;
    currency: string | null;
    project: string | null;
    applicant: string | null;
    created_at: string;
}

const props = defineProps<{
    filters: Record<string, string>;
    projects: { id: number; name: string }[];
    role: string;
    myQueue: { id: number; name: string }[];
    stats: Stats;
    financialSummary: Record<
        string,
        { approved: number; paid: number; remaining: number; overdue: number }
    >;
    attention: {
        overduePayment: number;
        overdueSupply: number;
        stuckApproval: number;
    };
    paymentDataIssues: number;
    paymentActivity: Record<string, number>;
    undatedPayments: number;
    monthlyStats: MonthStat[];
    currencySums: Record<string, number>;
    projectStats: ProjectStat[];
    recentRequests: RecentRequest[];
}>();

const filters = reactive({
    project_id: props.filters.project_id || '',
    date_from: props.filters.date_from || '',
    date_to: props.filters.date_to || '',
});
function listUrl(extra: Record<string, string> = {}) {
    return (
        '/dashboard/requests?' +
        new URLSearchParams({ ...props.filters, ...extra }).toString()
    );
}
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Обзор', href: '/dashboard' }];

function maxMonthValue(): number {
    return Math.max(...props.monthlyStats.map((s: MonthStat) => s.total), 1);
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <AppPage>
            <PageHeader
                title="Обзор"
                description="Текущая работа и состояние заявок по выбранному проекту и периоду."
                ><template #actions><CreateRequestMenu /></template
            ></PageHeader>
            <form
                @submit.prevent="router.get('/dashboard', filters)"
                class="flex flex-wrap items-end gap-3 rounded-xl border bg-muted/30 p-4"
            >
                <label class="grid gap-1 text-sm"
                    >Проект<select
                        v-model="filters.project_id"
                        class="rounded border bg-background p-2"
                    >
                        <option value="">Все доступные</option>
                        <option v-for="p in projects" :key="p.id" :value="p.id">
                            {{ p.name }}
                        </option>
                    </select></label
                >
                <label class="grid gap-1 text-sm"
                    >Созданы с<input
                        type="date"
                        v-model="filters.date_from"
                        class="rounded border bg-background p-2"
                /></label>
                <label class="grid gap-1 text-sm"
                    >По<input
                        type="date"
                        v-model="filters.date_to"
                        class="rounded border bg-background p-2"
                /></label>
                <button
                    class="rounded bg-primary px-4 py-2 text-primary-foreground"
                >
                    Применить</button
                ><Link href="/dashboard" class="p-2 text-sm underline"
                    >Сбросить</Link
                >
            </form>
            <section
                v-if="role === 'approver' || myQueue.length"
                class="rounded-xl border border-primary/30 p-5"
            >
                <h2 class="text-lg font-semibold">Ждут вашего решения</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Первые 10 заявок на вашем текущем этапе согласования.
                </p>
                <p v-if="!myQueue.length" class="mt-4">Очередь пуста.</p>
                <Link
                    v-for="r in myQueue"
                    :key="r.id"
                    :href="'/dashboard/requests/' + r.id"
                    class="mt-2 block rounded border p-3 hover:bg-muted"
                    >#{{ r.id }} · {{ r.name }} →</Link
                >
            </section>
            <div
                v-if="role === 'accountant' || role === 'treasurer'"
                class="rounded-xl bg-primary p-5 text-primary-foreground"
            >
                <h2 class="text-lg font-semibold">Рабочая очередь оплат</h2>
                <p class="mt-1">
                    Проверьте остатки и просрочку ниже, затем откройте платежи.
                </p>
                <Link
                    href="/finance/requests"
                    class="mt-3 inline-block underline"
                    >Перейти к оплатам →</Link
                >
            </div>
            <section aria-label="Требует внимания">
                <h2 class="mb-3 text-lg font-semibold">Требует внимания</h2>
                <p
                    v-if="
                        !attention.overduePayment &&
                        !attention.overdueSupply &&
                        !attention.stuckApproval
                    "
                    class="mb-3 text-sm text-muted-foreground"
                >
                    Просроченных оплат, поставок и задержанных согласований нет.
                </p>
                <div class="grid gap-3 sm:grid-cols-3">
                    <Link
                        v-for="card in [
                            {
                                label: 'Просрочена оплата',
                                count: attention.overduePayment,
                                filter: 'overdue-payment',
                            },
                            {
                                label: 'Задержана поставка',
                                count: attention.overdueSupply,
                                filter: 'overdue-supply',
                            },
                            {
                                label: 'Зависло согласование',
                                count: attention.stuckApproval,
                                filter: 'stuck-approval',
                            },
                        ]"
                        :key="card.filter"
                        :href="listUrl({ attention: card.filter })"
                        :class="
                            card.count
                                ? 'border-amber-600/50 bg-amber-50 dark:bg-amber-950/30'
                                : 'bg-muted/20'
                        "
                        class="rounded-xl border p-4 transition hover:bg-muted focus-visible:outline-2"
                    >
                        <p class="text-sm">{{ card.label }}</p>
                        <p class="text-lg font-semibold tabular-nums">
                            {{ card.count }}
                        </p>
                        <span class="text-xs text-muted-foreground"
                            >Открыть заявки →</span
                        >
                    </Link>
                </div>
            </section>
            <section aria-label="Состояние заявок" class="flex flex-wrap gap-2">
                <Link
                    v-for="card in [
                        {
                            label: 'Всего заявок',
                            count: stats.totalRequests,
                            status: '',
                        },
                        {
                            label: 'На согласовании',
                            count: stats.pendingRequests,
                            status: 'pending_approval',
                        },
                        {
                            label: 'Утверждены',
                            count: stats.approvedRequests,
                            status: 'approved',
                        },
                        {
                            label: 'Полностью оплачены',
                            count: stats.paidRequests,
                            status: 'paid',
                        },
                        {
                            label: 'На доработке',
                            count: stats.revisionRequests,
                            status: 'revision_requested',
                        },
                        {
                            label: 'Отклонены',
                            count: stats.rejectedRequests,
                            status: 'rejected',
                        },
                        {
                            label: 'В снабжении',
                            count: stats.supplyRequests,
                            status: 'pending_supply',
                        },
                    ]"
                    :key="card.label"
                    :href="listUrl({ status: card.status })"
                    class="flex items-center gap-3 rounded-lg bg-muted/40 px-3 py-2 hover:bg-muted focus-visible:outline-2"
                >
                    <p class="text-sm text-muted-foreground">
                        {{ card.label }}
                    </p>
                    <p class="text-lg font-semibold tabular-nums">
                        {{ card.count }}
                    </p>
                </Link>
            </section>

            <details class="rounded-xl border bg-card p-4">
                <summary class="cursor-pointer font-semibold">
                    Расходы и платежи — подробная сводка
                </summary>
                <div class="mt-4 space-y-4">
                    <p class="text-sm text-muted-foreground">
                        Расходы и накопленные оплаты относятся к заявкам,
                        созданным за выбранный период. Платежи по дате оплаты
                        показывают отдельный оборот независимо от даты создания
                        заявки.
                    </p>
                    <Card>
                        <CardHeader
                            ><CardTitle class="text-base"
                                >Согласованные расходы и оплата</CardTitle
                            >
                            <p class="text-sm text-muted-foreground">
                                Оплата по отметкам траншей, включая частичные
                                платежи. Приказы не входят в денежные
                                показатели.
                            </p>
                        </CardHeader>
                        <CardContent>
                            <p
                                v-if="paymentDataIssues"
                                class="mb-4 rounded-lg border border-amber-500/50 p-3 text-sm"
                                role="status"
                            >
                                В {{ paymentDataIssues }} заявках суммы траншей
                                или отметки оплаты расходятся с заявкой. Данные
                                требуют сверки; отсутствующие оплаты не
                                подставляются автоматически.
                            </p>
                            <div
                                v-if="Object.keys(financialSummary).length"
                                class="overflow-x-auto"
                            >
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr
                                            class="border-b text-left text-muted-foreground"
                                        >
                                            <th class="p-3">Валюта</th>
                                            <th class="p-3 text-right">
                                                Согласовано
                                            </th>
                                            <th class="p-3 text-right">
                                                Отмечено оплаченным
                                            </th>
                                            <th class="p-3 text-right">
                                                Осталось оплатить
                                            </th>
                                            <th class="p-3 text-right">
                                                Из них просрочено
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="(
                                                row, currency
                                            ) in financialSummary"
                                            :key="currency"
                                            class="border-b last:border-0"
                                        >
                                            <th class="p-3 text-left">
                                                {{ currency }}
                                            </th>
                                            <td
                                                class="p-3 text-right tabular-nums"
                                            >
                                                {{ formatNumber(row.approved) }}
                                            </td>
                                            <td
                                                class="p-3 text-right tabular-nums"
                                            >
                                                {{ formatNumber(row.paid) }}
                                            </td>
                                            <td
                                                class="p-3 text-right font-semibold tabular-nums"
                                            >
                                                {{
                                                    formatNumber(row.remaining)
                                                }}
                                            </td>
                                            <td
                                                class="p-3 text-right tabular-nums"
                                                :class="
                                                    row.overdue
                                                        ? 'text-red-600 dark:text-red-400'
                                                        : ''
                                                "
                                            >
                                                {{ formatNumber(row.overdue) }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <p v-else class="text-sm text-muted-foreground">
                                Согласованных расходов пока нет.
                            </p>
                        </CardContent>
                    </Card>
                    <section class="rounded-xl border p-5">
                        <h2 class="font-semibold">Платежи по дате оплаты</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            За выбранный период и проект, независимо от даты
                            создания заявки. По отметкам в системе.
                        </p>
                        <div class="mt-4 flex flex-wrap gap-6">
                            <p
                                v-for="(amount, currency) in paymentActivity"
                                :key="currency"
                                class="text-xl font-semibold"
                            >
                                {{ formatNumber(amount) }}
                                <span class="text-sm font-normal">{{
                                    currency
                                }}</span>
                            </p>
                            <p
                                v-if="!Object.keys(paymentActivity).length"
                                class="text-sm text-muted-foreground"
                            >
                                Платежей с известной датой за этот период нет.
                            </p>
                        </div>
                        <p
                            v-if="undatedPayments"
                            class="mt-3 text-sm text-amber-700 dark:text-amber-400"
                        >
                            {{ undatedPayments }} старых отметок оплаты без даты
                            в этом проекте не включены в оборот. Их суммы учтены
                            выше; даты требуют сверки.
                        </p>
                    </section>
                </div>
            </details>
            <details
                v-if="stats.totalRequests"
                class="rounded-xl border bg-card p-4"
            >
                <summary class="cursor-pointer font-semibold">
                    Динамика и распределение по проектам
                </summary>
                <div class="mt-4">
                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <!-- Monthly Chart -->
                        <Card>
                            <CardHeader>
                                <CardTitle
                                    class="flex items-center gap-2 text-sm"
                                >
                                    <BarChart3 class="h-4 w-4" />
                                    Заявки за последние 6 месяцев · по дате
                                    создания
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div class="space-y-3">
                                    <div
                                        v-for="stat in monthlyStats"
                                        :key="stat.month"
                                        class="flex items-center gap-3"
                                    >
                                        <span
                                            class="w-20 shrink-0 text-xs text-muted-foreground"
                                            >{{ stat.month }}</span
                                        >
                                        <div
                                            class="flex h-6 flex-1 items-center gap-1"
                                        >
                                            <div
                                                class="h-5 rounded-l bg-emerald-500 dark:bg-emerald-600"
                                                :style="{
                                                    width:
                                                        (stat.approved /
                                                            maxMonthValue()) *
                                                            100 +
                                                        '%',
                                                    minWidth: stat.approved
                                                        ? '4px'
                                                        : '0',
                                                }"
                                            ></div>
                                            <div
                                                class="h-5 bg-red-400 dark:bg-red-600"
                                                :style="{
                                                    width:
                                                        (stat.rejected /
                                                            maxMonthValue()) *
                                                            100 +
                                                        '%',
                                                    minWidth: stat.rejected
                                                        ? '4px'
                                                        : '0',
                                                }"
                                            ></div>
                                            <div
                                                class="h-5 rounded-r bg-muted"
                                                :style="{
                                                    width:
                                                        ((stat.total -
                                                            stat.approved -
                                                            stat.rejected) /
                                                            maxMonthValue()) *
                                                            100 +
                                                        '%',
                                                    minWidth:
                                                        stat.total -
                                                            stat.approved -
                                                            stat.rejected >
                                                        0
                                                            ? '4px'
                                                            : '0',
                                                }"
                                            ></div>
                                        </div>
                                        <span
                                            class="w-8 text-right text-xs font-medium text-foreground"
                                            >{{ stat.total }}</span
                                        >
                                    </div>
                                </div>
                                <div
                                    class="mt-4 flex items-center gap-4 text-xs text-muted-foreground"
                                >
                                    <span class="flex items-center gap-1"
                                        ><span
                                            class="h-3 w-3 rounded bg-emerald-500"
                                        ></span>
                                        Согласовано, включая оплаченные</span
                                    >
                                    <span class="flex items-center gap-1"
                                        ><span
                                            class="h-3 w-3 rounded bg-red-400"
                                        ></span>
                                        Отклонено</span
                                    >
                                    <span class="flex items-center gap-1"
                                        ><span
                                            class="h-3 w-3 rounded bg-muted"
                                        ></span>
                                        Прочее</span
                                    >
                                </div>
                            </CardContent>
                        </Card>

                        <!-- Project Stats -->
                        <Card>
                            <CardHeader>
                                <CardTitle
                                    class="flex items-center gap-2 text-sm"
                                >
                                    <FolderOpen class="h-4 w-4" />
                                    По проектам
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div class="space-y-3">
                                    <div
                                        v-for="project in projectStats"
                                        :key="project.id"
                                        class="rounded-lg bg-muted p-3"
                                    >
                                        <div
                                            class="mb-2 flex items-center justify-between"
                                        >
                                            <span
                                                class="text-sm font-medium text-foreground"
                                                >{{ project.name }}</span
                                            >
                                            <span
                                                class="text-xs text-muted-foreground"
                                                >{{
                                                    project.total_count
                                                }}
                                                заявок</span
                                            >
                                        </div>
                                        <div
                                            class="flex items-center gap-3 text-xs"
                                        >
                                            <span
                                                class="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400"
                                            >
                                                <Check class="h-3 w-3" />
                                                {{ project.approved_count }}
                                                согласовано
                                            </span>
                                            <span
                                                class="inline-flex items-center gap-1 text-red-500 dark:text-red-400"
                                            >
                                                <X class="h-3 w-3" />
                                                {{ project.rejected_count }}
                                                отклонено
                                            </span>
                                            <span
                                                class="inline-flex items-center gap-1 text-amber-600 dark:text-amber-400"
                                            >
                                                <Clock class="h-3 w-3" />
                                                {{ project.pending_count }} в
                                                ожидании
                                            </span>
                                        </div>
                                        <div
                                            v-if="
                                                Object.keys(
                                                    project.approved_sums,
                                                ).length
                                            "
                                            class="mt-2 flex flex-wrap gap-2"
                                        >
                                            <span
                                                v-for="(
                                                    sum, cur
                                                ) in project.approved_sums"
                                                :key="cur"
                                                class="rounded bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
                                            >
                                                {{ cur }}:
                                                {{ formatNumber(Number(sum)) }}
                                            </span>
                                        </div>
                                    </div>
                                    <p
                                        v-if="!projectStats.length"
                                        class="py-4 text-center text-sm text-muted-foreground"
                                    >
                                        Нет проектов
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </details>
            <!-- Recent Requests -->
            <Card>
                <CardHeader class="flex-row items-center justify-between">
                    <CardTitle class="flex items-center gap-2 text-sm">
                        <ClipboardList class="h-4 w-4" />
                        Последние заявки
                    </CardTitle>
                    <Link
                        :href="listUrl()"
                        class="text-xs text-muted-foreground transition-colors hover:text-foreground hover:underline"
                        >Все заявки →</Link
                    >
                </CardHeader>
                <CardContent>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-border">
                                    <th
                                        class="px-3 py-2 text-left text-xs font-medium text-muted-foreground"
                                    >
                                        ID
                                    </th>
                                    <th
                                        class="px-3 py-2 text-left text-xs font-medium text-muted-foreground"
                                    >
                                        Наименование
                                    </th>
                                    <th
                                        class="px-3 py-2 text-left text-xs font-medium text-muted-foreground"
                                    >
                                        Проект
                                    </th>
                                    <th
                                        class="px-3 py-2 text-left text-xs font-medium text-muted-foreground"
                                    >
                                        Заявитель
                                    </th>
                                    <th
                                        class="px-3 py-2 text-left text-xs font-medium text-muted-foreground"
                                    >
                                        Сумма
                                    </th>
                                    <th
                                        class="px-3 py-2 text-left text-xs font-medium text-muted-foreground"
                                    >
                                        Статус
                                    </th>
                                    <th
                                        class="px-3 py-2 text-left text-xs font-medium text-muted-foreground"
                                    >
                                        Дата
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="req in recentRequests"
                                    :key="req.id"
                                    class="border-b border-border/50 transition-colors hover:bg-accent"
                                >
                                    <td class="px-3 py-2.5">
                                        <Link
                                            :href="`/dashboard/requests/${req.id}`"
                                            class="font-medium text-foreground hover:underline"
                                            >#{{ req.id }}</Link
                                        >
                                    </td>
                                    <td
                                        class="max-w-80 min-w-52 px-3 py-2.5 text-foreground"
                                    >
                                        <Link
                                            :href="`/dashboard/requests/${req.id}`"
                                            class="font-medium break-words hover:underline"
                                            >{{ req.name }}</Link
                                        >
                                    </td>
                                    <td
                                        class="px-3 py-2.5 text-muted-foreground"
                                    >
                                        {{ req.project ?? '—' }}
                                    </td>
                                    <td
                                        class="px-3 py-2.5 text-muted-foreground"
                                    >
                                        {{ req.applicant ?? '—' }}
                                    </td>
                                    <td
                                        class="px-3 py-2.5 font-medium text-foreground"
                                    >
                                        <template v-if="req.total_price != null"
                                            ><MoneyAmount
                                                nowrap
                                                :amount="req.total_price"
                                                :currency="req.currency"
                                                currency-class="text-inherit"
                                        /></template>
                                        <template v-else>—</template>
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <RequestStatusBadge
                                            :status="req.status"
                                        />
                                    </td>
                                    <td
                                        class="px-3 py-2.5 text-xs text-muted-foreground"
                                    >
                                        {{ req.created_at }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p
                            v-if="!recentRequests.length"
                            class="py-8 text-center text-sm text-muted-foreground"
                        >
                            Нет заявок
                        </p>
                    </div>
                </CardContent>
            </Card>
        </AppPage>
    </AppLayout>
</template>
