import axios, { isAxiosError } from 'axios';
import type { ApiKeyRow } from '@/types';

export type CreatedApiKey = { key: ApiKeyRow; secret: string };

/**
 * Create / regenerate posts as plain JSON requests (not Inertia visits): the one response carrying the secret
 * never becomes page props, browser history or session data. The caller keeps it in component state only.
 */
export async function requestSecret(url: string, data: object = {}): Promise<CreatedApiKey> {
    const res = await axios.post<CreatedApiKey>(url, data, { headers: { Accept: 'application/json' } });
    return res.data;
}

/** Laravel's 422 `errors` as `field → first message` (the shape useForm's setError takes). */
export function validationErrors(error: unknown): Record<string, string> | null {
    if (!isAxiosError(error) || error.response?.status !== 422) return null;
    const errors = (error.response.data as { errors?: Record<string, string[]> }).errors ?? {};
    return Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, v[0] ?? '']));
}

export function errorMessage(error: unknown, fallback: string): string {
    return (isAxiosError(error) && (error.response?.data as { message?: string } | undefined)?.message) || fallback;
}
