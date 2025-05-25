import { NavFooter } from '@/components/app/sidebar/nav-footer';
import { NavMain } from '@/components/app/sidebar/nav-main';
import { NavUser } from '@/components/app/sidebar/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types/index';
import { ArrowDownTrayIcon } from '@heroicons/react/24/outline';
import { Link } from '@inertiajs/react';
import { BookOpen, Coins, LayoutGrid, PieChartIcon, ShoppingBag, TagIcon, Tags, Users } from 'lucide-react';
import AppLogo from './app-logo';
import AppLogoIcon from './app-logo-icon';

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
                
            <div className="flex flex-row items-center justify-start rounded-md">
                                <AppLogoIcon className="size-20 fill-current text-[var(--foreground)] dark:text-white" />
                                <span className="text-3xl font-bold">Spendly</span>
                            </div>
              
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
