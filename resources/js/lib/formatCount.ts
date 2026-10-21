const pluralRules = new Intl.PluralRules('ru-RU');

export function formatCount(
    count: number,
    forms: readonly [string, string, string],
): string {
    const category = pluralRules.select(count);
    return `${count} ${forms[category === 'one' ? 0 : category === 'few' ? 1 : 2]}`;
}
