<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    FileText,
    Calendar,
    Check,
    Clock,
    X,
    Package,
    CheckCircle2,
    Paperclip,
} from 'lucide-vue-next';
import { ref, computed, watch, nextTick } from 'vue';
import ActionButton from '@/components/patterns/ActionButton.vue';
import FormDialog from '@/components/patterns/FormDialog.vue';
import FormField from '@/components/patterns/FormField.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatMoney as formatNumber } from '@/lib/requestPresentation';

import type {
    RequestDetailData,
    RequestTranche as Tranche,
} from '@/types/request-detail';
const props = defineProps<{ request: RequestDetailData }>();

// Edit tranche dialog
const editDialog = ref(false);
const editingTranche = ref<Tranche | null>(null);
const paymentTask = ref<'payment' | 'promise' | 'rejection'>('payment');
const taskLabels = {
    payment: 'Отметить оплату',
    promise: 'Изменить обещанную дату',
    rejection: 'Отклонить оплату',
};

const trancheForm = useForm({
    _method: 'PUT' as string,
    status: 'pending' as 'pending' | 'paid' | 'failed',
    payment_proof: '',
    payment_proof_file: null as File | null,
    promised_date: '',
    reschedule_reason: '',
    comment: '',
    failure_reason: '',
    amount: 0,
});
watch(paymentTask, (task) => {
    trancheForm.clearErrors();
    trancheForm.status =
        task === 'payment'
            ? 'paid'
            : task === 'rejection'
              ? 'failed'
              : (editingTranche.value?.status ?? 'pending');
});

function openEditTranche(tranche: Tranche) {
    if (!tranche.can_manage) return;
    trancheForm.clearErrors();
    editingTranche.value = tranche;
    paymentTask.value = 'payment';
    trancheForm.status = 'paid';
    trancheForm.payment_proof = tranche.payment_proof ?? '';
    trancheForm.payment_proof_file = null;
    trancheForm.promised_date = tranche.promised_date ?? '';
    trancheForm.reschedule_reason = '';
    trancheForm.comment = tranche.comment ?? '';
    trancheForm.failure_reason = tranche.failure_reason ?? '';
    trancheForm.amount = Number(tranche.amount);
    editDialog.value = true;
}

function onPaymentProofFileChange(e: Event) {
    const input = e.target as HTMLInputElement;
    if (input.files?.length) {
        trancheForm.payment_proof_file = input.files[0];
    }
}

function saveTranche() {
    if (!editingTranche.value?.can_manage) return;
    trancheForm.transform((data) => {
        const common = { _method: data._method, comment: data.comment };
        if (paymentTask.value === 'payment')
            return {
                ...common,
                status: 'paid',
                payment_proof: data.payment_proof,
                payment_proof_file: data.payment_proof_file,
            };
        if (paymentTask.value === 'rejection')
            return {
                ...common,
                status: 'failed',
                failure_reason: data.failure_reason,
            };
        return {
            ...common,
            status: editingTranche.value?.status ?? 'pending',
            promised_date: data.promised_date,
            reschedule_reason: data.reschedule_reason,
            failure_reason: editingTranche.value?.failure_reason ?? '',
        };
    });
    trancheForm.post(
        `/finance/requests/${props.request.id}/tranches/${editingTranche.value.id}`,
        {
            forceFormData: true,
            onSuccess: () => {
                editDialog.value = false;
            },
            onError: (errors) => {
                const fieldIds: Record<string, string> = {
                    promised_date: 'payment-promise',
                    reschedule_reason: 'payment-promise-reason',
                    payment_proof: 'payment-proof',
                    payment_proof_file: 'payment-file',
                    failure_reason: 'payment-failure',
                    comment: 'payment-comment',
                };
                void nextTick(() => {
                    const field = Object.keys(errors)[0];
                    document
                        .getElementById(
                            fieldIds[field] ?? 'payment-operation-errors',
                        )
                        ?.focus();
                });
            },
        },
    );
}

const trancheStatusConfig: Record<
    string,
    { label: string; color: string; bg: string }
> = {
    pending: {
        label: 'Ожидает',
        color: 'text-amber-700 dark:text-amber-400',
        bg: 'bg-amber-50 dark:bg-amber-950',
    },
    paid: {
        label: 'Оплачен',
        color: 'text-emerald-700 dark:text-emerald-400',
        bg: 'bg-emerald-50 dark:bg-emerald-950',
    },
    failed: {
        label: 'Оплата отклонена',
        color: 'text-red-700 dark:text-red-400',
        bg: 'bg-red-50 dark:bg-red-950',
    },
};

const paidTotal = computed(() =>
    props.request.tranches
        .filter((t: Tranche) => t.status === 'paid')
        .reduce((s: number, t: Tranche) => s + Number(t.amount), 0),
);
const pendingTotal = computed(() =>
    props.request.tranches
        .filter((t: Tranche) => t.status === 'pending')
        .reduce((s: number, t: Tranche) => s + Number(t.amount), 0),
);
const progressPercent = computed(() => {
    const total = Number(props.request.total_price);
    return total > 0
        ? Math.min(100, Math.round((paidTotal.value / total) * 100))
        : 0;
});
</script>

<template>
    <div class="space-y-6">
        <Card>
            <CardHeader>
                <div class="flex items-center justify-between">
                    <CardTitle class="flex items-center gap-2 text-sm">
                        <Package class="h-4 w-4" />
                        Транши оплаты ({{ request.tranches.length }})
                    </CardTitle>
                    <!-- Progress pill -->
                    <span
                        v-if="request.tranches.length > 0"
                        class="text-xs text-muted-foreground"
                    >
                        {{ progressPercent }}% оплачено
                    </span>
                </div>
                <!-- Progress bar -->
                <div
                    v-if="request.tranches.length > 0"
                    class="mt-2 h-1.5 rounded-full bg-muted"
                >
                    <div
                        class="h-1.5 rounded-full transition-all"
                        :class="
                            progressPercent === 100
                                ? 'bg-emerald-500'
                                : 'bg-amber-500'
                        "
                        :style="{
                            width: progressPercent + '%',
                        }"
                    />
                </div>
            </CardHeader>
            <CardContent>
                <div
                    v-if="request.tranches.length === 0"
                    class="py-6 text-center text-sm text-muted-foreground"
                >
                    График платежей не указан
                </div>
                <div v-else class="space-y-3">
                    <div
                        v-for="(tranche, idx) in request.tranches"
                        :key="tranche.id"
                        class="rounded-lg border border-border p-4 transition-colors"
                        :class="
                            tranche.status === 'paid'
                                ? 'border-emerald-200 bg-emerald-50/50 dark:border-emerald-900 dark:bg-emerald-950/20'
                                : ''
                        "
                    >
                        <div
                            class="flex flex-col items-start justify-between gap-3 sm:flex-row"
                        >
                            <div class="flex gap-3">
                                <div
                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
                                    :class="
                                        trancheStatusConfig[tranche.status]?.bg
                                    "
                                >
                                    <CheckCircle2
                                        v-if="tranche.status === 'paid'"
                                        class="h-4 w-4 text-emerald-600"
                                    />
                                    <Clock
                                        v-else-if="tranche.status === 'pending'"
                                        class="h-4 w-4 text-amber-600"
                                    />
                                    <X v-else class="h-4 w-4 text-red-600" />
                                </div>
                                <div>
                                    <p
                                        class="text-sm font-medium text-foreground"
                                    >
                                        Транш {{ idx + 1 }}
                                        <span
                                            v-if="tranche.percent"
                                            class="text-muted-foreground"
                                            >({{ tranche.percent }}%)</span
                                        >
                                    </p>
                                    <p
                                        class="text-base font-bold text-foreground"
                                    >
                                        {{ formatNumber(tranche.amount) }}
                                        {{ request.currency }}
                                    </p>
                                </div>
                            </div>
                            <div
                                class="flex w-full flex-wrap items-center gap-2 sm:w-auto sm:justify-end"
                            >
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="[
                                        trancheStatusConfig[tranche.status]
                                            ?.color,
                                        trancheStatusConfig[tranche.status]?.bg,
                                    ]"
                                >
                                    {{
                                        trancheStatusConfig[tranche.status]
                                            ?.label
                                    }}
                                </span>
                                <Button
                                    v-if="tranche.can_manage"
                                    size="sm"
                                    variant="outline"
                                    @click="openEditTranche(tranche)"
                                >
                                    Управлять оплатой
                                </Button>
                            </div>
                        </div>

                        <!-- Details row -->
                        <div
                            class="mt-2 ml-11 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
                        >
                            <span
                                v-if="tranche.promised_date"
                                class="text-amber-700 dark:text-amber-400"
                                >Обещано:
                                {{
                                    tranche.promised_date
                                        ?.split('-')
                                        .reverse()
                                        .join('.')
                                }}</span
                            ><span
                                v-if="tranche.due_date"
                                class="flex items-center gap-1"
                            >
                                <Calendar class="h-3 w-3" />
                                Срок: {{ tranche.due_date }}
                            </span>
                            <span v-if="tranche.paid_at"
                                >Оплачено: {{ tranche.paid_at }}</span
                            >
                            <a
                                v-if="tranche.payment_proof_url"
                                :href="tranche.payment_proof_url"
                                target="_blank"
                                class="flex items-center gap-1 text-blue-600 hover:underline"
                            >
                                <Paperclip class="h-3 w-3" />
                                Скачать платежку
                            </a>
                            <span
                                v-else-if="tranche.payment_proof"
                                class="flex items-center gap-1 text-blue-600"
                            >
                                <FileText class="h-3 w-3" />
                                {{ tranche.payment_proof }}
                            </span>
                            <span
                                v-if="tranche.comment"
                                class="text-muted-foreground"
                            >
                                {{ tranche.comment }}
                            </span>
                            <span
                                v-if="tranche.failure_reason"
                                class="text-red-600"
                            >
                                Причина отказа:
                                {{ tranche.failure_reason }}
                            </span>
                        </div>
                    </div>

                    <!-- Summary -->
                    <div class="mt-4 rounded-lg bg-muted p-3">
                        <div class="flex justify-between text-sm">
                            <span class="text-muted-foreground">Оплачено:</span>
                            <span class="font-medium text-emerald-600"
                                >{{ formatNumber(paidTotal) }}
                                {{ request.currency }}</span
                            >
                        </div>
                        <div class="mt-1 flex justify-between text-sm">
                            <span class="text-muted-foreground">Ожидает:</span>
                            <span class="font-medium text-amber-600"
                                >{{ formatNumber(pendingTotal) }}
                                {{ request.currency }}</span
                            >
                        </div>
                        <div
                            class="mt-1 flex justify-between border-t border-border pt-1 text-sm"
                        >
                            <span class="font-medium text-foreground"
                                >Итого:</span
                            >
                            <span class="font-bold text-foreground"
                                >{{ formatNumber(request.total_price) }}
                                {{ request.currency }}</span
                            >
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>
        <Card v-if="request.tranches.some((t) => t.deadline_history?.length)"
            ><CardHeader
                ><CardTitle>История обещанных сроков</CardTitle></CardHeader
            ><CardContent
                ><div v-for="t in request.tranches" :key="t.id">
                    <p
                        v-for="(h, i) in t.deadline_history"
                        :key="i"
                        class="border-b py-2 text-sm"
                    >
                        Транш #{{ t.id }} ·
                        {{ h.previous_date || 'Не задан' }} →
                        {{ h.promised_date || 'Снят' }} · {{ h.reason }} ·
                        {{ h.user_name || 'Удалённый пользователь' }}
                        · {{ h.created_at }}
                    </p>
                </div></CardContent
            ></Card
        >
    </div>
    <Dialog :open="editDialog" @update:open="editDialog = $event">
        <FormDialog
            summary-id="payment-operation-errors"
            :errors="trancheForm.errors"
            ><template #header
                ><DialogHeader>
                    <DialogTitle>Управление оплатой транша</DialogTitle>
                    <DialogDescription
                        >Транш №{{ editingTranche?.id }} ·
                        {{ formatNumber(editingTranche?.amount ?? 0) }}
                        {{ request.currency }}. Выберите
                        действие.</DialogDescription
                    >
                </DialogHeader></template
            >

            <fieldset class="space-y-2" :disabled="trancheForm.processing">
                <legend class="mb-2 text-sm font-medium">
                    Действие с платежом
                </legend>
                <label
                    v-for="(label, task) in taskLabels"
                    :key="task"
                    class="flex cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm has-checked:border-primary has-checked:bg-muted"
                >
                    <input
                        v-model="paymentTask"
                        type="radio"
                        name="payment-task"
                        :value="task"
                        class="size-4 shrink-0 accent-current"
                    />{{ label }}
                </label>
            </fieldset>

            <template v-if="paymentTask === 'promise'">
                <div>
                    <p class="text-sm font-medium">Согласованный срок</p>
                    <p class="text-sm text-muted-foreground">
                        {{
                            editingTranche?.due_date ||
                            request.deadline_date ||
                            'Не указан'
                        }}
                    </p>
                </div>
                <FormField
                    id="payment-promise"
                    label="Новая обещанная дата оплаты"
                    :error="trancheForm.errors.promised_date"
                    help="Не отменяет просрочку по согласованному сроку."
                    v-slot="{ attrs }"
                    ><Input
                        v-model="trancheForm.promised_date"
                        name="promised_date"
                        type="date"
                        v-bind="attrs"
                /></FormField>
                <FormField
                    id="payment-promise-reason"
                    label="Причина изменения обещанного срока"
                    :error="trancheForm.errors.reschedule_reason"
                    v-slot="{ attrs }"
                    ><Input
                        v-model="trancheForm.reschedule_reason"
                        name="reschedule_reason"
                        maxlength="1000"
                        v-bind="attrs"
                /></FormField>
            </template>
            <template v-if="paymentTask === 'payment'">
                <FormField
                    id="payment-proof"
                    label="Номер платежа или подтверждение"
                    :error="trancheForm.errors.payment_proof"
                    v-slot="{ attrs }"
                    ><Input
                        v-model="trancheForm.payment_proof"
                        name="payment_proof"
                        placeholder="Номер платёжного поручения"
                        v-bind="attrs"
                /></FormField>
                <FormField
                    id="payment-file"
                    label="Файл подтверждения (необязательно)"
                    :error="trancheForm.errors.payment_proof_file"
                    help="PDF, JPG, PNG, Word — до 10 МБ."
                    v-slot="{ attrs }"
                    ><input
                        type="file"
                        name="payment_proof_file"
                        accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                        class="block w-full text-sm file:mr-3 file:rounded file:border-0 file:bg-muted file:px-3 file:py-2 file:text-foreground"
                        @change="onPaymentProofFileChange"
                        v-bind="attrs"
                /></FormField>
            </template>
            <FormField
                v-if="paymentTask === 'rejection'"
                id="payment-failure"
                label="Причина отказа в оплате"
                :error="trancheForm.errors.failure_reason"
                v-slot="{ attrs }"
                ><Input
                    v-model="trancheForm.failure_reason"
                    name="failure_reason"
                    maxlength="1000"
                    v-bind="attrs"
            /></FormField>
            <FormField
                id="payment-comment"
                label="Комментарий (необязательно)"
                :error="trancheForm.errors.comment"
                v-slot="{ attrs }"
                ><Input
                    v-model="trancheForm.comment"
                    name="comment"
                    maxlength="500"
                    v-bind="attrs"
            /></FormField>

            <template #actions>
                <Button
                    class="min-h-12"
                    variant="outline"
                    @click="editDialog = false"
                    >Закрыть</Button
                >
                <ActionButton
                    class="min-h-12"
                    @click="saveTranche"
                    :disabled="
                        trancheForm.processing ||
                        (paymentTask === 'rejection' &&
                            !trancheForm.failure_reason.trim())
                    "
                    :busy="trancheForm.processing"
                >
                    <Check class="size-4 shrink-0" />{{
                        taskLabels[paymentTask]
                    }}
                </ActionButton>
            </template></FormDialog
        >
    </Dialog>
</template>
