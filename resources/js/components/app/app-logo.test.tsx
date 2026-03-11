import { render, screen } from '@testing-library/react';
import AppLogo from './app-logo';

// Ensures the brand name and logo icon render

describe('AppLogo', () => {
    it('renders brand name and icon', () => {
        render(<AppLogo />);
        expect(screen.getByText('Spendly')).toBeInTheDocument();
        expect(screen.getByAltText('Spendly')).toBeInTheDocument();
    });
});
