import { renderHook } from '@testing-library/react';
import { useInitials } from './use-initials';

// Verifies initials extraction from full names

describe('useInitials', () => {
    it('returns first and last initials', () => {
        const { result } = renderHook(() => useInitials());
        expect(result.current('John Doe')).toBe('JD');
    });
});
