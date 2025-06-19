import { render, screen } from '@testing-library/react';
import AppLogo from './app-logo';

// Ensures the brand name and logo icon render

describe('AppLogo', () => {
    it('renders brand name and icon', () => {
        const { container } = render(<AppLogo />);
        expect(screen.getByText('Spendly')).toBeInTheDocument();
        expect(container.querySelector('#logo_svg')).toBeInTheDocument();
    });
});
