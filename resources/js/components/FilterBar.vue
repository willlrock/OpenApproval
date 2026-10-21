<script setup lang="ts">
import { Search, RotateCcw } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, useId } from 'vue';
import { Button } from '@/components/ui/button';
export type FilterField = {
    key: string;
    label: string;
    type?: 'search' | 'date';
    placeholder?: string;
    advanced?: boolean;
    options?: { value: string; label: string }[];
};
const props = defineProps<{
    fields: FilterField[];
    values: Record<string, string>;
}>();
const emit = defineEmits<{
    'update:values': [Record<string, string>];
    apply: [];
    reset: [];
}>();
const id = useId();
const expanded = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;
const active = computed(() =>
    props.fields.filter(
        (f) => props.values[f.key] && props.values[f.key] !== 'all',
    ),
);
function update(field: FilterField, event: Event) {
    emit('update:values', {
        ...props.values,
        [field.key]: (event.target as HTMLInputElement).value,
    });
    clearTimeout(timer);
    if (field.type === 'search') timer = setTimeout(() => emit('apply'), 400);
    else emit('apply');
}
function reset() {
    clearTimeout(timer);
    emit('reset');
}
function apply() {
    clearTimeout(timer);
    emit('apply');
}
onBeforeUnmount(() => clearTimeout(timer));
</script>
<template>
    <form
        role="search"
        aria-label="Фильтры заявок"
        class="rounded-xl border bg-card p-4"
        @submit.prevent="apply"
    >
        <div class="flex flex-wrap items-end gap-4">
            <slot name="leading" />
            <div
                v-for="field in fields"
                v-show="!field.advanced || expanded"
                :key="field.key"
                class="min-w-40 flex-1"
                :class="field.type === 'search' ? 'basis-64' : 'basis-40'"
            >
                <label
                    :for="id + field.key"
                    class="mb-2 block text-sm font-medium"
                    >{{ field.label }}</label
                >
                <div class="relative">
                    <Search
                        v-if="field.type === 'search'"
                        class="pointer-events-none absolute top-3 left-3 size-4 text-muted-foreground"
                    />
                    <select
                        v-if="field.options"
                        :id="id + field.key"
                        :value="values[field.key] || 'all'"
                        class="filter-control"
                        @change="update(field, $event)"
                    >
                        <option
                            v-for="option in field.options"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                    <input
                        v-else
                        :id="id + field.key"
                        :type="field.type === 'date' ? 'date' : 'search'"
                        :value="values[field.key]"
                        :placeholder="field.placeholder"
                        class="filter-control"
                        :class="field.type === 'search' && 'pl-9'"
                        @input="
                            field.type === 'search' && update(field, $event)
                        "
                        @change="field.type === 'date' && update(field, $event)"
                    />
                </div>
            </div>
            <Button
                v-if="fields.some((f) => f.advanced)"
                type="button"
                variant="outline"
                :aria-expanded="expanded"
                @click="expanded = !expanded"
                >{{ expanded ? 'Скрыть даты' : 'Период' }}</Button
            >
            <Button
                v-if="active.length"
                type="button"
                variant="outline"
                @click="reset"
                ><RotateCcw class="size-4" />Сбросить фильтры</Button
            >
            <slot name="actions" />
        </div>
        <div
            v-if="active.length"
            class="mt-3 flex flex-wrap gap-2 text-xs"
            role="status"
        >
            <span
                v-for="field in active"
                :key="field.key"
                class="rounded-md bg-muted px-2 py-1"
                >{{ field.label }}:
                {{
                    field.options?.find((o) => o.value === values[field.key])
                        ?.label || values[field.key]
                }}</span
            >
        </div>
    </form>
</template>
<style scoped>
.filter-control {
    width: 100%;
    min-height: 2.5rem;
    border: 1px solid var(--input);
    border-radius: 0.5rem;
    background: var(--background);
    color: var(--foreground);
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
}
.filter-control.pl-9 {
    padding-left: 2.25rem;
}
.filter-control:focus-visible {
    outline: 2px solid var(--ring);
    outline-offset: 2px;
}
</style>
