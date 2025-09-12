import { NavFooter } from '@/components/app/sidebar/nav-footer';
import { NavMain } from '@/components/app/sidebar/nav-main';
import { NavUser } from '@/components/app/sidebar/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader } from '@/components/ui/sidebar';
import { type NavItem } from '@/types/index';
import { ArrowDownTrayIcon } from '@heroicons/react/24/outline';
import { BookOpen, Coins, LayoutGrid, PieChartIcon, ShoppingBag, TagIcon, Tags, Users } from 'lucide-react';
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
        href: '/rules',
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

/**
 * Renders the main application sidebar with navigation and user sections.
 *
 * Displays the app logo and name in the header, main navigation links in the content area, and footer links along with user information in the footer. The sidebar is collapsible and uses an inset variant.
 */
export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <div className="flex min-w-0 flex-row items-center justify-start gap-2 rounded-md">
                    <AppLogoIcon className="size-20 shrink-0 fill-current text-[var(--foreground)] dark:text-white" />
                    <span className="truncate text-3xl font-bold group-data-[collapsible=icon]:hidden">Spendly</span>
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
