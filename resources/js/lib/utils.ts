import type { InertiaLinkProps } from '@inertiajs/vue3';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function approverLabel(
    user: { full_name?: string | null; title?: string | null } | undefined,
    fallback = 'Недоступен',
) {
    if (!user?.full_name) return fallback;
    return user.title ? `${user.full_name} (${user.title})` : user.full_name;
}

export function toUrl(href: NonNullable<InertiaLinkProps['href']>) {
    return typeof href === 'string' ? href : href?.url;
}
