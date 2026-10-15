import { httpErrorToHuman } from '@pterodactyl/sdk';

/**
 * Field errors from a failed request, keyed by field ("memory", "environment.VERSION"), in
 * either the Panel's error format or Laravel's.
 */
export function fieldErrors(error: unknown): Record<string, string> {
    const data = (error as { response?: { data?: unknown } })?.response?.data as
        | { errors?: unknown }
        | undefined;
    const errors = data?.errors;
    const result: Record<string, string> = {};

    if (Array.isArray(errors)) {
        for (const item of errors as { detail?: string; meta?: { source_field?: string } }[]) {
            const field = item.meta?.source_field;
            if (field && item.detail && !result[field]) result[field] = item.detail;
        }
    } else if (errors && typeof errors === 'object') {
        for (const [field, messages] of Object.entries(errors as Record<string, string[] | string>)) {
            result[field] = Array.isArray(messages) ? messages[0] : messages;
        }
    }

    return result;
}

export function errorMessage(error: unknown): string {
    return httpErrorToHuman(error);
}
