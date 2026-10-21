export function requestDetailHref(
    id: number,
    section: string,
    back: string,
): string {
    const query = new URLSearchParams({ section, back });
    return `/dashboard/requests/${id}?${query}`;
}
