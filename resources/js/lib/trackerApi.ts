function getCookie(name: string): string {
    const m = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop() as string) : '';
}

/**
 * Thin JSON fetch wrapper for tracker mutation endpoints. Sends Laravel's
 * XSRF token so VerifyCsrfToken accepts the request on the web guard.
 */
export async function api<T = any>(
    method: string,
    url: string,
    body?: Record<string, any>,
): Promise<T> {
    const res = await fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': getCookie('XSRF-TOKEN'),
        },
        body: body ? JSON.stringify(body) : undefined,
    });

    if (!res.ok) {
        throw new Error(`Request failed: ${res.status}`);
    }

    const text = await res.text();
    return (text ? JSON.parse(text) : {}) as T;
}

/**
 * Multipart upload helper for attachment endpoints. Lets the browser set the
 * Content-Type (with boundary); still sends the XSRF token for the web guard.
 */
export async function upload<T = any>(url: string, file: File, field = 'file'): Promise<T> {
    const form = new FormData();
    form.append(field, file);

    const res = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': getCookie('XSRF-TOKEN'),
        },
        body: form,
    });

    if (!res.ok) {
        throw new Error(`Upload failed: ${res.status}`);
    }

    const text = await res.text();
    return (text ? JSON.parse(text) : {}) as T;
}
