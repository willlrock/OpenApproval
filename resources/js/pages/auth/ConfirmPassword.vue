<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { store } from '@/routes/password/confirm';
</script>

<template>
    <AuthLayout
        title="Подтвердите пароль"
        description="Для продолжения подтвердите пароль вашего аккаунта."
    >
        <Head title="Подтверждение пароля" />

        <Form
            v-bind="store.form()"
            reset-on-success
            v-slot="{ errors, processing }"
        >
            <div class="space-y-6">
                <div class="grid gap-2">
                    <Label for="password">Пароль</Label>
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        :aria-invalid="!!errors.password"
                        :aria-describedby="
                            errors.password
                                ? 'confirm-password-error'
                                : undefined
                        "
                        class="mt-1 block w-full"
                        required
                        autocomplete="current-password"
                        autofocus
                    />

                    <InputError
                        id="confirm-password-error"
                        :message="errors.password"
                        role="alert"
                    />
                </div>

                <div class="flex items-center">
                    <Button
                        class="w-full"
                        :disabled="processing"
                        data-test="confirm-password-button"
                    >
                        <Spinner v-if="processing" />
                        Подтвердить пароль
                    </Button>
                </div>
            </div>
        </Form>
    </AuthLayout>
</template>
