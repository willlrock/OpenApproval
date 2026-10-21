<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';
defineProps<{ status?: string }>();
</script>

<template>
    <AuthLayout title="Восстановление пароля" description="Укажите телефон аккаунта. Одноразовая ссылка придёт в привязанный Telegram.">
        <Head title="Восстановление пароля" />
        <p v-if="status" role="status" class="mb-4 text-sm text-green-700 dark:text-green-400">{{ status }}</p>
        <Form action="/forgot-password" method="post" class="space-y-4" v-slot="{ errors, processing }">
            <div class="grid gap-2">
                <Label for="phone">Телефон</Label>
                <Input id="phone" name="phone" type="tel" autocomplete="tel" placeholder="+998…" required />
                <InputError :message="errors.phone" />
                <p class="text-sm text-muted-foreground">Введите номер, указанный при регистрации в боте. Знак + и пробелы допускаются.</p>
            </div>
            <Button :disabled="processing" class="w-full">Отправить ссылку в Telegram</Button>
        </Form>
        <p class="mt-4 text-sm text-muted-foreground">Если Telegram не привязан, обратитесь к администратору.</p>
        <Link href="/login" class="mt-4 block text-center underline">Вернуться ко входу</Link>
    </AuthLayout>
</template>
