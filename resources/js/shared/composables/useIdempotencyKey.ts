import { idempotent, newIdempotencyKey } from '@/shared/lib/api';

/**
 * One Idempotency-Key per form submission (audit round A), built on the shared helpers of `api.ts`. The same key is
 * sent again when the user clicks twice or retries after a network error, so the server answers with the first
 * result instead of creating a second document. Call renew() after a success so the next submission is a new one.
 */
export function useIdempotencyKey() {
    let key = newIdempotencyKey();
    return {
        /** Request config with the current key. */
        config: () => idempotent(key),
        renew: () => {
            key = newIdempotencyKey();
        },
    };
}
