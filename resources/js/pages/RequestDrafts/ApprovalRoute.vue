<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';

interface RecoveryRequest {
    id: number;
    name: string;
    revision: number;
    sequence: number;
    unavailable: { id: number; name: string }[];
    route: { id: number; name: string; status: string; unavailable: boolean }[];
    draft: { approvers: number[] } | null;
    eligible: { id: number; full_name: string }[];
}
const props = defineProps<{ request: RecoveryRequest }>();
const form = useForm({
    revision: props.request.revision,
    sequence: props.request.sequence,
    approvers:
        props.request.draft?.approvers ?? props.request.route.map((p) => p.id),
});
const selected = ref<number | null>(null);
const dirty = computed(
    () =>
        JSON.stringify(form.approvers) !==
        JSON.stringify(props.request.draft?.approvers),
);
const available = computed(() =>
    props.request.eligible.filter((p) => !form.approvers.includes(p.id)),
);
function person(id: number): string {
    return (
        props.request.eligible.find((p) => p.id === id)?.full_name ??
        props.request.route.find((p) => p.id === id)?.name ??
        `#${id}`
    );
}
function unavailable(id: number): boolean {
    return !props.request.eligible.some((p) => p.id === id);
}
function move(index: number, offset: number) {
    const ids = [...form.approvers];
    [ids[index], ids[index + offset]] = [ids[index + offset], ids[index]];
    form.approvers = ids;
}
function save() {
    form.sequence = props.request.sequence;
    form.put(`/requests/${props.request.id}/approval-route`, {
        preserveScroll: true,
    });
}
const submit = useForm({
    revision: props.request.revision,
    sequence: props.request.sequence,
});
function resubmit() {
    submit.sequence = props.request.sequence;
    submit.post(`/requests/${props.request.id}/approval-route/submit`);
}
</script>

<template>
    <AppLayout>
        <main
            class="mx-auto w-full max-w-3xl min-w-0 space-y-6 p-4 break-words sm:p-6"
        >
            <Link :href="`/dashboard/requests/${request.id}`" class="underline"
                >К заявке #{{ request.id }}</Link
            >
            <h1 class="text-2xl font-semibold">Изменить согласующих</h1>
            <p class="break-words">{{ request.name }}</p>
            <div
                role="status"
                class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100"
            >
                <p v-for="p in request.unavailable" :key="p.id">
                    Согласующий {{ p.name }} больше недоступен.
                </p>
                <p>
                    Замените участников, сохраните маршрут и явно отправьте
                    заявку на повторное согласование.
                </p>
            </div>
            <p>
                Повторное согласование начнётся с первого уровня. Прежние
                решения и подписи сохранятся в истории. Позиции, документы,
                сроки и платежи сохраняются.
            </p>
            <h2 class="font-semibold">Прежний маршрут</h2>
            <ol class="list-inside list-decimal space-y-2">
                <li v-for="p in request.route" :key="p.id" class="break-words">
                    {{ p.name }} —
                    {{
                        p.status === 'approved'
                            ? 'Согласовано'
                            : p.status === 'rejected'
                              ? 'Отклонено'
                              : 'Ожидает'
                    }}<strong
                        v-if="p.unavailable"
                        class="text-red-700 dark:text-red-300"
                    >
                        · Недоступен</strong
                    >
                </li>
            </ol>
            <form @submit.prevent="save" class="space-y-4">
                <h2 class="font-semibold">Новый маршрут</h2>
                <ol class="space-y-3">
                    <li
                        v-for="(id, i) in form.approvers"
                        :key="id"
                        class="flex flex-wrap items-center gap-2 rounded-lg border p-3"
                    >
                        <span
                            class="min-w-0 grow basis-full break-words sm:basis-auto"
                            >{{ i + 1 }}. {{ person(id)
                            }}<strong
                                v-if="unavailable(id)"
                                class="text-red-700 dark:text-red-300"
                            >
                                · Недоступен</strong
                            ></span
                        >
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="i === 0"
                            :aria-label="`Поднять ${person(id)}`"
                            @click="move(i, -1)"
                            >↑</Button
                        >
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="i === form.approvers.length - 1"
                            :aria-label="`Опустить ${person(id)}`"
                            @click="move(i, 1)"
                            >↓</Button
                        >
                        <Button
                            type="button"
                            variant="outline"
                            :aria-label="`Удалить ${person(id)}`"
                            @click="form.approvers.splice(i, 1)"
                            >Удалить</Button
                        >
                    </li>
                </ol>
                <label for="new-approver" class="block"
                    >Добавить согласующего</label
                >
                <select
                    id="new-approver"
                    v-model="selected"
                    class="w-full rounded-md border bg-background p-2"
                >
                    <option :value="null">Выберите сотрудника</option>
                    <option v-for="p in available" :key="p.id" :value="p.id">
                        {{ p.full_name }}
                    </option>
                </select>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="!selected || form.approvers.length >= 30"
                    @click="
                        selected && form.approvers.push(selected);
                        selected = null;
                    "
                    >Добавить</Button
                >
                <p
                    v-for="(error, key) in form.errors"
                    :key="key"
                    role="alert"
                    class="text-red-700 dark:text-red-300"
                >
                    {{ error }}
                </p>
                <div>
                    <Button
                        type="submit"
                        :disabled="form.processing || !form.approvers.length"
                        >Сохранить маршрут</Button
                    >
                </div>
            </form>
            <div class="space-y-3 border-t pt-4">
                <p v-if="request.draft && !dirty">
                    Маршрут сохранён. Заявка остаётся приостановленной до
                    отправки.
                </p>
                <p
                    v-for="(error, key) in submit.errors"
                    :key="key"
                    role="alert"
                    class="text-red-700 dark:text-red-300"
                >
                    {{ error }}
                </p>
                <Button
                    :disabled="
                        !request.draft ||
                        dirty ||
                        submit.processing ||
                        form.processing
                    "
                    class="h-auto min-h-12 w-full px-4 py-3 break-words whitespace-normal sm:w-auto"
                    @click="resubmit"
                    >Отправить на повторное согласование</Button
                >
            </div>
        </main>
    </AppLayout>
</template>
