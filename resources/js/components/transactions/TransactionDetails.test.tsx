import { Transaction as TransactionType } from '@/types';
import { render, screen } from '@testing-library/react';
import TransactionDetails from './TransactionDetails';

const transaction: TransactionType = {
    id: 1,
    transaction_id: 't',
    amount: -10,
    currency: 'EUR',
    booked_date: '2024-06-01',
    processed_date: '2024-06-01',
    description: 'desc',
    type: 'card',
    balance_after_transaction: 0,
    account_id: 1,
};

describe('TransactionDetails', () => {
    it('renders transaction info', () => {
        render(<TransactionDetails transaction={transaction} />);
        expect(screen.getByText('desc')).toBeInTheDocument();
        expect(screen.getByText('card')).toBeInTheDocument();
    });
});
