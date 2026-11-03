/** Format a sample for reading without turning decimal strings into floating-point numbers. */
export function sampleValue(value: unknown, type: unknown = null, original = false): string {
    const raw = originalSampleValue(value);
    if (original || value === null || value === undefined) return raw;
    const normalizedType = typeof type === 'string' ? type.trim().toLowerCase() : '';
    const numericType = /^(?:number|numeric|decimal|integer|int|bigint|smallint|float|double|real)(?:\(\d+(?:,\s*\d+)?\))?$/.test(normalizedType);
    if ((typeof value === 'number' && Number.isFinite(value)) || (numericType && typeof value === 'string')) {
        return decimalForReading(raw) ?? raw;
    }
    if (normalizedType === 'date' && typeof value === 'string') {
        const date = /^(\d{4})-(\d{2})-(\d{2})(?:[T ]00:00:00(?:\.0+)?Z?)?$/.exec(value);
        if (date) {
            const [, year, month, day] = date;
            const parsed = new Date(`${year}-${month}-${day}T00:00:00Z`);
            if (Number.isFinite(parsed.getTime()) && parsed.toISOString().slice(0, 10) === `${year}-${month}-${day}`) {
                return `${day}/${month}/${year}`;
            }
        }
    }
    return raw;
}

export function originalSampleValue(value: unknown): string {
    return value === null || value === undefined ? '—' : String(value);
}

function decimalForReading(value: string): string | null {
    const match = /^(-?)(0|[1-9]\d*)(?:\.(\d+))?(?:[eE]([+-]?\d{1,3}))?$/.exec(value);
    if (!match) return null;
    const [, sign, whole, fraction = '', exponent = '0'] = match;
    const shift = Number(exponent);
    if (Math.abs(shift) > 324) return null;
    const digits = whole + fraction;
    const point = whole.length + shift;
    const integer = (point <= 0 ? '0' : digits.slice(0, point).padEnd(point, '0')).replace(/^0+(?=\d)/, '');
    const decimal = (point <= 0 ? '0'.repeat(-point) + digits : digits.slice(point)).replace(/0+$/, '');
    const groups: string[] = [];
    for (let end = integer.length; end > 0; end -= 3) groups.push(integer.slice(Math.max(0, end - 3), end));
    return sign + groups.reverse().join('.') + (decimal ? ',' + decimal : '');
}
