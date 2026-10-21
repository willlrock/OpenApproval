<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowUp, ArrowDown } from 'lucide-vue-next';
import { ref, computed, watch, onBeforeUnmount, nextTick } from 'vue';
import ActionButton from '@/components/patterns/ActionButton.vue';
import AppPage from '@/components/patterns/AppPage.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { approverLabel } from '@/lib/utils';
const props = defineProps<{
    draft: any;
    files: any[];
    projects: any[];
    signers: Record<string, any[]>;
}>();
const payload = ref(JSON.parse(JSON.stringify(props.draft.payload)));
const version = ref(props.draft.version),
    files = ref(props.files),
    busy = ref(false),
    dirty = ref(false),
    conflict = ref(false);
const errors = ref<string[]>([]),
    status = ref('Сохранено');
const errorSummary = ref<HTMLElement>();
const pendingAction = ref('');
const signerSearch = ref('');
const previewUrl = ref(
    props.draft.preview_version === props.draft.version
        ? '/management-drafts/' + props.draft.id + '/preview'
        : '',
);
const confirmed = ref(false);
const available = computed(
    () => props.signers[String(payload.value.project_id)] || [],
);
const filteredSigners = computed(() =>
    available.value.filter((person) =>
        approverLabel(person)
            .toLocaleLowerCase('ru')
            .includes(signerSearch.value.trim().toLocaleLowerCase('ru')),
    ),
);
function signerName(id: number): string {
    return approverLabel(
        available.value.find((person) => person.id === id),
        'Недоступный подписант',
    );
}
watch(errors, async (messages) => {
    if (!messages.length) return;
    await nextTick();
    errorSummary.value?.focus();
});
const base = '/request-drafts/' + props.draft.id;
let timer: ReturnType<typeof setTimeout>,
    queue = Promise.resolve(),
    completed = false;
async function api(url: string, method: string, body: any) {
    const csrf = decodeURIComponent(
        document.cookie
            .split('; ')
            .find((c) => c.startsWith('XSRF-TOKEN='))
            ?.slice(11) || '',
    );
    const form = body instanceof FormData;
    let response: Response;
    try {
        response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrf,
                ...(form ? {} : { 'Content-Type': 'application/json' }),
            },
            body: form ? body : JSON.stringify(body),
        });
    } catch (error) {
        errors.value = [
            'Нет связи с сервером. Проверьте подключение и повторите сохранение. Введённые данные остаются в форме.',
        ];
        throw error;
    }
    const result = await response.json().catch(() => {
        errors.value = [
            'Сервер вернул неожиданный ответ. Повторите сохранение. Введённые данные остаются в форме.',
        ];
        throw new Error('invalid response');
    });
    if (!response.ok) {
        if (response.status === 409) conflict.value = true;
        errors.value = result.errors
            ? (Object.values(result.errors).flat() as string[])
            : [result.message || 'Ошибка сохранения'];
        throw new Error('request failed');
    }
    return result;
}
async function save() {
    if (conflict.value) throw new Error('conflict');
    queue = queue
        .catch(() => {})
        .then(async () => {
            while (dirty.value) {
                const snapshot = JSON.stringify(payload.value);
                status.value = 'Сохраняем…';
                const result = await api(base, 'PUT', {
                    version: version.value,
                    payload: JSON.parse(snapshot),
                });
                version.value = result.version;
                dirty.value = snapshot !== JSON.stringify(payload.value);
                status.value = dirty.value ? 'Есть изменения' : 'Сохранено';
                errors.value = [];
            }
        });
    return queue;
}
watch(
    payload,
    () => {
        dirty.value = true;
        previewUrl.value = '';
        confirmed.value = false;
        clearTimeout(timer);
        timer = setTimeout(
            () =>
                save().catch(
                    () =>
                        (status.value = 'Не сохранено — повторите сохранение'),
                ),
            800,
        );
    },
    { deep: true },
);
async function action(fn: () => Promise<void>, kind = 'save') {
    if (busy.value || conflict.value) return;
    busy.value = true;
    pendingAction.value = kind;
    errors.value = [];
    try {
        await save();
        await fn();
    } catch {
        status.value = conflict.value
            ? 'Черновик изменён в другой вкладке. Обновите страницу.'
            : 'Не удалось выполнить действие. Проверьте ошибки.';
    } finally {
        busy.value = false;
        pendingAction.value = '';
    }
}
async function upload(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    await action(async () => {
        const data = new FormData();
        data.append('version', String(version.value));
        data.append('file', file);
        const result = await api(base + '/files', 'POST', data);
        version.value = result.version;
        files.value = result.files;
        payload.value.source_file_id = result.files[result.files.length - 1].id;
        await save();
    }, 'upload');
}
async function preview() {
    await action(async () => {
        const result = await api(
            '/management-drafts/' + props.draft.id + '/preview',
            'POST',
            { version: version.value },
        );
        previewUrl.value = result.url;
        confirmed.value = false;
    }, 'preview');
}
async function submit() {
    if (!confirmed.value || !previewUrl.value) return;
    await action(async () => {
        const result = await api(base + '/submit', 'POST', {
            version: version.value,
        });
        completed = true;
        router.visit(result.url);
    }, 'submit');
}
function move(index: number, delta: number) {
    const ids = payload.value.approvers;
    const target = index + delta;
    if (target >= 0 && target < ids.length)
        [ids[index], ids[target]] = [ids[target], ids[index]];
}
const leave = (e: BeforeUnloadEvent) => {
    if (dirty.value && !completed) {
        e.preventDefault();
        e.returnValue = '';
    }
};
window.addEventListener('beforeunload', leave);
const removeGuard = router.on('before', (e) => {
    if (
        dirty.value &&
        !completed &&
        !window.confirm('Есть несохранённые изменения. Уйти?')
    )
        e.preventDefault();
});
onBeforeUnmount(() => {
    clearTimeout(timer);
    window.removeEventListener('beforeunload', leave);
    removeGuard();
});
</script>
<template>
    <Head title="Черновик документа" /><AppLayout
        ><AppPage width="form">
            <header class="flex flex-wrap justify-between gap-4">
                <h1 class="text-2xl font-semibold">Приказ / постановление</h1>
                <span role="status" class="text-sm">{{ status }}</span>
            </header>
            <p>
                Черновик сохраняется автоматически. Изменение любого поля
                требует нового предпросмотра.
            </p>
            <div
                v-if="errors.length"
                ref="errorSummary"
                tabindex="-1"
                role="alert"
                class="rounded border border-red-400 p-4"
            >
                <p v-for="e in errors" :key="e">{{ e }}</p>
                <ActionButton
                    v-if="dirty && !conflict"
                    :busy="pendingAction === 'save'"
                    busy-label="Сохраняем…"
                    @click="action(async () => {})"
                    variant="outline"
                    class="mt-3"
                >
                    Повторить сохранение
                </ActionButton>
            </div>
            <fieldset
                :disabled="busy || conflict"
                class="space-y-5 disabled:opacity-60"
            >
                <section
                    class="grid gap-4 rounded-xl border p-5 sm:grid-cols-2"
                >
                    <label class="grid gap-2"
                        >Вид<select
                            v-model="payload.document_kind"
                            class="field"
                        >
                            <option value="order">Приказ</option>
                            <option value="resolution">Постановление</option>
                        </select></label
                    ><label class="grid gap-2"
                        >Проект<select
                            v-model="payload.project_id"
                            class="field"
                        >
                            <option value="">Выберите проект</option>
                            <option
                                v-for="p in projects"
                                :key="p.id"
                                :value="p.id"
                            >
                                {{ p.name }}
                            </option>
                        </select></label
                    ><label class="grid gap-2 sm:col-span-2"
                        >Название<input
                            v-model="payload.title"
                            maxlength="255"
                            class="field" /></label
                    ><label class="grid gap-2"
                        >Вступает в силу<select
                            v-model="payload.effective_date_mode"
                            class="field"
                        >
                            <option value="approval_date">
                                После утверждения
                            </option>
                            <option value="specific_date">
                                В указанную дату
                            </option>
                        </select></label
                    ><label
                        v-if="payload.effective_date_mode === 'specific_date'"
                        class="grid gap-2"
                        >Дата<input
                            v-model="payload.effective_date"
                            type="date"
                            class="field"
                    /></label>
                </section>
                <section class="space-y-3 rounded-xl border p-5">
                    <h2 class="font-semibold">Исходный документ</h2>
                    <a
                        :href="'/management-templates/' + payload.document_kind"
                        class="inline-block underline"
                        >Скачать шаблон Word</a
                    >
                    <p class="text-sm">
                        Загрузите заполненный DOCX, до 15 МБ. Номер и подписи
                        система добавит после утверждения.
                    </p>
                    <label
                        for="management-source"
                        class="block text-sm font-medium"
                        >Загрузить DOCX</label
                    >
                    <input
                        id="management-source"
                        type="file"
                        accept=".docx"
                        class="block w-full min-w-0 text-sm file:mr-3 file:min-h-11 file:rounded-md file:border file:px-3"
                        @change="upload"
                    /><label class="grid gap-2"
                        >Использовать файл<select
                            v-model="payload.source_file_id"
                            class="field"
                        >
                            <option value="">Выберите DOCX</option>
                            <option
                                v-for="f in files.filter(
                                    (f) => f.file_type === 'docx',
                                )"
                                :key="f.id"
                                :value="f.id"
                            >
                                {{ f.name }}
                            </option>
                        </select></label
                    >
                </section>
                <section class="space-y-3 rounded-xl border p-5">
                    <h2 class="font-semibold">
                        Подписанты в порядке согласования
                    </h2>
                    <label
                        for="management-signer-search"
                        class="block text-sm font-medium"
                        >Найти подписанта</label
                    >
                    <input
                        id="management-signer-search"
                        v-model="signerSearch"
                        type="search"
                        placeholder="Имя или должность"
                        class="field"
                    />
                    <label
                        v-for="u in filteredSigners"
                        :key="u.id"
                        class="flex min-h-11 items-center gap-2 break-words"
                        ><input
                            v-model="payload.approvers"
                            type="checkbox"
                            :value="u.id"
                        />{{ approverLabel(u) }}</label
                    >
                    <p v-if="!available.length">
                        В выбранном проекте нет доступных подписантов с
                        подписью.
                    </p>
                    <p v-else-if="!filteredSigners.length" role="status">
                        Подписанты не найдены. Измените поисковый запрос.
                    </p>
                    <ol>
                        <li
                            v-for="(id, i) in payload.approvers"
                            :key="id"
                            class="flex min-w-0 flex-wrap items-center gap-2 py-2"
                        >
                            <span class="min-w-0 flex-1 basis-40 break-words"
                                >{{ i + 1 }}. {{ signerName(id) }}</span
                            >
                            <Button
                                type="button"
                                variant="outline"
                                class="size-11 shrink-0"
                                @click="move(i, -1)"
                                :disabled="i === 0"
                                :aria-label="`Поднять ${signerName(id)}`"
                            >
                                <ArrowUp
                                    class="size-4"
                                    aria-hidden="true" /></Button
                            ><Button
                                type="button"
                                variant="outline"
                                class="size-11 shrink-0"
                                @click="move(i, 1)"
                                :disabled="i === payload.approvers.length - 1"
                                :aria-label="`Опустить ${signerName(id)}`"
                            >
                                <ArrowDown
                                    class="size-4"
                                    aria-hidden="true" /></Button
                            ><Button
                                @click="payload.approvers.splice(i, 1)"
                                type="button"
                                variant="ghost"
                                class="min-h-11"
                                :aria-label="`Убрать ${signerName(id)}`"
                            >
                                Убрать
                            </Button>
                        </li>
                    </ol>
                    <p class="text-sm text-muted-foreground">
                        Последний подписант окончательно утверждает документ.
                    </p>
                </section>
                <ActionButton
                    type="button"
                    :busy="pendingAction === 'preview'"
                    busy-label="Формируем предпросмотр…"
                    @click="preview"
                    class="max-w-full"
                >
                    Сформировать предпросмотр
                </ActionButton>
                <section v-if="previewUrl" class="space-y-4">
                    <a :href="previewUrl" target="_blank" class="underline"
                        >Открыть PDF в новой вкладке</a
                    ><iframe
                        :src="previewUrl"
                        title="Предпросмотр документа"
                        class="h-[600px] w-full rounded border"
                    /><label class="flex gap-2"
                        ><input type="checkbox" v-model="confirmed" />Проверил
                        документ и порядок подписантов</label
                    ><ActionButton
                        type="button"
                        @click="submit"
                        :disabled="!confirmed"
                        :busy="pendingAction === 'submit'"
                        busy-label="Отправляем…"
                        class="max-w-full"
                    >
                        Отправить на согласование
                    </ActionButton>
                </section>
            </fieldset>
            <Link href="/request-drafts" class="inline-block underline"
                >К черновикам</Link
            >
        </AppPage></AppLayout
    >
</template>
<style scoped>
.field {
    width: 100%;
    border: 1px solid var(--border);
    border-radius: 0.5rem;
    padding: 0.6rem;
    background: var(--background);
}
</style>
