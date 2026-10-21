<script setup lang="ts">
import { useId } from 'vue';
import { Card } from '@/components/ui/card';

defineProps<{ title: string; initials: string }>();
const titleId = useId();
</script>

<template>
    <Card
        role="article"
        :aria-labelledby="titleId"
        data-directory-card
        class="row-span-6 grid min-w-0 grid-rows-subgrid gap-3 py-4 shadow-none"
    >
        <header
            data-directory-region="identity"
            class="flex min-w-0 items-start gap-3 px-4"
        >
            <div
                aria-hidden="true"
                class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted text-sm font-medium text-muted-foreground"
            >
                {{ initials }}
            </div>
            <div class="min-w-0 flex-1 pt-1">
                <h2
                    :id="titleId"
                    class="text-base leading-6 font-semibold [overflow-wrap:anywhere] text-foreground"
                >
                    {{ title }}
                </h2>
                <div
                    v-if="$slots.subtitle"
                    class="mt-1 text-sm leading-5 [overflow-wrap:anywhere] text-muted-foreground"
                >
                    <slot name="subtitle" />
                </div>
            </div>
        </header>
        <div
            data-directory-region="status"
            class="flex min-w-0 flex-wrap items-center gap-2 px-4 text-sm"
        >
            <slot name="status" />
        </div>
        <div
            data-directory-region="details"
            class="min-w-0 space-y-1 self-start px-4 text-sm leading-5 [overflow-wrap:anywhere] text-muted-foreground"
        >
            <slot name="details" />
        </div>
        <div
            data-directory-region="activity"
            class="min-w-0 px-4 text-sm leading-5 text-muted-foreground"
        >
            <slot name="activity" />
        </div>
        <div
            data-directory-region="relations"
            class="min-w-0 px-4 text-sm leading-5 text-muted-foreground"
        >
            <slot name="relations" />
        </div>
        <footer
            data-directory-region="actions"
            class="mx-4 grid grid-cols-[minmax(0,1fr)_5rem] items-end gap-2 border-t border-border pt-3"
        >
            <div
                class="min-w-0 [&>button]:h-auto [&>button]:min-h-11 [&>button]:w-full [&>button]:min-w-0 [&>button]:px-2 [&>button]:whitespace-normal"
            >
                <slot name="actions" />
            </div>
            <div
                v-if="$slots.secondary"
                class="min-w-0 [&>button]:min-h-11 [&>button]:w-full [&>button]:px-2"
            >
                <slot name="secondary" />
            </div>
        </footer>
    </Card>
</template>
