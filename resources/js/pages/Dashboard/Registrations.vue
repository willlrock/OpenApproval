<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    Search,
    CheckCircle,
    XCircle,
    Clock,
    User,
    PenTool,
    Phone,
} from 'lucide-vue-next';
import { ref, watch, computed, onBeforeUnmount } from 'vue';
import ActionButton from '@/components/patterns/ActionButton.vue';
import AppPage from '@/components/patterns/AppPage.vue';
import FormDialog from '@/components/patterns/FormDialog.vue';
import FormField from '@/components/patterns/FormField.vue';
import PageHeader from '@/components/patterns/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

interface RegUser {
    id: number;
    telegram_id: string;
    full_name: string | null;
    username: string | null;
    phone: string | null;
    department: string | null;
    department_label: string | null;
    account_status: string;
    signature_url: string | null;
    created_at: string | null;
}

interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

const props = defineProps<{
    users: PaginatedData<RegUser>;
    filters: Record<string, string>;
    counts: {
        pendingCount: number;
        approvedCount: number;
        rejectedCount: number;
        newCount: number;
    };
    roles: any[];
    projects: any[];
    departments: any[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Обзор', href: '/dashboard' },
    { title: 'Запросы доступа', href: '/dashboard/registrations' },
];

const search = ref(props.filters.search ?? '');
const statusFilter = ref(props.filters.status ?? 'pending');
const previewUser = ref<RegUser | null>(null);
const previewOpen = ref(false);
const errors = ref<Record<string, string>>({});
const processing = ref<'approve' | 'reject' | null>(null);

const approvalForm = ref({
    role_id: '',
    title: '',
    project_ids: [] as number[],
    department_ids: [] as number[],
});

let searchTimeout: ReturnType<typeof setTimeout>;
onBeforeUnmount(() => clearTimeout(searchTimeout));
const activationHint = computed(() => {
    if (!approvalForm.value.role_id) return 'Выберите роль пользователя.';
    if (!approvalForm.value.project_ids.length)
        return 'Выберите хотя бы один проект.';
    if (!approvalForm.value.department_ids.length)
        return 'Выберите хотя бы один департамент.';
    return '';
});
function groupError(key: string) {
    return Object.entries(errors.value)
        .filter(([field]) => field === key || field.startsWith(key + '.'))
        .map(([, message]) => message)
        .join(' ');
}

function applyFilters() {
    const params: Record<string, string> = {};
    if (search.value) params.search = search.value;
    if (statusFilter.value) params.status = statusFilter.value;
    router.get('/dashboard/registrations', params, {
        preserveState: true,
        replace: true,
    });
}

function onSearchInput() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(applyFilters, 400);
}

watch(statusFilter, applyFilters);

function openPreview(user: RegUser) {
    if (processing.value) return;
    errors.value = {};
    previewUser.value = user;
    previewOpen.value = true;
    // Reset form
    approvalForm.value = {
        role_id: '',
        title: '',
        project_ids: [],
        department_ids: [],
    };
}

function approve(id: number) {
    if (processing.value || activationHint.value) return;
    processing.value = 'approve';
    errors.value = {};
    router.post(
        `/dashboard/registrations/${id}/approve`,
        {
            ...approvalForm.value,
            role_id: Number(approvalForm.value.role_id),
        },
        {
            preserveState: true,
            onSuccess: () => {
                previewOpen.value = false;
            },
            onError: (e) => {
                processing.value = null;
                errors.value = e;
            },
            onFinish: () => {
                processing.value = null;
            },
        },
    );
}

function reject(id: number) {
    if (processing.value) return;
    if (!confirm('Вы уверены, что хотите отклонить эту заявку?')) return;
    processing.value = 'reject';
    errors.value = {};

    router.post(
        `/dashboard/registrations/${id}/reject`,
        {},
        {
            preserveState: true,
            onSuccess: () => {
                previewOpen.value = false;
            },
            onError: (e) => {
                processing.value = null;
                errors.value = e;
            },
            onFinish: () => {
                processing.value = null;
            },
        },
    );
}

const availableDepartments = computed(() => {
    if (approvalForm.value.project_ids.length === 0) {
        return [];
    }
    return props.departments.filter(
        (d) =>
            approvalForm.value.project_ids.includes(d.project_id) ||
            d.project_id === null,
    );
});

const statusConfig: Record<
    string,
    { label: string; color: string; bg: string; icon: any }
> = {
    pending: {
        label: 'Ожидает',
        color: 'text-amber-700 dark:text-amber-400',
        bg: 'bg-amber-50 dark:bg-amber-950',
        icon: Clock,
    },
    approved: {
        label: 'Одобрен',
        color: 'text-emerald-700 dark:text-emerald-400',
        bg: 'bg-emerald-50 dark:bg-emerald-950',
        icon: CheckCircle,
    },
    rejected: {
        label: 'Отклонён',
        color: 'text-red-700 dark:text-red-400',
        bg: 'bg-red-50 dark:bg-red-950',
        icon: XCircle,
    },
    new: {
        label: 'Новый',
        color: 'text-muted-foreground',
        bg: 'bg-muted',
        icon: User,
    },
};

function toggleProject(id: number) {
    const idx = approvalForm.value.project_ids.indexOf(id);
    if (idx > -1) {
        approvalForm.value.project_ids.splice(idx, 1);
    } else {
        approvalForm.value.project_ids.push(id);
    }
    approvalForm.value.department_ids =
        approvalForm.value.department_ids.filter((departmentId) =>
            availableDepartments.value.some((dept) => dept.id === departmentId),
        );
}

function toggleDepartment(id: number) {
    const idx = approvalForm.value.department_ids.indexOf(id);
    if (idx > -1) {
        approvalForm.value.department_ids.splice(idx, 1);
    } else {
        approvalForm.value.department_ids.push(id);
    }
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <AppPage>
            <PageHeader
                title="Запросы доступа"
                description="Проверьте регистрацию и настройте доступ к проектам и департаментам."
            />
            <!-- Stats -->
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                <Card class="border-amber-200 dark:border-amber-800">
                    <CardContent class="p-4">
                        <div class="flex items-center gap-3">
                            <div
                                class="flex h-10 w-10 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900"
                            >
                                <Clock
                                    class="h-5 w-5 text-amber-600 dark:text-amber-400"
                                />
                            </div>
                            <div>
                                <p
                                    class="text-2xl leading-none font-bold text-amber-700 dark:text-amber-400"
                                >
                                    {{ counts.pendingCount }}
                                </p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Ожидают решения
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
                <Card class="border-emerald-200 dark:border-emerald-800">
                    <CardContent class="p-4">
                        <div class="flex items-center gap-3">
                            <div
                                class="flex h-10 w-10 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900"
                            >
                                <CheckCircle
                                    class="h-5 w-5 text-emerald-600 dark:text-emerald-400"
                                />
                            </div>
                            <div>
                                <p
                                    class="text-2xl leading-none font-bold text-emerald-700 dark:text-emerald-400"
                                >
                                    {{ counts.approvedCount }}
                                </p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Одобрено
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
                <Card class="border-red-200 dark:border-red-800">
                    <CardContent class="p-4">
                        <div class="flex items-center gap-3">
                            <div
                                class="flex h-10 w-10 items-center justify-center rounded-full bg-red-100 dark:bg-red-900"
                            >
                                <XCircle
                                    class="h-5 w-5 text-red-600 dark:text-red-400"
                                />
                            </div>
                            <div>
                                <p
                                    class="text-2xl leading-none font-bold text-red-700 dark:text-red-400"
                                >
                                    {{ counts.rejectedCount }}
                                </p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Отклонено
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent class="p-4">
                        <div class="flex items-center gap-3">
                            <div
                                class="flex h-10 w-10 items-center justify-center rounded-full bg-muted"
                            >
                                <User class="h-5 w-5 text-muted-foreground" />
                            </div>
                            <div>
                                <p class="text-2xl leading-none font-bold">
                                    {{ counts.newCount }}
                                </p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Не завершили
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- Filters -->
            <Card>
                <CardContent class="p-4">
                    <div class="flex flex-wrap items-center gap-4">
                        <div
                            class="relative min-w-0 flex-1 basis-full sm:basis-60"
                        >
                            <Search
                                class="absolute top-2.5 left-2.5 h-4 w-4 text-muted-foreground"
                            />
                            <Input
                                aria-label="Поиск по имени или телефону"
                                v-model="search"
                                @input="onSearchInput"
                                placeholder="Поиск по ФИО, телефону..."
                                class="h-9 pl-9"
                            />
                        </div>
                        <Select v-model="statusFilter">
                            <SelectTrigger
                                aria-label="Статус регистрации"
                                class="h-11 w-full sm:w-44"
                            >
                                <SelectValue placeholder="Статус" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="pending">Ожидают</SelectItem>
                                <SelectItem value="approved"
                                    >Одобренные</SelectItem
                                >
                                <SelectItem value="rejected"
                                    >Отклонённые</SelectItem
                                >
                                <SelectItem value="new"
                                    >Не завершили регистрацию</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <p class="ml-auto text-sm text-muted-foreground">
                            Найдено:
                            <span class="font-semibold text-foreground">{{
                                users.total
                            }}</span>
                        </p>
                    </div>
                </CardContent>
            </Card>

            <!-- List -->
            <div
                class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
            >
                <Card
                    v-for="user in users.data"
                    :key="user.id"
                    class="group relative min-w-0 overflow-hidden transition-colors hover:border-primary/50"
                >
                    <CardContent class="p-5">
                        <div class="mb-4 flex items-center justify-between">
                            <div
                                class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 text-sm font-bold text-primary"
                            >
                                {{ (user.full_name || '?').charAt(0) }}
                            </div>
                            <Badge
                                :class="[
                                    statusConfig[user.account_status]?.bg,
                                    statusConfig[user.account_status]?.color,
                                    'border-0',
                                ]"
                            >
                                {{ statusConfig[user.account_status]?.label }}
                            </Badge>
                        </div>

                        <div class="mb-5 space-y-1">
                            <h2
                                class="font-semibold break-words text-foreground"
                            >
                                {{ user.full_name || '—' }}
                            </h2>
                            <div
                                class="flex items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <Phone class="h-3 w-3" />
                                {{ user.phone || 'Нет номера' }}
                            </div>
                        </div>

                        <div class="mb-5 flex flex-wrap gap-2">
                            <div
                                v-if="user.signature_url"
                                class="inline-flex items-center gap-1 rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-medium text-emerald-600 dark:bg-emerald-500/10"
                            >
                                <PenTool class="h-3 w-3" /> ПОДПИСЬ
                            </div>
                        </div>

                        <div
                            class="flex items-center justify-between border-t pt-4"
                        >
                            <span
                                class="text-[10px] tracking-wider text-muted-foreground uppercase"
                            >
                                {{ user.created_at }}
                            </span>
                            <Button
                                size="sm"
                                variant="ghost"
                                class="min-h-11 px-2 text-primary group-hover:bg-primary/5"
                                :aria-label="`Подробнее о регистрации ${user.full_name || user.phone || user.id}`"
                                @click="openPreview(user)"
                            >
                                Подробнее →
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <div
                v-if="!users.data.length"
                class="flex flex-col items-center justify-center rounded-2xl border border-dashed bg-white/50 py-20 text-muted-foreground dark:bg-black/20"
            >
                <div class="mb-4 rounded-full bg-muted p-4">
                    <User class="h-8 w-8" />
                </div>
                <p class="text-sm font-medium">Заявок не найдено</p>
                <p class="text-xs">
                    Попробуйте изменить параметры поиска или фильтры
                </p>
            </div>

            <!-- Pagination -->
            <div v-if="users.last_page > 1" class="mt-6 flex justify-center">
                <nav
                    aria-label="Страницы запросов доступа"
                    class="flex flex-wrap items-center justify-center gap-1"
                >
                    <template v-for="link in users.links" :key="link.label">
                        <button
                            v-if="link.url"
                            @click="router.get(link.url)"
                            :disabled="link.active"
                            :class="[
                                'min-h-11 min-w-11 rounded-lg px-3 text-sm font-medium transition-colors',
                                link.active
                                    ? 'bg-primary text-primary-foreground shadow-md'
                                    : 'text-muted-foreground hover:bg-accent',
                            ]"
                            v-html="link.label"
                        />
                    </template>
                </nav>
            </div>
        </AppPage>

        <!-- Approval / Preview Dialog -->
        <Dialog v-model:open="previewOpen">
            <FormDialog :errors="errors" class="sm:max-w-xl">
                <template #header
                    ><DialogHeader>
                        <DialogTitle class="text-xl"
                            >Регистрация пользователя</DialogTitle
                        >
                        <DialogDescription>
                            Проверьте данные и настройте права доступа для
                            активации аккаунта.
                        </DialogDescription>
                    </DialogHeader></template
                >

                <div v-if="previewUser" class="space-y-6 pt-2">
                    <!-- User Info Card -->
                    <div
                        class="rounded-xl border border-border/50 bg-muted/40 p-4"
                    >
                        <div
                            class="grid grid-cols-1 gap-4 break-words sm:grid-cols-2"
                        >
                            <div>
                                <Label
                                    class="text-[10px] font-bold tracking-widest text-muted-foreground uppercase"
                                    >ФИО</Label
                                >
                                <p class="text-sm font-medium">
                                    {{ previewUser.full_name || '—' }}
                                </p>
                            </div>
                            <div>
                                <Label
                                    class="text-[10px] font-bold tracking-widest text-muted-foreground uppercase"
                                    >Телефон</Label
                                >
                                <p class="text-sm font-medium">
                                    {{ previewUser.phone || '—' }}
                                </p>
                            </div>
                            <div>
                                <Label
                                    class="text-[10px] font-bold tracking-widest text-muted-foreground uppercase"
                                    >Telegram</Label
                                >
                                <p class="text-sm font-medium">
                                    @{{ previewUser.username || '—' }}
                                </p>
                            </div>
                            <div>
                                <Label
                                    class="text-[10px] font-bold tracking-widest text-muted-foreground uppercase"
                                    >Статус</Label
                                >
                                <div class="mt-0.5">
                                    <Badge
                                        :class="
                                            statusConfig[
                                                previewUser.account_status
                                            ]?.bg
                                        "
                                        class="border-0"
                                    >
                                        {{
                                            statusConfig[
                                                previewUser.account_status
                                            ]?.label
                                        }}
                                    </Badge>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Approval Form (pending, and rejected so a rejection can be undone) -->
                    <fieldset
                        v-if="
                            ['pending', 'rejected'].includes(
                                previewUser.account_status,
                            )
                        "
                        :disabled="!!processing"
                        class="space-y-5"
                    >
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <FormField
                                id="registration-role"
                                label="Глобальная роль *"
                                :error="errors.role_id"
                                v-slot="{ attrs }"
                            >
                                <Select
                                    v-model="approvalForm.role_id"
                                    :disabled="!!processing"
                                >
                                    <SelectTrigger
                                        v-bind="attrs"
                                        class="min-h-11"
                                    >
                                        <SelectValue
                                            placeholder="Выберите роль"
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="role in roles"
                                            :key="role.id"
                                            :value="role.id.toString()"
                                        >
                                            {{ role.name }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </FormField>
                            <FormField
                                id="registration-title"
                                label="Должность"
                                :error="errors.title"
                                v-slot="{ attrs }"
                            >
                                <Input
                                    v-bind="attrs"
                                    v-model="approvalForm.title"
                                    placeholder="Исполнительный директор..."
                                    class="min-h-11"
                                />
                            </FormField>
                        </div>

                        <!-- Projects Selection -->
                        <fieldset
                            id="registration-projects"
                            tabindex="-1"
                            :aria-invalid="!!groupError('project_ids')"
                            :aria-describedby="
                                groupError('project_ids')
                                    ? 'registration-projects-error'
                                    : undefined
                            "
                            class="min-w-0 space-y-3"
                        >
                            <legend class="text-sm font-medium">
                                Доступ к проектам *
                            </legend>
                            <div
                                class="grid grid-cols-1 gap-2 rounded-xl border p-3 sm:grid-cols-2"
                            >
                                <div
                                    v-for="project in projects"
                                    :key="project.id"
                                    class="flex min-w-0 items-center gap-2"
                                >
                                    <Checkbox
                                        :id="'proj-' + project.id"
                                        :model-value="
                                            approvalForm.project_ids.includes(
                                                project.id,
                                            )
                                        "
                                        :disabled="!!processing"
                                        @update:model-value="
                                            toggleProject(project.id)
                                        "
                                    />
                                    <label
                                        :for="'proj-' + project.id"
                                        class="flex min-h-11 min-w-0 flex-1 cursor-pointer items-center text-sm break-words select-none"
                                    >
                                        {{ project.name }}
                                    </label>
                                </div>
                            </div>
                            <p
                                v-if="groupError('project_ids')"
                                id="registration-projects-error"
                                role="alert"
                                class="text-sm text-destructive"
                            >
                                {{ groupError('project_ids') }}
                            </p>
                            <p
                                v-if="!projects.length"
                                class="text-sm text-muted-foreground"
                            >
                                Нет активных проектов. Сначала добавьте проект в
                                справочнике.
                            </p>
                        </fieldset>

                        <!-- Departments (Filtered) -->
                        <fieldset
                            id="registration-departments"
                            tabindex="-1"
                            :aria-invalid="!!groupError('department_ids')"
                            :aria-describedby="
                                groupError('department_ids')
                                    ? 'registration-departments-error'
                                    : undefined
                            "
                            class="min-w-0 space-y-3"
                        >
                            <legend class="text-sm font-medium">
                                Департаменты *
                            </legend>
                            <p
                                v-if="!approvalForm.project_ids.length"
                                class="text-sm text-muted-foreground"
                            >
                                Сначала выберите проект.
                            </p>
                            <p
                                v-else-if="!availableDepartments.length"
                                class="text-sm text-muted-foreground"
                            >
                                В выбранных проектах нет доступных
                                департаментов. Добавьте департамент в
                                справочнике.
                            </p>
                            <div
                                v-else
                                class="grid grid-cols-1 gap-2 rounded-xl border p-3 sm:grid-cols-2"
                            >
                                <div
                                    v-for="dept in availableDepartments"
                                    :key="dept.id"
                                    class="flex min-w-0 items-center gap-2"
                                >
                                    <Checkbox
                                        :id="'dept-' + dept.id"
                                        :model-value="
                                            approvalForm.department_ids.includes(
                                                dept.id,
                                            )
                                        "
                                        :disabled="!!processing"
                                        @update:model-value="
                                            toggleDepartment(dept.id)
                                        "
                                    />
                                    <label
                                        :for="'dept-' + dept.id"
                                        class="min-h-11 min-w-0 flex-1 cursor-pointer py-2 text-sm break-words select-none"
                                    >
                                        <span
                                            class="font-normal text-muted-foreground"
                                            >[{{
                                                departments.find(
                                                    (d) => d.id === dept.id,
                                                )?.project?.name || 'Общий'
                                            }}]</span
                                        ><br />
                                        {{ dept.name }}
                                    </label>
                                </div>
                            </div>
                            <p
                                v-if="groupError('department_ids')"
                                id="registration-departments-error"
                                role="alert"
                                class="text-sm text-destructive"
                            >
                                {{ groupError('department_ids') }}
                            </p>
                        </fieldset>
                        <p
                            v-if="activationHint"
                            id="registration-activation-hint"
                            role="status"
                            class="text-sm text-muted-foreground"
                        >
                            {{ activationHint }}
                        </p>
                    </fieldset>

                    <!-- Signature sample -->
                    <div v-if="previewUser.signature_url" class="space-y-2">
                        <Label
                            class="flex items-center gap-1.5 text-xs font-bold uppercase"
                        >
                            <PenTool class="h-3 w-3" /> Образец подписи
                        </Label>
                        <div
                            class="flex items-center justify-center overflow-hidden rounded-2xl border border-dashed bg-white p-6 shadow-sm"
                        >
                            <img
                                :src="previewUser.signature_url"
                                :alt="`Образец подписи ${previewUser.full_name || 'пользователя'}`"
                                class="max-h-32 max-w-full object-contain"
                            />
                        </div>
                    </div>
                </div>

                <template
                    v-if="
                        previewUser &&
                        ['pending', 'rejected'].includes(
                            previewUser.account_status,
                        )
                    "
                    #actions
                >
                    <ActionButton
                        v-if="previewUser.account_status === 'pending'"
                        variant="ghost"
                        class="text-destructive hover:bg-destructive/10"
                        :busy="processing === 'reject'"
                        busy-label="Отклоняем…"
                        :disabled="!!processing"
                        @click="reject(previewUser.id)"
                    >
                        Отклонить
                    </ActionButton>
                    <ActionButton
                        :busy="processing === 'approve'"
                        busy-label="Активируем…"
                        :disabled="!!processing || !!activationHint"
                        :aria-describedby="
                            activationHint
                                ? 'registration-activation-hint'
                                : undefined
                        "
                        @click="approve(previewUser.id)"
                    >
                        Активировать аккаунт
                    </ActionButton>
                </template>
            </FormDialog>
        </Dialog>
    </AppLayout>
</template>
