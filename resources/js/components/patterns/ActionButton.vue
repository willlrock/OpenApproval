<script setup lang="ts">
import { LoaderCircle } from 'lucide-vue-next';
import type { HTMLAttributes } from 'vue';
import { Button } from '@/components/ui/button';
import type { ButtonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';
defineOptions({ inheritAttrs: false });
const props = withDefaults(
    defineProps<{
        busy?: boolean;
        busyLabel?: string;
        disabled?: boolean;
        variant?: ButtonVariants['variant'];
        size?: ButtonVariants['size'];
        density?: 'form' | 'toolbar';
        class?: HTMLAttributes['class'];
    }>(),
    { busy: false, busyLabel: 'Сохраняем…', density: 'form' },
);
</script>
<template>
    <Button
        v-bind="$attrs"
        :variant="variant"
        :size="size"
        :disabled="disabled || busy"
        :aria-busy="busy || undefined"
        :class="
            cn(
                'h-auto whitespace-normal',
                density === 'form' ? 'min-h-12' : 'min-h-11 sm:min-h-9',
                props.class,
            )
        "
    >
        <span class="grid min-w-0">
            <span
                class="col-start-1 row-start-1 inline-flex items-center justify-center gap-2"
                :class="busy && 'invisible'"
                :aria-hidden="busy || undefined"
                ><slot
            /></span>
            <span
                class="col-start-1 row-start-1 inline-flex items-center justify-center gap-2"
                :class="!busy && 'invisible'"
                :aria-hidden="!busy || undefined"
                ><LoaderCircle
                    class="size-4 shrink-0 motion-safe:animate-spin"
                    aria-hidden="true"
                />{{ busyLabel }}</span
            >
        </span>
    </Button>
</template>
