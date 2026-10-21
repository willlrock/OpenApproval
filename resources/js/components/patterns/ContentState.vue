<script setup lang="ts">
import {
    Inbox,
    LoaderCircle,
    CircleCheck,
    TriangleAlert,
} from 'lucide-vue-next';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';
withDefaults(
    defineProps<{
        kind?: 'loading' | 'error' | 'empty' | 'success';
        message?: string;
        layout?: 'block' | 'inline' | 'notice';
        class?: HTMLAttributes['class'];
        showIcon?: boolean;
    }>(),
    { kind: 'empty', layout: 'block', showIcon: true },
);
const icons = {
    loading: LoaderCircle,
    error: TriangleAlert,
    empty: Inbox,
    success: CircleCheck,
};
</script>
<template>
    <div
        :role="kind === 'error' ? 'alert' : 'status'"
        :aria-busy="kind === 'loading' || undefined"
        :class="
            cn(
                'min-w-0 text-sm',
                layout === 'block'
                    ? 'flex flex-col items-center py-12 text-center'
                    : layout === 'inline'
                      ? 'flex min-h-5 items-center gap-2'
                      : 'rounded-lg border p-4',
                kind === 'error'
                    ? 'text-destructive'
                    : kind === 'success'
                      ? 'text-emerald-700 dark:text-emerald-400'
                      : 'text-muted-foreground',
                layout === 'notice' &&
                    (kind === 'error'
                        ? 'border-destructive/30'
                        : 'border-border'),
                $props.class,
            )
        "
    >
        <component
            v-if="showIcon && layout !== 'notice'"
            :is="icons[kind]"
            :class="
                cn(
                    'shrink-0',
                    layout === 'inline' ? 'size-4' : 'mb-2 size-8',
                    kind === 'loading' && 'motion-safe:animate-spin',
                )
            "
            aria-hidden="true"
        />
        <slot
            ><p>{{ message }}</p></slot
        >
        <div v-if="$slots.actions" class="mt-3 flex flex-wrap gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
