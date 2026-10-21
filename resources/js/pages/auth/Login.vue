<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import {
    Phone,
    Lock,
    ArrowRight,
    ShieldCheck,
    LayoutDashboard,
} from 'lucide-vue-next';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import InputError from '@/components/InputError.vue';
import ActionButton from '@/components/patterns/ActionButton.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { store } from '@/routes/login';

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();
</script>

<template>
    <Head title="Вход в систему" />

    <div
        class="relative flex min-h-screen items-center justify-center overflow-hidden bg-slate-50 dark:bg-[#0a0a0a]"
    >
        <!-- Декоративные элементы фона -->
        <div
            class="absolute -top-[10%] -left-[10%] h-[40%] w-[40%] rounded-full bg-primary/5 blur-[120px]"
        ></div>
        <div
            class="absolute -right-[10%] -bottom-[10%] h-[40%] w-[40%] rounded-full bg-primary/10 blur-[120px]"
        ></div>

        <div class="z-10 w-full max-w-md p-6">
            <div class="mb-10 flex flex-col items-center">
                <div
                    class="mb-6 flex h-16 w-16 items-center justify-center rounded-2xl bg-white shadow-xl dark:bg-[#161615]"
                >
                    <AppLogoIcon class="h-10 w-10 fill-primary" />
                </div>
                <h1
                    class="text-3xl font-bold tracking-tight text-slate-900 dark:text-white"
                >
                    TEG Approval Bot
                </h1>
                <p
                    class="mt-2 text-muted-foreground dark:text-muted-foreground"
                >
                    Панель управления системой согласования
                </p>
            </div>

            <div
                class="overflow-hidden rounded-3xl border border-white/20 bg-white/70 shadow-2xl backdrop-blur-xl dark:bg-[#161615]/80"
            >
                <div class="p-8">
                    <div
                        v-if="status"
                        role="status"
                        class="mb-6 rounded-xl bg-emerald-50 p-4 text-sm font-medium text-emerald-600 dark:bg-emerald-500/10"
                    >
                        {{ status }}
                    </div>

                    <Form
                        v-bind="store.form()"
                        :reset-on-success="['password']"
                        v-slot="{ errors, processing }"
                        class="space-y-6"
                    >
                        <div class="space-y-4">
                            <div class="space-y-2">
                                <Label
                                    for="phone"
                                    class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                >
                                    Номер телефона
                                </Label>
                                <div class="relative">
                                    <Phone
                                        class="absolute top-1/2 left-4 h-5 w-5 -translate-y-1/2 text-muted-foreground transition-colors"
                                    />
                                    <Input
                                        id="phone"
                                        type="tel"
                                        inputmode="tel"
                                        autocomplete="username"
                                        name="phone"
                                        :aria-invalid="!!errors.phone"
                                        :aria-describedby="
                                            errors.phone
                                                ? 'login-phone-error'
                                                : undefined
                                        "
                                        required
                                        autofocus
                                        class="h-12 pl-12 transition-all focus:ring-primary/20"
                                        placeholder="998901234567"
                                    />
                                </div>
                                <InputError
                                    id="login-phone-error"
                                    :message="errors.phone"
                                    role="alert"
                                />
                            </div>

                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <Label
                                        for="password"
                                        class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                    >
                                        Пароль
                                    </Label>
                                </div>
                                <div class="relative">
                                    <Lock
                                        class="absolute top-1/2 left-4 h-5 w-5 -translate-y-1/2 text-muted-foreground transition-colors"
                                    />
                                    <Input
                                        id="password"
                                        type="password"
                                        name="password"
                                        :aria-invalid="!!errors.password"
                                        :aria-describedby="
                                            errors.password
                                                ? 'login-password-error'
                                                : undefined
                                        "
                                        autocomplete="current-password"
                                        required
                                        class="h-12 pl-12 transition-all focus:ring-primary/20"
                                        placeholder="••••••••"
                                    />
                                </div>
                                <InputError
                                    id="login-password-error"
                                    :message="errors.password"
                                    role="alert"
                                />
                            </div>
                        </div>

                        <div class="flex items-center justify-between">
                            <Label
                                for="remember"
                                class="flex cursor-pointer items-center space-x-3 text-sm text-slate-600 dark:text-muted-foreground"
                            >
                                <Checkbox id="remember" name="remember" />
                                <span>Запомнить меня</span>
                            </Label>
                        </div>

                        <ActionButton
                            type="submit"
                            class="relative h-12 w-full overflow-hidden rounded-xl bg-primary text-primary-foreground shadow-lg transition-all hover:translate-y-[-2px] hover:shadow-primary/25 active:translate-y-0"
                            :busy="processing"
                            busy-label="Входим в систему…"
                        >
                            <span
                                class="flex items-center justify-center gap-2 font-semibold"
                            >
                                Войти в систему
                                <ArrowRight class="h-4 w-4" />
                            </span>
                        </ActionButton>
                    </Form>
                    <Link
                        v-if="canResetPassword"
                        href="/forgot-password"
                        class="mt-5 block text-center text-sm font-medium text-primary underline-offset-4 hover:underline focus-visible:rounded focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    >
                        Первый вход или забыли пароль? Получить ссылку в
                        Telegram
                    </Link>
                    <p class="mt-2 text-center text-sm text-muted-foreground">
                        Если доступа к Telegram нет, обратитесь к
                        администратору.
                    </p>
                </div>

                <div
                    class="mt-2 border-t border-slate-100 bg-slate-50/50 p-6 text-center dark:border-white/5 dark:bg-white/5"
                >
                    <div
                        class="flex items-center justify-center gap-2 text-sm text-muted-foreground"
                    >
                        <ShieldCheck class="h-4 w-4 text-emerald-500" />
                        Ваши данные надежно защищены
                    </div>
                </div>
            </div>

            <div
                class="mt-10 flex flex-wrap justify-center gap-6 text-xs text-muted-foreground"
            >
                <span class="flex items-center gap-1">
                    <LayoutDashboard class="h-3 w-3" />
                    TEG
                </span>
                <span
                    >© {{ new Date().getFullYear() }} TEG. Все права
                    защищены.</span
                >
            </div>
        </div>
    </div>
</template>
