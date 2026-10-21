<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    computed,
    ref,
    watch,
    nextTick,
    onMounted,
    onBeforeUnmount,
} from 'vue';
import ActionButton from '@/components/patterns/ActionButton.vue';
import AppPage from '@/components/patterns/AppPage.vue';
import ContentState from '@/components/patterns/ContentState.vue';
import FormSection from '@/components/patterns/FormSection.vue';
import PageHeader from '@/components/patterns/PageHeader.vue';
import SupplierInfo from '@/components/patterns/SupplierInfo.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    matchingSuppliers,
    steppedQuantity,
    normalizeMoneyInput,
} from '@/lib/draftInputs';
import { validateDraft } from '@/lib/draftValidation';
import { formatMoney as money } from '@/lib/requestPresentation';
import { approverLabel } from '@/lib/utils';
type Option = {
    id: number;
    name?: string;
    full_name?: string;
    title?: string | null;
    inn?: string;
};
type FileRow = {
    id: number;
    name: string;
    file_type: string;
    url: string | null;
};
type PendingUpload = {
    id: number;
    file: File;
    status: 'queued' | 'uploading' | 'failed';
    error: string;
};
const pendingUploads = ref<PendingUpload[]>([]);
let nextUploadId = 0;
const props = defineProps<{
    draft: {
        id: string;
        payload: any;
        version: number;
        updated_at: string;
        files: FileRow[];
        source_id?: number;
        is_revision?: boolean;
    };
    projects: Option[];
    suppliers: Option[];
    approversByProject: Record<string, Option[]>;
    supplyApproversByProject: Record<string, Option[]>;
}>();
const payload = ref(JSON.parse(JSON.stringify(props.draft.payload)));
const attachmentInput = ref<HTMLInputElement | null>(null);
function changeQuantity(item: { quantity: string | number }, delta: number) {
    const next = steppedQuantity(item.quantity, delta);
    if (next !== null) item.quantity = next;
}
const supplierFocused = ref(false);
const newSupplier = ref(
    Boolean(payload.value.supplier_name && !payload.value.supplier_id),
);
const supplierActiveIndex = ref(-1);
const supplierMatches = computed(() =>
    payload.value.supplier_id
        ? []
        : matchingSuppliers(props.suppliers, payload.value.supplier_name ?? ''),
);
const supplierSuggestionsOpen = computed(
    () =>
        supplierFocused.value &&
        !payload.value.supplier_id &&
        !newSupplier.value &&
        Boolean(payload.value.supplier_name?.trim()),
);
watch(
    () => payload.value.supplier_name,
    () => {
        supplierActiveIndex.value = -1;
    },
);
function supplierKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        supplierFocused.value = false;
        supplierActiveIndex.value = -1;
        return;
    }
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        if (!supplierMatches.value.length) return;
        event.preventDefault();
        supplierFocused.value = true;
        const length = supplierMatches.value.length;
        supplierActiveIndex.value =
            supplierActiveIndex.value < 0
                ? event.key === 'ArrowDown'
                    ? 0
                    : length - 1
                : (supplierActiveIndex.value +
                      (event.key === 'ArrowDown' ? 1 : -1) +
                      length) %
                  length;
        void nextTick(() =>
            document
                .getElementById('supplier-option-' + supplierActiveIndex.value)
                ?.scrollIntoView({ block: 'nearest' }),
        );
    } else if (
        event.key === 'Enter' &&
        supplierSuggestionsOpen.value &&
        supplierActiveIndex.value >= 0
    ) {
        event.preventDefault();
        chooseSupplier(supplierMatches.value[supplierActiveIndex.value]);
    }
}
function changeSupplier() {
    payload.value.supplier_id = '';
    payload.value.supplier_name = '';
    payload.value.supplier_inn = '';
    newSupplier.value = false;
    supplierFocused.value = true;
    void nextTick(() =>
        document.getElementById(fieldId('supplier_name'))?.focus(),
    );
}
function useNewSupplier() {
    newSupplier.value = true;
    supplierFocused.value = false;
    payload.value.supplier_id = '';
    void nextTick(() =>
        document.getElementById(fieldId('supplier_name'))?.focus(),
    );
}
function searchSupplier() {
    newSupplier.value = false;
    supplierFocused.value = true;
    void nextTick(() =>
        document.getElementById(fieldId('supplier_name'))?.focus(),
    );
}
function onSupplierBlur(event: FocusEvent) {
    if (
        !(event.currentTarget as HTMLElement).contains(
            event.relatedTarget as Node | null,
        )
    )
        supplierFocused.value = false;
}
function chooseSupplier(supplier: Option) {
    newSupplier.value = false;
    payload.value.supplier_id = supplier.id;
    selectSupplier();
    supplierFocused.value = false;
    supplierActiveIndex.value = -1;
    void nextTick(() =>
        document.getElementById(fieldId('supplier_id'))?.focus(),
    );
}
const version = ref(props.draft.version),
    files = ref(props.draft.files),
    savedAt = ref(props.draft.updated_at);
const state = ref('saved'),
    message = ref(''),
    errors = ref<Record<string, string[]>>({}),
    review = ref(false),
    conflict = ref(false),
    busy = ref(0),
    suggestion = ref<any>(null),
    backup = ref<any>(null);
const base = '/request-drafts/' + props.draft.id;
const backupKey =
    'request-draft:' +
    ((usePage().props.auth as any)?.user?.id ?? '') +
    ':' +
    props.draft.id;
let lastSaved = JSON.stringify(payload.value),
    queue: Promise<any> = Promise.resolve(),
    timer: ReturnType<typeof setTimeout>,
    completed = false;
const isSupply = computed(() => payload.value.type === 'supply');
const approvers = computed(
    () => props.approversByProject[String(payload.value.project_id)] ?? [],
);
const supplyChain = computed(
    () =>
        props.supplyApproversByProject[String(payload.value.project_id)] ?? [],
);
const total = computed(
    () =>
        payload.value.items.reduce(
            (sum: number, i: any) =>
                sum +
                Math.round(
                    Number(i.quantity || 0) *
                        Number(normalizeMoneyInput(i.price_per_unit)) *
                        100,
                ),
            0,
        ) / 100,
);
const attempted = ref(false);
const actionPending = ref(false);
const clientErrors = computed(() =>
    validateDraft(payload.value, {
        projects: props.projects,
        suppliers: props.suppliers,
        approvers: approvers.value,
        supplyChain: supplyChain.value,
    }),
);
const submitBlockReason = computed(() => {
    if (completed) return 'Заявка отправлена. Открываем результат…';
    if (conflict.value) return 'Обновите страницу и разрешите конфликт версий.';
    if (pendingUploads.value.length)
        return 'Загрузите выбранные вложения или уберите их перед отправкой.';
    if (actionPending.value || busy.value > 0)
        return 'Дождитесь завершения текущего действия.';
    if (state.value !== 'saved')
        return 'Сначала дождитесь успешного сохранения черновика.';
    if (
        Object.keys(clientErrors.value).length ||
        Object.keys(errors.value).length
    )
        return 'Исправьте отмеченные поля перед отправкой.';
    return '';
});
const fieldId = (key: string) =>
    key.match(/^items\.(\d+)\.quantity$/)
        ? 'quantity-' + key.split('.')[1]
        : 'draft-field-' + key.replaceAll('.', '-');
function fieldAttrs(key: string) {
    return {
        id: fieldId(key),
        'aria-invalid': !!error(key),
        'aria-describedby': error(key) ? fieldId(key) + '-error' : undefined,
    };
}
async function focusError(key = Object.keys(errors.value)[0]) {
    review.value = false;
    await nextTick();
    let target = key;
    while (target && !document.getElementById(fieldId(target)))
        target = target.split('.').slice(0, -1).join('.');
    const element =
        document.getElementById(fieldId(target)) ??
        document.getElementById('draft-errors');
    element?.focus();
    element?.scrollIntoView({ block: 'center', behavior: 'smooth' });
}
function validate() {
    attempted.value = true;
    errors.value = clientErrors.value;
    if (Object.keys(errors.value).length) {
        void focusError();
        return false;
    }
    return true;
}
const error = (key: string) => {
    const quantity = key.match(/^items\.(\d+)\.quantity$/);
    const value = quantity
        ? payload.value.items[Number(quantity[1])]?.quantity
        : null;
    return (
        errors.value[key]?.[0] ??
        (quantity && value != null && String(value).trim() !== ''
            ? clientErrors.value[key]?.[0]
            : undefined)
    );
};
const statusText = computed(
    () =>
        ({
            saved:
                'Сохранено ' +
                new Date(savedAt.value).toLocaleTimeString('ru-RU'),
            dirty: 'Есть несохранённые изменения',
            saving: 'Сохраняем…',
            error: 'Не удалось сохранить',
            conflict: 'Конфликт версий',
        })[state.value] || state.value,
);
async function api(path: string, method: string, body?: any) {
    const csrf = decodeURIComponent(
        document.cookie
            .split('; ')
            .find((c) => c.startsWith('XSRF-TOKEN='))
            ?.slice(11) ?? '',
    );
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-XSRF-TOKEN': csrf,
    };
    const form = body instanceof FormData;
    if (!form) headers['Content-Type'] = 'application/json';
    const response = await fetch(path, {
        method,
        credentials: 'same-origin',
        headers,
        body: body ? (form ? body : JSON.stringify(body)) : undefined,
    });
    const data = await response.json().catch(() => ({
        message:
            'Сессия истекла или сервер недоступен. Введённые поля сохранены в этой вкладке.',
    }));
    if (!response.ok || response.redirected) {
        if (response.status === 409) {
            conflict.value = true;
            state.value = 'conflict';
        }
        throw {
            message: data.message || 'Не удалось выполнить действие',
            errors: data.errors,
            status: response.status,
        };
    }
    return data;
}
function enqueue(action: () => Promise<void>) {
    queue = queue
        .catch(() => {})
        .then(async () => {
            if (conflict.value) return;
            busy.value++;
            try {
                await action();
            } catch (e: any) {
                message.value =
                    e.message || 'Ошибка соединения. Повторите сохранение.';
                errors.value = Object.fromEntries(
                    Object.entries(e.errors || {}).map(([key, value]) => [
                        key.replace(/^payload\./, ''),
                        Array.isArray(value) ? value : [String(value)],
                    ]),
                );
                if (Object.keys(errors.value).length) {
                    attempted.value = true;
                    void focusError();
                }
                state.value = conflict.value
                    ? 'conflict'
                    : e.status === 422 &&
                        JSON.stringify(payload.value) === lastSaved
                      ? 'saved'
                      : 'error';
                review.value = false;
            } finally {
                busy.value--;
            }
        });
    return queue;
}
async function persist() {
    while (JSON.stringify(payload.value) !== lastSaved) {
        state.value = 'saving';
        const snapshot = JSON.stringify(payload.value);
        const result = await api(base, 'PUT', {
            version: version.value,
            payload: JSON.parse(snapshot),
        });
        version.value = result.version;
        savedAt.value = result.updated_at;
        lastSaved = snapshot;
    }
    state.value = 'saved';
    message.value = '';
    try {
        sessionStorage.removeItem(backupKey);
    } catch {}
}
function changed() {
    if (completed) return;
    if (attempted.value) errors.value = clientErrors.value;
    state.value = 'dirty';
    try {
        sessionStorage.setItem(
            backupKey,
            JSON.stringify({ payload: payload.value, version: version.value }),
        );
    } catch {}
    clearTimeout(timer);
    timer = setTimeout(() => enqueue(persist), 800);
}
watch(payload, changed, { deep: true });
function selectProject() {
    payload.value.approvers = [];
}
function selectSupplier() {
    supplierFocused.value = false;
    supplierActiveIndex.value = -1;
    const s = props.suppliers.find(
        (s) => s.id === Number(payload.value.supplier_id),
    );
    if (s) {
        payload.value.supplier_name = s.name;
        payload.value.supplier_inn = s.inn || '';
    }
}
function addItem() {
    payload.value.items.push({
        name: '',
        description: '',
        quantity: '1',
        price_per_unit: '',
    });
}
function moveApprover(i: number, delta: number) {
    const a = payload.value.approvers;
    [a[i], a[i + delta]] = [a[i + delta], a[i]];
}
function addApprover(event: Event) {
    const input = event.target as HTMLSelectElement;
    const id = Number(input.value);
    if (id && !payload.value.approvers.includes(id))
        payload.value.approvers.push(id);
    input.value = '';
}
function check() {
    if (
        actionPending.value ||
        busy.value > 0 ||
        conflict.value ||
        completed ||
        pendingUploads.value.length
    )
        return;
    if (!validate()) return;
    clearTimeout(timer);
    actionPending.value = true;
    enqueue(async () => {
        await persist();
        if (validate() && !pendingUploads.value.length) review.value = true;
    }).finally(() => {
        actionPending.value = false;
    });
}
function submit() {
    if (
        actionPending.value ||
        busy.value > 0 ||
        conflict.value ||
        completed ||
        pendingUploads.value.length ||
        !review.value
    )
        return;
    if (!validate() || state.value !== 'saved') return;
    clearTimeout(timer);
    actionPending.value = true;
    enqueue(async () => {
        await persist();
        if (!validate() || pendingUploads.value.length) return;
        const result = await api(base + '/submit', 'POST', {
            version: version.value,
        });
        completed = true;
        try {
            sessionStorage.removeItem(backupKey);
        } catch {}
        router.visit(result.url);
    }).finally(() => {
        actionPending.value = false;
    });
}
function upload(event: Event) {
    const input = event.target as HTMLInputElement;
    const selected = Array.from(input.files ?? []);
    input.value = '';
    if (
        !selected.length ||
        busy.value ||
        actionPending.value ||
        conflict.value ||
        completed
    )
        return;
    const rows = selected.map((file) => ({
        id: ++nextUploadId,
        file,
        status: 'queued' as const,
        error: '',
    }));
    pendingUploads.value.push(...rows);
    review.value = false;
    uploadFiles(rows.map((row) => row.id));
}
function uploadFiles(ids: number[]) {
    enqueue(async () => {
        try {
            await persist();
            for (const id of ids) {
                const row = pendingUploads.value.find((row) => row.id === id);
                if (!row) continue;
                if (conflict.value)
                    throw new Error(
                        'Обновите страницу и повторно выберите файл.',
                    );
                row.status = 'uploading';
                try {
                    if (row.file.size > 15 * 1024 * 1024)
                        throw new Error('Размер файла превышает 15 МБ.');
                    if (files.value.length >= 20)
                        throw new Error(
                            'Не больше 20 вложений. Уберите лишний файл.',
                        );
                    const form = new FormData();
                    form.append('file', row.file);
                    form.append('version', String(version.value));
                    const result = await api(base + '/files', 'POST', form);
                    version.value = result.version;
                    files.value = result.files;
                    savedAt.value = result.updated_at;
                    pendingUploads.value = pendingUploads.value.filter(
                        (row) => row.id !== id,
                    );
                } catch (e: any) {
                    row.status = 'failed';
                    row.error =
                        e.status === 413
                            ? 'Сервер отклонил размер файла. Максимум — 15 МБ.'
                            : e.errors?.file?.[0] ||
                              e.message ||
                              'Не удалось загрузить файл. Повторите попытку.';
                }
            }
            if (!conflict.value) await persist();
        } catch (e: any) {
            for (const row of pendingUploads.value.filter(
                (row) => ids.includes(row.id) && row.status !== 'failed',
            )) {
                row.status = 'failed';
                row.error =
                    e.message ||
                    'Не удалось загрузить файл. Повторите попытку.';
            }
            throw e;
        }
    });
}
function retryUpload(id: number) {
    if (busy.value || actionPending.value || conflict.value || completed)
        return;
    const row = pendingUploads.value.find((row) => row.id === id);
    if (!row || row.status !== 'failed') return;
    row.status = 'queued';
    row.error = '';
    uploadFiles([id]);
}
function discardUpload(id: number) {
    if (busy.value || actionPending.value || completed) return;
    pendingUploads.value = pendingUploads.value.filter((row) => row.id !== id);
}
function removeFile(id: number) {
    enqueue(async () => {
        await persist();
        const result = await api(base + '/files/' + id, 'DELETE', {
            version: version.value,
        });
        version.value = result.version;
        files.value = result.files;
        await persist();
    });
}
function analyze(id: number) {
    enqueue(async () => {
        await persist();
        const result = await api(base + '/files/' + id + '/analyze', 'POST');
        suggestion.value = result.suggestion;
        message.value = result.message;
    });
}
function applySuggestion() {
    const s = suggestion.value;
    if (s.project_id) {
        payload.value.project_id = s.project_id;
        payload.value.approvers = [];
    }
    payload.value.supplier_id = '';
    payload.value.supplier_name =
        s.supplier_name || payload.value.supplier_name;
    payload.value.supplier_inn = s.supplier_inn || payload.value.supplier_inn;
    if (s.currency) payload.value.currency = s.currency;
    if (s.amount)
        payload.value.items = [
            {
                name: s.name,
                description: '',
                quantity: '1',
                price_per_unit: String(s.amount),
            },
        ];
    payload.value.tranches = [];
    suggestion.value = null;
    message.value =
        'Данные из PDF подставлены. Проверьте поля перед отправкой.';
}
function restoreBackup() {
    payload.value = backup.value.payload;
    backup.value = null;
    changed();
}
function hasUnsavedChanges() {
    return (
        pendingUploads.value.length > 0 ||
        JSON.stringify(payload.value) !== lastSaved
    );
}
function leave(e: BeforeUnloadEvent) {
    if (!completed && hasUnsavedChanges()) {
        e.preventDefault();
        e.returnValue = '';
    }
}
let removeNavigationGuard: (() => void) | undefined;
onMounted(() => {
    try {
        const stored = JSON.parse(sessionStorage.getItem(backupKey) || 'null');
        if (stored && JSON.stringify(stored.payload) !== lastSaved)
            backup.value = stored;
    } catch {}
    window.addEventListener('beforeunload', leave);
    removeNavigationGuard = router.on('before', (event) => {
        if (
            !completed &&
            hasUnsavedChanges() &&
            !window.confirm(
                'Есть несохранённые поля или незагруженные вложения. Уйти со страницы?',
            )
        )
            event.preventDefault();
    });
});
onBeforeUnmount(() => {
    clearTimeout(timer);
    window.removeEventListener('beforeunload', leave);
    removeNavigationGuard?.();
});
</script>
<template>
    <Head title="Черновик заявки" /><AppLayout
        :breadcrumbs="[
            { title: 'Заявки и черновики', href: '/request-drafts' },
        ]"
    >
        <AppPage width="form" class="pb-60 sm:pb-40">
            <PageHeader
                :title="
                    review
                        ? 'Проверка перед отправкой'
                        : draft.is_revision
                          ? 'Исправление заявки'
                          : 'Новая заявка'
                "
                description="Черновик видите только вы. Согласование начнётся после отправки."
                ><template #description
                    ><p>
                        Черновик видите только вы. Согласование начнётся после
                        отправки.
                    </p>
                    <p v-if="draft.source_id" class="mt-1 text-sm">
                        {{ draft.is_revision ? 'Исправление' : 'Копия' }} заявки
                        #{{ draft.source_id }}. История сохранится. При
                        исправлении можно удалить неверные вложения и загрузить
                        замену. Прежние файлы сохранятся в истории.
                    </p></template
                ><template #meta
                    ><div class="text-right">
                        <ContentState
                            :kind="
                                state === 'saved'
                                    ? 'success'
                                    : state === 'saving'
                                      ? 'loading'
                                      : ['error', 'conflict'].includes(state)
                                        ? 'error'
                                        : 'empty'
                            "
                            :show-icon="state === 'saved' || state === 'saving'"
                            layout="inline"
                            :message="statusText"
                        />
                        <button
                            v-if="state === 'error'"
                            class="mt-2 underline"
                            @click="enqueue(persist)"
                        >
                            Повторить сохранение
                        </button>
                    </div></template
                ></PageHeader
            >
            <div v-if="backup" class="rounded-lg border border-amber-500 p-4">
                <p>
                    В этой вкладке остались несохранённые поля. Восстановление
                    заменит поля текущего черновика.
                </p>
                <button class="mt-2 underline" @click="restoreBackup">
                    Восстановить мои поля</button
                ><button class="ml-4 underline" @click="backup = null">
                    Оставить серверную версию
                </button>
            </div>
            <ContentState
                v-if="message"
                role="alert"
                kind="error"
                layout="notice"
            >
                {{ message }}
                <p v-if="conflict" class="mt-2">
                    Ваши поля сохранены в этой вкладке. Обновите страницу,
                    сравните данные и при необходимости восстановите их.
                </p>
            </ContentState>
            <ContentState
                v-if="Object.keys(errors).length"
                id="draft-errors"
                tabindex="-1"
                role="alert"
                kind="error"
                layout="notice"
            >
                <p class="font-medium">Исправьте поля перед отправкой:</p>
                <ul class="mt-2 list-inside list-disc">
                    <li v-for="(values, key) in errors" :key="key">
                        <button
                            type="button"
                            class="text-left underline"
                            @click="focusError(String(key))"
                        >
                            {{ values[0] }}
                        </button>
                    </li>
                </ul>
            </ContentState>
            <FormSection v-if="suggestion">
                <h2 class="font-semibold">Результат распознавания PDF</h2>
                <p class="mt-2">
                    {{ suggestion.name }} ·
                    {{ suggestion.supplier_name || 'Поставщик не определён' }} ·
                    {{ suggestion.amount || 'Сумма не определена' }}
                    {{ suggestion.currency }}
                </p>
                <p class="mt-2 text-sm text-muted-foreground">
                    Применение заменит позиции и очистит график платежей.
                    Проверьте сумму аванса и полную стоимость.
                </p>
                <button
                    class="mt-3 rounded bg-primary px-4 py-2 text-primary-foreground"
                    @click="applySuggestion"
                >
                    Применить данные</button
                ><button class="ml-3 underline" @click="suggestion = null">
                    Отмена
                </button>
            </FormSection>
            <nav
                v-if="!review"
                aria-label="Разделы заявки"
                class="flex flex-wrap gap-2 rounded-xl bg-muted/40 p-3 text-sm"
            >
                <a
                    class="rounded-md px-3 py-2 hover:bg-muted"
                    href="#draft-basics"
                    >1. Основное</a
                ><a
                    v-if="!isSupply"
                    class="rounded-md px-3 py-2 hover:bg-muted"
                    href="#draft-supplier"
                    >2. Поставщик</a
                ><a
                    class="rounded-md px-3 py-2 hover:bg-muted"
                    href="#draft-items"
                    >{{ isSupply ? '2' : '3' }}. Позиции</a
                ><a
                    class="rounded-md px-3 py-2 hover:bg-muted"
                    href="#draft-route"
                    >{{ isSupply ? '3' : '4' }}. Согласование</a
                ><a
                    class="rounded-md px-3 py-2 hover:bg-muted"
                    href="#draft-files"
                    >{{ isSupply ? '4' : '5' }}. Вложения</a
                >
            </nav>
            <p v-if="!review" class="text-sm text-muted-foreground">
                Заполните проект, позиции и маршрут согласования.
                {{
                    isSupply
                        ? 'Укажите срок поставки.'
                        : 'Выберите поставщика; срок оплаты необязателен.'
                }}
                Необязательные поля отмечены в форме.
            </p>
            <template v-if="!review">
                <FormSection id="draft-basics">
                    <h2 class="mb-4 text-lg font-semibold">1. Основное</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="space-y-2"
                            ><span>Тип заявки</span
                            ><select
                                v-bind="fieldAttrs('type')"
                                v-model="payload.type"
                                :disabled="draft.is_revision"
                                class="field"
                            >
                                <option value="approval">Расход</option>
                                <option value="supply">
                                    Снабжение
                                </option></select
                            ><span
                                v-if="error('type')"
                                :id="fieldId('type') + '-error'"
                                class="error"
                                >{{ error('type') }}</span
                            ></label
                        ><label class="space-y-2"
                            ><span>Проект</span
                            ><select
                                v-bind="fieldAttrs('project_id')"
                                v-model="payload.project_id"
                                class="field"
                                @change="selectProject"
                            >
                                <option value="">Выберите проект</option>
                                <option
                                    v-for="p in projects"
                                    :key="p.id"
                                    :value="p.id"
                                >
                                    {{ p.name }}
                                </option></select
                            ><span
                                v-if="error('project_id')"
                                :id="fieldId('project_id') + '-error'"
                                class="error"
                                >{{ error('project_id') }}</span
                            ></label
                        ><label class="space-y-2"
                            ><span>{{
                                isSupply
                                    ? 'Срок поставки'
                                    : 'Срок оплаты (по умолчанию — сегодня)'
                            }}</span
                            ><input
                                v-bind="fieldAttrs('deadline_date')"
                                v-model="payload.deadline_date"
                                type="date"
                                class="field"
                            /><span
                                v-if="error('deadline_date')"
                                :id="fieldId('deadline_date') + '-error'"
                                class="error"
                                >{{ error('deadline_date') }}</span
                            ></label
                        ><template v-if="!isSupply"
                            ><label class="space-y-2"
                                ><span>Валюта</span
                                ><select
                                    v-bind="fieldAttrs('currency')"
                                    v-model="payload.currency"
                                    class="field"
                                >
                                    <option
                                        v-for="c in [
                                            'UZS',
                                            'USD',
                                            'EUR',
                                            'RUB',
                                        ]"
                                        :key="c"
                                    >
                                        {{ c }}
                                    </option></select
                                ><span
                                    v-if="error('currency')"
                                    :id="fieldId('currency') + '-error'"
                                    class="error"
                                    >{{ error('currency') }}</span
                                ></label
                            ><label class="space-y-2"
                                ><span>Способ оплаты</span
                                ><select
                                    v-bind="fieldAttrs('transaction_type')"
                                    v-model="payload.transaction_type"
                                    class="field"
                                >
                                    <option value="bank">Перечисление</option>
                                    <option value="cash">
                                        Наличные
                                    </option></select
                                ><span
                                    v-if="error('transaction_type')"
                                    :id="fieldId('transaction_type') + '-error'"
                                    class="error"
                                    >{{ error('transaction_type') }}</span
                                ></label
                            ></template
                        >
                    </div>
                    <p
                        v-if="!projects.length"
                        class="mt-3 text-sm text-amber-600"
                    >
                        Нет доступных проектов. Обратитесь к администратору;
                        черновик можно заполнить и сохранить.
                    </p>
                </FormSection>
                <FormSection id="draft-supplier" v-if="!isSupply">
                    <h2 class="mb-4 text-lg font-semibold">2. Поставщик</h2>
                    <div
                        v-if="payload.supplier_id"
                        :id="fieldId('supplier_id')"
                        tabindex="-1"
                        :aria-invalid="Boolean(error('supplier_id'))"
                        :aria-describedby="
                            error('supplier_id')
                                ? fieldId('supplier_id') + '-error'
                                : undefined
                        "
                        class="flex min-w-0 flex-col items-stretch gap-3 rounded-lg bg-muted p-4 sm:flex-row sm:items-center"
                    >
                        <SupplierInfo
                            :name="payload.supplier_name"
                            :inn="payload.supplier_inn"
                            class="min-w-0 flex-1 font-medium"
                        />
                        <button
                            type="button"
                            class="rounded-md border px-3 py-2 text-sm hover:bg-background"
                            @click="changeSupplier"
                        >
                            Изменить поставщика
                        </button>
                    </div>
                    <div v-else class="grid gap-4 sm:grid-cols-2">
                        <div
                            class="relative"
                            :class="{ 'sm:col-span-2': !newSupplier }"
                            @focusout="onSupplierBlur"
                        >
                            <label :for="fieldId('supplier_name')">{{
                                newSupplier
                                    ? 'Название нового поставщика'
                                    : 'Поставщик'
                            }}</label>
                            <input
                                v-bind="fieldAttrs('supplier_name')"
                                v-model="payload.supplier_name"
                                name="supplier_name"
                                :role="newSupplier ? undefined : 'combobox'"
                                :aria-autocomplete="
                                    newSupplier ? undefined : 'list'
                                "
                                :aria-controls="
                                    supplierSuggestionsOpen
                                        ? 'supplier-suggestions'
                                        : undefined
                                "
                                :aria-expanded="supplierSuggestionsOpen"
                                :aria-activedescendant="
                                    supplierSuggestionsOpen &&
                                    supplierActiveIndex >= 0
                                        ? 'supplier-option-' +
                                          supplierActiveIndex
                                        : undefined
                                "
                                autocomplete="off"
                                class="field mt-2"
                                maxlength="255"
                                placeholder="Название или ИНН"
                                @focus="supplierFocused = true"
                                @input="supplierFocused = true"
                                @keydown="supplierKeydown"
                            />
                            <div
                                v-if="supplierSuggestionsOpen"
                                class="absolute z-20 mt-1 w-full overflow-hidden rounded-lg border bg-popover text-popover-foreground"
                            >
                                <ul
                                    id="supplier-suggestions"
                                    role="listbox"
                                    aria-label="Существующие поставщики"
                                    class="max-h-64 overflow-y-auto"
                                >
                                    <li
                                        v-for="(
                                            supplier, index
                                        ) in supplierMatches"
                                        :id="'supplier-option-' + index"
                                        :key="supplier.id"
                                        role="option"
                                        :aria-selected="
                                            index === supplierActiveIndex
                                        "
                                        class="cursor-pointer px-3 py-3 text-sm break-words hover:bg-muted"
                                        :class="{
                                            'bg-muted':
                                                index === supplierActiveIndex,
                                        }"
                                        @mousedown.prevent
                                        @click="chooseSupplier(supplier)"
                                    >
                                        <span class="block font-medium">{{
                                            supplier.name
                                        }}</span
                                        ><span
                                            v-if="supplier.inn"
                                            class="text-muted-foreground"
                                            >ИНН: {{ supplier.inn }}</span
                                        >
                                    </li>
                                </ul>
                                <p
                                    v-if="!supplierMatches.length"
                                    class="px-3 py-2 text-sm text-muted-foreground"
                                    role="status"
                                >
                                    Поставщик не найден.
                                </p>
                                <button
                                    type="button"
                                    class="w-full border-t px-3 py-3 text-left text-sm font-medium break-words hover:bg-muted"
                                    @mousedown.prevent
                                    @click="useNewSupplier"
                                >
                                    Ввести нового поставщика «{{
                                        payload.supplier_name
                                    }}»
                                </button>
                            </div>
                            <p
                                v-if="newSupplier"
                                class="mt-2 text-sm text-muted-foreground"
                            >
                                Новый поставщик будет указан в заявке.
                                <button
                                    type="button"
                                    class="underline"
                                    @click="searchSupplier"
                                >
                                    Найти в справочнике
                                </button>
                            </p>
                            <span
                                v-if="error('supplier_name')"
                                :id="fieldId('supplier_name') + '-error'"
                                class="error"
                                >{{ error('supplier_name') }}</span
                            >
                        </div>
                        <label v-if="newSupplier"
                            >ИНН (необязательно)<input
                                v-bind="fieldAttrs('supplier_inn')"
                                v-model="payload.supplier_inn"
                                name="supplier_inn"
                                class="field mt-2"
                                maxlength="30"
                            /><span
                                v-if="error('supplier_inn')"
                                :id="fieldId('supplier_inn') + '-error'"
                                class="error"
                                >{{ error('supplier_inn') }}</span
                            ></label
                        >
                    </div>
                    <p
                        v-if="error('supplier_id')"
                        :id="fieldId('supplier_id') + '-error'"
                        class="error"
                    >
                        {{ error('supplier_id') }}
                    </p>
                </FormSection>
                <FormSection id="draft-items">
                    <div :id="fieldId('items')" tabindex="-1">
                        <h2 class="mb-4 text-lg font-semibold">
                            {{ isSupply ? '2' : '3' }}. Позиции
                        </h2>
                        <span
                            v-if="error('items')"
                            :id="fieldId('items') + '-error'"
                            class="error"
                            >{{ error('items') }}</span
                        >
                    </div>
                    <div
                        v-for="(item, i) in payload.items"
                        :key="i"
                        class="mb-4 rounded-lg bg-muted/40 p-4"
                    >
                        <div class="mb-3 flex justify-between">
                            <span class="font-medium">Позиция {{ i + 1 }}</span
                            ><button
                                v-if="payload.items.length > 1"
                                class="text-sm underline"
                                @click="payload.items.splice(i, 1)"
                            >
                                Удалить позицию
                            </button>
                        </div>
                        <label
                            >Наименование<input
                                v-bind="fieldAttrs('items.' + i + '.name')"
                                v-model="item.name"
                                class="field mt-2"
                                maxlength="255"
                            /><span
                                v-if="error('items.' + i + '.name')"
                                :id="fieldId('items.' + i + '.name') + '-error'"
                                class="error"
                                >{{ error('items.' + i + '.name') }}</span
                            ></label
                        ><label class="mt-3 block"
                            >Описание (необязательно)<textarea
                                v-bind="
                                    fieldAttrs('items.' + i + '.description')
                                "
                                v-model="item.description"
                                class="field mt-2"
                                rows="2"
                                maxlength="2000"
                            /><span
                                v-if="error('items.' + i + '.description')"
                                :id="
                                    fieldId('items.' + i + '.description') +
                                    '-error'
                                "
                                class="error"
                                >{{
                                    error('items.' + i + '.description')
                                }}</span
                            >
                        </label>
                        <div class="mt-3 grid gap-4 sm:grid-cols-3">
                            <div>
                                <label :for="'quantity-' + i">Количество</label>
                                <span
                                    v-if="error('items.' + i + '.quantity')"
                                    :id="
                                        fieldId('items.' + i + '.quantity') +
                                        '-error'
                                    "
                                    class="error"
                                    >{{
                                        error('items.' + i + '.quantity')
                                    }}</span
                                >
                                <div class="relative mt-2">
                                    <input
                                        v-bind="
                                            fieldAttrs(
                                                'items.' + i + '.quantity',
                                            )
                                        "
                                        v-model="item.quantity"
                                        type="number"
                                        step="any"
                                        min="0.001"
                                        class="field quantity-input"
                                        @keydown.up.prevent="
                                            changeQuantity(item, 1)
                                        "
                                        @keydown.down.prevent="
                                            changeQuantity(item, -1)
                                        "
                                    />
                                    <div
                                        class="absolute inset-y-0 right-0 flex w-9 flex-col border-l"
                                    >
                                        <button
                                            type="button"
                                            class="flex-1 rounded-tr-lg text-xs hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring"
                                            aria-label="Увеличить количество на 1"
                                            @click="changeQuantity(item, 1)"
                                        >
                                            ▲
                                        </button>
                                        <button
                                            type="button"
                                            class="flex-1 rounded-br-lg border-t text-xs hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring"
                                            aria-label="Уменьшить количество на 1"
                                            :disabled="
                                                steppedQuantity(
                                                    item.quantity,
                                                    -1,
                                                ) === null
                                            "
                                            @click="changeQuantity(item, -1)"
                                        >
                                            ▼
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <label v-if="!isSupply"
                                >Цена за единицу<input
                                    v-bind="
                                        fieldAttrs(
                                            'items.' + i + '.price_per_unit',
                                        )
                                    "
                                    v-model="item.price_per_unit"
                                    type="text"
                                    inputmode="decimal"
                                    :name="'items[' + i + '][price_per_unit]'"
                                    class="field mt-2"
                                /><span
                                    v-if="
                                        error('items.' + i + '.price_per_unit')
                                    "
                                    :id="
                                        fieldId(
                                            'items.' + i + '.price_per_unit',
                                        ) + '-error'
                                    "
                                    class="error"
                                    >{{
                                        error('items.' + i + '.price_per_unit')
                                    }}</span
                                ></label
                            >
                            <p v-if="!isSupply" class="self-center">
                                {{
                                    money(
                                        Math.round(
                                            Number(item.quantity) *
                                                Number(
                                                    normalizeMoneyInput(
                                                        item.price_per_unit,
                                                    ),
                                                ) *
                                                100,
                                        ) / 100,
                                    )
                                }}
                                {{ payload.currency }}
                            </p>
                        </div>
                    </div>
                    <button
                        v-if="payload.items.length < 100"
                        class="rounded-lg border px-4 py-2"
                        @click="addItem"
                    >
                        Добавить позицию
                    </button>
                    <p
                        v-if="!isSupply"
                        class="mt-4 text-right text-lg font-semibold"
                    >
                        Итого: {{ money(total) }} {{ payload.currency }}
                    </p>
                </FormSection>
                <details
                    v-if="!isSupply"
                    :open="
                        payload.tranches.length > 0 ||
                        Object.keys(errors).some((k) =>
                            k.startsWith('tranches'),
                        )
                    "
                    class="rounded-xl border p-4"
                >
                    <summary class="cursor-pointer font-semibold">
                        График платежей (необязательно)
                    </summary>
                    <div :id="fieldId('tranches')" tabindex="-1">
                        <h2 class="text-lg font-semibold">График платежей</h2>
                        <span
                            v-if="error('tranches')"
                            :id="fieldId('tranches') + '-error'"
                            class="error"
                            >{{ error('tranches') }}</span
                        >
                    </div>
                    <p class="my-3 text-sm text-muted-foreground">
                        Без графика будет один платёж на всю сумму со сроком из
                        заявки.
                    </p>
                    <div
                        v-for="(t, i) in payload.tranches"
                        :key="i"
                        class="mb-4 grid gap-3 rounded-lg bg-muted/40 p-3 sm:grid-cols-3"
                    >
                        <label
                            >Сумма<input
                                v-bind="fieldAttrs('tranches.' + i + '.amount')"
                                v-model="t.amount"
                                class="field mt-2"
                                type="text"
                                inputmode="decimal"
                                :name="'tranches[' + i + '][amount]'"
                            /><span
                                v-if="error('tranches.' + i + '.amount')"
                                :id="
                                    fieldId('tranches.' + i + '.amount') +
                                    '-error'
                                "
                                class="error"
                                >{{ error('tranches.' + i + '.amount') }}</span
                            ></label
                        ><label
                            >Срок<input
                                v-bind="
                                    fieldAttrs('tranches.' + i + '.due_date')
                                "
                                v-model="t.due_date"
                                class="field mt-2"
                                type="date"
                            /><span
                                v-if="error('tranches.' + i + '.due_date')"
                                :id="
                                    fieldId('tranches.' + i + '.due_date') +
                                    '-error'
                                "
                                class="error"
                                >{{
                                    error('tranches.' + i + '.due_date')
                                }}</span
                            ></label
                        ><label
                            >Комментарий (необязательно)<input
                                v-bind="
                                    fieldAttrs('tranches.' + i + '.comment')
                                "
                                v-model="t.comment"
                                class="field mt-2"
                                maxlength="1000"
                            /><span
                                v-if="error('tranches.' + i + '.comment')"
                                :id="
                                    fieldId('tranches.' + i + '.comment') +
                                    '-error'
                                "
                                class="error"
                                >{{ error('tranches.' + i + '.comment') }}</span
                            ></label
                        ><button
                            class="text-left text-sm underline"
                            @click="payload.tranches.splice(i, 1)"
                        >
                            Удалить платёж
                        </button>
                    </div>
                    <span class="error">{{ error('tranches') }}</span
                    ><button
                        v-if="payload.tranches.length < 50"
                        class="rounded-lg border px-4 py-2"
                        @click="
                            payload.tranches.push({
                                amount: '',
                                due_date: '',
                                comment: '',
                            })
                        "
                    >
                        Добавить платёж
                    </button>
                </details>
                <FormSection id="draft-route">
                    <h2
                        :id="fieldId('approvers')"
                        tabindex="-1"
                        class="mb-3 text-lg font-semibold"
                    >
                        Маршрут согласования
                    </h2>
                    <span
                        v-if="error('approvers')"
                        :id="fieldId('approvers') + '-error'"
                        class="error"
                        >{{ error('approvers') }}</span
                    >
                    <template v-if="isSupply"
                        ><p class="text-sm text-muted-foreground">
                            Маршрут снабжения определяется автоматически по
                            проекту.
                        </p>
                        <ol class="mt-3 list-inside list-decimal">
                            <li v-for="a in supplyChain" :key="a.id">
                                {{ approverLabel(a) }}
                            </li>
                        </ol>
                        <p
                            v-if="!supplyChain.length"
                            class="mt-2 text-amber-600"
                        >
                            Для выбранного проекта маршрут пока недоступен.
                        </p></template
                    ><template v-else
                        ><select
                            class="field"
                            aria-label="Добавить согласующего"
                            @change="addApprover"
                        >
                            <option value="">Добавить согласующего</option>
                            <option
                                v-for="a in approvers.filter(
                                    (a) => !payload.approvers.includes(a.id),
                                )"
                                :key="a.id"
                                :value="a.id"
                            >
                                {{ approverLabel(a) }}
                            </option>
                        </select>
                        <ol class="mt-4 space-y-3">
                            <li
                                v-for="(id, i) in payload.approvers"
                                :key="id"
                                class="flex flex-wrap items-center gap-3"
                            >
                                <span
                                    >{{ i + 1 }}.
                                    {{
                                        approverLabel(
                                            approvers.find(
                                                (a) => a.id === Number(id),
                                            ),
                                            'Согласующий недоступен',
                                        )
                                    }}</span
                                ><button
                                    v-if="i > 0"
                                    class="text-sm underline"
                                    @click="moveApprover(i, -1)"
                                >
                                    Выше</button
                                ><button
                                    v-if="i < payload.approvers.length - 1"
                                    class="text-sm underline"
                                    @click="moveApprover(i, 1)"
                                >
                                    Ниже</button
                                ><button
                                    class="text-sm underline"
                                    @click="payload.approvers.splice(i, 1)"
                                >
                                    Убрать
                                </button>
                            </li>
                        </ol></template
                    >
                </FormSection>
                <FormSection id="draft-files">
                    <h2 class="text-lg font-semibold">Вложения и импорт PDF</h2>
                    <p class="my-3 text-sm text-muted-foreground">
                        До 20 файлов по 15 МБ: PDF, изображения, Word и Excel.
                        Для PDF можно предложить заполнение полей.
                    </p>
                    <input
                        ref="attachmentInput"
                        type="file"
                        class="hidden"
                        multiple
                        accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"
                        :disabled="busy > 0 || conflict || actionPending"
                        aria-label="Загрузить вложения"
                        @change="upload"
                    />
                    <button
                        type="button"
                        class="rounded-lg bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90 focus-visible:outline-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="busy > 0 || conflict || actionPending"
                        @click="attachmentInput?.click()"
                    >
                        Выбрать файлы
                    </button>
                    <ul
                        v-if="pendingUploads.length"
                        class="mt-4 space-y-3"
                        aria-live="polite"
                    >
                        <li
                            v-for="upload in pendingUploads"
                            :key="upload.id"
                            class="rounded-lg border border-amber-500 p-3"
                        >
                            <p class="font-medium break-words">
                                {{ upload.file.name }}
                            </p>
                            <p
                                v-if="upload.status === 'failed'"
                                class="mt-1 text-sm text-destructive"
                                role="alert"
                            >
                                Не загружено: {{ upload.error }}
                            </p>
                            <p v-else class="mt-1 text-sm">
                                {{
                                    upload.status === 'uploading'
                                        ? 'Загружаем…'
                                        : 'Ожидает загрузки…'
                                }}
                            </p>
                            <div
                                v-if="upload.status === 'failed'"
                                class="mt-3 flex flex-wrap gap-3"
                            >
                                <button
                                    type="button"
                                    class="rounded-lg border px-3 py-2 text-sm disabled:opacity-50"
                                    :disabled="
                                        busy > 0 || conflict || actionPending
                                    "
                                    @click="retryUpload(upload.id)"
                                >
                                    Повторить загрузку
                                </button>
                                <button
                                    type="button"
                                    class="rounded-lg border px-3 py-2 text-sm disabled:opacity-50"
                                    :disabled="busy > 0 || actionPending"
                                    @click="discardUpload(upload.id)"
                                >
                                    Убрать из вложений
                                </button>
                            </div>
                        </li>
                    </ul>
                    <ul class="mt-4 space-y-3">
                        <li
                            v-for="f in files"
                            :key="f.id"
                            class="flex flex-wrap gap-3"
                        >
                            <a v-if="f.url" :href="f.url" class="underline">{{
                                f.name
                            }}</a>
                            <span v-else
                                >{{ f.name }} (просмотр в Telegram)</span
                            >
                            <button
                                v-if="
                                    f.url && f.file_type === 'pdf' && !isSupply
                                "
                                type="button"
                                class="rounded-lg border bg-background px-3 py-2 text-sm font-medium hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="busy > 0 || conflict"
                                @click="analyze(f.id)"
                            >
                                Распознать PDF</button
                            ><button
                                class="text-sm underline"
                                :disabled="busy > 0"
                                @click="removeFile(f.id)"
                            >
                                Убрать
                            </button>
                        </li>
                    </ul>
                </FormSection>
            </template>
            <FormSection v-else class="space-y-5">
                <h2 class="text-lg font-semibold">
                    {{ isSupply ? 'Заявка на снабжение' : 'Расходная заявка' }}
                </h2>
                <p>
                    Проект:
                    {{
                        projects.find(
                            (p) => p.id === Number(payload.project_id),
                        )?.name || 'Не выбран'
                    }}
                </p>
                <p v-if="!isSupply">
                    Поставщик: {{ payload.supplier_name
                    }}<span v-if="payload.supplier_inn">
                        · ИНН: {{ payload.supplier_inn }}</span
                    >
                    ·
                    {{
                        payload.transaction_type === 'cash'
                            ? 'Наличные'
                            : 'Перечисление'
                    }}
                </p>
                <p>
                    Срок:
                    {{
                        payload.deadline_date ||
                        (isSupply ? 'Не указан' : 'Сегодня (на дату отправки)')
                    }}
                </p>
                <ol class="list-inside list-decimal space-y-3">
                    <li v-for="(i, n) in payload.items" :key="n">
                        {{ i.name }} · {{ i.quantity
                        }}<span v-if="!isSupply">
                            ×
                            {{
                                money(
                                    Number(
                                        normalizeMoneyInput(i.price_per_unit),
                                    ),
                                )
                            }}
                            {{ payload.currency }}</span
                        >
                        <p class="text-sm text-muted-foreground">
                            {{ i.description }}
                        </p>
                    </li>
                </ol>
                <p v-if="!isSupply" class="text-xl font-semibold">
                    Итого: {{ money(total) }} {{ payload.currency }}
                </p>
                <p>
                    Согласующие:
                    {{
                        (isSupply
                            ? supplyChain
                            : payload.approvers.map((id: number) =>
                                  approvers.find((a) => a.id === Number(id)),
                              )
                        )
                            .map((a: Option | undefined) => approverLabel(a))
                            .join(' → ')
                    }}
                </p>
                <p>Вложений: {{ files.length }}</p>
                <ul v-if="!isSupply && payload.tranches.length">
                    <li v-for="(t, i) in payload.tranches" :key="i">
                        Платёж {{ i + 1 }}:
                        {{ money(Number(normalizeMoneyInput(t.amount))) }}
                        {{ payload.currency }} · {{ t.due_date }} ·
                        {{ t.comment }}
                    </li>
                </ul>
                <p class="text-sm text-muted-foreground">
                    После отправки поля будут зафиксированы. Заявка появится в
                    панели согласующего; при привязанном Telegram бот отправит
                    уведомление.
                </p>
            </FormSection>
            <footer
                class="draft-actions flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-background p-4 shadow-lg"
            >
                <div class="text-sm">
                    <p v-if="!isSupply" class="font-semibold">
                        Итого: {{ money(total) }} {{ payload.currency }}
                    </p>
                    <p v-else>Позиций: {{ payload.items.length }}</p>
                    <p class="text-muted-foreground">
                        {{ statusText }}
                    </p>
                </div>
                <Link href="/request-drafts" class="text-sm underline"
                    >К черновикам</Link
                >
                <p
                    v-if="
                        (review || pendingUploads.length) && submitBlockReason
                    "
                    id="submit-block-reason"
                    role="status"
                    class="w-full text-sm text-amber-600"
                >
                    {{ submitBlockReason }}
                </p>
                <div class="flex w-full flex-wrap justify-end gap-3 sm:w-auto">
                    <ActionButton
                        v-if="review"
                        variant="outline"
                        @click="review = false"
                        >Вернуться к полям</ActionButton
                    >
                    <ActionButton
                        v-if="!review"
                        :busy="busy > 0 || actionPending"
                        busy-label="Проверяем…"
                        :disabled="
                            busy > 0 ||
                            conflict ||
                            actionPending ||
                            pendingUploads.length > 0
                        "
                        :aria-describedby="
                            pendingUploads.length
                                ? 'submit-block-reason'
                                : undefined
                        "
                        @click="check"
                        >Проверить заявку</ActionButton
                    >
                    <ActionButton
                        v-else
                        :busy="busy > 0 || actionPending"
                        busy-label="Отправляем…"
                        :disabled="!!submitBlockReason"
                        :aria-describedby="
                            submitBlockReason
                                ? 'submit-block-reason'
                                : undefined
                        "
                        @click="submit"
                        >Отправить на согласование</ActionButton
                    >
                </div>
            </footer>
        </AppPage></AppLayout
    >
</template>
<style scoped>
.draft-actions {
    position: fixed;
    z-index: 30;
    bottom: 1rem;
    left: 1rem;
    right: 1rem;
    max-width: 64rem;
    margin-inline: auto;
}
@media (min-width: 768px) {
    .draft-actions {
        left: calc(var(--sidebar-width, 16rem) + 1.5rem);
        right: 1.5rem;
    }
    :global(body:has([data-slot='sidebar'][data-collapsible='icon']))
        .draft-actions {
        left: calc(var(--sidebar-width-icon, 3rem) + 1.5rem);
    }
    :global(body:has([data-slot='sidebar'][data-collapsible='offcanvas']))
        .draft-actions {
        left: 1.5rem;
    }
}

section[id] {
    scroll-margin-top: 1.5rem;
}
.field {
    display: block;
    width: 100%;
    border: 1px solid var(--input);
    border-radius: 0.5rem;
    padding: 0.65rem 0.75rem;
    background: var(--background);
    color: var(--foreground);
}
.field[aria-invalid='true'] {
    border-color: var(--destructive);
}
.field:focus {
    outline: 2px solid var(--ring);
    outline-offset: 2px;
}
.quantity-input {
    padding-right: 2.75rem;
    appearance: textfield;
    -moz-appearance: textfield;
}
.quantity-input::-webkit-inner-spin-button,
.quantity-input::-webkit-outer-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
.error {
    display: block;
    margin-top: 0.3rem;
    font-size: 0.8rem;
    color: var(--destructive);
}
button:disabled {
    opacity: 0.5;
}
</style>
