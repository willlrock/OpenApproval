<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';
defineProps<{
    title: string;
    description?: string;
    class?: HTMLAttributes['class'];
}>();
</script>
<template>
    <header
        :class="
            cn(
                'flex min-w-0 flex-wrap items-start justify-between gap-x-4 gap-y-1 sm:gap-4',
                $props.class,
            )
        "
    >
        <div class="min-w-0 flex-1">
            <h1
                class="text-2xl font-semibold tracking-tight break-words text-foreground"
            >
                {{ title }}
            </h1>
            <div
                v-if="description || $slots.description"
                class="mt-1 text-sm text-muted-foreground"
            >
                <slot name="description"
                    ><p>{{ description }}</p></slot
                >
            </div>
        </div>
        <div
            v-if="$slots.actions"
            class="flex max-w-full flex-wrap items-center gap-2"
        >
            <slot name="actions" />
        </div>
        <div
            v-if="$slots.meta"
            class="min-h-5 w-full text-sm text-muted-foreground sm:w-auto"
        >
            <slot name="meta" />
        </div>
    </header>
</template>
