<script setup lang="ts">
import { X } from 'lucide-vue-next';
import { nextTick, ref, watch } from 'vue';
import type { HTMLAttributes } from 'vue';
import { DialogClose, DialogContent } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import FormActions from './FormActions.vue';
defineOptions({ inheritAttrs: false });
const props = defineProps<{
    errors?: Record<string, string>;
    summaryId?: string;
    class?: HTMLAttributes['class'];
}>();
const body = ref<HTMLElement>();
const summary = ref<HTMLElement>();
watch(
    () => props.errors,
    async (errors) => {
        if (!errors || !Object.keys(errors).length) return;
        await nextTick();
        (
            body.value?.querySelector<HTMLElement>('[aria-invalid="true"]') ??
            summary.value
        )?.focus();
    },
    { deep: true },
);
</script>
<template>
    <DialogContent
        v-bind="$attrs"
        :show-close-button="false"
        :class="
            cn(
                'flex max-h-[90dvh] flex-col overflow-hidden p-4 sm:p-6',
                props.class,
            )
        "
    >
        <div class="shrink-0 pr-8"><slot name="header" /></div>
        <div ref="body" class="min-h-0 space-y-4 overflow-y-auto px-1">
            <div
                v-if="errors && Object.keys(errors).length"
                ref="summary"
                :id="summaryId"
                tabindex="-1"
                role="alert"
                class="rounded-md border border-destructive/30 p-3 text-sm text-destructive"
            >
                <p class="font-medium">Не удалось сохранить:</p>
                <ul class="mt-1 list-inside list-disc">
                    <li
                        v-for="message in [...new Set(Object.values(errors))]"
                        :key="message"
                    >
                        {{ message }}
                    </li>
                </ul>
            </div>
            <slot />
        </div>
        <FormActions v-if="$slots.actions"><slot name="actions" /></FormActions>
        <DialogClose as-child
            ><button
                type="button"
                aria-label="Закрыть"
                class="absolute top-1 right-1 inline-flex min-h-11 min-w-11 items-center justify-center rounded-md text-muted-foreground hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
            >
                <X class="size-4" aria-hidden="true" /></button
        ></DialogClose>
    </DialogContent>
</template>
