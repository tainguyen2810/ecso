/**
 * One Idempotency-Key per form submission (audit round A). The same key is sent again when the user clicks twice or
 * retries after a network error, so the server answers with the first result instead of creating a second
 * document. Call renew() after a success so the next submission is a new one.
 */
export function newIdempotencyKey(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
}

export function useIdempotencyKey() {
    let key = newIdempotencyKey();
    return {
        /** Request config with the current key. */
        config: () => ({ headers: { 'Idempotency-Key': key } }),
        renew: () => {
            key = newIdempotencyKey();
        },
    };
}
