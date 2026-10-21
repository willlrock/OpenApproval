<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';
defineProps<{ title?: string; class?: HTMLAttributes['class'] }>();
</script>
<template>
    <section
        :class="
            cn(
                'min-w-0 rounded-xl border border-border bg-card p-4 sm:p-5',
                $props.class,
            )
        "
    >
        <h2 v-if="title" class="mb-4 text-lg font-semibold">{{ title }}</h2>
        <slot />
    </section>
</template>
