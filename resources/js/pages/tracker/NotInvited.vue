<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Inbox, ArrowLeftRight } from 'lucide-vue-next';
</script>

<template>
    <div class="flex min-h-screen flex-col items-center justify-center gap-4 bg-neutral-50 p-6 text-center dark:bg-neutral-950">
        <div class="flex size-16 items-center justify-center rounded-full bg-neutral-200 dark:bg-neutral-800">
            <Inbox class="size-8 text-neutral-500" />
        </div>
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
            Вы не приглашены ни в одно рабочее пространство
        </h1>
        <p class="max-w-md text-sm text-neutral-500">
            Попросите администратора добавить вас в рабочее пространство Task Tracker.
            После приглашения доски станут доступны автоматически.
        </p>
        <Link
            href="/dashboard"
            class="mt-2 inline-flex items-center gap-1.5 rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium hover:bg-neutral-100 dark:border-neutral-700 dark:hover:bg-neutral-800"
        >
            <ArrowLeftRight class="size-4" /> Вернуться в панель согласований
        </Link>
    </div>
</template>
