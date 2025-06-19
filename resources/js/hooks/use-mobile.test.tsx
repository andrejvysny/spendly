import { act, renderHook } from '@testing-library/react';
import { useIsMobile } from './use-mobile';

// Checks responsive hook reacting to viewport changes

describe('useIsMobile', () => {
    const originalMatchMedia = window.matchMedia;

    beforeEach(() => {
        window.innerWidth = 500;
        window.matchMedia = jest.fn().mockReturnValue({
            matches: true,
            addEventListener: jest.fn(),
            removeEventListener: jest.fn(),
        });
    });

    afterEach(() => {
        window.matchMedia = originalMatchMedia;
    });

    it('returns true when width below breakpoint', () => {
        const { result } = renderHook(() => useIsMobile());
        expect(result.current).toBe(true);
    });

    it('updates when width changes', () => {
        const listeners: { (): void }[] = [];
        window.innerWidth = 1000;
        window.matchMedia = jest.fn().mockReturnValue({
            matches: false,
            addEventListener: (_: string, cb: () => void) => listeners.push(cb),
            removeEventListener: jest.fn(),
        });
        const { result } = renderHook(() => useIsMobile());
        expect(result.current).toBe(false);

        act(() => {
            window.innerWidth = 500;
            listeners.forEach((cb) => cb());
        });

        expect(result.current).toBe(true);
    });
});
