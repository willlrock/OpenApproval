<script setup lang="ts">
import ContentState from '@/components/patterns/ContentState.vue';
import FormActions from '@/components/patterns/FormActions.vue';
import { Button } from '@/components/ui/button';

defineEmits<{ reload: []; dismiss: [] }>();
</script>

<template>
    <aside
        aria-label="Ошибка загрузки приложения"
        class="fixed inset-x-3 bottom-3 z-[100] mx-auto max-h-[90dvh] max-w-xl overflow-y-auto rounded-xl border border-border bg-background p-4 text-foreground shadow-lg sm:inset-x-6 sm:bottom-6"
    >
        <ContentState kind="error" layout="notice">
            <p class="font-semibold">Не удалось загрузить часть приложения</p>
            <p class="mt-2 text-foreground">
                Проверьте соединение. Если сайт обновился, обновите страницу.
                Перед этим сохраните или скопируйте введённые данные:
                несохранённые изменения могут потеряться.
            </p>
        </ContentState>
        <FormActions layout="inline" class="mt-4">
            <Button type="button" @click="$emit('reload')">
                Обновить страницу
            </Button>
            <Button type="button" variant="outline" @click="$emit('dismiss')">
                Остаться на странице
            </Button>
        </FormActions>
    </aside>
</template>
