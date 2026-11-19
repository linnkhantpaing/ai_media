export class MediaApiError extends Error {
    constructor(public code: string, public fields: Record<string, string[]> = {}, public status = 0, public offset?: number) { super(code); }
}
export async function mediaApi<T>(url: string, method = 'GET', body?: unknown, signal?: AbortSignal, extra: Record<string, string> = {}): Promise<T> {
    const raw = body instanceof Blob;
    const multipart = body instanceof FormData;
    const token = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='))?.slice(11);
    let response: Response;
    try {
        response = await fetch(url, {
            method, credentials: 'same-origin', signal, redirect: 'error',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}), ...(body !== undefined && !multipart ? { 'Content-Type': raw ? 'application/octet-stream' : 'application/json' } : {}), ...extra },
            body: body === undefined ? undefined : multipart ? body as FormData : raw ? body as Blob : JSON.stringify(body),
        });
    } catch (reason) {
        if (signal?.aborted) throw reason;
        throw new MediaApiError('network_error');
    }
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new MediaApiError(data.code || ([401, 419].includes(response.status) ? 'session_expired' : response.status === 429 ? 'rate_limited' : response.status === 422 ? 'validation_failed' : 'processing_failed'), data.errors || {}, response.status, data.offset);
    if (method !== 'GET' && method !== 'PUT' && url.startsWith('/media/')) window.dispatchEvent(new Event('media-queue-changed'));
    return data as T;
}
export function failureCode(error: unknown) { return error instanceof MediaApiError ? error.code : 'network_error'; }
export function formatBytes(bytes: number) { return bytes >= 1024 ** 3 ? (bytes / 1024 ** 3).toFixed(1).replace(/\.0$/, '') + ' GB' : bytes >= 1024 ** 2 ? (bytes / 1024 ** 2).toFixed(1) + ' MB' : Math.ceil(bytes / 1024) + ' KB'; }
export function formatTime(seconds: number) { const whole = Math.max(0, Math.floor(seconds)); return [Math.floor(whole / 3600), Math.floor(whole / 60) % 60, whole % 60].map((value) => String(value).padStart(2, '0')).join(':'); }
export const busyStatuses = ['importing', 'validating', 'queued', 'analyzing', 'translating', 'rendering', 'cancel_requested'];