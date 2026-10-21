<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    Plus,
    Pencil,
    Trash2,
    Building2,
    Users,
    FolderOpen,
    MoreHorizontal,
} from 'lucide-vue-next';
import { ref } from 'vue';
import ActionButton from '@/components/patterns/ActionButton.vue';
import AppPage from '@/components/patterns/AppPage.vue';
import FormDialog from '@/components/patterns/FormDialog.vue';
import FormField from '@/components/patterns/FormField.vue';
import PageHeader from '@/components/patterns/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount } from '@/lib/formatCount';
import type { BreadcrumbItem } from '@/types';

interface Department {
    id: number;
    name: string;
    is_active: boolean;
    project_id: number | null;
    project_name: string | null;
    users_count: number;
}

interface ProjectOption {
    id: number;
    name: string;
}

const props = defineProps<{
    departments: Department[];
    projects: ProjectOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Обзор', href: '/dashboard' },
    { title: 'Департаменты', href: '/dashboard/departments' },
];

// ── Form state ──
const dialogOpen = ref(false);
const isEditing = ref(false);
const form = ref({
    id: 0,
    name: '',
    project_id: '',
    is_active: true,
});
const errors = ref<Record<string, string>>({});
const processing = ref(false);
const rowProcessing = ref<number | null>(null);
const rowError = ref('');
const deleteTarget = ref<Department | null>(null);
const deleteDialogOpen = ref(false);
const deleteErrors = ref<Record<string, string>>({});

function confirmDelete(dept: Department) {
    if (rowProcessing.value !== null) return;
    deleteTarget.value = dept;
    deleteErrors.value = {};
    deleteDialogOpen.value = true;
}

function restoreDeleteFocus(event: Event) {
    event.preventDefault();
    (
        document.getElementById(`department-menu-${deleteTarget.value?.id}`) ??
        document.getElementById('department-add')
    )?.focus();
}

function openCreate() {
    if (processing.value) return;
    isEditing.value = false;
    form.value = {
        id: 0,
        name: '',
        project_id: props.projects.length ? String(props.projects[0].id) : '',
        is_active: true,
    };
    errors.value = {};
    dialogOpen.value = true;
}

function openEdit(dept: Department) {
    if (processing.value) return;
    isEditing.value = true;
    form.value = {
        id: dept.id,
        name: dept.name,
        project_id: dept.project_id ? String(dept.project_id) : '',
        is_active: Boolean(dept.is_active),
    };
    errors.value = {};
    dialogOpen.value = true;
}

function submit() {
    if (processing.value) return;
    processing.value = true;
    errors.value = {};
    const payload = {
        name: form.value.name,
        project_id: Number(form.value.project_id),
        is_active: form.value.is_active,
    };

    if (isEditing.value) {
        router.put(`/dashboard/departments/${form.value.id}`, payload, {
            preserveState: true,
            onSuccess: () => {
                dialogOpen.value = false;
            },
            onError: (e) => {
                processing.value = false;
                errors.value = e;
            },
            onFinish: () => {
                processing.value = false;
            },
        });
    } else {
        router.post('/dashboard/departments', payload, {
            preserveState: true,
            onSuccess: () => {
                dialogOpen.value = false;
            },
            onError: (e) => {
                processing.value = false;
                errors.value = e;
            },
            onFinish: () => {
                processing.value = false;
            },
        });
    }
}

function deleteDept() {
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
    router.delete(`/dashboard/departments/${id}`, {
        preserveState: true,
        preserveScroll: true,
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

function toggleActive(dept: Department) {
    if (rowProcessing.value !== null) return;
    rowProcessing.value = dept.id;
    rowError.value = '';
    router.put(
        `/dashboard/departments/${dept.id}`,
        {
            name: dept.name,
            project_id: dept.project_id,
            is_active: !dept.is_active,
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
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <AppPage>
            <!-- Header -->
            <PageHeader
                title="Департаменты"
                description="Управление списком департаментов"
                class="max-sm:flex-col max-sm:gap-3"
            >
                <template #actions
                    ><ActionButton
                        id="department-add"
                        density="toolbar"
                        @click="openCreate"
                    >
                        <Plus class="mr-2 h-4 w-4" /> Добавить
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

            <!-- List -->
            <Card>
                <CardHeader class="pb-3">
                    <CardTitle
                        class="text-sm font-medium text-muted-foreground"
                    >
                        Всего департаментов: {{ departments.length }}
                    </CardTitle>
                </CardHeader>
                <CardContent class="p-0">
                    <div
                        v-if="departments.length"
                        class="divide-y divide-border"
                    >
                        <div
                            v-for="dept in departments"
                            :key="dept.id"
                            class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 px-4 py-4 transition-colors hover:bg-muted/30 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:px-6"
                        >
                            <!-- Info -->
                            <div class="flex min-w-0 items-center gap-3">
                                <div
                                    class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-border bg-muted sm:flex"
                                >
                                    <Building2
                                        class="h-5 w-5 text-muted-foreground"
                                    />
                                </div>
                                <div class="min-w-0">
                                    <p
                                        class="text-base font-medium break-words text-foreground"
                                    >
                                        {{ dept.name }}
                                    </p>
                                    <div
                                        class="mt-0.5 flex flex-wrap items-center gap-2"
                                    >
                                        <Badge
                                            v-if="dept.project_name"
                                            variant="outline"
                                            class="max-w-full gap-1 text-sm break-words whitespace-normal"
                                        >
                                            <FolderOpen class="h-3 w-3" />
                                            {{ dept.project_name }}
                                        </Badge>
                                        <span
                                            class="inline-flex items-center gap-1 text-sm text-muted-foreground"
                                        >
                                            <Users class="h-3 w-3" />
                                            {{
                                                formatCount(dept.users_count, [
                                                    'сотрудник',
                                                    'сотрудника',
                                                    'сотрудников',
                                                ])
                                            }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Active toggle -->
                            <Label
                                :for="`department-active-${dept.id}`"
                                class="inline-flex min-h-11 w-[6.5rem] cursor-pointer items-center gap-2 rounded-md pr-2 text-sm text-muted-foreground focus-within:ring-2 focus-within:ring-ring"
                            >
                                <Switch
                                    :id="`department-active-${dept.id}`"
                                    :model-value="Boolean(dept.is_active)"
                                    :aria-label="`Активность департамента ${dept.name}`"
                                    :disabled="rowProcessing !== null"
                                    @update:model-value="toggleActive(dept)"
                                />
                                <span>
                                    {{ dept.is_active ? 'Активен' : 'Скрыт' }}
                                </span>
                            </Label>

                            <!-- Actions -->
                            <div
                                class="col-span-2 ml-auto grid w-full grid-cols-[minmax(0,1fr)_5rem] gap-2 sm:col-span-1 sm:w-auto sm:grid-cols-[auto_5rem]"
                            >
                                <Button
                                    variant="outline"
                                    class="min-h-11 px-2"
                                    :aria-label="`Редактировать департамент ${dept.name}`"
                                    :disabled="rowProcessing !== null"
                                    @click="openEdit(dept)"
                                >
                                    <Pencil
                                        class="size-4 shrink-0"
                                    />Редактировать
                                </Button>
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child
                                        ><Button
                                            :id="`department-menu-${dept.id}`"
                                            variant="outline"
                                            class="min-h-11 px-2"
                                            :aria-label="`Ещё действия: ${dept.name}`"
                                            :disabled="rowProcessing !== null"
                                            ><MoreHorizontal
                                                class="size-4"
                                            />Ещё</Button
                                        ></DropdownMenuTrigger
                                    >
                                    <DropdownMenuContent
                                        align="end"
                                        @close-auto-focus="
                                            (event) => {
                                                if (deleteDialogOpen)
                                                    event.preventDefault();
                                            }
                                        "
                                        ><DropdownMenuItem
                                            variant="destructive"
                                            class="min-h-11"
                                            @select="confirmDelete(dept)"
                                            ><Trash2 class="size-4" />Удалить
                                            департамент</DropdownMenuItem
                                        ></DropdownMenuContent
                                    >
                                </DropdownMenu>
                            </div>
                        </div>
                    </div>
                    <div v-else class="py-16 text-center text-muted-foreground">
                        <Building2 class="mx-auto mb-3 h-10 w-10 opacity-30" />
                        <p class="text-sm">Департаменты не добавлены</p>
                        <Button
                            variant="outline"
                            class="mt-3"
                            @click="openCreate"
                        >
                            <Plus class="mr-2 h-4 w-4" /> Добавить первый
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </AppPage>

        <Dialog v-model:open="deleteDialogOpen">
            <FormDialog
                :errors="deleteErrors"
                @close-auto-focus="restoreDeleteFocus"
            >
                <template #header
                    ><DialogHeader
                        ><DialogTitle>Удалить департамент?</DialogTitle
                        ><DialogDescription class="[overflow-wrap:anywhere]"
                            >Департамент «{{ deleteTarget?.name }}» будет
                            удалён. Это действие нельзя отменить. Если
                            департамент ещё нужен для истории, скройте его
                            переключателем «Активен».</DialogDescription
                        ></DialogHeader
                    ></template
                >
                <template #actions
                    ><Button variant="outline" @click="deleteDialogOpen = false"
                        >Отмена</Button
                    ><ActionButton
                        variant="destructive"
                        :busy="rowProcessing !== null"
                        busy-label="Удаляем…"
                        @click="deleteDept"
                        >Удалить департамент</ActionButton
                    ></template
                >
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
                                    ? 'Редактировать департамент'
                                    : 'Новый департамент'
                            }}
                        </DialogTitle>
                    </DialogHeader></template
                >

                <div class="space-y-4">
                    <!-- Name -->
                    <FormField
                        id="dept-name"
                        label="Название *"
                        :error="errors.name"
                        v-slot="{ attrs }"
                    >
                        <Input
                            v-bind="attrs"
                            :disabled="processing"
                            v-model="form.name"
                            placeholder="Отдел финансов"
                        />
                    </FormField>

                    <!-- Project -->
                    <FormField
                        id="dept-project"
                        label="Проект *"
                        :error="errors.project_id"
                        v-slot="{ attrs }"
                    >
                        <Select
                            v-model="form.project_id"
                            :disabled="processing"
                        >
                            <SelectTrigger v-bind="attrs" class="w-full">
                                <SelectValue placeholder="Выберите проект" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="p in projects"
                                    :key="p.id"
                                    :value="String(p.id)"
                                >
                                    {{ p.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </FormField>

                    <!-- Active -->
                    <Label
                        for="dept-active"
                        class="inline-flex min-h-11 cursor-pointer items-center gap-3 rounded-md pr-2 focus-within:ring-2 focus-within:ring-ring"
                    >
                        <Switch
                            v-model="form.is_active"
                            id="dept-active"
                            :disabled="processing"
                        />
                        <span>Активен (отображается в боте)</span>
                    </Label>
                </div>

                <template #actions>
                    <Button
                        variant="outline"
                        :disabled="processing"
                        @click="dialogOpen = false"
                        >Отмена</Button
                    >
                    <ActionButton
                        :busy="processing"
                        :busy-label="isEditing ? 'Сохраняем…' : 'Создаём…'"
                        @click="submit"
                    >
                        {{ isEditing ? 'Сохранить' : 'Создать' }}
                    </ActionButton>
                </template>
            </FormDialog>
        </Dialog>
    </AppLayout>
</template>
