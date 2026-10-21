<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    LayoutGrid,
    FileText,
    Users,
    FolderOpen,
    Clock,
    UserCheck,
    Building2,
    Package,
    Wallet,
    Banknote,
    Store,
    ClipboardCheck,
    Inbox,
    SquareKanban,
} from 'lucide-vue-next';
import { computed } from 'vue';
import NavMain from '@/components/NavMain.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import type { NavItem } from '@/types';
import AppLogo from './AppLogo.vue';

const page = usePage();
const userRole = computed(
    () => (page.props.auth as any)?.user?.role ?? 'admin',
);

const canViewApprovalDashboard = computed(
    () => (page.props.auth as any)?.user?.can_view_approval_dashboard ?? false,
);

const supplierDashboardItems: NavItem[] = [
    { title: 'Обзор', href: '/dashboard', icon: LayoutGrid },
    { title: 'Все заявки', href: '/dashboard/requests', icon: FileText },
    { title: 'Мои заявки', href: '/dashboard/my-requests', icon: Inbox },
    {
        title: 'На согласование',
        href: '/dashboard/my-approvals',
        icon: ClipboardCheck,
    },
];

const adminNavItems: NavItem[] = [
    {
        title: 'Обзор',
        href: '/dashboard',
        icon: LayoutGrid,
    },
    {
        title: 'Все заявки',
        href: '/dashboard/requests',
        icon: FileText,
    },
    {
        title: 'Мои заявки',
        href: '/dashboard/my-requests',
        icon: Inbox,
    },
    {
        title: 'На согласование',
        href: '/dashboard/my-approvals',
        icon: ClipboardCheck,
    },
    {
        title: 'Запросы доступа',
        href: '/dashboard/registrations',
        icon: UserCheck,
    },
    {
        title: 'Пользователи',
        href: '/dashboard/users',
        icon: Users,
    },
    {
        title: 'Поставщики',
        href: '/dashboard/suppliers',
        icon: Store,
    },
    {
        title: 'Департаменты',
        href: '/dashboard/departments',
        icon: Building2,
    },
    {
        title: 'Проекты',
        href: '/dashboard/projects',
        icon: FolderOpen,
    },
    {
        title: 'Логи',
        href: '/dashboard/logs',
        icon: Clock,
    },
];

const supplyNavItems: NavItem[] = [
    {
        title: 'Обзор снабжения',
        href: '/supply',
        icon: LayoutGrid,
    },
    {
        title: 'Очередь снабжения',
        href: '/supply/requests',
        icon: Package,
    },
];

const mainNavItems = computed<NavItem[]>(() => {
    const role = userRole.value;

    if (role === 'superadmin') {
        return [
            ...adminNavItems,
            ...supplyNavItems,
            { title: 'Оплаты', href: '/finance/requests', icon: Banknote },
        ];
    }

    if (role === 'admin') {
        return [
            ...adminNavItems,
            { title: 'Оплаты', href: '/finance/requests', icon: Banknote },
        ];
    }

    if (role === 'supplier') {
        return [
            ...supplyNavItems,
            {
                title: 'Поставщики',
                href: '/dashboard/suppliers',
                icon: Store,
            },
            ...(canViewApprovalDashboard.value ? supplierDashboardItems : []),
        ];
    }

    // accountant / treasurer can see dashboard + requests + finance panel
    if (role === 'accountant' || role === 'treasurer') {
        return [
            {
                title: 'Обзор',
                href: '/dashboard',
                icon: LayoutGrid,
            },
            {
                title: 'Все заявки',
                href: '/dashboard/requests',
                icon: FileText,
            },
            {
                title: 'Мои заявки',
                href: '/dashboard/my-requests',
                icon: Inbox,
            },
            {
                title: 'На согласование',
                href: '/dashboard/my-approvals',
                icon: ClipboardCheck,
            },
            {
                title: 'Оплаты',
                href: '/finance/requests',
                icon: role === 'accountant' ? Wallet : Banknote,
            },
        ];
    }

    if (!canViewApprovalDashboard.value) return [];

    // The server scopes visible requests and actions for each approved user.
    const items: NavItem[] = [
        {
            title: 'Обзор',
            href: '/dashboard',
            icon: LayoutGrid,
        },
        {
            title: 'Все заявки',
            href: '/dashboard/requests',
            icon: FileText,
        },
        {
            title: 'Мои заявки',
            href: '/dashboard/my-requests',
            icon: Inbox,
        },
    ];

    if (role === 'approver') {
        items.push({
            title: 'На согласование',
            href: '/dashboard/my-approvals',
            icon: ClipboardCheck,
        });
    }

    return items;
});

const groupedNavigation = computed(() => {
    const items = mainNavItems.value.filter(
        (i) => i.href !== '/dashboard/my-requests',
    );
    const administration = [
        '/dashboard/users',
        '/dashboard/registrations',
        '/dashboard/logs',
    ];
    const directories = [
        '/dashboard/suppliers',
        '/dashboard/departments',
        '/dashboard/projects',
    ];
    return [
        {
            title: 'Работа',
            items: [
                {
                    title: 'Заявки и черновики',
                    href: '/request-drafts',
                    icon: Inbox,
                },
                ...items.filter(
                    (i) =>
                        String(i.href).startsWith('/dashboard') &&
                        !administration.includes(String(i.href)) &&
                        !directories.includes(String(i.href)),
                ),
            ],
        },
        {
            title: 'Снабжение',
            items: items.filter((i) => String(i.href).startsWith('/supply')),
        },
        {
            title: 'Оплаты',
            items: items.filter((i) => String(i.href).startsWith('/finance')),
        },
        {
            title: 'Справочники',
            items: items.filter((i) => directories.includes(String(i.href))),
        },
        {
            title: 'Администрирование',
            items: items.filter((i) => administration.includes(String(i.href))),
        },
    ].filter((g) => g.items.length);
});

const dashboardHref = computed(() => {
    return userRole.value === 'supplier'
        ? '/supply'
        : canViewApprovalDashboard.value
          ? '/dashboard'
          : '/request-drafts';
});

const hasTrackerAccess = computed(
    () => (page.props.auth as any)?.user?.tracker?.has_access ?? false,
);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboardHref">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain
                v-for="group in groupedNavigation"
                :key="group.title"
                :title="group.title"
                :items="group.items"
            />

            <SidebarMenu v-if="hasTrackerAccess" class="mt-auto">
                <SidebarMenuItem>
                    <SidebarMenuButton as-child>
                        <Link href="/tracker">
                            <SquareKanban />
                            <span>Task Tracker</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarContent>
    </Sidebar>
    <slot />
</template>
