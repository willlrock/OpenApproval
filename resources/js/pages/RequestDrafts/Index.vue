<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import CreateRequestMenu from '@/components/CreateRequestMenu.vue';
import AppPage from '@/components/patterns/AppPage.vue';
import PageHeader from '@/components/patterns/PageHeader.vue';
import RequestStatusBadge from '@/components/patterns/RequestStatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
defineProps<{
    drafts: {
        id: string;
        name: string;
        type: string;
        updated_at: string;
        version: number;
    }[];
    submitted: {
        data: any[];
        links: { url: string | null; label: string; active: boolean }[];
    };
}>();
function removeDraft(draft: { id: string; version: number }) {
    if (window.confirm('Удалить этот черновик?'))
        router.delete('/request-drafts/' + draft.id, {
            data: { version: draft.version },
        });
}
</script>
<template>
    <Head title="Заявки и черновики" /><AppLayout
        :breadcrumbs="[
            { title: 'Заявки и черновики', href: '/request-drafts' },
        ]"
    >
        <AppPage width="detail">
            <PageHeader
                title="Заявки и черновики"
                description="Подготовьте заявку, проверьте данные и отправьте на согласование."
                ><template #actions><CreateRequestMenu /></template
            ></PageHeader>

            <section>
                <h2 class="mb-4 text-lg font-semibold">
                    Черновики · {{ drafts.length }}
                </h2>
                <p
                    v-if="!drafts.length"
                    class="rounded-xl border border-dashed p-6 text-muted-foreground"
                >
                    Сохранённые черновики появятся здесь. До отправки их видите
                    только вы.
                </p>
                <div class="grid gap-3 md:grid-cols-2">
                    <div
                        v-for="draft in drafts"
                        :key="draft.id"
                        class="rounded-xl border"
                    >
                        <Link
                            :href="'/request-drafts/' + draft.id"
                            class="block p-5 hover:bg-muted"
                            ><p class="font-medium">
                                {{ draft.name || 'Новая заявка' }}
                            </p>
                            <p class="mt-2 text-sm text-muted-foreground">
                                {{
                                    draft.type === 'supply'
                                        ? 'Снабжение'
                                        : draft.type === 'management_document'
                                          ? 'Приказ / постановление'
                                          : 'Расход'
                                }}
                                · Сохранено {{ draft.updated_at }}
                            </p></Link
                        ><button
                            @click="removeDraft(draft)"
                            class="m-4 mt-0 text-sm text-red-600 underline"
                        >
                            Удалить черновик
                        </button>
                    </div>
                </div>
            </section>
            <section>
                <h2 class="mb-4 text-lg font-semibold">Отправленные мной</h2>
                <p v-if="!submitted.data.length" class="text-muted-foreground">
                    Вы ещё не отправляли заявки.
                </p>
                <div class="divide-y rounded-xl border" v-else>
                    <Link
                        v-for="r in submitted.data"
                        :key="r.id"
                        :href="'/request-submissions/' + r.id"
                        class="flex flex-wrap justify-between gap-3 p-4 hover:bg-muted"
                        ><span>#{{ r.id }} · {{ r.name }}</span
                        ><RequestStatusBadge :status="r.status"
                    /></Link>
                </div>
                <nav
                    v-if="submitted.links.length > 3"
                    class="mt-4 flex flex-wrap gap-2"
                    aria-label="Страницы отправленных заявок"
                >
                    <template v-for="(link, i) in submitted.links" :key="i"
                        ><Link
                            v-if="link.url && !link.active"
                            :href="link.url"
                            class="inline-flex min-h-10 min-w-10 items-center justify-center rounded-lg border px-3 py-2 text-sm hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring"
                            preserve-scroll
                            >{{
                                i === 0
                                    ? '← Назад'
                                    : i === submitted.links.length - 1
                                      ? 'Вперёд →'
                                      : link.label
                            }}</Link
                        ><span
                            v-else
                            class="inline-flex min-h-10 min-w-10 items-center justify-center rounded-lg border px-3 py-2 text-sm"
                            :class="
                                link.active
                                    ? 'bg-primary font-semibold text-primary-foreground'
                                    : 'text-muted-foreground opacity-50'
                            "
                            :aria-current="link.active ? 'page' : undefined"
                            :aria-disabled="!link.active ? true : undefined"
                            >{{
                                i === 0
                                    ? '← Назад'
                                    : i === submitted.links.length - 1
                                      ? 'Вперёд →'
                                      : link.label
                            }}</span
                        ></template
                    >
                </nav>
            </section>
        </AppPage></AppLayout
    >
</template>
