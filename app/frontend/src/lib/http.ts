/**
 * Fetch wrapper for our Laravel API. Every request carries a fresh App Bridge
 * session token (ID token); no cookies are used.
 *
 * The API never returns text: errors are {code, params} (validation errors per
 * field), translated here with `errorMessage()` / `fieldError()`.
 */
import i18n from 'i18next';
import { translateCode } from '@/i18n/codes';
import type { Coded } from '@/types/coded';

export class ApiError extends Error {
    constructor(
        public readonly code: string,
        public readonly status: number,
        public readonly params: Record<string, unknown> = {},
        public readonly body: unknown = null,
    ) {
        super(`${status} ${code}`);
        this.name = 'ApiError';
    }

    static fromResponse(status: number, body: unknown): ApiError {
        const b = (body ?? {}) as Partial<Coded>;
        return new ApiError(typeof b.code === 'string' ? b.code : `http_${status}`, status, b.params ?? {}, body);
    }

    /** Validation errors (422): field => first error. */
    get fieldErrors(): Record<string, Coded> {
        const errors = (this.body as { errors?: Record<string, Coded[]> } | null)?.errors ?? {};
        return Object.fromEntries(Object.entries(errors).map(([field, list]) => [field, list[0]]));
    }
}

/** Merchant-facing message for any error, in the current language. */
export function errorMessage(error: unknown): string {
    if (!(error instanceof ApiError)) return i18n.t('errors.generic');
    const params = { ...error.params };
    // A `plan` param is a plan key: show the plan's name.
    if (typeof params.plan === 'string') params.plan = translateCode('plans.names', { code: params.plan, params: {} });
    return translateCode('apiErrors', { code: error.code, params }, 'generic');
}

/** Message for a form field, from a mutation error. */
export function fieldError(error: unknown, field: string): string | undefined {
    const coded = error instanceof ApiError ? error.fieldErrors[field] : undefined;
    return coded ? translateCode('validation', coded, 'invalid') : undefined;
}

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

async function headers(): Promise<Record<string, string>> {
    // ID tokens live ~1 minute: ask App Bridge for one per request (it caches internally).
    return { Authorization: `Bearer ${await shopify.idToken()}` };
}

async function request<T>(method: Method, path: string, body?: unknown): Promise<T> {
    const response = await fetch(`/api${path}`, {
        method,
        headers: {
            Accept: 'application/json',
            ...(await headers()),
            ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });

    const data: unknown = response.status === 204 ? null : await response.json().catch(() => null);
    if (!response.ok) throw ApiError.fromResponse(response.status, data);

    return data as T;
}

/** POST multipart form data (file uploads). The browser sets the multipart boundary. */
async function postForm<T>(path: string, form: FormData): Promise<T> {
    const response = await fetch(`/api${path}`, { method: 'POST', headers: { Accept: 'application/json', ...(await headers()) }, body: form });
    const data: unknown = await response.json().catch(() => null);
    if (!response.ok) throw ApiError.fromResponse(response.status, data);

    return data as T;
}

/** Download a file from the API (auth header included) and hand it to the browser. */
/** Saves the file the API returns; resolves with the response headers (e.g. counts the file comes with). */
async function download(path: string): Promise<Headers> {
    const response = await fetch(`/api${path}`, { headers: { Accept: 'application/json', ...(await headers()) } });
    if (!response.ok) {
        throw ApiError.fromResponse(response.status, await response.json().catch(() => null));
    }
    const filename = /filename="?([^";]+)"?/.exec(response.headers.get('Content-Disposition') ?? '')?.[1] ?? 'download';
    const url = URL.createObjectURL(await response.blob());
    const a = Object.assign(document.createElement('a'), { href: url, download: filename });
    document.body.append(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
    return response.headers;
}

export const http = {
    download,
    postForm,
    get: <T>(path: string) => request<T>('GET', path),
    post: <T>(path: string, body?: unknown) => request<T>('POST', path, body),
    put: <T>(path: string, body?: unknown) => request<T>('PUT', path, body),
    patch: <T>(path: string, body?: unknown) => request<T>('PATCH', path, body),
    delete: <T>(path: string) => request<T>('DELETE', path),
};
