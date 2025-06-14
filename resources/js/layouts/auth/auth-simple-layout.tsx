import AppLogoIcon from '@/components/app/app-logo-icon';
import { Link } from '@inertiajs/react';
import { type PropsWithChildren } from 'react';

interface AuthLayoutProps {
    name?: string;
    title?: string;
    description?: string;
}

/**
 * Provides a centered authentication layout with a logo, app name, title, description, and custom content.
 *
 * Renders a visually prominent logo and app name at the top, followed by an accessible title, optional description, and any child elements.
 *
 * @param title - The main heading displayed for the authentication page.
 * @param description - Optional descriptive text shown below the title.
 * @returns The authentication layout as a React element.
 */
export default function AuthSimpleLayout({ children, title, description }: PropsWithChildren<AuthLayoutProps>) {
    return (
        <div className="bg-background flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div className="w-full max-w-sm">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <Link href={route('dashboard')} className="flex flex-col items-center gap-2 font-medium">
                            <div className="mb-1 flex flex-col items-center justify-center rounded-md">
                                <AppLogoIcon className="size-50 fill-current text-[var(--foreground)] dark:text-white" />
                                <span className="text-6xl font-bold">Spendly</span>
                            </div>
                            <span className="sr-only">{title}</span>
                        </Link>

                        <div className="space-y-2 text-center">
                            <h1 className="text-xl font-medium">{title}</h1>
                            <p className="text-muted-foreground text-center text-sm">{description}</p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
