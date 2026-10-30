export function createRequestGuard() {
    let generation = 0;
    const current = () => {
        const expected = generation;
        return () => expected === generation;
    };

    return {
        current,
        next() { generation++; return current(); },
        invalidate() { generation++; },
    };
}
