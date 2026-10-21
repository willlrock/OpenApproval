<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Package, Send, Plus, Trash2, Ban } from 'lucide-vue-next';
import { ref, computed, nextTick } from 'vue';
import ActionButton from '@/components/patterns/ActionButton.vue';
import ContentState from '@/components/patterns/ContentState.vue';
import FormActions from '@/components/patterns/FormActions.vue';
import SupplierInfo from '@/components/patterns/SupplierInfo.vue';
import PaymentScheduleFields from '@/components/PaymentScheduleFields.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatMoney as formatNumber } from '@/lib/requestPresentation';

import type { RequestDetailData } from '@/types/request-detail';

interface AvailableApprover {
    id: number;
    name: string;
    title: string | null;
}

interface ConvertItem {
    id: number;
    name: string;
    description: string | null;
    quantity: number;
    price_per_unit: number;
}

const props = defineProps<{ request: RequestDetailData }>();
const fieldId = (field: string) =>
    `supply-${props.request.id}-${field.replaceAll('.', '-')}`;
const fieldError = (field: string) =>
    (convertForm.errors as Record<string, string>)[field];
const fieldAttrs = (field: string) => ({
    id: fieldId(field),
    name: field,
    'aria-invalid': Boolean(fieldError(field)),
    'aria-describedby': fieldError(field)
        ? `${fieldId(field)}-error`
        : undefined,
});

const isPendingSupply = computed(() => props.request.can_manage_supply);

// ── Convert form ──
const showConvertForm = ref(false);
const availableApprovers = ref<AvailableApprover[]>([]);
const loadingApprovers = ref(false);
const approversLoadError = ref(false);

const convertItems = ref<ConvertItem[]>(
    props.request.items.map((item) => ({
        id: item.id,
        name: item.name,
        description: item.description,
        quantity: item.quantity,
        price_per_unit: item.price_per_unit ?? 0,
    })),
);

const convertForm = useForm({
    revision: props.request.revision,
    deadline_date: props.request.deadline_date_iso ?? '',
    currency: 'UZS',
    supplier_name: '',
    supplier_id: null as number | null,
    transaction_type: 'bank',
    deadline_from_supplier: '',
    factual_delivery_date: '',
    tranches: [] as { amount: string; due_date: string; comment: string }[],
    approvers: [] as number[],
    items: [] as { id: number; quantity: number; price_per_unit: number }[],
});
const unshownErrors = computed(() =>
    Object.entries(convertForm.errors).filter(
        ([field]) =>
            field !== 'supplier_name' &&
            field !== 'approvers' &&
            !/^items\.\d+\.(quantity|price_per_unit)$/.test(field),
    ),
);

// ── Supplier search ──
interface SupplierResult {
    id: number;
    name: string;
    inn: string | null;
}

const supplierQuery = ref('');
const supplierResults = ref<SupplierResult[]>([]);
const showSupplierDropdown = ref(false);
const supplierSearchLoading = ref(false);
const supplierSearchError = ref(false);
const showNewSupplierForm = ref(false);
const newSupplierInn = ref('');
const supplierCreateError = ref('');
const supplierCreating = ref(false);
let supplierSearchVersion = 0;
let supplierSearchTimeout: ReturnType<typeof window.setTimeout> | null = null;

function hideSupplierDropdown(event: FocusEvent) {
    if (
        (event.currentTarget as HTMLElement).parentElement?.contains(
            event.relatedTarget as Node | null,
        )
    )
        return;
    showSupplierDropdown.value = false;
}

function onSupplierInput(e: Event) {
    const val = (e.target as HTMLInputElement).value;
    supplierQuery.value = val;
    convertForm.supplier_name = val;
    convertForm.supplier_id = null;
    supplierSearchError.value = false;
    const searchVersion = ++supplierSearchVersion;
    supplierResults.value = [];

    if (supplierSearchTimeout) window.clearTimeout(supplierSearchTimeout);

    if (val.length < 2) {
        supplierResults.value = [];
        supplierSearchLoading.value = false;
        showSupplierDropdown.value = false;
        return;
    }

    supplierSearchLoading.value = true;
    showSupplierDropdown.value = true;
    supplierSearchTimeout = window.setTimeout(async () => {
        if (searchVersion !== supplierSearchVersion) return;
        try {
            const res = await fetch(
                `/supply/suppliers/search?q=${encodeURIComponent(val)}`,
            );
            if (!res.ok) throw new Error('Supplier search failed');
            const results = await res.json();
            if (searchVersion !== supplierSearchVersion) return;
            supplierResults.value = results;
            showSupplierDropdown.value = true;
        } catch (e) {
            if (searchVersion !== supplierSearchVersion) return;
            console.error('Supplier search failed', e);
            supplierResults.value = [];
            supplierSearchError.value = true;
            showSupplierDropdown.value = true;
        } finally {
            if (searchVersion === supplierSearchVersion)
                supplierSearchLoading.value = false;
        }
    }, 300);
}

function selectSupplier(supplier: SupplierResult) {
    supplierSearchVersion++;
    supplierSearchLoading.value = false;
    convertForm.supplier_name = supplier.name;
    convertForm.supplier_id = supplier.id;
    supplierQuery.value = supplier.name;
    showSupplierDropdown.value = false;
    void nextTick(() =>
        document.getElementById(fieldId('selected-supplier'))?.focus(),
    );
}
function changeSupplier() {
    supplierSearchVersion++;
    supplierSearchLoading.value = false;
    convertForm.supplier_id = null;
    convertForm.supplier_name = '';
    supplierQuery.value = '';
    supplierResults.value = [];
    showSupplierDropdown.value = false;
    void nextTick(() =>
        document.getElementById(fieldId('supplier_name'))?.focus(),
    );
}

function openNewSupplierForm() {
    supplierSearchVersion++;
    supplierSearchLoading.value = false;
    showNewSupplierForm.value = true;
    showSupplierDropdown.value = false;
}

async function createSupplier() {
    if (!supplierQuery.value.trim() || supplierCreating.value) return;
    supplierCreateError.value = '';
    supplierCreating.value = true;
    try {
        const res = await fetch('/supply/suppliers', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
                ),
            },
            body: JSON.stringify({
                name: supplierQuery.value.trim(),
                inn: newSupplierInn.value || null,
            }),
        });
        const payload = await res.json().catch(() => null);
        if (!res.ok || !payload)
            throw new Error(
                payload?.message ??
                    'Не удалось создать поставщика. Попробуйте ещё раз.',
            );
        const supplier: SupplierResult = payload;
        selectSupplier(supplier);
        showNewSupplierForm.value = false;
        newSupplierInn.value = '';
    } catch (e) {
        console.error('Create supplier failed', e);
        supplierCreateError.value =
            e instanceof Error
                ? e.message
                : 'Не удалось создать поставщика. Попробуйте ещё раз.';
    } finally {
        supplierCreating.value = false;
    }
}

const grandTotal = computed(() =>
    convertItems.value.reduce(
        (sum: number, item: ConvertItem) =>
            sum + item.price_per_unit * item.quantity,
        0,
    ),
);

async function loadApprovers() {
    if (availableApprovers.value.length) return;
    loadingApprovers.value = true;
    approversLoadError.value = false;
    try {
        const res = await fetch(
            `/supply/approvers?request_id=${props.request.id}`,
        );
        if (!res.ok) throw new Error('Не удалось загрузить согласующих');
        availableApprovers.value = await res.json();
    } catch (e) {
        console.error('Failed to load approvers', e);
        approversLoadError.value = true;
    } finally {
        loadingApprovers.value = false;
    }
}

function openConvertForm() {
    showConvertForm.value = true;
    loadApprovers();
}

function addApprover(id: number) {
    if (!convertForm.approvers.includes(id)) {
        convertForm.approvers.push(id);
    }
}

function removeApprover(id: number) {
    convertForm.approvers = convertForm.approvers.filter(
        (a: number) => a !== id,
    );
}

function moveApprover(index: number, direction: -1 | 1) {
    const newIndex = index + direction;
    if (newIndex < 0 || newIndex >= convertForm.approvers.length) return;
    const temp = convertForm.approvers[index];
    convertForm.approvers[index] = convertForm.approvers[newIndex];
    convertForm.approvers[newIndex] = temp;
}

function getApproverName(id: number): string {
    const a = availableApprovers.value.find(
        (x: AvailableApprover) => x.id === id,
    );
    return a ? `${a.name}${a.title ? ` (${a.title})` : ''}` : `ID: ${id}`;
}

function submitConvert() {
    convertForm.items = convertItems.value.map((item: ConvertItem) => ({
        id: item.id,
        quantity: item.quantity,
        price_per_unit: item.price_per_unit,
    }));
    convertForm.revision = props.request.revision;
    convertForm.post(`/supply/requests/${props.request.id}/convert`, {
        onError: (errors) => {
            void nextTick(() => {
                const field = Object.keys(errors)[0];
                if (!field) return;
                const target =
                    document.getElementById(fieldId(field)) ??
                    document.getElementById(`${fieldId(field)}-error`);
                target?.focus();
            });
        },
    });
}

// ── Cancel form ──
const showCancelForm = ref(false);
const cancelForm = useForm({
    revision: props.request.revision,
    reason: '',
});

function submitCancel() {
    cancelForm.revision = props.request.revision;
    cancelForm.post(`/supply/requests/${props.request.id}/cancel`, {
        onError: (errors) => {
            void nextTick(() =>
                document
                    .getElementById(
                        fieldId(
                            errors.reason ? 'cancel-reason' : 'cancel-errors',
                        ),
                    )
                    ?.focus(),
            );
        },
    });
}
</script>

<template>
    <div class="space-y-6">
        <div
            v-if="isPendingSupply && !showConvertForm && !showCancelForm"
            class="flex flex-wrap items-center justify-between gap-3"
        >
            <div>
                <h2 class="text-lg font-semibold">Подготовка закупки</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Уточните позиции и стоимость перед передачей на
                    согласование.
                </p>
            </div>
            <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                <Button class="min-h-12" @click="openConvertForm"
                    ><Send class="size-4" />Передать на согласование</Button
                ><Button
                    class="min-h-12"
                    variant="outline"
                    @click="showCancelForm = true"
                    >Отменить заявку</Button
                >
            </div>
        </div>
        <Card v-if="!showConvertForm">
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-sm">
                    <Package class="h-4 w-4" />
                    Позиции ({{ request.items.length }})
                </CardTitle>
            </CardHeader>
            <CardContent>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-border">
                                <th
                                    class="px-3 py-2 text-left text-xs font-medium text-muted-foreground"
                                >
                                    №
                                </th>
                                <th
                                    class="px-3 py-2 text-left text-xs font-medium text-muted-foreground"
                                >
                                    Наименование
                                </th>
                                <th
                                    class="px-3 py-2 text-left text-xs font-medium text-muted-foreground"
                                >
                                    Описание
                                </th>
                                <th
                                    class="px-3 py-2 text-right text-xs font-medium text-muted-foreground"
                                >
                                    Кол-во
                                </th>
                                <th
                                    v-if="request.total_price"
                                    class="px-3 py-2 text-right text-xs font-medium text-muted-foreground"
                                >
                                    Цена
                                </th>
                                <th
                                    v-if="request.total_price"
                                    class="px-3 py-2 text-right text-xs font-medium text-muted-foreground"
                                >
                                    Итого
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(item, idx) in request.items"
                                :key="item.id"
                                class="border-b border-border/50"
                            >
                                <td class="px-3 py-2 text-muted-foreground">
                                    {{ idx + 1 }}
                                </td>
                                <td
                                    class="px-3 py-2 font-medium text-foreground"
                                >
                                    {{ item.name }}
                                </td>
                                <td class="px-3 py-2 text-muted-foreground">
                                    {{ item.description ?? '—' }}
                                </td>
                                <td
                                    class="px-3 py-2 text-right text-foreground"
                                >
                                    {{ item.quantity }}
                                </td>
                                <td
                                    v-if="request.total_price"
                                    class="px-3 py-2 text-right text-foreground"
                                >
                                    {{
                                        item.price_per_unit
                                            ? formatNumber(item.price_per_unit)
                                            : '—'
                                    }}
                                </td>
                                <td
                                    v-if="request.total_price"
                                    class="px-3 py-2 text-right font-medium text-foreground"
                                >
                                    {{
                                        item.total_price
                                            ? formatNumber(item.total_price) +
                                              ' ' +
                                              request.currency
                                            : '—'
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div
                        v-if="request.total_price"
                        class="mt-3 flex justify-end rounded bg-muted px-4 py-2"
                    >
                        <span class="text-sm font-bold text-foreground">
                            ИТОГО:
                            {{ formatNumber(request.total_price) }}
                            {{ request.currency }}
                        </span>
                    </div>
                </div>
            </CardContent>
        </Card>
        <Card
            v-if="showConvertForm && isPendingSupply"
            class="border-2 border-violet-300 dark:border-violet-700"
        >
            <CardHeader>
                <CardTitle
                    class="flex items-center gap-2 text-sm text-violet-700 dark:text-violet-400"
                >
                    <Send class="h-4 w-4" />
                    Передача на согласование
                </CardTitle>
            </CardHeader>
            <CardContent>
                <form @submit.prevent="submitConvert" class="space-y-6">
                    <!-- Edit items: quantity + price -->
                    <div>
                        <h3 class="mb-2 text-sm font-semibold">
                            Позиции — количество и цены
                        </h3>
                        <div class="space-y-3">
                            <div
                                v-for="(item, index) in convertItems"
                                :key="item.id"
                                class="grid grid-cols-1 gap-3 rounded-lg bg-muted p-3 sm:grid-cols-4"
                            >
                                <div class="sm:col-span-2">
                                    <p
                                        class="text-sm font-medium text-foreground"
                                    >
                                        {{ item.name }}
                                    </p>
                                    <p
                                        v-if="item.description"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ item.description }}
                                    </p>
                                </div>
                                <div>
                                    <Label
                                        :for="
                                            fieldId(`items.${index}.quantity`)
                                        "
                                        class="text-xs"
                                        >Кол-во</Label
                                    >
                                    <Input
                                        v-bind="
                                            fieldAttrs(
                                                `items.${index}.quantity`,
                                            )
                                        "
                                        :aria-label="`Количество: ${item.name}`"
                                        v-model.number="item.quantity"
                                        type="number"
                                        min="1"
                                        class="mt-1"
                                    />
                                    <p
                                        v-if="
                                            fieldError(
                                                `items.${index}.quantity`,
                                            )
                                        "
                                        :id="`${fieldId(`items.${index}.quantity`)}-error`"
                                        class="mt-1 text-xs text-destructive"
                                    >
                                        {{
                                            fieldError(
                                                `items.${index}.quantity`,
                                            )
                                        }}
                                    </p>
                                </div>
                                <div>
                                    <Label
                                        :for="
                                            fieldId(
                                                `items.${index}.price_per_unit`,
                                            )
                                        "
                                        class="text-xs"
                                        >Цена за ед.</Label
                                    >
                                    <Input
                                        v-bind="
                                            fieldAttrs(
                                                `items.${index}.price_per_unit`,
                                            )
                                        "
                                        :aria-label="`Цена за единицу: ${item.name}`"
                                        v-model.number="item.price_per_unit"
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                        class="mt-1"
                                    />
                                    <p
                                        v-if="
                                            fieldError(
                                                `items.${index}.price_per_unit`,
                                            )
                                        "
                                        :id="`${fieldId(`items.${index}.price_per_unit`)}-error`"
                                        class="mt-1 text-xs text-destructive"
                                    >
                                        {{
                                            fieldError(
                                                `items.${index}.price_per_unit`,
                                            )
                                        }}
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div
                            class="mt-3 flex justify-end rounded bg-violet-50 px-4 py-2 dark:bg-violet-950"
                        >
                            <span
                                class="text-sm font-bold text-violet-700 dark:text-violet-300"
                            >
                                ИТОГО:
                                {{ formatNumber(grandTotal) }}
                            </span>
                        </div>
                    </div>

                    <!-- Currency & Supplier & Payment -->
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <Label :for="fieldId('currency')">Валюта</Label>
                            <Select v-model="convertForm.currency">
                                <SelectTrigger
                                    v-bind="fieldAttrs('currency')"
                                    class="mt-1 w-full"
                                    ><SelectValue
                                /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="UZS">UZS</SelectItem>
                                    <SelectItem value="USD">USD</SelectItem>
                                    <SelectItem value="RUB">RUB</SelectItem>
                                    <SelectItem value="EUR">EUR</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="relative">
                            <Label :for="fieldId('supplier_name')"
                                >Поставщик</Label
                            >
                            <Input
                                v-if="!convertForm.supplier_id"
                                :disabled="supplierCreating"
                                v-bind="fieldAttrs('supplier_name')"
                                :model-value="supplierQuery"
                                @input="onSupplierInput"
                                @focus="
                                    supplierQuery.length >= 2 &&
                                    (showSupplierDropdown = true)
                                "
                                @blur="hideSupplierDropdown"
                                placeholder="Поиск или ввод поставщика..."
                                class="mt-1"
                            />
                            <div
                                v-if="convertForm.supplier_id"
                                :id="fieldId('selected-supplier')"
                                tabindex="-1"
                                role="group"
                                :aria-label="
                                    'Выбран поставщик: ' +
                                    convertForm.supplier_name
                                "
                                class="mt-2 flex min-w-0 flex-col items-start gap-2 rounded-lg bg-muted p-3 sm:flex-row sm:items-center"
                            >
                                <SupplierInfo
                                    :name="convertForm.supplier_name"
                                    class="w-full min-w-0 flex-1 font-medium sm:w-auto"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    @click="changeSupplier"
                                    class="min-h-12 w-full sm:w-auto"
                                >
                                    Изменить поставщика
                                </Button>
                            </div>
                            <!-- Dropdown -->
                            <div
                                v-if="
                                    showSupplierDropdown &&
                                    (supplierResults.length ||
                                        supplierQuery.length >= 2)
                                "
                                class="absolute z-50 mt-1 w-full rounded-md border border-border bg-background shadow-lg"
                            >
                                <ContentState
                                    v-if="supplierSearchLoading"
                                    kind="loading"
                                    layout="inline"
                                    class="px-3 py-2"
                                >
                                    Поиск...
                                </ContentState>
                                <div v-else>
                                    <button
                                        v-for="s in supplierResults"
                                        :key="s.id"
                                        type="button"
                                        class="flex w-full flex-wrap items-center gap-2 px-3 py-2 text-left text-sm hover:bg-muted"
                                        @mousedown.prevent
                                        @click="selectSupplier(s)"
                                    >
                                        <span
                                            class="min-w-0 font-medium break-words"
                                            >{{ s.name }}</span
                                        >
                                        <span
                                            v-if="s.inn"
                                            class="text-xs text-muted-foreground"
                                            >ИНН: {{ s.inn }}</span
                                        >
                                    </button>
                                    <ContentState
                                        v-if="supplierSearchError"
                                        kind="error"
                                        layout="inline"
                                        class="px-3 py-2"
                                    >
                                        Не удалось найти поставщиков. Измените
                                        запрос и попробуйте ещё раз.
                                    </ContentState>
                                    <div
                                        v-else-if="
                                            !supplierSearchLoading &&
                                            !supplierResults.length
                                        "
                                        class="px-3 py-2 text-xs text-muted-foreground"
                                    >
                                        Не найдено
                                    </div>
                                    <button
                                        v-if="!supplierSearchLoading"
                                        type="button"
                                        class="flex w-full items-center gap-2 border-t border-border px-3 py-2 text-left text-sm break-words text-violet-600 hover:bg-muted dark:text-violet-400"
                                        @mousedown.prevent
                                        @click="openNewSupplierForm"
                                    >
                                        <Plus class="h-3 w-3" />
                                        Создать «{{ supplierQuery }}»
                                    </button>
                                </div>
                            </div>
                            <!-- New supplier form -->
                            <div
                                v-if="showNewSupplierForm"
                                class="mt-2 space-y-2 rounded-lg border border-violet-200 bg-violet-50 p-3 dark:border-violet-800 dark:bg-violet-950"
                            >
                                <p
                                    class="text-xs font-semibold text-violet-700 dark:text-violet-300"
                                >
                                    Новый поставщик:
                                    {{ supplierQuery }}
                                </p>
                                <p
                                    v-if="supplierCreateError"
                                    role="alert"
                                    class="text-xs text-destructive"
                                >
                                    {{ supplierCreateError }}
                                </p>
                                <div>
                                    <Label
                                        :for="fieldId('new-supplier-inn')"
                                        class="text-xs"
                                        >ИНН (необязательно)</Label
                                    >
                                    <Input
                                        :id="fieldId('new-supplier-inn')"
                                        name="new_supplier_inn"
                                        v-model="newSupplierInn"
                                        :disabled="supplierCreating"
                                        placeholder="ИНН"
                                        class="mt-1"
                                    />
                                </div>
                                <div class="flex flex-col gap-2 sm:flex-row">
                                    <ActionButton
                                        type="button"
                                        size="sm"
                                        @click="createSupplier"
                                        :disabled="supplierCreating"
                                        class="min-h-12 gap-1"
                                        :busy="supplierCreating"
                                    >
                                        <Plus class="h-3 w-3" />
                                        Создать поставщика
                                    </ActionButton>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        @click="showNewSupplierForm = false"
                                        :disabled="supplierCreating"
                                        class="min-h-12"
                                        >Закрыть</Button
                                    >
                                </div>
                            </div>
                            <p
                                v-if="convertForm.errors.supplier_name"
                                :id="`${fieldId('supplier_name')}-error`"
                                class="mt-1 text-xs text-red-500"
                            >
                                {{ convertForm.errors.supplier_name }}
                            </p>
                        </div>
                        <div>
                            <Label :for="fieldId('transaction_type')"
                                >Способ оплаты</Label
                            >
                            <Select v-model="convertForm.transaction_type">
                                <SelectTrigger
                                    v-bind="fieldAttrs('transaction_type')"
                                    class="mt-1 w-full"
                                    ><SelectValue
                                /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="bank"
                                        >Банковское перечисление</SelectItem
                                    >
                                    <SelectItem value="cash"
                                        >Наличные</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label :for="fieldId('deadline_from_supplier')"
                                >Срок поставки</Label
                            >
                            <Input
                                v-bind="fieldAttrs('deadline_from_supplier')"
                                v-model="convertForm.deadline_from_supplier"
                                type="date"
                                class="mt-1"
                            />
                        </div>
                    </div>

                    <div class="space-y-3">
                        <Label :for="fieldId('deadline_date')"
                            >Срок оплаты заявки</Label
                        >
                        <Input
                            v-bind="fieldAttrs('deadline_date')"
                            v-model="convertForm.deadline_date"
                            type="date"
                        />
                        <Label :for="fieldId('factual_delivery_date')"
                            >Фактическая дата поставки (если поставлено)</Label
                        >
                        <Input
                            v-bind="fieldAttrs('factual_delivery_date')"
                            v-model="convertForm.factual_delivery_date"
                            type="date"
                        />
                        <PaymentScheduleFields
                            v-model="convertForm.tranches"
                            :currency="convertForm.currency"
                        />
                        <p
                            v-for="[key, error] in unshownErrors"
                            :key="key"
                            :id="`${fieldId(key)}-error`"
                            role="alert"
                            class="text-sm text-red-600"
                            tabindex="-1"
                        >
                            {{ error }}
                        </p>
                    </div>
                    <!-- Approvers -->
                    <div
                        role="group"
                        :aria-labelledby="fieldId('approvers-label')"
                        :aria-describedby="
                            fieldError('approvers')
                                ? fieldId('approvers-error')
                                : undefined
                        "
                    >
                        <h3
                            :id="fieldId('approvers-label')"
                            class="mb-2 text-sm font-semibold"
                        >
                            Маршрут согласования
                        </h3>
                        <p
                            v-if="loadingApprovers"
                            class="text-sm text-muted-foreground"
                        >
                            Загрузка согласующих...
                        </p>
                        <div
                            v-else-if="approversLoadError"
                            role="alert"
                            class="flex flex-wrap items-center gap-3 rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm"
                        >
                            <span class="text-destructive"
                                >Не удалось загрузить список согласующих.</span
                            >
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="loadApprovers"
                                >Повторить</Button
                            >
                        </div>
                        <div v-else>
                            <p
                                v-if="!availableApprovers.length"
                                class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
                            >
                                Список согласующих пуст. Нельзя передать заявку,
                                пока не будет добавлен маршрут согласования.
                            </p>
                            <!-- Selected approvers with order -->
                            <div
                                v-if="convertForm.approvers.length"
                                class="mb-3 space-y-2"
                            >
                                <div
                                    v-for="(
                                        approverId, idx
                                    ) in convertForm.approvers"
                                    :key="approverId"
                                    class="grid grid-cols-[1.5rem_1fr] items-start gap-2 rounded-lg bg-muted p-3 sm:grid-cols-[1.5rem_1fr_auto]"
                                >
                                    <span
                                        class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-violet-500 text-xs font-bold text-white"
                                    >
                                        {{ idx + 1 }}
                                    </span>
                                    <span
                                        class="min-w-0 flex-1 text-sm break-words text-foreground"
                                        >{{ getApproverName(approverId) }}</span
                                    >
                                    <div
                                        class="col-start-2 flex flex-wrap gap-1 sm:col-start-3 sm:row-start-1"
                                    >
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            @click="moveApprover(idx, -1)"
                                            :aria-label="`Переместить ${getApproverName(approverId)} выше`"
                                            :disabled="idx === 0"
                                            class="h-12 w-12 p-0"
                                        >
                                            <span class="text-xs">▲</span>
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            @click="moveApprover(idx, 1)"
                                            :aria-label="`Переместить ${getApproverName(approverId)} ниже`"
                                            :disabled="
                                                idx ===
                                                convertForm.approvers.length - 1
                                            "
                                            class="h-12 w-12 p-0"
                                        >
                                            <span class="text-xs">▼</span>
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            @click="removeApprover(approverId)"
                                            :aria-label="`Убрать согласующего ${getApproverName(approverId)}`"
                                            class="h-12 w-12 p-0 text-red-500 hover:text-red-700"
                                        >
                                            <Trash2 class="h-3 w-3" />
                                        </Button>
                                    </div>
                                </div>
                            </div>

                            <!-- Available approvers to add -->
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                <Button
                                    v-for="approver in availableApprovers.filter(
                                        (a) =>
                                            !convertForm.approvers.includes(
                                                a.id,
                                            ),
                                    )"
                                    :key="approver.id"
                                    type="button"
                                    variant="outline"
                                    class="h-auto min-h-12 max-w-full min-w-0 flex-wrap justify-start gap-2 text-left whitespace-normal"
                                    @click="addApprover(approver.id)"
                                >
                                    <Plus class="h-3 w-3" />
                                    {{ approver.name }}
                                    <span
                                        v-if="approver.title"
                                        class="text-xs text-muted-foreground"
                                        >· {{ approver.title }}</span
                                    >
                                </Button>
                            </div>
                            <p
                                v-if="convertForm.errors.approvers"
                                :id="fieldId('approvers-error')"
                                class="mt-1 text-xs text-red-500"
                            >
                                {{ convertForm.errors.approvers }}
                            </p>
                        </div>
                    </div>

                    <!-- Submit -->
                    <p
                        v-if="
                            !convertForm.supplier_name ||
                            !convertForm.approvers.length
                        "
                        :id="fieldId('requirements')"
                        class="text-sm text-muted-foreground"
                        role="status"
                    >
                        Для отправки выберите поставщика и хотя бы одного
                        согласующего.
                    </p>
                    <FormActions layout="inline">
                        <ActionButton
                            type="submit"
                            :aria-describedby="
                                !convertForm.supplier_name ||
                                !convertForm.approvers.length
                                    ? fieldId('requirements')
                                    : undefined
                            "
                            :disabled="
                                convertForm.processing ||
                                !convertForm.approvers.length ||
                                !convertForm.supplier_name
                            "
                            class="min-h-12 gap-2"
                            :busy="convertForm.processing"
                        >
                            <Send class="h-4 w-4" />
                            Передать на согласование
                        </ActionButton>
                        <Button
                            type="button"
                            variant="outline"
                            @click="showConvertForm = false"
                            class="min-h-12"
                            >Закрыть форму</Button
                        >
                    </FormActions>
                </form>
            </CardContent>
        </Card>
        <Card
            v-if="showCancelForm && isPendingSupply"
            class="border-2 border-red-300 dark:border-red-700"
        >
            <CardHeader>
                <CardTitle
                    class="flex items-center gap-2 text-sm text-red-700 dark:text-red-400"
                >
                    <Ban class="h-4 w-4" />
                    Отмена заявки на снабжение
                </CardTitle>
            </CardHeader>
            <CardContent>
                <form @submit.prevent="submitCancel" class="space-y-4">
                    <div
                        v-if="
                            Object.keys(cancelForm.errors).some(
                                (field) => field !== 'reason',
                            )
                        "
                        :id="fieldId('cancel-errors')"
                        tabindex="-1"
                        role="alert"
                        class="rounded-md border border-destructive/30 p-3 text-sm text-destructive"
                    >
                        <p
                            v-for="(error, field) in cancelForm.errors"
                            :key="field"
                        >
                            <template v-if="field !== 'reason'">{{
                                error
                            }}</template>
                        </p>
                    </div>
                    <div>
                        <Label :for="fieldId('cancel-reason')"
                            >Причина отмены</Label
                        >
                        <textarea
                            :id="fieldId('cancel-reason')"
                            name="reason"
                            :aria-invalid="Boolean(cancelForm.errors.reason)"
                            :aria-describedby="
                                cancelForm.errors.reason
                                    ? fieldId('cancel-reason-error')
                                    : undefined
                            "
                            v-model="cancelForm.reason"
                            rows="3"
                            class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            placeholder="Укажите причину отмены заявки..."
                        ></textarea>
                        <p
                            v-if="cancelForm.errors.reason"
                            :id="fieldId('cancel-reason-error')"
                            class="mt-1 text-xs text-red-500"
                        >
                            {{ cancelForm.errors.reason }}
                        </p>
                    </div>
                    <div
                        class="flex flex-col gap-3 sm:flex-row sm:items-center"
                    >
                        <ActionButton
                            type="submit"
                            variant="destructive"
                            :disabled="
                                cancelForm.processing || !cancelForm.reason
                            "
                            class="min-h-12 gap-2"
                            :busy="cancelForm.processing"
                        >
                            <Ban class="h-4 w-4" />
                            Отменить заявку
                        </ActionButton>
                        <Button
                            type="button"
                            variant="outline"
                            @click="showCancelForm = false"
                            class="min-h-12"
                            >Закрыть форму</Button
                        >
                    </div>
                </form>
            </CardContent>
        </Card>
    </div>
</template>
