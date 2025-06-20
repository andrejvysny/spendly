import { render, screen } from '@testing-library/react';
import BulkActionMenu from './BulkActionMenu';

describe('BulkActionMenu', () => {
    it('renders when transactions are selected', () => {
        render(<BulkActionMenu selectedTransactions={['1']} categories={[]} merchants={[]} onUpdate={jest.fn()} />);
        expect(screen.getByText(/assign category/i)).toBeInTheDocument();
    });
});
