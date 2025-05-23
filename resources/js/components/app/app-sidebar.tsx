import { NavFooter } from '@/components/app/sidebar/nav-footer';
import { NavMain } from '@/components/app/sidebar/nav-main';
import { NavUser } from '@/components/app/sidebar/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types/index';
import { ArrowDownTrayIcon } from '@heroicons/react/24/outline';
import { Link } from '@inertiajs/react';
import { BookOpen, Coins, LayoutGrid, PieChartIcon, ShoppingBag, TagIcon, Tags, Users } from 'lucide-react';
import AppLogo from './app-logo';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
        icon: LayoutGrid,
    },
    {
        title: 'Analytics',
        href: '/analytics',
        icon: PieChartIcon,
    },
    {
        title: 'Accounts',
        href: '/accounts',
        icon: Users,
    },
    {
        title: 'Transactions',
        href: '/transactions',
        icon: Coins,
    },
    {
        title: 'Categories',
        href: '/categories',
        icon: TagIcon,
    },
    {
        title: 'Tags',
        href: '/tags',
        icon: Tags,
    },
    {
        title: 'Merchants',
        href: '/merchants',
        icon: ShoppingBag,
    },
    {
        title: 'Rules',
        href: '/transaction-rules',
        icon: TagIcon,
    },
    {
        title: 'Imports',
        href: '/imports',
        icon: ArrowDownTrayIcon,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Documentation',
        href: 'https://github.com/andrejvysny/spendly/wiki',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
