<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import ActionButton from '@/components/patterns/ActionButton.vue';
import AppPage from '@/components/patterns/AppPage.vue';
import AppLayout from '@/layouts/AppLayout.vue';
const form = useForm({});
</script>
<template>
    <Head title="Создание приказа" /><AppLayout
        ><AppPage width="form">
            <h1 class="text-2xl font-semibold">Приказ или постановление</h1>
            <p>
                Заполните шаблон Word, загрузите его и выберите подписантов.
                Перед отправкой вы увидите PDF. После утверждения документ
                получит номер и лист подписей.
            </p>
            <p>Инициатору и подписантам нужна подпись в профиле.</p>
            <div class="flex flex-wrap gap-4">
                <a href="/management-templates/order" class="underline"
                    >Шаблон приказа</a
                ><a href="/management-templates/resolution" class="underline"
                    >Шаблон постановления</a
                >
            </div>
            <ActionButton
                :busy="form.processing"
                busy-label="Создаём черновик…"
                class="w-fit max-w-full"
                @click="!form.processing && form.post('/management-drafts')"
                >Начать черновик</ActionButton
            >
        </AppPage></AppLayout
    >
</template>
