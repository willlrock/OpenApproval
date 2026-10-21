<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';
withDefaults(
    defineProps<{
        name?: string | null;
        inn?: string | null;
        compact?: boolean;
        class?: HTMLAttributes['class'];
    }>(),
    { compact: false },
);
</script>
<template>
    <div
        :class="
            cn(
                'min-w-0 break-words',
                compact ? 'text-xs text-muted-foreground' : 'text-sm',
                $props.class,
            )
        "
    >
        <p>{{ name || 'Не указан' }}</p>
        <p v-if="inn" class="text-xs text-muted-foreground">ИНН: {{ inn }}</p>
        <div v-if="$slots.actions" class="mt-2 flex flex-col gap-2 sm:flex-row">
            <slot name="actions" />
        </div>
    </div>
</template>
