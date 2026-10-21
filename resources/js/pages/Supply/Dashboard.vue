<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Package, Clock, Check, X, ArrowRight } from 'lucide-vue-next';
import AppPage from '@/components/patterns/AppPage.vue';
import PageHeader from '@/components/patterns/PageHeader.vue';
import RequestStatusBadge from '@/components/patterns/RequestStatusBadge.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';

import type { BreadcrumbItem } from '@/types';

interface Stats {
    pendingSupply: number;
    forwardedCount: number;
    approvedCount: number;
    rejectedCount: number;
}

interface RecentRequest {
    id: number;
    name: string;
    status: string;
    project: string | null;
    applicant: string | null;
    applicant_phone: string | null;
    created_at: string;
    items_count: number;
}

defineProps<{
    stats: Stats;
    recentRequests: RecentRequest[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Снабжение', href: '/supply' }];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <AppPage
            ><PageHeader title="Обзор снабжения" />
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card>
                    <CardContent class="pt-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-muted-foreground">
                                    В снабжении
                                </p>
                                <p
                                    class="mt-1 text-3xl font-bold text-violet-600 dark:text-violet-400"
                                >
                                    {{ stats.pendingSupply }}
                                </p>
                            </div>
                            <div
                                class="flex h-12 w-12 items-center justify-center rounded-xl bg-violet-50 dark:bg-violet-950"
                            >
                                <Package
                                    class="h-6 w-6 text-violet-600 dark:text-violet-400"
                                />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent class="pt-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-muted-foreground">
                                    На согласовании
                                </p>
                                <p
                                    class="mt-1 text-3xl font-bold text-amber-600 dark:text-amber-400"
                                >
                                    {{ stats.forwardedCount }}
                                </p>
                            </div>
                            <div
                                class="flex h-12 w-12 items-center justify-center rounded-xl bg-amber-50 dark:bg-amber-950"
                            >
                                <Clock
                                    class="h-6 w-6 text-amber-600 dark:text-amber-400"
                                />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent class="pt-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-muted-foreground">
                                    Утверждено
                                </p>
                                <p
                                    class="mt-1 text-3xl font-bold text-emerald-600 dark:text-emerald-400"
                                >
                                    {{ stats.approvedCount }}
                                </p>
                            </div>
                            <div
                                class="flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-50 dark:bg-emerald-950"
                            >
                                <Check
                                    class="h-6 w-6 text-emerald-600 dark:text-emerald-400"
                                />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent class="pt-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-muted-foreground">
                                    Отклонено
                                </p>
                                <p
                                    class="mt-1 text-3xl font-bold text-red-600 dark:text-red-400"
                                >
                                    {{ stats.rejectedCount }}
                                </p>
                            </div>
                            <div
                                class="flex h-12 w-12 items-center justify-center rounded-xl bg-red-50 dark:bg-red-950"
                            >
                                <X
                                    class="h-6 w-6 text-red-600 dark:text-red-400"
                                />
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- Recent Requests -->
            <Card>
                <CardHeader class="flex-row items-center justify-between">
                    <CardTitle class="flex items-center gap-2 text-sm">
                        <Package class="h-4 w-4" />
                        Последние заявки на снабжение
                    </CardTitle>
                    <Link
                        href="/supply/requests"
                        class="inline-flex items-center gap-1 text-xs text-muted-foreground transition-colors hover:text-foreground hover:underline"
                    >
                        Все заявки <ArrowRight class="h-3 w-3" />
                    </Link>
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
                                        Позиций
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
                                            :href="`/supply/requests/${req.id}`"
                                            class="font-medium text-foreground hover:underline"
                                        >
                                            #{{ req.id }}
                                        </Link>
                                    </td>
                                    <td
                                        class="max-w-80 min-w-52 px-3 py-2.5 text-foreground"
                                    >
                                        <Link
                                            :href="`/supply/requests/${req.id}`"
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
                                        class="px-3 py-2.5 text-center text-muted-foreground"
                                    >
                                        {{ req.items_count }}
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
                            Нет заявок на снабжение
                        </p>
                    </div>
                </CardContent>
            </Card>
        </AppPage>
    </AppLayout>
</template>
