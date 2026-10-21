<script setup lang="ts">
import {
    ArrowRight,
    CalendarDays,
    FolderOpen,
    UserRound,
} from 'lucide-vue-next';
import { computed } from 'vue';
import MoneyAmount from '@/components/patterns/MoneyAmount.vue';
import { requestStatusLabels, requestTypeLabels } from '@/lib/requestLabels';
import { formatMoney } from '@/lib/requestPresentation';

type Stage = 'approval' | 'supply' | 'finance';

const props = defineProps<{
    id: number;
    name: string;
    type?: string;
    status: string;
    stage: Stage;
    createdAt: string;
    applicant?: string | null;
    project?: string | null;
    paymentMethod?: string | null;
    amount?: number | null;
    paidAmount?: number;
    currency?: string | null;
    description?: string | null;
}>();

const typeLabel = computed(
    () => requestTypeLabels[props.type ?? ''] ?? 'Заявка',
);

const status = computed(() => {
    if (props.stage === 'finance') {
        if (props.status === 'paid')
            return {
                label: 'Оплачена',
                hint: 'Все платежи по заявке проведены.',
                tone: 'success',
            };
        if (props.status === 'approved')
            return {
                label: requestStatusLabels.approved,
                hint: 'Проверьте и отметьте платежи в графике ниже.',
                tone: 'pending',
            };
    }
    const statuses: Record<
        string,
        { label: string; hint: string; tone: string }
    > = {
        draft: {
            label: requestStatusLabels.draft,
            hint: 'Заявка ещё не отправлена на согласование.',
            tone: 'neutral',
        },
        pending_supply: {
            label: requestStatusLabels.pending_supply,
            hint: 'Снабжение уточняет позиции, поставщика и стоимость.',
            tone: 'pending',
        },
        approval_paused: {
            label: requestStatusLabels.approval_paused,
            hint: 'Замените недоступных участников и отправьте заявку на повторное согласование.',
            tone: 'pending',
        },
        pending_approval: {
            label: requestStatusLabels.pending_approval,
            hint: 'Ожидается решение назначенных согласующих.',
            tone: 'pending',
        },
        approved: {
            label: requestStatusLabels.approved,
            hint: 'Согласование завершено.',
            tone: 'success',
        },
        revision_requested: {
            label: requestStatusLabels.revision_requested,
            hint: 'Заявителю нужно исправить заявку и отправить её снова.',
            tone: 'pending',
        },
        rejected: {
            label: requestStatusLabels.rejected,
            hint: 'Заявка отклонена. Причина указана в истории согласования.',
            tone: 'danger',
        },
        cancelled: {
            label: requestStatusLabels.cancelled,
            hint: 'Работа по заявке остановлена.',
            tone: 'neutral',
        },
        paid: {
            label: requestStatusLabels.paid,
            hint: 'Платежи по заявке проведены.',
            tone: 'success',
        },
    };
    return (
        statuses[props.status] ?? {
            label: props.status,
            hint: 'Текущий статус заявки.',
            tone: 'neutral',
        }
    );
});

const toneClasses: Record<string, string> = {
    neutral: 'border-border bg-muted/60 text-foreground',
    pending:
        'border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100',
    success:
        'border-emerald-200 bg-emerald-50 text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100',
    danger: 'border-red-200 bg-red-50 text-red-950 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100',
};

const executionSummary = computed(() => {
    if (
        props.type === 'management_document' ||
        !['approved', 'paid'].includes(props.status) ||
        !props.amount
    )
        return null;
    const paid = Number(props.paidAmount ?? 0);
    const remaining = Math.max(
        0,
        Math.round((Number(props.amount) - paid) * 100) / 100,
    );
    const percent = Math.min(
        100,
        Math.round((paid / Number(props.amount)) * 100),
    );
    return `Оплачено ${percent}% · Осталось ${formatMoney(remaining)} ${props.currency ?? ''}`;
});
</script>

<template>
    <section
        class="overflow-hidden rounded-xl border border-border bg-card shadow-sm"
        aria-labelledby="request-title"
    >
        <div class="p-4 sm:p-6">
            <div
                class="mb-3 flex flex-wrap items-center gap-2 text-xs font-medium text-muted-foreground"
            >
                <span class="rounded-md bg-muted px-2 py-1 text-foreground">{{
                    typeLabel
                }}</span>
                <span>Заявка №{{ id }}</span>
            </div>
            <div
                class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between"
            >
                <div class="min-w-0">
                    <h1
                        id="request-title"
                        class="text-xl font-semibold tracking-tight text-balance text-foreground sm:text-3xl"
                    >
                        {{ name }}
                    </h1>
                    <p
                        v-if="description"
                        class="mt-2 hidden max-w-2xl text-sm leading-6 whitespace-pre-line text-muted-foreground sm:block"
                    >
                        {{ description }}
                    </p>
                </div>
                <div
                    v-if="amount != null"
                    class="shrink-0 rounded-lg bg-muted/60 px-4 py-3 md:text-right"
                >
                    <p class="text-xs text-muted-foreground">Сумма заявки</p>
                    <p
                        class="mt-1 text-xl font-semibold text-foreground tabular-nums"
                    >
                        <MoneyAmount
                            :amount="amount"
                            :currency="currency"
                            currency-class="text-foreground"
                        />
                    </p>
                </div>
            </div>
            <div
                class="mt-5 hidden flex-wrap gap-x-5 gap-y-2 border-t border-border pt-4 text-sm text-muted-foreground sm:flex"
            >
                <span v-if="applicant" class="inline-flex items-center gap-1.5"
                    ><UserRound class="size-4" aria-hidden="true" />{{
                        applicant
                    }}</span
                >
                <span v-if="project" class="inline-flex items-center gap-1.5"
                    ><FolderOpen class="size-4" aria-hidden="true" />{{
                        project
                    }}</span
                >
                <span
                    v-if="paymentMethod"
                    class="inline-flex items-center gap-1.5"
                    >{{ paymentMethod }}</span
                >
                <span class="inline-flex items-center gap-1.5"
                    ><CalendarDays class="size-4" aria-hidden="true" />Создана
                    {{ createdAt }}</span
                >
            </div>
            <details class="mt-3 text-sm text-muted-foreground sm:hidden">
                <summary class="cursor-pointer py-2 font-medium">
                    Описание и участники
                </summary>
                <p v-if="description" class="mt-2 whitespace-pre-line">
                    {{ description }}
                </p>
                <dl class="mt-3 space-y-2">
                    <div v-if="applicant">
                        <dt class="text-xs">Заявитель</dt>
                        <dd>{{ applicant }}</dd>
                    </div>
                    <div v-if="project">
                        <dt class="text-xs">Проект</dt>
                        <dd>{{ project }}</dd>
                    </div>
                    <div v-if="paymentMethod">
                        <dt class="text-xs">Способ оплаты</dt>
                        <dd>{{ paymentMethod }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs">Создана</dt>
                        <dd>{{ createdAt }}</dd>
                    </div>
                </dl>
            </details>
        </div>
        <div
            :class="toneClasses[status.tone]"
            class="flex flex-col gap-3 border-t px-5 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6"
        >
            <div class="flex min-w-0 items-start gap-3">
                <ArrowRight class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <div>
                    <p class="text-sm font-semibold">{{ status.label }}</p>
                    <p class="mt-0.5 text-xs opacity-80">{{ status.hint }}</p>
                    <p v-if="executionSummary" class="mt-2 text-sm font-medium">
                        {{ executionSummary }}
                    </p>
                </div>
            </div>
            <slot name="status-action" />
        </div>
        <div
            v-if="$slots.actions"
            class="flex flex-wrap items-center gap-2 border-t border-border bg-muted/20 px-5 py-3 sm:px-6"
        >
            <slot name="actions" />
        </div>
    </section>
</template>
