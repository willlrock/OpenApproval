<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import {
    Plus,
    Search,
    Pencil,
    Trash2,
    Building2,
    Upload,
    MoreHorizontal,
} from 'lucide-vue-next';
import { computed, ref, onBeforeUnmount } from 'vue';
import ActionButton from '@/components/patterns/ActionButton.vue';
import AppPage from '@/components/patterns/AppPage.vue';
import ContentState from '@/components/patterns/ContentState.vue';
import FormDialog from '@/components/patterns/FormDialog.vue';
import FormField from '@/components/patterns/FormField.vue';
import PageHeader from '@/components/patterns/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useListFeedback } from '@/composables/useListFeedback';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount } from '@/lib/formatCount';
import type { BreadcrumbItem } from '@/types';

interface Supplier {
    id: number;
    name: string;
    inn: string | null;
    description: string | null;
    is_active: boolean;
    requests_count: number;
    created_at: string;
}

const props = defineProps<{
    suppliers: {
        data: Supplier[];
        links: any[];
        meta: any;
        total: number;
        last_page: number;
    };
    filters: Record<string, string>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Обзор', href: '/dashboard' },
    { title: 'Поставщики', href: '/dashboard/suppliers' },
];

const page = usePage();
const userRole = computed(() => (page.props.auth as any)?.user?.role ?? '');
const canManage = computed(() =>
    ['superadmin', 'admin'].includes(userRole.value),
);

const search = ref(props.filters.search ?? '');
let searchTimeout: ReturnType<typeof setTimeout>;
onBeforeUnmount(() => clearTimeout(searchTimeout));
const { loading: listLoading, error: listError } = useListFeedback(
    '/dashboard/suppliers',
);
const rowError = ref('');

function onSearchInput() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(applyFilters, 400);
}

function resetSearch() {
    search.value = '';
    applyFilters();
}

function applyFilters() {
    clearTimeout(searchTimeout);
    router.get(
        '/dashboard/suppliers',
        { search: search.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

// Create/Edit dialog
const dialog = ref(false);
const editingId = ref<number | null>(null);

const form = useForm({
    name: '',
    inn: '',
    description: '',
    is_active: true,
});

function openCreate() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    form.is_active = true;
    dialog.value = true;
}

function openEdit(s: Supplier) {
    form.clearErrors();
    editingId.value = s.id;
    form.name = s.name;
    form.inn = s.inn ?? '';
    form.description = s.description ?? '';
    form.is_active = s.is_active;
    dialog.value = true;
}

function save() {
    if (form.processing) return;
    if (editingId.value) {
        form.put(`/dashboard/suppliers/${editingId.value}`, {
            onSuccess: () => {
                dialog.value = false;
            },
        });
    } else {
        form.post('/dashboard/suppliers', {
            onSuccess: () => {
                dialog.value = false;
            },
        });
    }
}

const deleteTarget = ref<Supplier | null>(null);
const deleteDialogOpen = ref(false);
const deleteErrors = ref<Record<string, string>>({});
const deleteProcessing = ref(false);

function confirmDelete(supplier: Supplier) {
    if (deleteProcessing.value) return;
    deleteTarget.value = supplier;
    deleteErrors.value = {};
    deleteDialogOpen.value = true;
}

function restoreDeleteFocus(event: Event) {
    event.preventDefault();
    (
        document.getElementById(`supplier-menu-${deleteTarget.value?.id}`) ??
        document.getElementById('supplier-add')
    )?.focus();
}

function remove() {
    if (
        !deleteDialogOpen.value ||
        !deleteTarget.value ||
        deleteProcessing.value
    )
        return;
    deleteProcessing.value = true;
    deleteErrors.value = {};
    rowError.value = '';
    router.delete(`/dashboard/suppliers/${deleteTarget.value.id}`, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            deleteDialogOpen.value = false;
        },
        onError: (e) => {
            deleteErrors.value = e;
            if (!deleteDialogOpen.value)
                rowError.value = Object.values(e).join(' ');
        },
        onFinish: () => {
            deleteProcessing.value = false;
        },
    });
}

// Excel import
const importDialog = ref(false);
const importForm = useForm({
    file: null as File | null,
});

function onImportFileChange(e: Event) {
    const input = e.target as HTMLInputElement;
    if (input.files?.length) {
        importForm.file = input.files[0];
    }
}

function submitImport() {
    if (!importForm.file) return;
    importForm.post('/dashboard/suppliers/import', {
        forceFormData: true,
        onSuccess: () => {
            importDialog.value = false;
            importForm.reset();
        },
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <AppPage>
            <PageHeader
                title="Поставщики"
                :description="
                    formatCount(suppliers.total ?? 0, [
                        'поставщик',
                        'поставщика',
                        'поставщиков',
                    ])
                "
                class="max-sm:flex-col max-sm:gap-3"
            >
                <template v-if="canManage" #actions>
                    <ActionButton
                        density="toolbar"
                        variant="outline"
                        @click="importDialog = true"
                    >
                        <Upload class="mr-2 h-4 w-4" />
                        Импорт Excel
                    </ActionButton>
                    <ActionButton
                        id="supplier-add"
                        density="toolbar"
                        @click="openCreate"
                    >
                        <Plus class="mr-2 h-4 w-4" />
                        Добавить
                    </ActionButton>
                </template>
            </PageHeader>

            <!-- Filters -->
            <Card class="gap-0 py-3 shadow-none">
                <CardContent class="px-3">
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="min-w-0 basis-full sm:flex-1 sm:basis-auto">
                            <Label
                                for="suppliers-search"
                                class="mb-1 block text-sm text-muted-foreground"
                                >Поиск</Label
                            >
                            <div class="relative">
                                <Search
                                    class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                                />
                                <Input
                                    id="suppliers-search"
                                    v-model="search"
                                    placeholder="Поиск по названию или ИНН..."
                                    class="min-h-11 pl-9 sm:min-h-9"
                                    @input="onSearchInput"
                                    @keyup.enter="applyFilters"
                                />
                            </div>
                        </div>
                        <div class="min-h-9 content-center sm:w-32">
                            <ContentState
                                v-if="listLoading"
                                kind="loading"
                                layout="inline"
                                message="Обновляем…"
                            />
                            <p
                                v-else
                                role="status"
                                class="text-sm text-muted-foreground"
                            >
                                Всего:
                                <span class="font-medium text-foreground">{{
                                    suppliers.total
                                }}</span>
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>
            <ContentState
                v-if="listError"
                kind="error"
                layout="notice"
                :message="listError"
                ><template #actions
                    ><Button
                        variant="outline"
                        class="min-h-11"
                        @click="applyFilters"
                        >Повторить</Button
                    ></template
                ></ContentState
            >
            <ContentState
                v-if="rowError"
                kind="error"
                layout="notice"
                :message="rowError"
            />

            <!-- Table -->
            <Card>
                <CardContent class="p-0">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b border-border bg-muted/50">
                                <tr>
                                    <th
                                        class="px-4 py-3 text-left font-medium text-muted-foreground"
                                    >
                                        Поставщик
                                    </th>
                                    <th
                                        class="px-4 py-3 text-left font-medium text-muted-foreground"
                                    >
                                        ИНН
                                    </th>
                                    <th
                                        class="px-4 py-3 text-left font-medium text-muted-foreground"
                                    >
                                        Описание
                                    </th>
                                    <th
                                        class="px-4 py-3 text-center font-medium text-muted-foreground"
                                    >
                                        Заявки
                                    </th>
                                    <th
                                        class="px-4 py-3 text-center font-medium text-muted-foreground"
                                    >
                                        Статус
                                    </th>
                                    <th v-if="canManage" class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="s in suppliers.data"
                                    :key="s.id"
                                    class="border-b border-border last:border-0 hover:bg-muted/30"
                                >
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-2">
                                            <Building2
                                                class="h-4 w-4 text-muted-foreground"
                                            />
                                            <p
                                                class="font-medium text-foreground"
                                            >
                                                {{ s.name }}
                                            </p>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-muted-foreground">
                                        {{ s.inn ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-muted-foreground">
                                        {{ s.description ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        {{ s.requests_count }}
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <Badge
                                            :variant="
                                                s.is_active
                                                    ? 'default'
                                                    : 'secondary'
                                            "
                                        >
                                            {{
                                                s.is_active
                                                    ? 'Активен'
                                                    : 'Неактивен'
                                            }}
                                        </Badge>
                                    </td>
                                    <td
                                        v-if="canManage"
                                        class="px-4 py-3 text-right"
                                    >
                                        <div class="flex justify-end gap-2">
                                            <Button
                                                variant="outline"
                                                class="min-h-11 px-2"
                                                :aria-label="`Редактировать поставщика: ${s.name}`"
                                                :disabled="deleteProcessing"
                                                @click="openEdit(s)"
                                            >
                                                <Pencil
                                                    class="size-4"
                                                />Редактировать
                                            </Button>
                                            <DropdownMenu>
                                                <DropdownMenuTrigger as-child
                                                    ><Button
                                                        :id="`supplier-menu-${s.id}`"
                                                        variant="outline"
                                                        class="min-h-11 w-20 px-2"
                                                        :aria-label="`Ещё действия: ${s.name}`"
                                                        :disabled="
                                                            deleteProcessing
                                                        "
                                                        ><MoreHorizontal
                                                            class="size-4"
                                                        />Ещё</Button
                                                    ></DropdownMenuTrigger
                                                >
                                                <DropdownMenuContent
                                                    align="end"
                                                    @close-auto-focus="
                                                        (event) => {
                                                            if (
                                                                deleteDialogOpen
                                                            )
                                                                event.preventDefault();
                                                        }
                                                    "
                                                    ><DropdownMenuItem
                                                        variant="destructive"
                                                        class="min-h-11"
                                                        @select="
                                                            confirmDelete(s)
                                                        "
                                                        ><Trash2
                                                            class="size-4"
                                                        />Удалить
                                                        поставщика</DropdownMenuItem
                                                    ></DropdownMenuContent
                                                >
                                            </DropdownMenu>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!suppliers.data.length">
                                    <td
                                        :colspan="canManage ? 6 : 5"
                                        class="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        <template v-if="filters.search">
                                            <p>
                                                По вашему запросу поставщики не
                                                найдены
                                            </p>
                                            <Button
                                                variant="link"
                                                class="mt-2 min-h-11"
                                                @click="resetSearch"
                                                >Сбросить поиск</Button
                                            >
                                        </template>
                                        <template v-else
                                            ><p>Поставщиков пока нет.</p>
                                            <Button
                                                v-if="canManage"
                                                variant="outline"
                                                class="mt-3 min-h-11"
                                                @click="openCreate"
                                                >Добавить поставщика</Button
                                            ></template
                                        >
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div
                        v-if="suppliers.last_page > 1"
                        class="flex justify-center gap-1 border-t border-border p-4"
                    >
                        <template
                            v-for="link in suppliers.links"
                            :key="link.label"
                        >
                            <a
                                v-if="link.url"
                                :href="link.url"
                                class="rounded border px-3 py-1 text-sm"
                                :class="
                                    link.active
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'border-border hover:bg-muted'
                                "
                                v-html="link.label"
                            />
                            <span
                                v-else
                                class="px-3 py-1 text-sm text-muted-foreground"
                                v-html="link.label"
                            />
                        </template>
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
                        ><DialogTitle>Удалить поставщика?</DialogTitle
                        ><DialogDescription class="[overflow-wrap:anywhere]"
                            >Поставщик «{{ deleteTarget?.name }}» будет удалён.
                            Это действие нельзя отменить. Поставщика с
                            привязанными заявками удалить нельзя — его можно
                            сделать неактивным в редакторе.</DialogDescription
                        ></DialogHeader
                    ></template
                >
                <template #actions
                    ><Button variant="outline" @click="deleteDialogOpen = false"
                        >Отмена</Button
                    ><ActionButton
                        variant="destructive"
                        :busy="deleteProcessing"
                        busy-label="Удаляем…"
                        @click="remove"
                        >Удалить поставщика</ActionButton
                    ></template
                >
            </FormDialog>
        </Dialog>

        <!-- Create/Edit Dialog -->
        <Dialog :open="dialog" @update:open="dialog = $event">
            <FormDialog :errors="form.errors">
                <template #header
                    ><DialogHeader>
                        <DialogTitle>{{
                            editingId
                                ? 'Редактировать поставщика'
                                : 'Новый поставщик'
                        }}</DialogTitle>
                        <DialogDescription
                            >Название обязательно. ИНН и описание можно оставить
                            пустыми.</DialogDescription
                        >
                    </DialogHeader></template
                >
                <form
                    id="supplier-form"
                    class="space-y-4"
                    @submit.prevent="save"
                >
                    <FormField
                        id="supplier-name"
                        label="Название *"
                        :error="form.errors.name"
                        v-slot="{ attrs }"
                    >
                        <Input
                            v-model="form.name"
                            v-bind="attrs"
                            name="name"
                            placeholder="ООО Пример"
                        />
                    </FormField>
                    <FormField
                        id="supplier-inn"
                        label="ИНН"
                        :error="form.errors.inn"
                        v-slot="{ attrs }"
                    >
                        <Input
                            v-model="form.inn"
                            v-bind="attrs"
                            name="inn"
                            placeholder="123456789"
                        />
                    </FormField>
                    <FormField
                        id="supplier-description"
                        label="Описание"
                        :error="form.errors.description"
                        v-slot="{ attrs }"
                    >
                        <Input
                            v-model="form.description"
                            v-bind="attrs"
                            name="description"
                            placeholder="Краткое описание..."
                        />
                    </FormField>
                    <div v-if="editingId" class="flex items-center gap-2">
                        <input
                            type="checkbox"
                            v-model="form.is_active"
                            id="is_active"
                        />
                        <Label for="is_active">Активен</Label>
                    </div>
                </form>
                <template #actions>
                    <Button
                        variant="outline"
                        :disabled="form.processing"
                        @click="dialog = false"
                        >Отмена</Button
                    >
                    <ActionButton
                        type="submit"
                        form="supplier-form"
                        :busy="form.processing"
                        :busy-label="editingId ? 'Сохраняем…' : 'Создаём…'"
                    >
                        {{ editingId ? 'Сохранить' : 'Создать' }}
                    </ActionButton>
                </template>
            </FormDialog>
        </Dialog>

        <!-- Import Excel Dialog -->
        <Dialog :open="importDialog" @update:open="importDialog = $event">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Импорт поставщиков из Excel</DialogTitle>
                </DialogHeader>
                <div class="space-y-4">
                    <p class="text-sm text-muted-foreground">
                        Загрузите файл Excel (.xlsx, .xls, .csv) с поставщиками.
                        Первая строка должна содержать заголовки столбцов.
                    </p>
                    <div
                        class="space-y-1 rounded-lg bg-muted p-3 text-xs text-muted-foreground"
                    >
                        <p><b>Обязательные столбцы:</b></p>
                        <p>
                            - <code>name</code> (или <code>название</code> /
                            <code>наименование</code>) — название поставщика
                        </p>
                        <p>
                            - <code>inn</code> (или <code>инн</code>) — ИНН
                            поставщика
                        </p>
                        <p>
                            - <code>description</code> (или
                            <code>описание</code>) — описание
                        </p>
                    </div>
                    <div>
                        <Label>Файл Excel</Label>
                        <input
                            type="file"
                            accept=".xlsx,.xls,.csv"
                            class="mt-1 block w-full text-sm text-foreground file:mr-4 file:rounded file:border-0 file:bg-primary file:px-4 file:py-2 file:text-sm file:font-semibold file:text-primary-foreground hover:file:bg-primary/90"
                            @change="onImportFileChange"
                        />
                        <p
                            v-if="importForm.errors.file"
                            class="mt-1 text-xs text-red-500"
                        >
                            {{ importForm.errors.file }}
                        </p>
                    </div>
                </div>
                <DialogFooter class="mt-4">
                    <Button variant="outline" @click="importDialog = false"
                        >Отмена</Button
                    >
                    <Button
                        @click="submitImport"
                        :disabled="importForm.processing || !importForm.file"
                    >
                        <Upload class="mr-2 h-4 w-4" />
                        Импортировать
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
