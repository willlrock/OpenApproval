<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';
withDefaults(
    defineProps<{
        layout?: 'dialog' | 'inline';
        class?: HTMLAttributes['class'];
    }>(),
    { layout: 'dialog' },
);
</script>
<template>
    <div
        :class="
            cn(
                'flex shrink-0 gap-2 border-t border-border pt-4 sm:flex-row sm:items-center [&>button]:min-h-12 [&>button]:whitespace-normal',
                layout === 'dialog'
                    ? 'flex-col-reverse sm:justify-end'
                    : 'flex-col',
                $props.class,
            )
        "
    >
        <slot />
    </div>
</template>
