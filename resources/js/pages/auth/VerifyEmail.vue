<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AuthLayout from '@/layouts/AuthLayout.vue';
</script>
<template><AuthLayout title="Вход в кабинет" description="Аккаунт подтверждается администратором через Telegram."><Head title="Вход в кабинет" /><Link href="/login" class="underline">Вернуться ко входу</Link></AuthLayout></template>
