import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';

/** Feedback is scoped to this GET queue, never to another form or module. */
export function useListFeedback(path: string) {
    const loading = ref(false);
    const error = ref('');
    let activeUrl = '';
    const matches = (url: string | undefined, method = 'get') =>
        Boolean(
            url &&
            method.toLowerCase() === 'get' &&
            new URL(url, 'http://local.invalid').pathname === path,
        );
    const isActiveResponse = (url: string | undefined, method?: string) =>
        matches(url, method) &&
        Boolean(activeUrl) &&
        new URL(url!, activeUrl).href === activeUrl;
    const removeListeners = [
        router.on('start', ({ detail: { visit } }) => {
            if (!matches(visit.url.href, visit.method)) return;
            activeUrl = visit.url.href;
            loading.value = true;
            error.value = '';
        }),
        router.on('finish', ({ detail: { visit } }) => {
            if (
                matches(visit.url.href, visit.method) &&
                activeUrl === visit.url.href
            )
                loading.value = false;
        }),
        router.on('invalid', ({ detail: { response } }) => {
            if (
                !isActiveResponse(
                    response.config.url,
                    response.config.method,
                ) ||
                response.status < 400
            )
                return;
            loading.value = false;
            error.value =
                'Не удалось обновить список. Показаны предыдущие данные. Попробуйте ещё раз.';
            return false;
        }),
        router.on('exception', ({ detail: { exception } }) => {
            const config = (
                exception as Error & {
                    config?: { url?: string; method?: string };
                }
            ).config;
            if (!isActiveResponse(config?.url, config?.method)) return;
            loading.value = false;
            error.value =
                'Нет связи с сервером. Показаны предыдущие данные. Попробуйте ещё раз.';
            return false;
        }),
    ];
    onBeforeUnmount(() => removeListeners.forEach((remove) => remove()));
    return { loading, error };
}
