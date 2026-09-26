/**
 * Calls to the Clear Stock API from an admin extension. Relative URLs resolve against
 * the app URL and Shopify adds the ID token (Authorization header) itself. The API
 * never returns text: errors are {code, params}, translated by the extension.
 */
export class ApiError extends Error {
    constructor(
        public readonly code: string,
        public readonly status: number,
    ) {
        super(`${status} ${code}`);
    }
}

export async function api<T>(path: string, init: { method?: 'GET' | 'POST'; body?: unknown } = {}): Promise<T> {
    let response: Response;
    try {
        response = await fetch(`api/${path}`, {
            method: init.method ?? 'GET',
            headers: { Accept: 'application/json', ...(init.body !== undefined ? { 'Content-Type': 'application/json' } : {}) },
            body: init.body !== undefined ? JSON.stringify(init.body) : undefined,
        });
    } catch {
        throw new ApiError('network_error', 0);
    }
    const body = await response.json().catch(() => null);
    if (!response.ok) throw new ApiError(typeof body?.code === 'string' ? body.code : `http_${response.status}`, response.status);
    return (body as { data: T }).data;
}
