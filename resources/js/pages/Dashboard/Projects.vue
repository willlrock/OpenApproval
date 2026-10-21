<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    Plus,
    Pencil,
    Trash2,
    FolderPlus,
    FileText,
    Check,
    MoreHorizontal,
} from 'lucide-vue-next';
import { ref } from 'vue';
import ActionButton from '@/components/patterns/ActionButton.vue';
import AppPage from '@/components/patterns/AppPage.vue';
import DirectoryCard from '@/components/patterns/DirectoryCard.vue';
import DirectoryGrid from '@/components/patterns/DirectoryGrid.vue';
import DirectoryRelations from '@/components/patterns/DirectoryRelations.vue';
import FormDialog from '@/components/patterns/FormDialog.vue';
import FormField from '@/components/patterns/FormField.vue';
import PageHeader from '@/components/patterns/PageHeader.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount } from '@/lib/formatCount';
import type { BreadcrumbItem } from '@/types';

interface DepartmentItem {
    id: number;
    name: string;
    is_active: boolean;
}

interface Project {
    id: number;
    name: string;
    company_name: string | null;
    inn: string | null;
    bank_account: string | null;
    bank_mfo: string | null;
    aliases: string | null;
    is_active: boolean;
    total_count: number;
    approved_count: number;
    created_at: string | null;
    departments: DepartmentItem[];
}

defineProps<{ projects: Project[] }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Обзор', href: '/dashboard' },
    { title: 'Проекты', href: '/dashboard/projects' },
];

const dialogOpen = ref(false);
const isEditing = ref(false);
const form = ref({
    id: 0,
    name: '',
    company_name: '',
    inn: '',
    bank_account: '',
    bank_mfo: '',
    aliases: '',
    is_active: true,
});
const errors = ref<Record<string, string>>({});
const deleteTarget = ref<Project | null>(null);
const deleteDialogOpen = ref(false);
const deleteErrors = ref<Record<string, string>>({});
const processing = ref(false);
const rowProcessing = ref<number | null>(null);
const rowError = ref('');

function openCreate() {
    if (processing.value) return;
    isEditing.value = false;
    form.value = {
        id: 0,
        name: '',
        company_name: '',
        inn: '',
        bank_account: '',
        bank_mfo: '',
        aliases: '',
        is_active: true,
    };
    errors.value = {};
    dialogOpen.value = true;
}

function openEdit(project: Project) {
    if (processing.value) return;
    isEditing.value = true;
    form.value = {
        id: project.id,
        name: project.name,
        company_name: project.company_name ?? '',
        inn: project.inn ?? '',
        bank_account: project.bank_account ?? '',
        bank_mfo: project.bank_mfo ?? '',
        aliases: project.aliases ?? '',
        is_active: Boolean(project.is_active),
    };
    errors.value = {};
    dialogOpen.value = true;
}

function submit() {
    if (processing.value) return;
    errors.value = {};
    if (!form.value.name.trim()) {
        errors.value = { name: 'Укажите название проекта.' };
        return;
    }
    processing.value = true;

    if (isEditing.value) {
        router.put(
            `/dashboard/projects/${form.value.id}`,
            {
                name: form.value.name.trim(),
                company_name: form.value.company_name.trim(),
                inn: form.value.inn.trim(),
                bank_account: form.value.bank_account.trim(),
                bank_mfo: form.value.bank_mfo.trim(),
                aliases: form.value.aliases.trim(),
                is_active: form.value.is_active,
            },
            {
                preserveState: true,
                onSuccess: () => {
                    dialogOpen.value = false;
                },
                onError: (errs: Record<string, string>) => {
                    processing.value = false;
                    errors.value = errs;
                },
                onFinish: () => {
                    processing.value = false;
                },
            },
        );
    } else {
        router.post(
            '/dashboard/projects',
            {
                name: form.value.name.trim(),
                company_name: form.value.company_name.trim(),
                inn: form.value.inn.trim(),
                bank_account: form.value.bank_account.trim(),
                bank_mfo: form.value.bank_mfo.trim(),
                aliases: form.value.aliases.trim(),
                is_active: form.value.is_active,
            },
            {
                preserveState: true,
                onSuccess: () => {
                    dialogOpen.value = false;
                },
                onError: (errs: Record<string, string>) => {
                    processing.value = false;
                    errors.value = errs;
                },
                onFinish: () => {
                    processing.value = false;
                },
            },
        );
    }
}

function toggleActive(project: Project) {
    if (rowProcessing.value !== null) return;
    rowProcessing.value = project.id;
    rowError.value = '';
    router.put(
        `/dashboard/projects/${project.id}`,
        {
            name: project.name,
            company_name: project.company_name ?? '',
            inn: project.inn ?? '',
            bank_account: project.bank_account ?? '',
            bank_mfo: project.bank_mfo ?? '',
            aliases: project.aliases ?? '',
            is_active: !project.is_active,
        },
        {
            preserveState: true,
            onError: (e) => {
                rowProcessing.value = null;
                rowError.value = Object.values(e).join(' ');
            },
            onFinish: () => {
                rowProcessing.value = null;
            },
        },
    );
}

function confirmDelete(project: Project) {
    if (rowProcessing.value !== null) return;
    deleteTarget.value = project;
    deleteErrors.value = {};
    deleteDialogOpen.value = true;
}
function restoreDeleteFocus(event: Event) {
    event.preventDefault();
    (
        document.getElementById(`project-menu-${deleteTarget.value?.id}`) ??
        document.getElementById('project-add')
    )?.focus();
}
function deleteProject() {
    if (
        !deleteDialogOpen.value ||
        !deleteTarget.value ||
        rowProcessing.value !== null
    )
        return;
    const id = deleteTarget.value.id;
    rowProcessing.value = id;
    rowError.value = '';
    deleteErrors.value = {};
    router.delete(`/dashboard/projects/${id}`, {
        preserveState: true,
        onSuccess: () => {
            deleteDialogOpen.value = false;
        },
        onError: (e) => {
            rowProcessing.value = null;
            deleteErrors.value = e;
            if (!deleteDialogOpen.value)
                rowError.value = Object.values(e).join(' ');
        },
        onFinish: () => {
            rowProcessing.value = null;
        },
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <AppPage>
            <!-- Header -->
            <PageHeader
                title="Проекты"
                description="Управление проектами и их статусами"
                class="max-sm:flex-col max-sm:gap-3"
            >
                <template #actions
                    ><ActionButton
                        id="project-add"
                        density="toolbar"
                        @click="openCreate"
                    >
                        <Plus class="mr-2 h-4 w-4" />
                        Добавить проект
                    </ActionButton></template
                >
            </PageHeader>
            <p
                v-if="rowError"
                role="alert"
                class="rounded-lg border border-destructive/30 p-4 text-sm text-destructive"
            >
                {{ rowError }}
            </p>
            <p
                v-if="rowProcessing !== null"
                role="status"
                class="text-sm text-muted-foreground"
            >
                Сохраняем изменения…
            </p>

            <!-- Projects Grid -->
            <DirectoryGrid>
                <DirectoryCard
                    v-for="project in projects"
                    :key="project.id"
                    :title="project.name"
                    :initials="project.name.charAt(0)"
                >
                    <template #status>
                        <Label
                            :for="`project-active-${project.id}`"
                            class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-md pr-2 focus-within:ring-2 focus-within:ring-ring"
                        >
                            <Switch
                                :id="`project-active-${project.id}`"
                                :model-value="Boolean(project.is_active)"
                                :disabled="rowProcessing !== null"
                                :aria-label="
                                    'Активность проекта ' + project.name
                                "
                                @update:model-value="toggleActive(project)"
                            />
                            <span>{{
                                project.is_active ? 'Активен' : 'Скрыт'
                            }}</span>
                        </Label>
                    </template>
                    <template #details>
                        <p>
                            Компания:
                            <span class="text-foreground">{{
                                project.company_name || '—'
                            }}</span>
                        </p>
                        <p>
                            ИНН:
                            <span class="text-foreground">{{
                                project.inn || '—'
                            }}</span>
                        </p>
                        <p>
                            Создан
                            <span class="text-foreground">{{
                                project.created_at || '—'
                            }}</span>
                        </p>
                    </template>
                    <template #activity>
                        <div
                            class="flex flex-wrap items-center gap-x-4 gap-y-1"
                        >
                            <span class="inline-flex items-center gap-1"
                                ><FileText class="size-3.5 shrink-0" />{{
                                    formatCount(project.total_count, [
                                        'заявка',
                                        'заявки',
                                        'заявок',
                                    ])
                                }}</span
                            >
                            <span class="inline-flex items-center gap-1"
                                ><Check class="size-3.5 shrink-0" />{{
                                    project.approved_count
                                }}
                                одобрено</span
                            >
                        </div>
                        <div
                            class="mt-2 h-1.5 rounded-full bg-muted"
                            aria-hidden="true"
                        >
                            <div
                                class="h-1.5 rounded-full bg-emerald-500"
                                :style="{
                                    width:
                                        (project.total_count
                                            ? (project.approved_count /
                                                  project.total_count) *
                                              100
                                            : 0) + '%',
                                }"
                            />
                        </div>
                    </template>
                    <template #relations>
                        <DirectoryRelations
                            title="Департаменты"
                            :items="
                                project.departments.map((dept) => ({
                                    id: dept.id,
                                    name: dept.name,
                                    inactive: !dept.is_active,
                                }))
                            "
                            empty="Нет привязанных департаментов"
                        />
                    </template>
                    <template #actions>
                        <Button
                            variant="outline"
                            :disabled="rowProcessing !== null"
                            @click="openEdit(project)"
                            ><Pencil
                                class="size-4 shrink-0"
                            />Редактировать</Button
                        >
                    </template>
                    <template #secondary>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    :id="`project-menu-${project.id}`"
                                    variant="outline"
                                    :disabled="rowProcessing !== null"
                                    :aria-label="
                                        'Ещё действия: ' + project.name
                                    "
                                    ><MoreHorizontal
                                        class="size-4"
                                    />Ещё</Button
                                >
                            </DropdownMenuTrigger>
                            <DropdownMenuContent
                                align="end"
                                @close-auto-focus="
                                    (event) => {
                                        if (deleteDialogOpen)
                                            event.preventDefault();
                                    }
                                "
                            >
                                <DropdownMenuItem
                                    variant="destructive"
                                    class="min-h-11"
                                    @select="confirmDelete(project)"
                                    ><Trash2 class="size-4" />Удалить
                                    проект</DropdownMenuItem
                                >
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </template>
                </DirectoryCard>
            </DirectoryGrid>

            <div
                v-if="!projects.length"
                class="py-16 text-center text-muted-foreground"
            >
                <FolderPlus class="mx-auto mb-3 h-10 w-10" />
                <p class="text-sm">Нет проектов. Создайте первый!</p>
            </div>
        </AppPage>

        <Dialog v-model:open="deleteDialogOpen">
            <FormDialog
                :errors="deleteErrors"
                @close-auto-focus="restoreDeleteFocus"
            >
                <template #header>
                    <DialogHeader>
                        <DialogTitle>Удалить проект?</DialogTitle>
                        <DialogDescription class="[overflow-wrap:anywhere]">
                            Проект «{{ deleteTarget?.name }}» будет удалён. Это
                            действие нельзя отменить. Проект с историей заявок
                            удалить нельзя — его можно скрыть переключателем
                            «Активен».
                        </DialogDescription>
                    </DialogHeader>
                </template>
                <template #actions>
                    <Button
                        variant="outline"
                        :disabled="rowProcessing !== null"
                        @click="deleteDialogOpen = false"
                        >Отмена</Button
                    >
                    <ActionButton
                        variant="destructive"
                        :busy="rowProcessing !== null"
                        busy-label="Удаляем…"
                        @click="deleteProject"
                        >Удалить проект</ActionButton
                    >
                </template>
            </FormDialog>
        </Dialog>

        <!-- Create / Edit Dialog -->
        <Dialog v-model:open="dialogOpen">
            <FormDialog :errors="errors" class="sm:max-w-md">
                <template #header
                    ><DialogHeader>
                        <DialogTitle>
                            {{
                                isEditing
                                    ? 'Редактировать проект'
                                    : 'Новый проект'
                            }}
                        </DialogTitle>
                    </DialogHeader></template
                >
                <fieldset :disabled="processing" class="space-y-4">
                    <FormField
                        id="project-name"
                        label="Название *"
                        :error="errors.name"
                        v-slot="{ attrs }"
                    >
                        <Input
                            v-bind="attrs"
                            v-model="form.name"
                            @keyup.enter="submit"
                            placeholder="Название проекта"
                            autofocus
                        />
                    </FormField>
                    <FormField
                        id="project-company-name"
                        label="Юр. название компании"
                        :error="errors.company_name"
                        v-slot="{ attrs }"
                    >
                        <Input
                            v-bind="attrs"
                            v-model="form.company_name"
                            placeholder="Например: RESTORAN N MCHJ"
                        />
                    </FormField>
                    <FormField
                        id="project-inn"
                        label="ИНН компании"
                        :error="errors.inn"
                        v-slot="{ attrs }"
                    >
                        <Input
                            v-bind="attrs"
                            v-model="form.inn"
                            placeholder="Например: 310841894"
                        />
                    </FormField>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <FormField
                            id="project-bank-account"
                            label="Расчетный счет"
                            :error="errors.bank_account"
                            v-slot="{ attrs }"
                        >
                            <Input
                                v-bind="attrs"
                                v-model="form.bank_account"
                                placeholder="20208000..."
                            />
                        </FormField>
                        <FormField
                            id="project-bank-mfo"
                            label="МФО"
                            :error="errors.bank_mfo"
                            v-slot="{ attrs }"
                        >
                            <Input
                                v-bind="attrs"
                                v-model="form.bank_mfo"
                                placeholder="01018"
                            />
                        </FormField>
                    </div>
                    <FormField
                        id="project-aliases"
                        label="Алиасы названия"
                        :error="errors.aliases"
                        v-slot="{ attrs }"
                    >
                        <Input
                            v-bind="attrs"
                            v-model="form.aliases"
                            placeholder="Через запятую: RESTORAN N, Ресторан Н"
                        />
                    </FormField>
                    <Label
                        for="project-active"
                        class="inline-flex min-h-11 cursor-pointer items-center gap-3 rounded-md pr-2 focus-within:ring-2 focus-within:ring-ring"
                    >
                        <Switch
                            :model-value="form.is_active"
                            id="project-active"
                            @update:model-value="
                                form.is_active = Boolean($event)
                            "
                        />
                        <span>Активен (доступен в боте)</span>
                    </Label>
                </fieldset>
                <template #actions>
                    <Button
                        variant="outline"
                        :disabled="processing"
                        @click="dialogOpen = false"
                        >Отмена</Button
                    >
                    <ActionButton :busy="processing" @click="submit">
                        {{ isEditing ? 'Сохранить' : 'Создать' }}
                    </ActionButton>
                </template>
            </FormDialog>
        </Dialog>
    </AppLayout>
</template>
