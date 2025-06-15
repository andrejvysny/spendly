import AppLogoIcon from './app-logo-icon';

/**
 * Displays the application logo icon alongside the "Spendly" brand name with predefined styling.
 *
 * Renders a styled logo icon and the application name for use in navigation bars or headers.
 */
export default function AppLogo() {
    return (
        <>
            <div className="text-sidebar-primary-foreground flex aspect-square size-8 items-center justify-center">
                <AppLogoIcon className="size-25 fill-current text-white dark:text-black" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-none font-semibold">Spendly</span>
            </div>
        </>
    );
}
