<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';
withDefaults(
    defineProps<{
        width?: 'list' | 'detail' | 'form';
        class?: HTMLAttributes['class'];
    }>(),
    { width: 'list' },
);
const widths = {
    list: 'w-full',
    detail: 'mx-auto w-full max-w-6xl',
    form: 'mx-auto w-full max-w-5xl',
};
</script>
<template>
    <div :class="cn('min-w-0 flex-1 p-4 sm:p-6', $props.class)">
        <div :class="cn('flex min-w-0 flex-col gap-6', widths[width])">
            <slot />
        </div>
    </div>
</template>
