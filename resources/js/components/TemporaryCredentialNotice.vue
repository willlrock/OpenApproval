<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const credential = computed(() => page.props.temporaryCredential as { name: string; password: string } | null);
</script>

<template>
    <div v-if="credential" role="status" class="m-4 rounded-lg border border-amber-500 bg-amber-50 p-4 text-amber-950 dark:bg-amber-950 dark:text-amber-100">
        <p class="font-semibold">Временный пароль: {{ credential.name }}</p>
        <p class="my-2 select-all break-all font-mono text-lg">{{ credential.password }}</p>
        <p class="text-sm">Передайте пароль пользователю лично. Сохраните его сейчас: после перехода он больше не будет показан. Пользователь должен сменить его в профиле.</p>
    </div>
</template>
