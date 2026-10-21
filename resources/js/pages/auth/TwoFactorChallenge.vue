<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { store } from '@/routes/two-factor/login';
import type { TwoFactorConfigContent } from '@/types';

const authConfigContent = computed<TwoFactorConfigContent>(() => {
    if (showRecoveryInput.value) {
        return {
            title: 'Код восстановления',
            description:
                'Введите один из резервных кодов, сохранённых при настройке двухфакторной защиты.',
            buttonText: 'Войти с кодом из приложения',
        };
    }

    return {
        title: 'Код подтверждения',
        description: 'Введите шестизначный код из приложения-аутентификатора.',
        buttonText: 'Войти с резервным кодом',
    };
});

const showRecoveryInput = ref<boolean>(false);

const toggleRecoveryMode = (clearErrors: () => void): void => {
    showRecoveryInput.value = !showRecoveryInput.value;
    clearErrors();
    code.value = '';
};

const code = ref<string>('');
</script>

<template>
    <AuthLayout
        :title="authConfigContent.title"
        :description="authConfigContent.description"
    >
        <Head title="Двухфакторная защита" />

        <div class="space-y-6">
            <template v-if="!showRecoveryInput">
                <Form
                    v-bind="store.form()"
                    class="space-y-4"
                    reset-on-error
                    @error="code = ''"
                    #default="{ errors, processing, clearErrors }"
                >
                    <input type="hidden" name="code" :value="code" />
                    <div
                        class="flex flex-col items-center justify-center space-y-3 text-center"
                    >
                        <Label for="otp">Код из приложения</Label>
                        <div class="flex w-full items-center justify-center">
                            <InputOTP
                                id="otp"
                                aria-label="Шестизначный код из приложения"
                                :aria-invalid="!!errors.code"
                                :aria-describedby="
                                    errors.code ? 'otp-error' : undefined
                                "
                                v-model="code"
                                :maxlength="6"
                                :disabled="processing"
                                autofocus
                            >
                                <InputOTPGroup>
                                    <InputOTPSlot
                                        v-for="index in 6"
                                        :key="index"
                                        :index="index - 1"
                                    />
                                </InputOTPGroup>
                            </InputOTP>
                        </div>
                        <InputError
                            id="otp-error"
                            :message="errors.code"
                            role="alert"
                        />
                    </div>
                    <Button type="submit" class="w-full" :disabled="processing"
                        >Продолжить</Button
                    >
                    <div class="text-center text-sm text-muted-foreground">
                        <span>Или </span>
                        <button
                            type="button"
                            class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                            @click="() => toggleRecoveryMode(clearErrors)"
                        >
                            {{ authConfigContent.buttonText }}
                        </button>
                    </div>
                </Form>
            </template>

            <template v-else>
                <Form
                    v-bind="store.form()"
                    class="space-y-4"
                    reset-on-error
                    #default="{ errors, processing, clearErrors }"
                >
                    <Label for="recovery-code">Резервный код</Label>
                    <Input
                        id="recovery-code"
                        name="recovery_code"
                        :aria-invalid="!!errors.recovery_code"
                        :aria-describedby="
                            errors.recovery_code
                                ? 'recovery-code-error'
                                : undefined
                        "
                        type="text"
                        placeholder="Введите резервный код"
                        :autofocus="showRecoveryInput"
                        required
                    />
                    <InputError
                        id="recovery-code-error"
                        :message="errors.recovery_code"
                        role="alert"
                    />
                    <Button type="submit" class="w-full" :disabled="processing"
                        >Продолжить</Button
                    >

                    <div class="text-center text-sm text-muted-foreground">
                        <span>Или </span>
                        <button
                            type="button"
                            class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                            @click="() => toggleRecoveryMode(clearErrors)"
                        >
                            {{ authConfigContent.buttonText }}
                        </button>
                    </div>
                </Form>
            </template>
        </div>
    </AuthLayout>
</template>
