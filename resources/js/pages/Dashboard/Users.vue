<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import {
    Search,
    Pencil,
    Power,
    Crown,
    KeyRound,
    Package,
    ShieldCheck,
    User,
    FileText,
    X,
    PenTool,
    Building2,
    FolderOpen,
    Plus,
    MoreHorizontal,
    SlidersHorizontal,
    ChevronDown,
} from 'lucide-vue-next';
import { ref, reactive, watch, computed, onBeforeUnmount } from 'vue';
import InputError from '@/components/InputError.vue';
import ActionButton from '@/components/patterns/ActionButton.vue';
import AppPage from '@/components/patterns/AppPage.vue';
import ContentState from '@/components/patterns/ContentState.vue';
import DirectoryCard from '@/components/patterns/DirectoryCard.vue';
import DirectoryGrid from '@/components/patterns/DirectoryGrid.vue';
import DirectoryRelations from '@/components/patterns/DirectoryRelations.vue';
import FormDialog from '@/components/patterns/FormDialog.vue';
import FormField from '@/components/patterns/FormField.vue';
import PageHeader from '@/components/patterns/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogHeader,
    DialogTitle,
    DialogDescription,
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
import { useListFeedback } from '@/composables/useListFeedback';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount } from '@/lib/formatCount';
import type { BreadcrumbItem } from '@/types';

interface UserItem {
    id: number;
    is_active: boolean;
    can_manage_activation: boolean;
    telegram_id: string;
    full_name: string;
    username: string | null;
    phone: string | null;
    role: string;
    title: string | null;
    signature_url: string | null;
    created_at: string | null;
    requests_count: number;
    approvals_count: number;
    project_ids: number[];
    department_ids: number[];
}

interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

interface ProjectOption {
    id: number;
    name: string;
}

interface DepartmentOption {
    id: number;
    name: string;
    project_id: number | null;
    project?: { id: number; name: string } | null;
}

const props = defineProps<{
    users: PaginatedData<UserItem>;
    filters: Record<string, string>;
    roles: { id: number; name: string }[];
    projects: ProjectOption[];
    departments: DepartmentOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Обзор', href: '/dashboard' },
    { title: 'Пользователи', href: '/dashboard/users' },
];

const search = ref(props.filters.search ?? '');
const roleFilter = ref(props.filters.role ?? 'all');
const activeFilter = ref(props.filters.active ?? 'all');
const filtersOpen = ref(false);
const advancedFilterCount = computed(
    () =>
        Number(roleFilter.value !== 'all') +
        Number(activeFilter.value !== 'all'),
);
const { loading: listLoading, error: listError } =
    useListFeedback('/dashboard/users');
const editingUser = ref<UserItem | null>(null);
const editDialogOpen = ref(false);
const editForm = reactive({
    phone: '',
    role: '',
    title: '',
    project_ids: [] as number[],
    department_ids: [] as number[],
});
const editErrors = ref<Record<string, string>>({});
const editProcessing = ref(false);
const activationUser = ref<UserItem | null>(null);
const activationDialogOpen = ref(false);
const activationProcessing = ref(false);
const activationError = ref('');
const signatureFile = ref<File | null>(null);
const signaturePreview = ref<string | null>(null);
const uploadingSignature = ref(false);
const removingSignature = ref(false);
const signatureProcessing = computed(
    () => uploadingSignature.value || removingSignature.value,
);
let searchTimeout: ReturnType<typeof setTimeout>;
onBeforeUnmount(() => clearTimeout(searchTimeout));
const hasAppliedFilters = computed(() =>
    Boolean(
        props.filters.search ||
        (props.filters.role && props.filters.role !== 'all') ||
        (props.filters.active != null && props.filters.active !== 'all'),
    ),
);

// Create user
const page = usePage();
const currentUserRole = computed(
    () => (page.props.auth as any)?.user?.role ?? '',
);
const isSuperAdmin = computed(() => currentUserRole.value === 'superadmin');

const createDialogOpen = ref(false);
const createForm = reactive({
    full_name: '',
    phone: '',
    role: '',
    title: '',
    project_ids: [] as number[],
    department_ids: [] as number[],
});
const createErrors = ref<Record<string, string>>({});
const createProcessing = ref(false);

function groupError(
    errors: Record<string, string>,
    key: string,
): string | undefined {
    return (
        Object.entries(errors)
            .filter(([name]) => name === key || name.startsWith(key + '.'))
            .map(([, message]) => message)
            .join(' ') || undefined
    );
}

const availableRolesForCreate = computed(() => {
    return props.roles.filter((r: { id: number; name: string }) => {
        if (r.name === 'superadmin') return false; // nobody creates superadmin via UI
        if (r.name === 'admin') return isSuperAdmin.value;
        return true;
    });
});

const createDepartments = computed(() => {
    if (createForm.project_ids.length === 0) return props.departments;
    return props.departments.filter(
        (d) =>
            d.project_id === null ||
            createForm.project_ids.includes(d.project_id),
    );
});

function openCreate() {
    if (createProcessing.value) return;
    createForm.full_name = '';
    createForm.phone = '';
    createForm.role = '';
    createForm.title = '';
    createForm.project_ids = [];
    createForm.department_ids = [];
    createErrors.value = {};
    createDialogOpen.value = true;
}

function toggleCreateProject(id: number) {
    const idx = createForm.project_ids.indexOf(id);
    if (idx > -1) {
        createForm.project_ids.splice(idx, 1);
        createForm.department_ids = createForm.department_ids.filter(
            (dId: number) => {
                const dept = props.departments.find((d) => d.id === dId);
                return dept && dept.project_id !== id;
            },
        );
    } else {
        createForm.project_ids.push(id);
    }
}

function toggleCreateDepartment(id: number) {
    const idx = createForm.department_ids.indexOf(id);
    if (idx > -1) {
        createForm.department_ids.splice(idx, 1);
    } else {
        createForm.department_ids.push(id);
    }
}

function submitCreate() {
    if (createProcessing.value) return;
    createErrors.value = {};
    createProcessing.value = true;
    router.post(
        '/dashboard/users',
        {
            full_name: createForm.full_name,
            phone: createForm.phone || null,
            role: createForm.role,
            title: createForm.title || null,
            project_ids: createForm.project_ids,
            department_ids: createForm.department_ids,
        },
        {
            preserveState: true,
            onSuccess: () => {
                createDialogOpen.value = false;
            },
            onError: (errors: Record<string, string>) => {
                createProcessing.value = false;
                createErrors.value = errors;
            },
            onFinish: () => {
                createProcessing.value = false;
            },
        },
    );
}

const roleLabels: Record<
    string,
    { label: string; color: string; bg: string; icon: any }
> = {
    superadmin: {
        label: 'СуперАдмин',
        icon: Crown,
        color: 'text-purple-700 dark:text-purple-400',
        bg: 'bg-purple-50 dark:bg-purple-950',
    },
    admin: {
        label: 'Админ',
        icon: KeyRound,
        color: 'text-red-700 dark:text-red-400',
        bg: 'bg-red-50 dark:bg-red-950',
    },
    supplier: {
        label: 'Дир. снабжения',
        icon: Package,
        color: 'text-violet-700 dark:text-violet-400',
        bg: 'bg-violet-50 dark:bg-violet-950',
    },
    approver: {
        label: 'Согласующий',
        icon: ShieldCheck,
        color: 'text-emerald-700 dark:text-emerald-400',
        bg: 'bg-emerald-50 dark:bg-emerald-950',
    },
    requester: {
        label: 'Заявитель',
        icon: User,
        color: 'text-muted-foreground',
        bg: 'bg-muted',
    },
    accountant: {
        label: 'Бухгалтер',
        icon: FileText,
        color: 'text-blue-700 dark:text-blue-400',
        bg: 'bg-blue-50 dark:bg-blue-950',
    },
    treasurer: {
        label: 'Казначей',
        icon: KeyRound,
        color: 'text-amber-700 dark:text-amber-400',
        bg: 'bg-amber-50 dark:bg-amber-950',
    },
};

const availableDepartments = computed(() => {
    if (editForm.project_ids.length === 0) return props.departments;
    return props.departments.filter(
        (d) =>
            d.project_id === null ||
            editForm.project_ids.includes(d.project_id),
    );
});

const activeFiltersSummary = computed(() =>
    [
        roleFilter.value !== 'all'
            ? `Роль: ${roleLabels[roleFilter.value]?.label ?? roleFilter.value}`
            : '',
        activeFilter.value !== 'all'
            ? activeFilter.value === '1'
                ? 'Аккаунт: активные'
                : 'Аккаунт: деактивированные'
            : '',
    ]
        .filter(Boolean)
        .join(' · '),
);

function applyFilters() {
    clearTimeout(searchTimeout);
    const params: Record<string, string> = {};
    if (search.value) params.search = search.value;
    if (roleFilter.value && roleFilter.value !== 'all')
        params.role = roleFilter.value;
    if (activeFilter.value !== 'all') params.active = activeFilter.value;
    router.get('/dashboard/users', params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function onSearchInput() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(applyFilters, 400);
}

watch([roleFilter, activeFilter], applyFilters);

function resetFilters() {
    clearTimeout(searchTimeout);
    const willTriggerWatch =
        roleFilter.value !== 'all' || activeFilter.value !== 'all';
    search.value = '';
    roleFilter.value = 'all';
    activeFilter.value = 'all';
    if (!willTriggerWatch) applyFilters();
}

function openEdit(user: UserItem) {
    if (editProcessing.value || signatureProcessing.value) return;
    editingUser.value = user;
    editForm.phone = user.phone ?? '';
    editErrors.value = {};
    editForm.role = user.role;
    editForm.title = user.title ?? '';
    editForm.project_ids = [...(user.project_ids || [])];
    editForm.department_ids = [...(user.department_ids || [])];
    signatureFile.value = null;
    signaturePreview.value = null;
    editDialogOpen.value = true;
}

function closeEdit() {
    if (signatureProcessing.value) return;
    editDialogOpen.value = false;
    editingUser.value = null;
}

function saveUser() {
    if (!editingUser.value || editProcessing.value || signatureProcessing.value)
        return;
    editErrors.value = {};
    editProcessing.value = true;
    router.put(
        `/dashboard/users/${editingUser.value.id}`,
        {
            ...(editForm.phone !== (editingUser.value.phone ?? '')
                ? { phone: editForm.phone }
                : {}),
            role: editForm.role,
            title: editForm.title || null,
            project_ids: editForm.project_ids,
            department_ids: editForm.department_ids,
        },
        {
            preserveState: true,
            onSuccess: () => closeEdit(),
            onError: (errors) => {
                editProcessing.value = false;
                editErrors.value = errors;
            },
            onFinish: () => {
                editProcessing.value = false;
            },
        },
    );
}

function confirmActivation(user: UserItem) {
    if (activationProcessing.value) return;
    activationUser.value = user;
    activationError.value = '';
    activationDialogOpen.value = true;
}

function restoreActivationFocus(event: Event) {
    event.preventDefault();
    document.getElementById(`user-menu-${activationUser.value?.id}`)?.focus();
}

function updateActivation() {
    if (!activationUser.value || activationProcessing.value) return;
    activationProcessing.value = true;
    router.patch(
        `/dashboard/users/${activationUser.value.id}/activation`,
        {
            is_active: !activationUser.value.is_active,
        },
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                activationDialogOpen.value = false;
            },
            onError: (errors) => {
                activationProcessing.value = false;
                activationError.value =
                    errors.is_active ??
                    'Не удалось изменить доступ к аккаунту.';
            },
            onFinish: () => {
                activationProcessing.value = false;
            },
        },
    );
}

function onSignatureSelected(event: Event) {
    if (editProcessing.value || signatureProcessing.value) return;
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    signatureFile.value = file;
    signaturePreview.value = URL.createObjectURL(file);
}

function uploadSignature() {
    if (
        !editingUser.value ||
        !signatureFile.value ||
        editProcessing.value ||
        signatureProcessing.value
    )
        return;
    editErrors.value = {};
    uploadingSignature.value = true;
    const formData = new FormData();
    formData.append('signature', signatureFile.value);
    router.post(
        `/dashboard/users/${editingUser.value.id}/signature`,
        formData,
        {
            preserveState: true,
            onSuccess: () => {
                signatureFile.value = null;
                signaturePreview.value = null;
            },
            onError: (errors) => {
                uploadingSignature.value = false;
                editErrors.value = errors;
            },
            onFinish: () => {
                uploadingSignature.value = false;
            },
        },
    );
}

function removeSignature() {
    if (!editingUser.value || editProcessing.value || signatureProcessing.value)
        return;
    removingSignature.value = true;
    editErrors.value = {};
    router.delete(`/dashboard/users/${editingUser.value.id}/signature`, {
        preserveState: true,
        onError: (errors) => {
            removingSignature.value = false;
            editErrors.value = errors;
        },
        onFinish: () => {
            removingSignature.value = false;
        },
    });
}

function toggleProject(id: number) {
    const idx = editForm.project_ids.indexOf(id);
    if (idx > -1) {
        editForm.project_ids.splice(idx, 1);
        // Remove departments that belong to the removed project
        editForm.department_ids = editForm.department_ids.filter((dId) => {
            const dept = props.departments.find((d) => d.id === dId);
            return dept && dept.project_id !== id;
        });
    } else {
        editForm.project_ids.push(id);
    }
}

function toggleDepartment(id: number) {
    const idx = editForm.department_ids.indexOf(id);
    if (idx > -1) {
        editForm.department_ids.splice(idx, 1);
    } else {
        editForm.department_ids.push(id);
    }
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <AppPage>
            <!-- Header -->
            <PageHeader
                title="Пользователи"
                class="max-sm:flex-col max-sm:gap-3"
            >
                <template #actions>
                    <ActionButton density="toolbar" @click="openCreate">
                        <Plus class="mr-1.5 h-4 w-4" /> Создать пользователя
                    </ActionButton>
                </template>
            </PageHeader>

            <!-- Filters -->
            <Card class="gap-0 py-3 shadow-none">
                <CardContent class="space-y-2 px-3">
                    <div
                        class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_auto]"
                    >
                        <div>
                            <label
                                for="users-search"
                                class="mb-1 block text-sm font-medium text-muted-foreground"
                                >Поиск</label
                            >
                            <div class="relative">
                                <Search
                                    class="absolute top-3.5 left-3 size-4 text-muted-foreground sm:top-2.5"
                                />
                                <Input
                                    id="users-search"
                                    v-model="search"
                                    @input="onSearchInput"
                                    type="text"
                                    placeholder="Имя, фамилия, username..."
                                    class="min-h-11 pl-9 sm:min-h-9"
                                />
                            </div>
                        </div>
                        <div
                            class="flex items-end justify-between gap-3 lg:order-3"
                        >
                            <Button
                                variant="outline"
                                class="min-h-11 gap-2 px-2 sm:hidden"
                                :aria-expanded="filtersOpen"
                                aria-controls="users-advanced-filters"
                                @click="filtersOpen = !filtersOpen"
                            >
                                <SlidersHorizontal class="size-4" />Фильтры<span
                                    v-if="advancedFilterCount"
                                    >({{ advancedFilterCount }})</span
                                ><ChevronDown
                                    class="size-4"
                                    :class="filtersOpen && 'rotate-180'"
                                />
                            </Button>
                            <div class="min-h-9 content-center lg:w-32">
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
                                        users.total
                                    }}</span>
                                </p>
                            </div>
                        </div>
                        <div
                            id="users-advanced-filters"
                            :class="filtersOpen ? 'grid' : 'hidden sm:grid'"
                            class="gap-3 sm:grid-cols-2 lg:order-2"
                        >
                            <div>
                                <label
                                    for="users-role"
                                    class="mb-1 block text-sm font-medium text-muted-foreground"
                                    >Роль</label
                                >
                                <Select v-model="roleFilter">
                                    <SelectTrigger
                                        id="users-role"
                                        class="min-h-11 w-full sm:min-h-9"
                                        ><SelectValue placeholder="Все роли"
                                    /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all"
                                            >Все роли</SelectItem
                                        >
                                        <SelectItem
                                            v-for="(role, name) in roleLabels"
                                            :key="name"
                                            :value="name"
                                            >{{ role.label }}</SelectItem
                                        >
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <label
                                    for="users-active"
                                    class="mb-1 block text-sm font-medium text-muted-foreground"
                                    >Аккаунт</label
                                >
                                <Select v-model="activeFilter">
                                    <SelectTrigger
                                        id="users-active"
                                        class="min-h-11 w-full sm:min-h-9"
                                        ><SelectValue
                                    /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all"
                                            >Все аккаунты</SelectItem
                                        >
                                        <SelectItem value="1"
                                            >Активные</SelectItem
                                        >
                                        <SelectItem value="0"
                                            >Деактивированные</SelectItem
                                        >
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </div>
                    <p
                        v-if="activeFiltersSummary && !filtersOpen"
                        class="text-sm text-muted-foreground sm:hidden"
                    >
                        {{ activeFiltersSummary }}
                    </p>
                </CardContent>
            </Card>
            <ContentState
                v-if="listError"
                kind="error"
                layout="notice"
                :message="listError"
            >
                <template #actions
                    ><Button
                        variant="outline"
                        class="min-h-11"
                        @click="applyFilters"
                        >Повторить</Button
                    ></template
                >
            </ContentState>

            <!-- Users Grid -->
            <DirectoryGrid>
                <DirectoryCard
                    v-for="user in users.data"
                    :key="user.id"
                    :title="user.full_name || 'Имя не указано'"
                    :initials="user.full_name?.charAt(0) || '?'"
                >
                    <template v-if="user.username" #subtitle
                        >@{{ user.username }}</template
                    >
                    <template #status>
                        <span
                            :class="[
                                roleLabels[user.role]?.color,
                                roleLabels[user.role]?.bg,
                            ]"
                            class="inline-flex min-h-7 max-w-full items-center gap-1 rounded-md px-2 py-1 text-sm font-medium"
                        >
                            <component
                                :is="roleLabels[user.role]?.icon"
                                class="size-3.5 shrink-0"
                            /><span class="min-w-0 [overflow-wrap:anywhere]">{{
                                roleLabels[user.role]?.label ?? user.role
                            }}</span>
                        </span>
                        <span
                            v-if="!user.is_active"
                            class="inline-flex min-h-7 items-center text-amber-700 dark:text-amber-400"
                            >Аккаунт деактивирован</span
                        >
                    </template>
                    <template #details>
                        <p>
                            Телефон для входа:
                            <span class="text-foreground select-text">{{
                                user.phone || 'Не указан'
                            }}</span>
                        </p>
                        <p>
                            Должность:
                            <span class="text-foreground">{{
                                user.title || '—'
                            }}</span>
                        </p>
                        <p class="flex items-center gap-1">
                            <PenTool class="size-3.5 shrink-0" />Подпись:
                            <span class="text-foreground">{{
                                user.signature_url ? 'Загружена' : '—'
                            }}</span>
                        </p>
                    </template>
                    <template #activity>
                        <div
                            class="flex flex-wrap items-center gap-x-4 gap-y-1"
                        >
                            <span class="inline-flex items-center gap-1"
                                ><FileText class="size-3.5 shrink-0" />{{
                                    formatCount(user.requests_count, [
                                        'заявка',
                                        'заявки',
                                        'заявок',
                                    ])
                                }}</span
                            >
                            <span class="inline-flex items-center gap-1"
                                ><ShieldCheck class="size-3.5 shrink-0" />{{
                                    formatCount(user.approvals_count, [
                                        'решение',
                                        'решения',
                                        'решений',
                                    ])
                                }}</span
                            >
                        </div>
                    </template>
                    <template #relations>
                        <DirectoryRelations
                            title="Проекты"
                            :items="
                                (user.project_ids || []).map((id) => ({
                                    id,
                                    name:
                                        projects.find((p) => p.id === id)
                                            ?.name ?? String(id),
                                }))
                            "
                            empty="Проекты не назначены"
                        />
                    </template>
                    <template #actions>
                        <Button variant="outline" @click="openEdit(user)"
                            ><Pencil
                                class="size-4 shrink-0"
                            />Редактировать</Button
                        >
                    </template>
                    <template v-if="user.can_manage_activation" #secondary>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    :id="`user-menu-${user.id}`"
                                    variant="outline"
                                    :aria-label="
                                        'Ещё действия: ' + user.full_name
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
                                        if (activationDialogOpen)
                                            event.preventDefault();
                                    }
                                "
                            >
                                <DropdownMenuItem
                                    class="min-h-11"
                                    @select="confirmActivation(user)"
                                    ><Power class="size-4" />{{
                                        user.is_active
                                            ? 'Деактивировать аккаунт'
                                            : 'Активировать аккаунт'
                                    }}</DropdownMenuItem
                                >
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </template>
                </DirectoryCard>
            </DirectoryGrid>

            <ContentState
                v-if="!users.data.length"
                :message="
                    hasAppliedFilters
                        ? 'По выбранным условиям пользователи не найдены. Измените поиск или сбросьте фильтры.'
                        : 'Пользователей пока нет. Создайте первый аккаунт.'
                "
                ><template #actions
                    ><Button
                        v-if="hasAppliedFilters"
                        variant="outline"
                        class="min-h-11"
                        @click="resetFilters"
                        >Сбросить фильтры</Button
                    ><Button
                        v-else
                        variant="outline"
                        class="min-h-11"
                        @click="openCreate"
                        >Создать пользователя</Button
                    ></template
                ></ContentState
            >

            <!-- Pagination -->
            <div v-if="users.last_page > 1" class="flex justify-center gap-1">
                <template v-for="link in users.links" :key="link.label">
                    <a
                        v-if="link.url"
                        :href="link.url"
                        :class="
                            link.active
                                ? 'bg-primary text-primary-foreground'
                                : 'border border-border bg-card text-muted-foreground hover:bg-accent'
                        "
                        class="rounded px-3 py-1.5 text-xs font-medium transition-colors"
                        v-html="link.label"
                    />
                </template>
            </div>
        </AppPage>

        <Dialog v-model:open="activationDialogOpen">
            <FormDialog
                @close-auto-focus="restoreActivationFocus"
                :errors="
                    activationError ? { is_active: activationError } : undefined
                "
            >
                <template #header>
                    <DialogHeader>
                        <DialogTitle>{{
                            activationUser?.is_active
                                ? 'Деактивировать аккаунт?'
                                : 'Активировать аккаунт?'
                        }}</DialogTitle>
                        <DialogDescription>
                            <template v-if="activationUser?.is_active">
                                {{ activationUser.full_name }} потеряет доступ к
                                веб-панели и Telegram-боту. Все заявки,
                                согласования и документы сохранятся. Доступ
                                можно вернуть позже.
                            </template>
                            <template v-else>
                                {{ activationUser?.full_name }} снова получит
                                доступ с прежней ролью и назначениями. Для ещё
                                не одобренного аккаунта также потребуется
                                одобрение регистрации.
                            </template>
                        </DialogDescription>
                    </DialogHeader>
                </template>
                <template #actions>
                    <Button
                        variant="outline"
                        :disabled="activationProcessing"
                        @click="activationDialogOpen = false"
                        >Отмена</Button
                    >
                    <ActionButton
                        :busy="activationProcessing"
                        busy-label="Меняем доступ…"
                        @click="updateActivation"
                    >
                        {{
                            activationUser?.is_active
                                ? 'Деактивировать'
                                : 'Активировать'
                        }}
                    </ActionButton>
                </template>
            </FormDialog>
        </Dialog>

        <!-- Create User Dialog -->
        <Dialog v-model:open="createDialogOpen">
            <FormDialog :errors="createErrors" class="sm:max-w-lg">
                <template #header>
                    <DialogHeader>
                        <DialogTitle>Создать пользователя</DialogTitle>
                        <DialogDescription>
                            Пользователь сможет войти через Telegram-бот с
                            указанным номером телефона.
                        </DialogDescription>
                    </DialogHeader>
                </template>
                <div class="space-y-5">
                    <!-- Full name -->
                    <FormField
                        id="create-user-name"
                        label="Полное имя"
                        :error="createErrors.full_name"
                        v-slot="{ attrs }"
                    >
                        <Input
                            v-model="createForm.full_name"
                            v-bind="attrs"
                            autocomplete="name"
                            required
                            placeholder="Иванов Иван Иванович"
                        />
                    </FormField>

                    <!-- Phone -->
                    <FormField
                        id="create-user-phone"
                        label="Номер телефона"
                        help="Логин для входа на сайт. Номер должен быть уникальным; пробелы и скобки не создают новый номер."
                        :error="createErrors.phone"
                        v-slot="{ attrs }"
                    >
                        <Input
                            v-model="createForm.phone"
                            v-bind="attrs"
                            type="tel"
                            autocomplete="tel"
                            maxlength="30"
                            required
                            placeholder="+998 90 000 00 00"
                        />
                    </FormField>

                    <!-- Role -->
                    <FormField
                        id="create-user-role"
                        label="Роль"
                        :error="createErrors.role"
                        v-slot="{ attrs }"
                    >
                        <Select v-model="createForm.role">
                            <SelectTrigger v-bind="attrs" class="w-full">
                                <SelectValue placeholder="Выберите роль" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="role in availableRolesForCreate"
                                    :key="role.id"
                                    :value="role.name"
                                >
                                    {{
                                        roleLabels[role.name]?.label ??
                                        role.name
                                    }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </FormField>

                    <!-- Title -->
                    <FormField
                        id="create-user-title"
                        label="Должность"
                        :error="createErrors.title"
                        v-slot="{ attrs }"
                    >
                        <Input
                            v-model="createForm.title"
                            v-bind="attrs"
                            placeholder="Например: Финансовый директор"
                        />
                    </FormField>

                    <!-- Projects -->
                    <div
                        role="group"
                        aria-labelledby="create-projects-label"
                        :aria-invalid="
                            !!groupError(createErrors, 'project_ids')
                        "
                        :aria-describedby="
                            groupError(createErrors, 'project_ids')
                                ? 'create-projects-error'
                                : undefined
                        "
                        tabindex="-1"
                    >
                        <Label
                            id="create-projects-label"
                            class="mb-2 block text-sm font-medium text-foreground"
                        >
                            <FolderOpen class="mr-1 inline h-4 w-4" /> Доступ к
                            проектам
                        </Label>
                        <div
                            class="grid grid-cols-1 gap-2 rounded-lg border p-3 sm:grid-cols-2"
                        >
                            <div
                                v-for="project in projects"
                                :key="project.id"
                                class="flex items-center space-x-2"
                            >
                                <Checkbox
                                    :id="'create-proj-' + project.id"
                                    :model-value="
                                        createForm.project_ids.includes(
                                            project.id,
                                        )
                                    "
                                    @update:model-value="
                                        toggleCreateProject(project.id)
                                    "
                                />
                                <label
                                    :for="'create-proj-' + project.id"
                                    class="cursor-pointer text-sm select-none"
                                >
                                    {{ project.name }}
                                </label>
                            </div>
                            <p
                                v-if="!projects.length"
                                class="col-span-2 py-2 text-center text-xs text-muted-foreground"
                            >
                                Нет активных проектов
                            </p>
                        </div>
                        <InputError
                            id="create-projects-error"
                            :message="groupError(createErrors, 'project_ids')"
                            class="mt-1"
                            role="alert"
                        />
                    </div>

                    <!-- Departments -->
                    <div
                        role="group"
                        aria-labelledby="create-departments-label"
                        :aria-invalid="
                            !!groupError(createErrors, 'department_ids')
                        "
                        :aria-describedby="
                            groupError(createErrors, 'department_ids')
                                ? 'create-departments-error'
                                : undefined
                        "
                        tabindex="-1"
                    >
                        <Label
                            id="create-departments-label"
                            class="mb-2 block text-sm font-medium text-foreground"
                        >
                            <Building2 class="mr-1 inline h-4 w-4" />
                            Департаменты
                        </Label>
                        <div
                            class="grid grid-cols-1 gap-2 rounded-lg border p-3 sm:grid-cols-2"
                        >
                            <div
                                v-for="dept in createDepartments"
                                :key="dept.id"
                                class="flex items-center space-x-2"
                            >
                                <Checkbox
                                    :id="'create-dept-' + dept.id"
                                    :model-value="
                                        createForm.department_ids.includes(
                                            dept.id,
                                        )
                                    "
                                    @update:model-value="
                                        toggleCreateDepartment(dept.id)
                                    "
                                />
                                <label
                                    :for="'create-dept-' + dept.id"
                                    class="cursor-pointer text-xs leading-tight select-none"
                                >
                                    <span
                                        class="font-normal text-muted-foreground"
                                        >[{{
                                            dept.project?.name || 'Общий'
                                        }}]</span
                                    ><br />
                                    {{ dept.name }}
                                </label>
                            </div>
                            <p
                                v-if="!createDepartments.length"
                                class="col-span-2 py-2 text-center text-xs text-muted-foreground"
                            >
                                Выберите проект для отображения департаментов
                            </p>
                        </div>
                        <InputError
                            id="create-departments-error"
                            :message="
                                groupError(createErrors, 'department_ids')
                            "
                            class="mt-1"
                            role="alert"
                        />
                    </div>

                    <p
                        v-if="createErrors.general"
                        class="text-sm text-destructive"
                    >
                        {{ createErrors.general }}
                    </p>
                </div>
                <template #actions>
                    <Button
                        variant="outline"
                        :disabled="createProcessing"
                        @click="createDialogOpen = false"
                        >Отмена</Button
                    >
                    <ActionButton
                        :busy="createProcessing"
                        busy-label="Создаём…"
                        @click="submitCreate"
                        >Создать</ActionButton
                    >
                </template>
            </FormDialog>
        </Dialog>

        <!-- Edit Modal -->
        <Dialog v-model:open="editDialogOpen">
            <FormDialog :errors="editErrors" class="sm:max-w-lg">
                <template #header>
                    <DialogHeader>
                        <DialogTitle>Редактирование пользователя</DialogTitle>
                        <DialogDescription v-if="editingUser">
                            {{ editingUser.full_name }}
                        </DialogDescription>
                    </DialogHeader>
                </template>
                <div class="space-y-5">
                    <div>
                        <Label for="edit-phone" class="mb-1 block"
                            >Телефон для входа</Label
                        >
                        <Input
                            id="edit-phone"
                            v-model="editForm.phone"
                            type="tel"
                            autocomplete="tel"
                            maxlength="30"
                            placeholder="+998 90 000 00 00"
                            :aria-invalid="!!editErrors.phone"
                            :aria-describedby="
                                editErrors.phone
                                    ? 'edit-phone-error'
                                    : undefined
                            "
                        />
                        <InputError
                            id="edit-phone-error"
                            :message="editErrors.phone"
                            class="mt-1"
                        />
                    </div>
                    <!-- Role -->
                    <div>
                        <label
                            for="edit-user-role"
                            class="mb-1 block text-sm font-medium text-foreground"
                            >Роль</label
                        >
                        <Select v-model="editForm.role">
                            <SelectTrigger
                                id="edit-user-role"
                                :aria-invalid="!!editErrors.role"
                                :aria-describedby="
                                    editErrors.role
                                        ? 'edit-user-role-error'
                                        : undefined
                                "
                                class="w-full"
                            >
                                <SelectValue placeholder="Выберите роль" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="superadmin"
                                    >СуперАдмин</SelectItem
                                >
                                <SelectItem value="admin">Админ</SelectItem>
                                <SelectItem value="supplier"
                                    >Директор снабжения</SelectItem
                                >
                                <SelectItem value="approver"
                                    >Согласующий</SelectItem
                                >
                                <SelectItem value="requester"
                                    >Заявитель</SelectItem
                                >
                                <SelectItem value="accountant"
                                    >Бухгалтер</SelectItem
                                >
                                <SelectItem value="treasurer"
                                    >Казначей</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <InputError
                            id="edit-user-role-error"
                            :message="editErrors.role"
                            class="mt-1"
                            role="alert"
                        />
                    </div>

                    <!-- Title -->
                    <FormField
                        id="edit-user-title"
                        label="Должность"
                        :error="editErrors.title"
                        v-slot="{ attrs }"
                    >
                        <Input
                            v-model="editForm.title"
                            v-bind="attrs"
                            placeholder="Например: Финансовый директор"
                        />
                    </FormField>

                    <!-- Projects -->
                    <div
                        role="group"
                        aria-labelledby="edit-projects-label"
                        :aria-invalid="!!groupError(editErrors, 'project_ids')"
                        :aria-describedby="
                            groupError(editErrors, 'project_ids')
                                ? 'edit-projects-error'
                                : undefined
                        "
                        tabindex="-1"
                    >
                        <Label
                            id="edit-projects-label"
                            class="mb-2 block text-sm font-medium text-foreground"
                        >
                            <FolderOpen class="mr-1 inline h-4 w-4" /> Доступ к
                            проектам
                        </Label>
                        <div
                            class="grid grid-cols-1 gap-2 rounded-lg border p-3 sm:grid-cols-2"
                        >
                            <div
                                v-for="project in projects"
                                :key="project.id"
                                class="flex items-center space-x-2"
                            >
                                <Checkbox
                                    :id="'edit-proj-' + project.id"
                                    :model-value="
                                        editForm.project_ids.includes(
                                            project.id,
                                        )
                                    "
                                    @update:model-value="
                                        toggleProject(project.id)
                                    "
                                />
                                <label
                                    :for="'edit-proj-' + project.id"
                                    class="cursor-pointer text-sm select-none"
                                >
                                    {{ project.name }}
                                </label>
                            </div>
                            <p
                                v-if="!projects.length"
                                class="col-span-2 py-2 text-center text-xs text-muted-foreground"
                            >
                                Нет активных проектов
                            </p>
                        </div>
                        <InputError
                            id="edit-projects-error"
                            :message="groupError(editErrors, 'project_ids')"
                            class="mt-1"
                            role="alert"
                        />
                    </div>

                    <!-- Departments -->
                    <div
                        role="group"
                        aria-labelledby="edit-departments-label"
                        :aria-invalid="
                            !!groupError(editErrors, 'department_ids')
                        "
                        :aria-describedby="
                            groupError(editErrors, 'department_ids')
                                ? 'edit-departments-error'
                                : undefined
                        "
                        tabindex="-1"
                    >
                        <Label
                            id="edit-departments-label"
                            class="mb-2 block text-sm font-medium text-foreground"
                        >
                            <Building2 class="mr-1 inline h-4 w-4" />
                            Департаменты
                        </Label>
                        <div
                            class="grid grid-cols-1 gap-2 rounded-lg border p-3 sm:grid-cols-2"
                        >
                            <div
                                v-for="dept in availableDepartments"
                                :key="dept.id"
                                class="flex items-center space-x-2"
                            >
                                <Checkbox
                                    :id="'edit-dept-' + dept.id"
                                    :model-value="
                                        editForm.department_ids.includes(
                                            dept.id,
                                        )
                                    "
                                    @update:model-value="
                                        toggleDepartment(dept.id)
                                    "
                                />
                                <label
                                    :for="'edit-dept-' + dept.id"
                                    class="cursor-pointer text-xs leading-tight select-none"
                                >
                                    <span
                                        class="font-normal text-muted-foreground"
                                        >[{{
                                            dept.project?.name || 'Общий'
                                        }}]</span
                                    ><br />
                                    {{ dept.name }}
                                </label>
                            </div>
                            <p
                                v-if="!availableDepartments.length"
                                class="col-span-2 py-2 text-center text-xs text-muted-foreground"
                            >
                                Выберите проект для отображения департаментов
                            </p>
                        </div>
                        <InputError
                            id="edit-departments-error"
                            :message="groupError(editErrors, 'department_ids')"
                            class="mt-1"
                            role="alert"
                        />
                    </div>

                    <!-- Signature section -->
                    <div>
                        <label
                            for="user-signature-file"
                            class="mb-1 block text-sm font-medium text-foreground"
                            >Подпись</label
                        >
                        <div
                            v-if="
                                editingUser?.signature_url && !signaturePreview
                            "
                            class="mb-2"
                        >
                            <div
                                class="flex items-center gap-2 rounded-md border border-border p-2"
                            >
                                <img
                                    :src="editingUser.signature_url"
                                    alt="Подпись"
                                    class="h-12 max-w-50 object-contain"
                                />
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label="Удалить подпись пользователя"
                                    :disabled="
                                        editProcessing || signatureProcessing
                                    "
                                    class="ml-auto min-h-11 min-w-11 text-destructive hover:bg-destructive/10"
                                    @click="removeSignature"
                                >
                                    <X class="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                        <div v-if="signaturePreview" class="mb-2">
                            <div
                                class="flex items-center gap-2 rounded-md border border-dashed border-primary p-2"
                            >
                                <img
                                    :src="signaturePreview"
                                    alt="Новая подпись"
                                    class="h-12 max-w-50 object-contain"
                                />
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label="Отменить выбор файла подписи"
                                    :disabled="
                                        editProcessing || signatureProcessing
                                    "
                                    class="ml-auto min-h-11 min-w-11"
                                    @click="
                                        signatureFile = null;
                                        signaturePreview = null;
                                    "
                                >
                                    <X class="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                        <div class="flex min-w-0 flex-wrap items-center gap-2">
                            <input
                                id="user-signature-file"
                                type="file"
                                accept="image/png,image/jpeg"
                                class="block w-full min-w-0 text-sm file:mr-3 file:min-h-11 file:rounded-md file:border file:px-3"
                                :disabled="
                                    editProcessing || signatureProcessing
                                "
                                @change="onSignatureSelected"
                            />
                            <ActionButton
                                v-if="signatureFile"
                                :busy="uploadingSignature"
                                :disabled="editProcessing || removingSignature"
                                busy-label="Загружаем…"
                                @click="uploadSignature"
                            >
                                Загрузить
                            </ActionButton>
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">
                            PNG или JPG, до 2 МБ
                        </p>
                        <p
                            v-if="removingSignature"
                            role="status"
                            class="text-sm text-muted-foreground"
                        >
                            Удаляем подпись…
                        </p>
                    </div>
                </div>
                <template #actions>
                    <Button
                        variant="outline"
                        :disabled="editProcessing || signatureProcessing"
                        @click="closeEdit"
                        >Отмена</Button
                    >
                    <ActionButton
                        :busy="editProcessing"
                        :disabled="signatureProcessing"
                        busy-label="Сохраняем…"
                        @click="saveUser"
                        >Сохранить</ActionButton
                    >
                </template>
            </FormDialog>
        </Dialog>
    </AppLayout>
</template>
