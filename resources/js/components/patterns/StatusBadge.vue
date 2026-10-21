<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { statusToneClasses } from '@/lib/requestPresentation';
import type { StatusTone } from '@/lib/requestPresentation';
import { cn } from '@/lib/utils';
withDefaults(
    defineProps<{
        label: string;
        tone?: StatusTone;
        class?: HTMLAttributes['class'];
    }>(),
    { tone: 'neutral' },
);
</script>
<template>
    <span
        :class="
            cn(
                'inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap',
                statusToneClasses[tone],
                $props.class,
            )
        "
        >{{ label }}</span
    >
</template>
