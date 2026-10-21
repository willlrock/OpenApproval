<script setup lang="ts">
import { computed, useId } from 'vue';
import type { HTMLAttributes } from 'vue';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
const props = defineProps<{
    id?: string;
    label: string;
    help?: string;
    error?: string;
    class?: HTMLAttributes['class'];
}>();
const generatedId = useId();
const fieldId = computed(() => props.id ?? generatedId);
const controlAttrs = computed(() => ({
    id: fieldId.value,
    'aria-invalid': Boolean(props.error),
    'aria-describedby':
        [
            props.help ? `${fieldId.value}-help` : '',
            props.error ? `${fieldId.value}-error` : '',
        ]
            .filter(Boolean)
            .join(' ') || undefined,
}));
</script>
<template>
    <div :class="cn('min-w-0 space-y-2', $props.class)">
        <Label :for="fieldId">{{ label }}</Label>
        <slot :attrs="controlAttrs" :id="fieldId" />
        <p
            v-if="help"
            :id="`${fieldId}-help`"
            class="text-xs text-muted-foreground"
        >
            {{ help }}
        </p>
        <p
            v-if="error"
            :id="`${fieldId}-error`"
            class="text-sm text-destructive"
            role="alert"
        >
            {{ error }}
        </p>
    </div>
</template>
