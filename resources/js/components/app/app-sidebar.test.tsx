import { SidebarProvider } from '@/components/ui/sidebar';
import { render, screen } from '@testing-library/react';
import { AppSidebar } from './app-sidebar';

// Mock ESM icons to avoid Jest ESM transform issues
jest.mock('lucide-react', () => new Proxy({}, { get: () => () => null }));
jest.mock('@/components/app/sidebar/nav-main', () => ({ NavMain: () => null }));
jest.mock('@/components/app/sidebar/nav-footer', () => ({ NavFooter: () => null }));
jest.mock('@/components/app/sidebar/nav-user', () => ({ NavUser: () => null }));

describe('AppSidebar', () => {
    it('renders brand name with truncation and collapse-hiding classes', () => {
        // Mock matchMedia used by useIsMobile
        Object.defineProperty(window, 'matchMedia', {
            writable: true,
            value: jest.fn().mockImplementation((query: string) => ({
                matches: false,
                media: query,
                onchange: null,
                addEventListener: jest.fn(),
                removeEventListener: jest.fn(),
                addListener: jest.fn(),
                removeListener: jest.fn(),
                dispatchEvent: jest.fn(),
            })),
        });

        const { container } = render(
            <SidebarProvider>
                <AppSidebar />
            </SidebarProvider>,
        );

        const brand = screen.getByText('Spendly');
        expect(brand).toBeInTheDocument();
        const className = brand.getAttribute('class') ?? '';
        expect(className).toContain('truncate');
        expect(className).toContain('group-data-[collapsible=icon]:hidden');

        // Ensure the icon is present so hiding text in collapsed mode still shows a brand mark
        expect(container.querySelector('#logo_svg')).toBeInTheDocument();
    });
});
