<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useResizeObserver } from '@vueuse/core';
import {
    FileText,
    User,
    Info,
    Paperclip,
    Users,
    Check,
    X,
    ImageIcon,
    FolderOpen,
    FileDown,
    Building2,
    ThumbsUp,
    ThumbsDown,
    UserPlus,
    Trash2,
    MoreHorizontal,
    Copy,
    Pencil,
    ArrowLeft,
} from 'lucide-vue-next';
import { TabsRoot, TabsList, TabsTrigger, TabsContent } from 'reka-ui';
import { ref, computed, nextTick, watch } from 'vue';
import ActionButton from '@/components/patterns/ActionButton.vue';
import AppPage from '@/components/patterns/AppPage.vue';
import FormDialog from '@/components/patterns/FormDialog.vue';
import FormField from '@/components/patterns/FormField.vue';
import SupplierInfo from '@/components/patterns/SupplierInfo.vue';
import RequestOverview from '@/components/RequestOverview.vue';
import RequestPaymentsPanel from '@/components/requests/RequestPaymentsPanel.vue';
import RequestSupplyPanel from '@/components/requests/RequestSupplyPanel.vue';
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
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney as formatNumber } from '@/lib/requestPresentation';
import type { BreadcrumbItem } from '@/types';

import type {
    RequestDetailData,
    RequestPerson as PersonInfo,
} from '@/types/request-detail';

const props = defineProps<{
    request: RequestDetailData;
    section: string;
    back_url: string;
}>();
const copyForm = useForm({});
const reviseForm = useForm({});
const paidTotal = computed(
    () =>
        props.request.tranches
            .filter((t) => t.status === 'paid')
            .reduce((sum, t) => sum + Math.round(Number(t.amount) * 100), 0) /
        100,
);

// Approve/Reject
const revisionForm = useForm({ reason: '', revision: props.request.revision });
const revisionDialog = ref(false);
const rejectDialog = ref(false);
const rejectForm = useForm({ reason: '', revision: props.request.revision });

const approveDialog = ref(false);
const approveForm = useForm({ comment: '', revision: props.request.revision });

function approveRequest() {
    approveDialog.value = true;
}

function submitApprove() {
    approveForm.post(`/dashboard/requests/${props.request.id}/approve`, {
        preserveScroll: true,
        onSuccess: () => {
            approveDialog.value = false;
            approveForm.reset();
        },
    });
}

function submitReject() {
    rejectForm.post(`/dashboard/requests/${props.request.id}/reject`, {
        onSuccess: () => {
            rejectDialog.value = false;
            rejectForm.reset();
        },
    });
}

// Delete (applicant's own non-approved request, or any non-approved for admins)
const deleteDialog = ref(false);
const deleting = ref(false);

function submitDelete() {
    deleting.value = true;
    router.delete(`/dashboard/requests/${props.request.id}`, {
        onFinish: () => {
            deleting.value = false;
            deleteDialog.value = false;
        },
    });
}

// Add approver at last level
const addApproverDialog = ref(false);
const addApproverForm = useForm({
    approver_id: null as number | null,
    revision: props.request.revision,
});
const availableApprovers = ref<
    { id: number; name: string; title: string | null }[]
>([]);
const loadingApprovers = ref(false);
const approversLoadError = ref(false);

const isLastLevel = computed(
    () =>
        props.request.can_approve &&
        props.request.expected_approval_level !== null &&
        props.request.expected_approval_level ===
            props.request.approvals.length,
);

async function openAddApproverDialog() {
    addApproverDialog.value = true;
    if (availableApprovers.value.length) return;
    loadingApprovers.value = true;
    approversLoadError.value = false;
    try {
        const res = await fetch(
            `/supply/approvers?request_id=${props.request.id}`,
        );
        if (!res.ok) throw new Error('Не удалось загрузить согласующих');
        availableApprovers.value = await res.json();
    } catch {
        approversLoadError.value = true;
    } finally {
        loadingApprovers.value = false;
    }
}

function submitAddApprover() {
    addApproverForm.post(
        `/dashboard/requests/${props.request.id}/add-approver`,
        {
            onSuccess: () => {
                addApproverDialog.value = false;
                addApproverForm.reset();
            },
        },
    );
}

// Attach supplier
const attachSupplierDialog = ref(false);
const attachForm = useForm({
    create_from_name: true,
    inn: '',
    description: '',
});

function attachSupplier() {
    attachForm.post(`/dashboard/requests/${props.request.id}/attach-supplier`, {
        onSuccess: () => {
            attachSupplierDialog.value = false;
            attachForm.reset();
        },
    });
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Заявки', href: props.back_url },
    {
        title: `#${props.request.id}`,
        href: `/dashboard/requests/${props.request.id}`,
    },
];

const approvalStatusConfig: Record<
    string,
    { label: string; color: string; bg: string }
> = {
    pending: {
        label: 'Ожидает',
        color: 'text-muted-foreground',
        bg: 'bg-muted',
    },
    approved: {
        label: 'Одобрено',
        color: 'text-emerald-700 dark:text-emerald-400',
        bg: 'bg-emerald-50 dark:bg-emerald-950',
    },
    rejected: {
        label: 'Отклонено',
        color: 'text-red-700 dark:text-red-400',
        bg: 'bg-red-50 dark:bg-red-950',
    },
};

function fileSizeLabel(size: number | null): string {
    if (size == null) return 'Размер не указан';
    if (size < 1024) return `${size} Б`;
    const unit = size < 1024 * 1024 ? 'КБ' : 'МБ';
    const value = size / (unit === 'КБ' ? 1024 : 1024 * 1024);
    return `${new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 1 }).format(value)} ${unit}`;
}

function fullName(user: PersonInfo | null): string {
    if (!user) return 'Неизвестно';
    return user.full_name || 'Неизвестно';
}
const page = usePage();
const activeSection = computed(() => {
    const requested =
        new URL(page.url, 'http://localhost').searchParams.get('section') ??
        props.section;
    return sections.value.some((s) => s.id === requested)
        ? requested
        : 'overview';
});
const sections = computed(() => [
    { id: 'overview', label: 'Обзор', count: null },
    {
        id: 'approval',
        label: 'Согласование',
        count: props.request.approvals.length,
    },
    ...(props.request.type === 'supply' || props.request.supply_applicant
        ? [
              {
                  id: 'supply',
                  label: 'Снабжение',
                  count: props.request.items.length,
              },
          ]
        : []),
    ...(props.request.type !== 'management_document'
        ? [
              {
                  id: 'payments',
                  label: 'Оплаты',
                  count: props.request.tranches.length,
              },
          ]
        : []),
    {
        id: 'documents',
        label: 'Документы',
        count: props.request.attachments.length,
    },
]);
function changeSection(value: string | number) {
    if (String(value) === activeSection.value) return;
    router.get(
        '/dashboard/requests/' + props.request.id,
        { section: String(value), back: props.back_url },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const sectionNavigation = ref<HTMLElement | null>(null);
async function revealActiveSection() {
    await nextTick();
    const navigation = sectionNavigation.value;
    const selected = navigation?.querySelector<HTMLElement>(
        '[role="tab"][data-state="active"]',
    );
    if (!navigation || !selected) return;
    const container = navigation.getBoundingClientRect();
    const tab = selected.getBoundingClientRect();
    if (tab.left < container.left)
        navigation.scrollLeft += tab.left - container.left;
    else if (tab.right > container.right)
        navigation.scrollLeft += tab.right - container.right;
}
watch(activeSection, revealActiveSection);
useResizeObserver(sectionNavigation, revealActiveSection);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <AppPage width="detail">
            <div
                v-if="request.status === 'approval_paused'"
                role="status"
                class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-amber-950"
            >
                <p class="font-semibold">Согласование приостановлено</p>
                <p v-for="person in request.route_unavailable" :key="person.id">
                    Согласующий {{ person.name }} больше недоступен.
                </p>
                <p>
                    Для продолжения ответственный или уполномоченный
                    администратор должен изменить согласующих и отправить заявку
                    на повторное согласование.
                </p>
            </div>
            <RequestOverview
                :id="request.id"
                :name="request.name"
                :type="request.type"
                :status="request.status"
                :stage="
                    request.status === 'approved' && request.can_manage_payments
                        ? 'finance'
                        : activeSection === 'supply'
                          ? 'supply'
                          : 'approval'
                "
                :payment-method="
                    request.transaction_type === 'cash'
                        ? 'Наличные'
                        : request.transaction_type === 'bank'
                          ? 'Перечисление'
                          : null
                "
                :created-at="request.created_at"
                :applicant="request.applicant?.full_name"
                :project="request.project?.name"
                :amount="
                    request.type === 'management_document'
                        ? null
                        : request.total_price
                "
                :currency="request.currency"
                :paid-amount="paidTotal"
                :description="request.description"
            >
                <template v-if="request.can_approve" #status-action>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            class="gap-2 bg-emerald-600 hover:bg-emerald-700"
                            @click="approveRequest"
                        >
                            <ThumbsUp class="h-4 w-4" /> Одобрить
                        </Button>
                        <Button
                            variant="outline"
                            class="gap-2"
                            @click="rejectDialog = true"
                        >
                            <ThumbsDown class="h-4 w-4" /> Отклонить
                        </Button>
                    </div>
                </template>
                <template
                    v-else-if="
                        request.status === 'approved' &&
                        request.can_manage_payments &&
                        activeSection !== 'payments'
                    "
                    #status-action
                >
                    <Button @click="changeSection('payments')"
                        >Открыть оплаты</Button
                    >
                </template>
                <template
                    v-else-if="
                        request.can_manage_supply && activeSection !== 'supply'
                    "
                    #status-action
                >
                    <Button @click="changeSection('supply')"
                        >Подготовить закупку</Button
                    >
                </template>
                <template #actions>
                    <Link
                        v-if="request.can_recover_route"
                        :href="`/requests/${request.id}/approval-route`"
                        class="inline-flex min-h-9 items-center rounded-md bg-primary px-3 text-sm text-primary-foreground"
                        >Изменить согласующих</Link
                    >
                    <Link
                        :href="back_url"
                        class="inline-flex min-h-9 items-center gap-2 rounded-md px-2 text-sm text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
                        ><ArrowLeft class="size-4" />К списку</Link
                    >
                    <Button
                        v-if="request.can_revise"
                        type="button"
                        :disabled="reviseForm.processing"
                        @click="
                            reviseForm.post(
                                '/request-submissions/' +
                                    request.id +
                                    '/revise',
                            )
                        "
                    >
                        <Pencil class="h-4 w-4" /> Исправить заявку
                    </Button>
                    <Button
                        v-if="request.can_copy"
                        type="button"
                        variant="outline"
                        :disabled="copyForm.processing"
                        title="Откроется новый черновик. Исходная заявка сохранится."
                        @click="
                            copyForm.post(
                                '/request-submissions/' + request.id + '/copy',
                            )
                        "
                    >
                        <Copy class="h-4 w-4" /> Создать копию
                    </Button>
                    <Button
                        v-if="request.can_request_changes"
                        variant="outline"
                        @click="revisionDialog = true"
                        >На доработку</Button
                    >
                    <template v-if="request.can_approve">
                        <Button
                            v-if="
                                isLastLevel &&
                                request.type !== 'management_document'
                            "
                            variant="outline"
                            class="gap-2"
                            @click="openAddApproverDialog"
                        >
                            <UserPlus class="h-4 w-4" />
                            Добавить согласующего
                        </Button>
                    </template>
                    <a
                        v-if="request.items && request.items.length > 0"
                        :href="`/dashboard/requests/${request.id}/export-items`"
                    >
                        <Button variant="outline" class="gap-2">
                            <FileDown class="h-4 w-4" />
                            Excel
                        </Button>
                    </a>
                    <a
                        v-if="request.document_preview_url"
                        :href="request.document_preview_url"
                        target="_blank"
                    >
                        <Button variant="outline" class="gap-2">
                            <FileDown class="h-4 w-4" />
                            Версия на подпись
                        </Button>
                    </a>
                    <DropdownMenu v-if="request.can_delete">
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="Дополнительные действия"
                            >
                                <MoreHorizontal class="h-4 w-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem
                                variant="destructive"
                                @select="deleteDialog = true"
                            >
                                <Trash2 class="h-4 w-4" />
                                Удалить заявку
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </template>
            </RequestOverview>
            <div
                v-if="request.source_id"
                class="mb-5 text-sm text-muted-foreground"
            >
                Создана на основе
                <Link
                    :href="'/dashboard/requests/' + request.source_id"
                    class="underline"
                    >заявки №{{ request.source_id }}</Link
                >
            </div>
            <TabsRoot
                :model-value="activeSection"
                @update:model-value="changeSection"
            >
                <div
                    ref="sectionNavigation"
                    class="mb-6 max-w-full overflow-x-auto border-b border-border"
                >
                    <TabsList
                        aria-label="Разделы заявки"
                        class="flex w-max min-w-full gap-1"
                    >
                        <TabsTrigger
                            v-for="section in sections"
                            :key="section.id"
                            :value="section.id"
                            class="inline-flex min-h-12 shrink-0 items-center gap-2 border-b-2 border-transparent px-4 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted/50 hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-ring data-[state=active]:border-foreground data-[state=active]:text-foreground"
                            >{{ section.label
                            }}<span
                                v-if="section.count"
                                class="rounded-md bg-muted px-1.5 py-0.5 text-xs tabular-nums"
                                >{{ section.count }}</span
                            ></TabsTrigger
                        >
                    </TabsList>
                </div>
                <TabsContent
                    value="overview"
                    class="space-y-6 focus-visible:outline-none"
                >
                    <div
                        v-if="
                            request.type !== 'management_document' &&
                            request.currency &&
                            request.total_price !== null
                        "
                        class="grid grid-cols-1 divide-y divide-border rounded-lg border border-border sm:grid-cols-3 sm:divide-x sm:divide-y-0"
                    >
                        <button
                            type="button"
                            class="p-4 text-left hover:bg-muted/40 focus-visible:outline-2 focus-visible:outline-ring"
                            @click="changeSection('approval')"
                        >
                            <span class="text-sm text-muted-foreground"
                                >Согласование</span
                            ><span class="mt-1 block text-lg font-semibold"
                                >{{
                                    request.approvals.filter(
                                        (a) => a.status === 'approved',
                                    ).length
                                }}
                                из
                                {{ request.approvals.length }} решений</span
                            >
                        </button>
                        <button
                            type="button"
                            class="p-4 text-left hover:bg-muted/40 focus-visible:outline-2 focus-visible:outline-ring"
                            @click="changeSection('payments')"
                        >
                            <span class="text-sm text-muted-foreground"
                                >Оплачено</span
                            ><span
                                class="mt-1 block text-lg font-semibold tabular-nums"
                                >{{ formatNumber(paidTotal) }}
                                <span
                                    class="text-sm font-normal text-muted-foreground"
                                    >{{ request.currency }}</span
                                ></span
                            >
                        </button>
                        <button
                            type="button"
                            class="p-4 text-left hover:bg-muted/40 focus-visible:outline-2 focus-visible:outline-ring"
                            @click="changeSection('payments')"
                        >
                            <span class="text-sm text-muted-foreground"
                                >Осталось оплатить</span
                            ><span
                                class="mt-1 block text-lg font-semibold tabular-nums"
                                >{{
                                    formatNumber(
                                        Math.max(
                                            0,
                                            Number(request.total_price) -
                                                paidTotal,
                                        ),
                                    )
                                }}
                                <span
                                    class="text-sm font-normal text-muted-foreground"
                                    >{{ request.currency }}</span
                                ></span
                            >
                        </button>
                    </div>
                    <div
                        class="grid min-w-0 grid-cols-1 items-start gap-6 lg:grid-cols-3"
                    >
                        <div class="min-w-0 lg:col-span-2">
                            <Card>
                                <CardHeader>
                                    <CardTitle
                                        class="flex items-center gap-2 text-sm"
                                    >
                                        <FileText class="h-4 w-4" />
                                        Детали заявки
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <dl class="space-y-3">
                                        <div
                                            v-if="request.document_kind"
                                            class="flex justify-between border-b border-border py-2"
                                        >
                                            <dt
                                                class="text-sm text-muted-foreground"
                                            >
                                                Вид документа
                                            </dt>
                                            <dd
                                                class="text-right text-sm font-medium text-foreground"
                                            >
                                                {{
                                                    request.document_kind ===
                                                    'resolution'
                                                        ? 'Постановление руководства'
                                                        : 'Приказ руководства'
                                                }}
                                            </dd>
                                        </div>
                                        <div
                                            v-if="request.document_number"
                                            class="flex justify-between border-b border-border py-2"
                                        >
                                            <dt
                                                class="text-sm text-muted-foreground"
                                            >
                                                Регистрационный номер
                                            </dt>
                                            <dd
                                                class="text-right text-sm font-medium text-foreground"
                                            >
                                                {{ request.document_number }}
                                            </dd>
                                        </div>
                                        <div
                                            v-if="request.effective_date_label"
                                            class="flex justify-between border-b border-border py-2"
                                        >
                                            <dt
                                                class="text-sm text-muted-foreground"
                                            >
                                                Вступает в силу
                                            </dt>
                                            <dd
                                                class="text-right text-sm font-medium text-foreground"
                                            >
                                                {{
                                                    request.effective_date_label
                                                }}
                                            </dd>
                                        </div>
                                        <template
                                            v-if="
                                                request.items &&
                                                request.items.length
                                            "
                                        >
                                            <div
                                                v-for="(
                                                    item, idx
                                                ) in request.items"
                                                :key="item.id"
                                                class="border-b border-border py-2"
                                            >
                                                <div
                                                    class="flex justify-between"
                                                >
                                                    <dt
                                                        class="text-sm text-muted-foreground"
                                                    >
                                                        {{ idx + 1 }}.
                                                        {{ item.name }}
                                                    </dt>
                                                    <dd
                                                        class="text-sm text-foreground"
                                                    >
                                                        {{
                                                            formatNumber(
                                                                item.price_per_unit,
                                                            )
                                                        }}
                                                        ×
                                                        {{ item.quantity }}
                                                        =
                                                        <span
                                                            class="font-medium"
                                                        >
                                                            {{
                                                                formatNumber(
                                                                    item.total_price,
                                                                )
                                                            }}
                                                            {{
                                                                request.currency
                                                            }}
                                                        </span>
                                                    </dd>
                                                </div>
                                                <p
                                                    v-if="item.description"
                                                    class="mt-1 text-xs text-muted-foreground"
                                                >
                                                    {{ item.description }}
                                                </p>
                                            </div>
                                            <div
                                                class="-mx-6 flex justify-between rounded bg-muted px-6 py-2"
                                            >
                                                <dt
                                                    class="text-sm font-semibold text-foreground"
                                                >
                                                    ИТОГО
                                                </dt>
                                                <dd
                                                    class="text-sm font-bold text-foreground"
                                                >
                                                    {{
                                                        formatNumber(
                                                            request.total_price ??
                                                                0,
                                                        )
                                                    }}
                                                    {{ request.currency }}
                                                </dd>
                                            </div>
                                        </template>
                                        <template
                                            v-else-if="request.total_price"
                                        >
                                            <div
                                                class="-mx-6 flex justify-between rounded bg-muted px-6 py-2"
                                            >
                                                <dt
                                                    class="text-sm font-semibold text-foreground"
                                                >
                                                    ИТОГО
                                                </dt>
                                                <dd
                                                    class="text-sm font-bold text-foreground"
                                                >
                                                    {{
                                                        formatNumber(
                                                            request.total_price,
                                                        )
                                                    }}
                                                    {{ request.currency }}
                                                </dd>
                                            </div>
                                        </template>
                                    </dl>
                                </CardContent>
                            </Card>
                        </div>
                        <div class="min-w-0 space-y-4">
                            <Card v-if="request.supplier_name">
                                <CardHeader>
                                    <CardTitle
                                        class="flex items-center gap-2 text-sm"
                                    >
                                        <Building2 class="h-4 w-4" />
                                        Поставщик
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <SupplierInfo
                                        :name="
                                            request.supplier?.name ??
                                            request.supplier_name
                                        "
                                        :inn="
                                            request.supplier?.inn ||
                                            request.supplier_inn
                                        "
                                        class="font-medium text-foreground"
                                    />
                                    <div
                                        v-if="
                                            !request.supplier_id &&
                                            request.can_attach_supplier
                                        "
                                        class="mt-2"
                                    >
                                        <span class="text-xs text-amber-600"
                                            >Не верифицирован (новый)</span
                                        >
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            class="mt-2 w-full text-xs"
                                            @click="attachSupplierDialog = true"
                                        >
                                            Создать поставщика
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                            <details class="rounded-xl border p-4">
                                <summary
                                    class="cursor-pointer text-sm font-medium"
                                >
                                    Дополнительные сведения
                                </summary>
                                <div class="mt-4 space-y-4">
                                    <Card>
                                        <CardHeader>
                                            <CardTitle
                                                class="flex items-center gap-2 text-sm"
                                            >
                                                <User class="h-4 w-4" />
                                                Заявитель
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <div
                                                class="flex items-center gap-3"
                                            >
                                                <div
                                                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-muted"
                                                >
                                                    <span
                                                        class="text-sm font-medium text-muted-foreground"
                                                        >{{
                                                            request.applicant?.full_name?.charAt(
                                                                0,
                                                            ) ?? '?'
                                                        }}</span
                                                    >
                                                </div>
                                                <div>
                                                    <p
                                                        class="text-sm font-medium text-foreground"
                                                    >
                                                        {{
                                                            fullName(
                                                                request.applicant,
                                                            )
                                                        }}
                                                    </p>
                                                    <p
                                                        v-if="
                                                            request.applicant
                                                                ?.title
                                                        "
                                                        class="text-xs text-muted-foreground"
                                                    >
                                                        {{
                                                            request.applicant
                                                                .title
                                                        }}
                                                    </p>
                                                    <p
                                                        v-if="
                                                            request.applicant
                                                                ?.username
                                                        "
                                                        class="text-xs text-muted-foreground"
                                                    >
                                                        @{{
                                                            request.applicant
                                                                .username
                                                        }}
                                                    </p>
                                                </div>
                                            </div>
                                        </CardContent> </Card
                                    ><Card>
                                        <CardHeader>
                                            <CardTitle
                                                class="flex items-center gap-2 text-sm"
                                            >
                                                <Info class="h-4 w-4" />
                                                Информация
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <dl class="space-y-2 text-sm">
                                                <div
                                                    class="flex justify-between"
                                                >
                                                    <dt
                                                        class="text-muted-foreground"
                                                    >
                                                        ID
                                                    </dt>
                                                    <dd
                                                        class="font-medium text-foreground"
                                                    >
                                                        #{{ request.id }}
                                                    </dd>
                                                </div>
                                                <div
                                                    class="flex justify-between"
                                                >
                                                    <dt
                                                        class="text-muted-foreground"
                                                    >
                                                        Тип
                                                    </dt>
                                                    <dd class="text-foreground">
                                                        {{
                                                            request.type ===
                                                            'supply'
                                                                ? 'Снабжение'
                                                                : 'Расход'
                                                        }}
                                                    </dd>
                                                </div>
                                                <div
                                                    class="flex justify-between"
                                                >
                                                    <dt
                                                        class="text-muted-foreground"
                                                    >
                                                        Создана
                                                    </dt>
                                                    <dd class="text-foreground">
                                                        {{ request.created_at }}
                                                    </dd>
                                                </div>
                                                <div
                                                    class="flex justify-between"
                                                >
                                                    <dt
                                                        class="text-muted-foreground"
                                                    >
                                                        Обновлена
                                                    </dt>
                                                    <dd class="text-foreground">
                                                        {{ request.updated_at }}
                                                    </dd>
                                                </div>
                                                <div
                                                    v-if="
                                                        request.status ===
                                                            'pending_approval' &&
                                                        request.expected_approval_level
                                                    "
                                                    class="flex justify-between"
                                                >
                                                    <dt
                                                        class="text-muted-foreground"
                                                    >
                                                        Уровень
                                                    </dt>
                                                    <dd
                                                        class="font-medium text-amber-600 dark:text-amber-400"
                                                    >
                                                        {{
                                                            request.expected_approval_level
                                                        }}
                                                        из
                                                        {{
                                                            request.approvals
                                                                .length
                                                        }}
                                                    </dd>
                                                </div>
                                            </dl>
                                        </CardContent>
                                    </Card>
                                </div>
                            </details>
                        </div>
                    </div>
                </TabsContent>
                <TabsContent value="approval" class="focus-visible:outline-none"
                    ><Card v-if="request.approvals.length">
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2 text-sm">
                                <Users class="h-4 w-4" />
                                Маршрут согласования
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div class="space-y-0">
                                <div
                                    v-for="(approval, idx) in request.approvals"
                                    :key="approval.id"
                                    class="relative flex gap-4"
                                >
                                    <div class="flex flex-col items-center">
                                        <div
                                            :class="[
                                                approval.status === 'approved'
                                                    ? 'bg-emerald-500 ring-emerald-100 dark:ring-emerald-900'
                                                    : approval.status ===
                                                        'rejected'
                                                      ? 'bg-red-500 ring-red-100 dark:ring-red-900'
                                                      : request.expected_approval_level ===
                                                          approval.level
                                                        ? 'animate-pulse bg-amber-500 ring-amber-100 dark:ring-amber-900'
                                                        : 'bg-muted-foreground/30 ring-muted',
                                            ]"
                                            class="z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full ring-4"
                                        >
                                            <Check
                                                v-if="
                                                    approval.status ===
                                                    'approved'
                                                "
                                                class="h-4 w-4 text-white"
                                            />
                                            <X
                                                v-else-if="
                                                    approval.status ===
                                                    'rejected'
                                                "
                                                class="h-4 w-4 text-white"
                                            />
                                            <span
                                                v-else
                                                class="text-xs font-bold text-white"
                                                >{{ approval.level }}</span
                                            >
                                        </div>
                                        <div
                                            v-if="
                                                idx <
                                                request.approvals.length - 1
                                            "
                                            class="min-h-6 w-0.5 flex-1"
                                            :class="
                                                approval.status === 'approved'
                                                    ? 'bg-emerald-300 dark:bg-emerald-700'
                                                    : 'bg-border'
                                            "
                                        ></div>
                                    </div>
                                    <div class="flex-1 pb-6">
                                        <div
                                            class="flex items-center justify-between"
                                        >
                                            <div>
                                                <p
                                                    class="text-sm font-medium text-foreground"
                                                >
                                                    {{
                                                        fullName(
                                                            approval.approver,
                                                        )
                                                    }}
                                                </p>
                                                <p
                                                    v-if="
                                                        approval.approver?.title
                                                    "
                                                    class="text-xs text-muted-foreground"
                                                >
                                                    {{
                                                        approval.approver.title
                                                    }}
                                                </p>
                                            </div>
                                            <span
                                                :class="[
                                                    approvalStatusConfig[
                                                        approval.status
                                                    ]?.color,
                                                    approvalStatusConfig[
                                                        approval.status
                                                    ]?.bg,
                                                ]"
                                                class="rounded-full px-2 py-0.5 text-xs font-medium"
                                            >
                                                {{
                                                    approvalStatusConfig[
                                                        approval.status
                                                    ]?.label ?? approval.status
                                                }}
                                            </span>
                                        </div>
                                        <p
                                            v-if="approval.action_date"
                                            class="mt-1 text-xs text-muted-foreground"
                                        >
                                            {{ approval.action_date }}
                                        </p>
                                        <div
                                            v-if="approval.comment"
                                            class="mt-2 rounded-lg bg-muted p-3 text-sm text-foreground"
                                        >
                                            💬 {{ approval.comment }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <div
                        v-if="!request.approvals.length"
                        class="rounded-lg border border-dashed border-border p-8 text-center"
                    >
                        <h2 class="font-medium">
                            Маршрут согласования не назначен
                        </h2>
                        <p class="mt-2 text-sm text-muted-foreground">
                            Участники появятся после отправки заявки на
                            согласование.
                        </p>
                    </div></TabsContent
                >
                <TabsContent
                    v-if="sections.some((s) => s.id === 'supply')"
                    value="supply"
                    class="focus-visible:outline-none"
                    ><RequestSupplyPanel :request="request"
                /></TabsContent>
                <TabsContent
                    v-if="request.type !== 'management_document'"
                    value="payments"
                    class="focus-visible:outline-none"
                    ><RequestPaymentsPanel :request="request"
                /></TabsContent>
                <TabsContent
                    value="documents"
                    class="space-y-6 focus-visible:outline-none"
                    ><div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <div>
                            <h2 class="text-lg font-semibold">
                                Документы и история
                            </h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Файлы заявки, протокол и сохранённые версии.
                            </p>
                        </div>
                        <Link
                            :href="'/requests/' + request.id + '/history'"
                            class="inline-flex min-h-10 items-center rounded-md border border-border px-3 text-sm font-medium hover:bg-muted"
                            >История изменений</Link
                        >
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <a
                            v-if="request.document_preview_url"
                            :href="request.document_preview_url"
                            target="_blank"
                            class="inline-flex min-h-10 items-center gap-2 rounded-md border border-border px-3 text-sm hover:bg-muted"
                            ><FileDown class="size-4" />Версия на подпись</a
                        ><a
                            v-if="request.pdf_url"
                            :href="request.pdf_url"
                            target="_blank"
                            class="inline-flex min-h-10 items-center gap-2 rounded-md border border-border px-3 text-sm hover:bg-muted"
                            ><FileDown class="size-4" />Скачать протокол PDF</a
                        >
                    </div>
                    <Card v-if="request.attachments.length">
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2 text-sm">
                                <Paperclip class="h-4 w-4" />
                                Вложения ({{ request.attachments.length }})
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div class="space-y-2">
                                <a
                                    v-for="att in request.attachments"
                                    :key="att.id"
                                    :href="`/dashboard/requests/${request.id}/attachments/${att.id}/download`"
                                    class="flex cursor-pointer items-center gap-2 rounded-lg bg-muted p-2 transition-colors hover:bg-muted/80"
                                >
                                    <FileText
                                        v-if="att.file_type === 'pdf'"
                                        class="h-5 w-5 text-muted-foreground"
                                    />
                                    <ImageIcon
                                        v-else-if="att.file_type === 'jpg'"
                                        class="h-5 w-5 text-muted-foreground"
                                    />
                                    <FolderOpen
                                        v-else
                                        class="h-5 w-5 text-muted-foreground"
                                    />
                                    <div class="min-w-0 flex-1">
                                        <p
                                            class="text-xs font-medium break-words text-foreground"
                                        >
                                            {{ att.name }}
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ att.file_type.toUpperCase() }}
                                            ·
                                            {{ fileSizeLabel(att.file_size) }}
                                            · Скачать
                                        </p>
                                    </div>
                                    <FileDown
                                        class="h-4 w-4 shrink-0 text-muted-foreground"
                                    />
                                </a>
                            </div>
                        </CardContent>
                    </Card>
                    <div
                        v-if="!request.attachments.length"
                        class="rounded-lg border border-dashed border-border p-8 text-center"
                    >
                        <Paperclip
                            class="mx-auto size-6 text-muted-foreground"
                        />
                        <h3 class="mt-3 font-medium">Вложений пока нет</h3>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Здесь отображаются файлы, приложенные к заявке.
                        </p>
                    </div></TabsContent
                >
            </TabsRoot>
        </AppPage>
        <!-- Approve Dialog -->
        <Dialog :open="revisionDialog" @update:open="revisionDialog = $event"
            ><FormDialog :errors="revisionForm.errors"
                ><template #header
                    ><DialogHeader
                        ><DialogTitle>Вернуть на доработку</DialogTitle
                        ><DialogDescription
                            >Укажите, что исправить. После отправки автором
                            согласование начнётся заново.</DialogDescription
                        ></DialogHeader
                    ></template
                ><FormField
                    id="revision-reason"
                    label="Что нужно исправить"
                    :error="revisionForm.errors.reason"
                    v-slot="{ attrs }"
                >
                    <textarea
                        v-model="revisionForm.reason"
                        v-bind="attrs"
                        class="rounded border bg-background p-3"
                        maxlength="2000"
                        placeholder="Что нужно исправить"
                    />
                </FormField>

                <template #actions
                    ><Button @click="revisionDialog = false" variant="outline"
                        >Отмена</Button
                    ><ActionButton
                        :disabled="
                            revisionForm.processing ||
                            !revisionForm.reason.trim()
                        "
                        @click="
                            revisionForm.post(
                                '/requests/' + request.id + '/request-changes',
                                { onSuccess: () => (revisionDialog = false) },
                            )
                        "
                        :busy="revisionForm.processing"
                        >Вернуть автору</ActionButton
                    ></template
                ></FormDialog
            ></Dialog
        >
        <Dialog :open="approveDialog" @update:open="approveDialog = $event">
            <FormDialog :errors="approveForm.errors"
                ><template #header
                    ><DialogHeader>
                        <DialogTitle
                            >Одобрить заявку #{{ request.id }}</DialogTitle
                        >
                        <DialogDescription>
                            Можете оставить комментарий к одобрению
                            (необязательно). Он будет виден заявителю и в
                            истории согласований.
                        </DialogDescription></DialogHeader
                    ></template
                >
                <div class="space-y-4">
                    <FormField
                        id="approve-comment"
                        label="Комментарий"
                        :error="approveForm.errors.comment"
                        v-slot="{ attrs }"
                    >
                        <textarea
                            v-model="approveForm.comment"
                            v-bind="attrs"
                            placeholder="Например: согласовано в пределах бюджета"
                            rows="3"
                            class="mt-1 flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                        />
                    </FormField>
                </div>
                <template #actions>
                    <Button variant="outline" @click="approveDialog = false"
                        >Отмена</Button
                    >
                    <ActionButton
                        class="bg-emerald-600 hover:bg-emerald-700"
                        @click="submitApprove"
                        :disabled="approveForm.processing"
                        :busy="approveForm.processing"
                    >
                        <ThumbsUp class="mr-2 h-4 w-4" />
                        Одобрить
                    </ActionButton>
                </template></FormDialog
            >
        </Dialog>

        <!-- Reject Dialog -->
        <Dialog :open="rejectDialog" @update:open="rejectDialog = $event">
            <FormDialog :errors="rejectForm.errors"
                ><template #header
                    ><DialogHeader>
                        <DialogTitle
                            >Отклонить заявку #{{ request.id }}</DialogTitle
                        >
                        <DialogDescription>
                            Укажите причину отклонения. Заявитель получит
                            уведомление.
                        </DialogDescription></DialogHeader
                    ></template
                >
                <div class="space-y-4">
                    <FormField
                        id="reject-reason"
                        label="Причина отклонения"
                        :error="rejectForm.errors.reason"
                        v-slot="{ attrs }"
                        ><Input
                            v-model="rejectForm.reason"
                            v-bind="attrs"
                            placeholder="Укажите причину..."
                            class="mt-1"
                    /></FormField>
                </div>
                <template #actions>
                    <Button variant="outline" @click="rejectDialog = false"
                        >Отмена</Button
                    >
                    <ActionButton
                        variant="destructive"
                        @click="submitReject"
                        :disabled="
                            rejectForm.processing || !rejectForm.reason.trim()
                        "
                        :busy="rejectForm.processing"
                    >
                        <X class="mr-2 h-4 w-4" />
                        Отклонить
                    </ActionButton>
                </template></FormDialog
            >
        </Dialog>

        <!-- Delete Dialog -->
        <Dialog :open="deleteDialog" @update:open="deleteDialog = $event">
            <FormDialog
                ><template #header
                    ><DialogHeader>
                        <DialogTitle
                            >Удалить заявку #{{ request.id }}?</DialogTitle
                        >
                    </DialogHeader></template
                >
                <DialogDescription>
                    Заявка будет скрыта из рабочих списков. Администратор сможет
                    восстановить её вместе с позициями, решениями и файлами.
                    <span v-if="request.status === 'pending_approval'"
                        >Текущий согласующий получит уведомление об отзыве
                        заявки.</span
                    >
                </DialogDescription>
                <template #actions>
                    <Button variant="outline" @click="deleteDialog = false"
                        >Отмена</Button
                    >
                    <ActionButton
                        variant="destructive"
                        @click="submitDelete"
                        :disabled="deleting"
                        :busy="deleting"
                    >
                        <Trash2 class="mr-2 h-4 w-4" />
                        Удалить
                    </ActionButton>
                </template></FormDialog
            >
        </Dialog>

        <!-- Add Approver Dialog -->
        <Dialog
            :open="addApproverDialog"
            @update:open="addApproverDialog = $event"
        >
            <FormDialog :errors="addApproverForm.errors"
                ><template #header
                    ><DialogHeader>
                        <DialogTitle>Добавить согласующего</DialogTitle>
                        <DialogDescription>
                            Выберите согласующего, который будет добавлен
                            следующим уровнем после вашего одобрения.
                        </DialogDescription></DialogHeader
                    ></template
                >
                <div class="space-y-4">
                    <div>
                        <Label for="add-approver">Согласующий</Label>
                        <p
                            v-if="loadingApprovers"
                            class="mt-1 text-sm text-muted-foreground"
                            role="status"
                        >
                            Загрузка согласующих…
                        </p>
                        <div
                            v-else-if="approversLoadError"
                            class="mt-2 space-y-2 text-sm text-destructive"
                            role="alert"
                        >
                            <p>Не удалось загрузить список согласующих.</p>
                            <Button
                                variant="outline"
                                size="sm"
                                @click="openAddApproverDialog"
                                >Повторить</Button
                            >
                        </div>
                        <p
                            v-else-if="!availableApprovers.length"
                            class="mt-2 text-sm text-muted-foreground"
                        >
                            Доступных согласующих нет.
                        </p>
                        <select
                            v-else
                            v-model.number="addApproverForm.approver_id"
                            id="add-approver"
                            :aria-invalid="
                                Boolean(addApproverForm.errors.approver_id)
                            "
                            aria-describedby="add-approver-error"
                            class="mt-1 flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <option :value="null" disabled>
                                — Выберите согласующего —
                            </option>
                            <option
                                v-for="a in availableApprovers"
                                :key="a.id"
                                :value="a.id"
                            >
                                {{ a.name }}{{ a.title ? ` (${a.title})` : '' }}
                            </option>
                        </select>
                    </div>
                    <p
                        v-if="addApproverForm.errors.approver_id"
                        id="add-approver-error"
                        role="alert"
                        class="text-xs text-red-500"
                    >
                        {{ addApproverForm.errors.approver_id }}
                    </p>
                </div>
                <template #actions>
                    <Button variant="outline" @click="addApproverDialog = false"
                        >Отмена</Button
                    >
                    <ActionButton
                        @click="submitAddApprover"
                        :disabled="
                            addApproverForm.processing ||
                            !addApproverForm.approver_id
                        "
                        :busy="addApproverForm.processing"
                    >
                        <UserPlus class="mr-2 h-4 w-4" />
                        Добавить
                    </ActionButton>
                </template></FormDialog
            >
        </Dialog>

        <!-- Attach Supplier Dialog -->
        <Dialog
            :open="attachSupplierDialog"
            @update:open="attachSupplierDialog = $event"
        >
            <FormDialog :errors="attachForm.errors"
                ><template #header
                    ><DialogHeader>
                        <DialogTitle>Создать поставщика из заявки</DialogTitle>
                        <DialogDescription>
                            Поставщик «{{ request.supplier_name }}» будет создан
                            в базе и прикреплён к заявке.
                        </DialogDescription></DialogHeader
                    ></template
                >
                <div class="space-y-4">
                    <FormField
                        id="attach-inn"
                        label="ИНН"
                        :error="attachForm.errors.inn"
                        v-slot="{ attrs }"
                        ><Input
                            v-model="attachForm.inn"
                            v-bind="attrs"
                            placeholder="123456789"
                            class="mt-1"
                    /></FormField>
                    <FormField
                        id="attach-description"
                        label="Описание"
                        :error="attachForm.errors.description"
                        v-slot="{ attrs }"
                        ><Input
                            v-model="attachForm.description"
                            v-bind="attrs"
                            placeholder="Краткое описание..."
                            class="mt-1"
                    /></FormField>
                </div>
                <template #actions>
                    <Button
                        variant="outline"
                        @click="attachSupplierDialog = false"
                        >Отмена</Button
                    >
                    <ActionButton
                        @click="attachSupplier"
                        :disabled="attachForm.processing"
                        :busy="attachForm.processing"
                    >
                        <Building2 class="mr-2 h-4 w-4" />
                        Создать и прикрепить
                    </ActionButton>
                </template></FormDialog
            >
        </Dialog>
    </AppLayout>
</template>
