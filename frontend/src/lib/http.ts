/**
 * Fetch wrapper for our Laravel API. Every request carries a fresh App Bridge
 * session token (ID token); no cookies are used.
 */
export class ApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly body: unknown = null,
    ) {
        super(message);
        this.name = 'ApiError';
    }
}

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

async function request<T>(method: Method, path: string, body?: unknown): Promise<T> {
    // ID tokens live ~1 minute: ask App Bridge for one per request (it caches internally).
    const token = await shopify.idToken();

    const response = await fetch(`/api${path}`, {
        method,
        headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${token}`,
            ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });

    const data: unknown = response.status === 204 ? null : await response.json().catch(() => null);

    if (!response.ok) {
        const message =
            (data as { message?: string } | null)?.message ??
            `Request failed (${response.status}). Please try again.`;
        throw new ApiError(message, response.status, data);
    }

    return data as T;
}

export const http = {
    get: <T>(path: string) => request<T>('GET', path),
    post: <T>(path: string, body?: unknown) => request<T>('POST', path, body),
    put: <T>(path: string, body?: unknown) => request<T>('PUT', path, body),
    patch: <T>(path: string, body?: unknown) => request<T>('PATCH', path, body),
    delete: <T>(path: string) => request<T>('DELETE', path),
};
