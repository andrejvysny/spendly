import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import { type BreadcrumbItem } from '@/types';
import { usePage } from '@inertiajs/react';
import { useEffect, useRef, type ReactNode } from 'react';
import { toast, ToastContainer } from 'react-toastify';
import 'react-toastify/dist/ReactToastify.css';

interface AppLayoutProps {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
}

type FlashProps = {
    flash?: {
        success?: string | null;
        error?: string | null;
    };
};

/**
 * Renders the flash messages HandleInertiaRequests shares on every response.
 *
 * These were previously shared and never read by anything: the whole GoCardless bank-connect
 * callback reports its outcome this way — connected, cancelled, link already used, failed to
 * fetch accounts — so a user returning from their bank landed back on the page with no feedback
 * at all. Lives in the layout rather than per-page so any controller `->with('success'|'error')`
 * is surfaced.
 */
function useFlashToasts(): void {
    const { flash } = usePage<FlashProps>().props;
    // Inertia keeps the flash in props until the next visit, so re-renders would re-fire the
    // toast. Keyed on the message itself: the same text arriving again is a new event.
    const lastShown = useRef<string | null>(null);

    useEffect(() => {
        const success = flash?.success;
        const error = flash?.error;
        const message = error ?? success;

        if (!message) {
            lastShown.current = null;
            return;
        }

        const key = `${error ? 'error' : 'success'}:${message}`;
        if (lastShown.current === key) {
            return;
        }
        lastShown.current = key;

        if (error) {
            toast.error(error);
        } else {
            toast.success(success as string);
        }
    }, [flash?.success, flash?.error]);
}

export default ({ children, breadcrumbs, ...props }: AppLayoutProps) => {
    useFlashToasts();

    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs} {...props}>
            <ToastContainer
                position="top-center"
                autoClose={3000}
                hideProgressBar={false}
                newestOnTop
                closeOnClick
                rtl={false}
                pauseOnFocusLoss
                draggable
                pauseOnHover
                theme="light"
                className="toast-container"
                toastClassName="toast-notification"
            />
            {children}
        </AppLayoutTemplate>
    );
};
