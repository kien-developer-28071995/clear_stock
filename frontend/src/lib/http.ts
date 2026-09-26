/**
 * Fetch wrapper for our Laravel API. Every request carries a fresh App Bridge
 * session token (ID token); no cookies are used. Accept-Language tells the API
 * which language to answer in (validation messages, explanations).
 */
import i18n from 'i18next';
import { currentLocale } from '@/i18n';

export class ApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly body: unknown = null,
    ) {
        super(message);
        this.name = 'ApiError';
    }

    /** Laravel validation errors (422): field => first message. */
    get fieldErrors(): Record<string, string> {
        const errors = (this.body as { errors?: Record<string, string[]> } | null)?.errors ?? {};
        return Object.fromEntries(Object.entries(errors).map(([field, messages]) => [field, messages[0]]));
    }
}

/** Message for a form field, from a mutation error. */
export function fieldError(error: unknown, field: string): string | undefined {
    return error instanceof ApiError ? error.fieldErrors[field] : undefined;
}

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

async function request<T>(method: Method, path: string, body?: unknown): Promise<T> {
    // ID tokens live ~1 minute: ask App Bridge for one per request (it caches internally).
    const token = await shopify.idToken();

    const response = await fetch(`/api${path}`, {
        method,
        headers: {
            Accept: 'application/json',
            'Accept-Language': currentLocale(),
            Authorization: `Bearer ${token}`,
            ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });

    const data: unknown = response.status === 204 ? null : await response.json().catch(() => null);

    if (response.status === 422) {
        throw new ApiError(i18n.t('errors.checkFields'), 422, data);
    }

    if (!response.ok) {
        const message =
            (data as { message?: string } | null)?.message ??
            i18n.t('errors.requestFailed', { status: response.status });
        throw new ApiError(message, response.status, data);
    }

    return data as T;
}

/** Download a file from the API (auth header included) and hand it to the browser. */
async function download(path: string): Promise<void> {
    const token = await shopify.idToken();
    const response = await fetch(`/api${path}`, {
        headers: { Authorization: `Bearer ${token}`, 'Accept-Language': currentLocale() },
    });
    if (!response.ok) {
        const data = await response.json().catch(() => null);
        throw new ApiError((data as { message?: string } | null)?.message ?? i18n.t('errors.downloadFailed'), response.status, data);
    }
    const filename = /filename="?([^";]+)"?/.exec(response.headers.get('Content-Disposition') ?? '')?.[1] ?? 'download';
    const url = URL.createObjectURL(await response.blob());
    const a = Object.assign(document.createElement('a'), { href: url, download: filename });
    document.body.append(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

export const http = {
    download,
    get: <T>(path: string) => request<T>('GET', path),
    post: <T>(path: string, body?: unknown) => request<T>('POST', path, body),
    put: <T>(path: string, body?: unknown) => request<T>('PUT', path, body),
    patch: <T>(path: string, body?: unknown) => request<T>('PATCH', path, body),
    delete: <T>(path: string) => request<T>('DELETE', path),
};
