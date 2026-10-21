<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import PasswordController from '@/actions/App/Http/Controllers/Settings/PasswordController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { edit } from '@/routes/user-password';
import type { BreadcrumbItem } from '@/types';

defineProps<{ passwordChangeRequired?: boolean }>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Настройки пароля',
        href: edit(),
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Настройки пароля" />

        <h1 class="sr-only">Настройки пароля</h1>

        <SettingsLayout>
            <div class="space-y-6">
                <p
                    v-if="passwordChangeRequired"
                    role="alert"
                    class="rounded-lg border border-amber-500 bg-amber-50 p-4 text-amber-950 dark:bg-amber-950 dark:text-amber-100"
                >
                    Смените временный пароль или пароль по умолчанию. После
                    смены уведомление исчезнет, а доступ к рабочим разделам
                    будет восстановлен.
                </p>
                <Heading
                    variant="small"
                    title="Изменить пароль"
                    description="Задайте новый пароль для входа в аккаунт"
                />

                <Form
                    v-bind="PasswordController.update.form()"
                    :options="{
                        preserveScroll: true,
                    }"
                    reset-on-success
                    :reset-on-error="[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]"
                    class="space-y-6"
                    v-slot="{ errors, processing, recentlySuccessful }"
                >
                    <div class="grid gap-2">
                        <Label for="current_password">Текущий пароль</Label>
                        <Input
                            id="current_password"
                            name="current_password"
                            type="password"
                            class="mt-1 block w-full"
                            autocomplete="current-password"
                            placeholder="Текущий пароль"
                        />
                        <InputError :message="errors.current_password" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password">Новый пароль</Label>
                        <Input
                            id="password"
                            name="password"
                            type="password"
                            class="mt-1 block w-full"
                            autocomplete="new-password"
                            placeholder="Новый пароль"
                        />
                        <InputError :message="errors.password" />
                        <p class="text-sm text-muted-foreground">
                            Минимум 8 символов, хотя бы одна буква и одна цифра.
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="password_confirmation"
                            >Повторите новый пароль</Label
                        >
                        <Input
                            id="password_confirmation"
                            name="password_confirmation"
                            type="password"
                            class="mt-1 block w-full"
                            autocomplete="new-password"
                            placeholder="Повторите новый пароль"
                        />
                        <InputError :message="errors.password_confirmation" />
                    </div>

                    <div class="flex items-center gap-4">
                        <Button
                            :disabled="processing"
                            data-test="update-password-button"
                            >Сохранить пароль</Button
                        >

                        <Transition
                            enter-active-class="transition ease-in-out"
                            enter-from-class="opacity-0"
                            leave-active-class="transition ease-in-out"
                            leave-to-class="opacity-0"
                        >
                            <p
                                v-show="recentlySuccessful"
                                role="status"
                                class="text-sm text-muted-foreground"
                            >
                                Пароль сохранён.
                            </p>
                        </Transition>
                    </div>
                </Form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
