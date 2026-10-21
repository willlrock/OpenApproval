export type SupplierOption = { id: number; name?: string; inn?: string };

export function normalizeMoneyInput(value: unknown): string {
    return String(value ?? '')
        .replace(/\s/g, '')
        .replace(',', '.');
}

export function matchingSuppliers<T extends SupplierOption>(
    suppliers: T[],
    query: string,
): T[] {
    const words = query.trim().toLocaleLowerCase('ru-RU').split(/\s+/);
    if (!words[0]) return [];
    return suppliers.filter((supplier) => {
        const text =
            `${supplier.name ?? ''} ${supplier.inn ?? ''}`.toLocaleLowerCase(
                'ru-RU',
            );
        return words.every((word) => text.includes(word));
    });
}

// A step must never silently replace an invalid result with a tiny quantity.
export function steppedQuantity(
    value: string | number,
    delta: number,
): number | null {
    const current = Number(value);
    if (!Number.isFinite(current)) return null;
    const next = Math.round((current + delta) * 1000) / 1000;
    return next >= 0.001 && next <= 100000000 ? next : null;
}
