<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppPage from '@/components/patterns/AppPage.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/requestPresentation';
const props = defineProps<{
    id: number;
    versions: any[];
    current: any;
    revision: number;
}>();
const selected = ref(
    props.versions.length
        ? props.versions[props.versions.length - 1].version
        : 0,
);
const previous = computed(
    () =>
        props.versions.find((v) => v.version === Number(selected.value))
            ?.data ?? {},
);
function display(value: any, field = ''): string {
    if (Array.isArray(value))
        return value.length
            ? value
                  .map((item, index) => `${index + 1}. ${display(item)}`)
                  .join('\n\n')
            : '—';
    if (value !== null && typeof value === 'object')
        return Object.entries(value)
            .map(([key, item]) => `${key}: ${display(item, key)}`)
            .join('\n');
    if (
        (field === 'Сумма' || field === 'Цена') &&
        value !== null &&
        value !== undefined
    )
        return formatMoney(value);
    return String(value ?? '—');
}
const changedFields = computed(() =>
    props.versions.length
        ? Object.keys(props.current).filter(
              (key) =>
                  display(props.current[key], key) !==
                  display(previous.value[key], key),
          )
        : [],
);
</script>
<template>
    <Head title="История заявки" /><AppLayout
        ><AppPage width="detail">
            <Link
                :href="`/dashboard/requests/${id}`"
                class="w-fit text-sm underline underline-offset-4"
                >К заявке #{{ id }}</Link
            >
            <h1 class="text-2xl font-semibold">
                Заявка #{{ id }} · История изменений
            </h1>
            <p>
                Предыдущие данные и решения сохраняются при повторной отправке.
                Каждая новая версия проходит согласование с первого этапа.
            </p>
            <label v-if="versions.length"
                >Сравнить с версией
                <select
                    v-model="selected"
                    class="rounded border bg-background p-2"
                >
                    <option
                        v-for="v in versions"
                        :key="v.version"
                        :value="v.version"
                    >
                        {{ v.version }} · {{ v.saved_at }}
                    </option>
                </select></label
            >
            <p v-else>Предыдущих версий пока нет.</p>
            <p
                v-if="versions.length"
                role="status"
                class="text-sm text-muted-foreground"
            >
                {{
                    changedFields.length
                        ? `Изменены поля: ${changedFields.join(', ')}.`
                        : 'Различий с выбранной версией нет.'
                }}
            </p>
            <div class="flex flex-wrap gap-3">
                <a
                    v-for="file in versions.find(
                        (v) => v.version === Number(selected),
                    )?.files || []"
                    :key="file.id"
                    :href="file.url"
                    class="min-w-0 break-words underline"
                    >{{ file.name || `Вложение прежней версии №${file.id}` }}</a
                >
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left">
                            <th class="p-3">Поле</th>
                            <th class="p-3">Предыдущая версия</th>
                            <th class="p-3">Текущая · {{ revision }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(value, key) in current"
                            :key="key"
                            class="border-b"
                            :class="
                                versions.length &&
                                display(value, String(key)) !==
                                    display(previous[key], String(key))
                                    ? 'bg-amber-50 dark:bg-amber-950/30'
                                    : ''
                            "
                        >
                            <th class="p-3 text-left align-top">{{ key }}</th>
                            <td class="p-3 align-top">
                                <pre
                                    class="font-sans break-words whitespace-pre-wrap"
                                    >{{
                                        display(previous[key], String(key))
                                    }}</pre
                                >
                            </td>
                            <td class="p-3 align-top">
                                <pre
                                    class="font-sans break-words whitespace-pre-wrap"
                                    >{{ display(value, String(key)) }}</pre
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </AppPage></AppLayout
    >
</template>
