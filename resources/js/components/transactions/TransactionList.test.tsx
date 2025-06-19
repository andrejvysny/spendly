import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import TransactionList from './TransactionList';

// Integration test covering transaction list pagination

jest.mock('@/components/transactions/Transaction', () => () => <div data-testid="transaction" />);
jest.mock('@/components/transactions/BulkActionMenu', () => () => <div data-testid="bulk-menu" />);
jest.mock('@/components/ui/loading-dots', () => ({ LoadingDots: () => <span>loading</span> }));
jest.mock('@/components/ui/button', () => ({
    Button: (props: React.ButtonHTMLAttributes<HTMLButtonElement>) => <button {...props}>{props.children}</button>,
}));

describe('TransactionList', () => {
    const sample = [
        {
            id: 1,
            transaction_id: '1',
            amount: 10,
            currency: 'EUR',
            booked_date: '2024-06-01',
            processed_date: '2024-06-01',
            description: 't',
            type: 'card',
            balance_after_transaction: 0,
            account_id: 1,
        },
    ];

    it('renders transactions and handles load more', async () => {
        const user = userEvent.setup();
        const handleLoad = jest.fn();
        render(<TransactionList transactions={sample} monthlySummaries={{}} categories={[]} merchants={[]} hasMorePages onLoadMore={handleLoad} />);

        expect(screen.getByTestId('transaction')).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: /load more/i }));
        expect(handleLoad).toHaveBeenCalled();
    });
});
