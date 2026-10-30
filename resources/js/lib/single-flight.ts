export function createSingleFlight<T>() {
    const pending = new Map<string, Promise<T>>();
    return (key: string, task: () => Promise<T>): Promise<T> => {
        const existing = pending.get(key);
        if (existing) return existing;
        const request = Promise.resolve().then(task).finally(() => {
            if (pending.get(key) === request) pending.delete(key);
        });
        pending.set(key, request);
        return request;
    };
}
