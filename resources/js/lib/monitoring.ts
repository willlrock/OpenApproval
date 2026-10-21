import type { ErrorEvent } from '@sentry/vue';
import { init } from '@sentry/vue';
import type { App } from 'vue';

function scrubText(value: string): string {
    return value
        .replace(/\b\d{6,}:[A-Za-z0-9_-]{20,}\b/g, '[REDACTED]')
        .replace(/\b(Bearer|Basic)\s+[A-Za-z0-9+/=._-]+/gi, '$1 [REDACTED]')
        .replace(/([?&](?:[^=&#\s]+)=)[^&#\s]*/g, '$1[REDACTED]')
        .replace(
            /((?:password|secret|token|authorization|cookie|api[_-]?key)\s*[:=]\s*)\S+/gi,
            '$1[REDACTED]',
        )
        .slice(0, 4096);
}

export function scrubBrowserEvent(
    event: ErrorEvent,
    pathname: string,
): ErrorEvent | null {
    if (/^\/tracker(?:\/|$)/.test(pathname)) return null;

    // Allowlist the outgoing envelope; never send Inertia props, form fields,
    // breadcrumbs, request URLs, user data or Vue component state.
    return {
        type: undefined,
        event_id: event.event_id,
        timestamp: event.timestamp,
        platform: event.platform,
        level: event.level,
        environment: event.environment,
        release: event.release,
        sdk: event.sdk,
        message: event.message ? scrubText(event.message) : undefined,
        exception: event.exception
            ? {
                  values: event.exception.values?.map((exception) => ({
                      type: exception.type,
                      value: exception.value
                          ? scrubText(exception.value)
                          : undefined,
                      stacktrace: exception.stacktrace
                          ? {
                                frames: exception.stacktrace.frames?.map(
                                    (frame) => ({
                                        filename:
                                            frame.filename?.split(/[?#]/)[0],
                                        function: frame.function,
                                        lineno: frame.lineno,
                                        colno: frame.colno,
                                        in_app: frame.in_app,
                                    }),
                                ),
                            }
                          : undefined,
                  })),
              }
            : undefined,
    };
}

export function initializeMonitoring(app: App): void {
    const meta = (name: string) =>
        document.querySelector<HTMLMetaElement>(`meta[name="${name}"]`)
            ?.content;
    const dsn = meta('sentry-dsn');
    if (!dsn || /^\/tracker(?:\/|$)/.test(window.location.pathname)) return;

    try {
        init({
            app,
            dsn,
            environment: meta('sentry-environment'),
            release: meta('sentry-release') || undefined,
            attachProps: false,
            dataCollection: {
                userInfo: false,
                cookies: false,
                httpHeaders: false,
                httpBodies: [],
                urlQueryParams: false,
                databaseQueryData: false,
                queues: false,
                stackFrameVariables: false,
                frameContextLines: 0,
            },
            maxBreadcrumbs: 0,
            tracesSampleRate: 0,
            replaysSessionSampleRate: 0,
            replaysOnErrorSampleRate: 0,
            integrations: (defaults) =>
                defaults.filter(
                    (integration) =>
                        !['Breadcrumbs', 'BrowserSession'].includes(
                            integration.name,
                        ),
                ),
            beforeSend: (event) =>
                scrubBrowserEvent(event, window.location.pathname),
        });
    } catch {
        // A broken monitoring configuration must not prevent the application mounting.
        console.warn('Error monitoring could not be initialized.');
    }
}
