<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { formatMoney } from '@/lib/requestPresentation';
import { cn } from '@/lib/utils';
defineProps<{
    amount: number | string | null | undefined;
    currency?: string | null;
    class?: HTMLAttributes['class'];
    currencyClass?: HTMLAttributes['class'];
    nowrap?: boolean;
}>();
</script>
<template>
    <span
        :class="
            cn(
                'inline-flex max-w-full items-baseline gap-x-1 tabular-nums',
                nowrap ? 'flex-nowrap' : 'flex-wrap',
                $props.class,
            )
        "
    >
        <span class="whitespace-nowrap">{{ formatMoney(amount) }}</span
        >{{ currency && formatMoney(amount) !== '—' ? ' ' : '' }}
        <span
            v-if="currency && formatMoney(amount) !== '—'"
            :class="
                cn('text-sm font-normal text-muted-foreground', currencyClass)
            "
            >{{ currency }}</span
        >
    </span>
</template>
