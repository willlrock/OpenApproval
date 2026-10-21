<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Check, X, Clock, ShieldCheck, ShieldAlert } from 'lucide-vue-next';
import MoneyAmount from '@/components/patterns/MoneyAmount.vue';
import { requestStatusLabels } from '@/lib/requestLabels';

interface Approval {
    level: number;
    status: string;
    approver: string | null;
    action_date: string | null;
}

interface RequestData {
    id: number;
    type: string;
    document_kind: string | null;
    document_number: string | null;
    effective_date_label: string | null;
    name: string;
    status: string;
    total_price: number | null;
    currency: string | null;
    project: string | null;
    applicant: string | null;
    created_at: string;
    approvals: Approval[];
}

defineProps<{
    request: RequestData;
    verified: boolean;
}>();

const statusLabels = requestStatusLabels;

const approvalStatusIcons: Record<string, any> = {
    approved: Check,
    rejected: X,
    pending: Clock,
};
</script>

<template>
    <Head title="Проверка заявки" />
    <div
        class="flex min-h-screen items-center justify-center bg-gray-50 p-4 dark:bg-gray-950"
    >
        <div
            class="w-full max-w-lg min-w-0 rounded-xl border bg-white p-4 shadow-lg sm:p-8 dark:border-gray-800 dark:bg-gray-900"
        >
            <div
                class="mb-6 flex items-center justify-center gap-2 text-foreground"
            >
                <component
                    :is="verified ? ShieldCheck : ShieldAlert"
                    class="h-8 w-8 shrink-0"
                    aria-hidden="true"
                />
                <h1 class="min-w-0 text-xl font-bold">Проверка документа</h1>
            </div>

            <div
                role="status"
                class="mb-6 rounded-lg border bg-muted p-4 text-center text-sm text-foreground"
            >
                {{
                    verified
                        ? 'Документ найден в системе TEG Approval Bot. Текущее состояние согласования указано ниже.'
                        : 'Подлинность документа не подтверждена. Обратитесь к отправителю.'
                }}
            </div>

            <dl class="space-y-3 text-sm">
                <div
                    v-if="request.document_number"
                    class="grid grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)] gap-3 border-b border-gray-100 pb-2 dark:border-gray-800"
                >
                    <dt class="text-gray-500 dark:text-gray-400">
                        Номер документа
                    </dt>
                    <dd
                        class="min-w-0 text-right font-semibold break-words text-gray-900 dark:text-gray-100"
                    >
                        {{ request.document_number }}
                    </dd>
                </div>
                <div
                    v-if="request.effective_date_label"
                    class="grid grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)] gap-3 border-b border-gray-100 pb-2 dark:border-gray-800"
                >
                    <dt class="text-gray-500 dark:text-gray-400">
                        Вступает в силу
                    </dt>
                    <dd
                        class="min-w-0 text-right font-medium break-words text-gray-900 dark:text-gray-100"
                    >
                        {{ request.effective_date_label }}
                    </dd>
                </div>
                <div
                    class="grid grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)] gap-3 border-b border-gray-100 pb-2 dark:border-gray-800"
                >
                    <dt class="text-gray-500 dark:text-gray-400">Заявка №</dt>
                    <dd
                        class="min-w-0 text-right font-semibold break-words text-gray-900 dark:text-gray-100"
                    >
                        {{ request.id }}
                    </dd>
                </div>
                <div
                    class="grid grid-cols-1 gap-1 border-b border-gray-100 pb-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)] sm:gap-3 dark:border-gray-800"
                >
                    <dt class="text-gray-500 dark:text-gray-400">
                        Наименование
                    </dt>
                    <dd
                        class="min-w-0 font-medium break-words text-gray-900 sm:text-right dark:text-gray-100"
                    >
                        {{ request.name }}
                    </dd>
                </div>
                <div
                    class="grid grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)] gap-3 border-b border-gray-100 pb-2 dark:border-gray-800"
                >
                    <dt class="text-gray-500 dark:text-gray-400">Статус</dt>
                    <dd
                        class="min-w-0 text-right font-medium break-words text-foreground"
                        :class="{
                            'text-emerald-600 dark:text-emerald-400':
                                request.status === 'approved',
                            'text-red-600 dark:text-red-400':
                                request.status === 'rejected',
                            'text-amber-600 dark:text-amber-400':
                                request.status === 'pending_approval',
                        }"
                    >
                        {{ statusLabels[request.status] ?? request.status }}
                    </dd>
                </div>
                <div
                    v-if="request.project"
                    class="grid grid-cols-1 gap-1 border-b border-gray-100 pb-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)] sm:gap-3 dark:border-gray-800"
                >
                    <dt class="text-gray-500 dark:text-gray-400">Проект</dt>
                    <dd
                        class="min-w-0 break-words text-gray-900 sm:text-right dark:text-gray-100"
                    >
                        {{ request.project }}
                    </dd>
                </div>
                <div
                    v-if="request.applicant"
                    class="grid grid-cols-1 gap-1 border-b border-gray-100 pb-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)] sm:gap-3 dark:border-gray-800"
                >
                    <dt class="text-gray-500 dark:text-gray-400">Заявитель</dt>
                    <dd
                        class="min-w-0 break-words text-gray-900 sm:text-right dark:text-gray-100"
                    >
                        {{ request.applicant }}
                    </dd>
                </div>
                <div
                    v-if="
                        request.total_price !== null &&
                        request.total_price !== undefined
                    "
                    class="grid grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)] gap-3 border-b border-gray-100 pb-2 dark:border-gray-800"
                >
                    <dt class="text-gray-500 dark:text-gray-400">Сумма</dt>
                    <dd
                        class="min-w-0 text-right font-bold break-words text-gray-900 dark:text-gray-100"
                    >
                        <MoneyAmount
                            :amount="request.total_price"
                            :currency="request.currency"
                            class="justify-end"
                        />
                    </dd>
                </div>
                <div
                    class="grid grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)] gap-3 border-b border-gray-100 pb-2 dark:border-gray-800"
                >
                    <dt class="text-gray-500 dark:text-gray-400">
                        Дата создания
                    </dt>
                    <dd
                        class="min-w-0 text-right break-words text-gray-900 dark:text-gray-100"
                    >
                        {{ request.created_at }}
                    </dd>
                </div>
            </dl>

            <!-- Approval chain -->
            <div v-if="request.approvals.length" class="mt-6">
                <h2
                    class="mb-3 text-sm font-semibold text-gray-700 dark:text-gray-300"
                >
                    Согласующие
                </h2>
                <div class="space-y-2">
                    <div
                        v-for="approval in request.approvals"
                        :key="approval.level"
                        class="flex items-center gap-3 rounded-lg border px-3 py-2 dark:border-gray-800"
                    >
                        <div
                            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full"
                            :class="{
                                'bg-emerald-100 dark:bg-emerald-900':
                                    approval.status === 'approved',
                                'bg-red-100 dark:bg-red-900':
                                    approval.status === 'rejected',
                                'bg-gray-100 dark:bg-gray-800':
                                    approval.status === 'pending',
                            }"
                        >
                            <component
                                :is="
                                    approvalStatusIcons[approval.status] ??
                                    Clock
                                "
                                class="h-4 w-4"
                                :class="{
                                    'text-emerald-600 dark:text-emerald-400':
                                        approval.status === 'approved',
                                    'text-red-600 dark:text-red-400':
                                        approval.status === 'rejected',
                                    'text-gray-400':
                                        approval.status === 'pending',
                                }"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p
                                class="text-sm font-medium break-words text-gray-900 dark:text-gray-100"
                            >
                                {{ approval.approver ?? 'Неизвестно' }}
                            </p>
                            <p
                                v-if="approval.action_date"
                                class="text-xs text-gray-500 dark:text-gray-400"
                            >
                                {{ approval.action_date }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <p class="mt-6 text-center text-xs text-muted-foreground">
                TEG Approval & Supply Bot · Электронное согласование
            </p>
        </div>
    </div>
</template>
