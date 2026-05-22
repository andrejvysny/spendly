import { NavFooter } from '@/components/app/sidebar/nav-footer';
import { NavMain } from '@/components/app/sidebar/nav-main';
import { NavUser } from '@/components/app/sidebar/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types/index';
import { ArrowDownTrayIcon } from '@heroicons/react/24/outline';
import { usePage } from '@inertiajs/react';
import { Banknote, BookOpen, Coins, LayoutGrid, PieChartIcon, Repeat, Shield, ShoppingBag, TagIcon, Tags, Users } from 'lucide-react';
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
        title: 'Recurring',
        href: '/recurring',
        icon: Repeat,
    },
    {
        title: 'Budgets',
        href: '/budgets',
        icon: Banknote,
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
        title: 'Counterparties',
        href: '/counterparties',
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
    const { auth } = usePage<SharedData>().props;
    const isSuperAdmin = auth.user?.is_superadmin === true;

    const navItems = isSuperAdmin ? [...mainNavItems, { title: 'Admin', href: '/admin', icon: Shield }] : mainNavItems;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <div className="flex flex-row items-center justify-start rounded-md">
                    <AppLogoIcon className="size-20 fill-current text-[var(--foreground)] dark:text-white" />
                    <span className="text-3xl font-bold">Spendly</span>
                </div>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={navItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
