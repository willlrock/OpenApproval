<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { edit } from '@/routes/profile';
import type { BreadcrumbItem } from '@/types';

type Props = {
    mustVerifyEmail: boolean;
    status?: string;
    passwordChangeNotice?: { deadline: string; overdue: boolean } | null;
    telegramLinkCode?: string | null;
};

defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Настройки профиля',
        href: edit(),
    },
];

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Настройки профиля" />

        <h1 class="sr-only">Настройки профиля</h1>

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <div
                    v-if="passwordChangeNotice"
                    role="alert"
                    class="rounded-lg border p-5"
                    :class="
                        passwordChangeNotice.overdue
                            ? 'border-red-500 bg-red-50 text-red-950 dark:bg-red-950 dark:text-red-100'
                            : 'border-amber-500 bg-amber-50 text-amber-950 dark:bg-amber-950 dark:text-amber-100'
                    "
                >
                    <h2 class="font-semibold">Смените пароль по умолчанию</h2>
                    <p class="mt-2 text-sm">
                        {{
                            passwordChangeNotice.overdue
                                ? 'Срок смены пароля истёк. Пожалуйста, смените его сейчас.'
                                : 'Смените временный пароль перед продолжением работы.'
                        }}
                        Срок: {{ passwordChangeNotice.deadline }}.
                    </p>
                    <Link
                        href="/settings/password"
                        class="mt-3 inline-block font-semibold underline"
                        >Изменить пароль</Link
                    >
                </div>
                <div v-if="!user.telegram_id" class="rounded-lg border p-5">
                    <h2 class="font-semibold">Привязать Telegram</h2>
                    <p class="mt-2 text-sm">
                        Создайте одноразовый код и отправьте боту команду ниже.
                        Код действует 10 минут. Если открыт диалог регистрации,
                        сначала отправьте /cancel.
                    </p>
                    <Form
                        action="/settings/telegram-link"
                        method="post"
                        class="mt-3"
                        v-slot="{ processing, errors }"
                    >
                        <Button :disabled="processing"
                            >Создать код привязки</Button
                        >
                        <InputError :message="errors.telegram" />
                    </Form>
                    <code
                        v-if="telegramLinkCode"
                        class="mt-3 block break-all select-all"
                        >/link {{ telegramLinkCode }}</code
                    >
                </div>
                <Heading
                    variant="small"
                    title="Данные профиля"
                    description="Измените имя и номер телефона"
                />

                <Form
                    v-bind="ProfileController.update.form()"
                    class="space-y-6"
                    v-slot="{ errors, processing, recentlySuccessful }"
                >
                    <div class="grid gap-2">
                        <Label for="full_name">Имя и фамилия</Label>
                        <Input
                            id="full_name"
                            class="mt-1 block w-full"
                            name="full_name"
                            :default-value="user.full_name"
                            required
                            autocomplete="name"
                            placeholder="Имя и фамилия"
                        />
                        <InputError class="mt-2" :message="errors.full_name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="phone">Номер телефона</Label>
                        <Input
                            id="phone"
                            type="tel"
                            class="mt-1 block w-full"
                            name="phone"
                            :default-value="user.phone ?? ''"
                            required
                            autocomplete="tel"
                            placeholder="Номер телефона"
                        />
                        <InputError class="mt-2" :message="errors.phone" />
                    </div>

                    <div class="flex items-center gap-4">
                        <Button
                            :disabled="processing"
                            data-test="update-profile-button"
                            >Сохранить</Button
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
                                Сохранено.
                            </p>
                        </Transition>
                    </div>
                </Form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
