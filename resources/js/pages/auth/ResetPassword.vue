<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';
defineProps<{ token: string }>();
</script>

<template>
    <AuthLayout title="Новый пароль" description="Установите личный пароль для веб-кабинета.">
        <Head title="Новый пароль" />
        <Form action="/reset-password" method="post" :transform="data => ({ ...data, token })" class="space-y-4" v-slot="{ errors, processing }">
            <InputError :message="errors.token" />
            <div class="grid gap-2">
                <Label for="password">Новый пароль</Label>
                <Input id="password" name="password" type="password" autocomplete="new-password" required />
                <InputError :message="errors.password" />
                <p class="text-sm text-muted-foreground">Минимум 8 символов, хотя бы одна буква и одна цифра.</p>
            </div>
            <div class="grid gap-2">
                <Label for="password_confirmation">Повторите пароль</Label>
                <Input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required />
            </div>
            <Button :disabled="processing" class="w-full">Сохранить пароль</Button>
        </Form>
        <Link href="/forgot-password" class="mt-4 block text-center underline">Запросить новую ссылку</Link>
    </AuthLayout>
</template>
