import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import Transaction from './Transaction';

jest.mock('./TransactionDetails', () => () => <div data-testid="details" />);

const baseTransaction = {
    id: 1,
    transaction_id: 'trx',
    amount: -10,
    currency: 'EUR',
    booked_date: '2024-06-01',
    processed_date: '2024-06-01',
    description: 'd',
    type: 'card',
    balance_after_transaction: 0,
    account_id: 1,
    partner: 'Partner',
};

describe('Transaction', () => {
    it('toggles details on click', async () => {
        const user = userEvent.setup();
        render(<Transaction {...baseTransaction} />);
        const card = screen.getByText('Partner').closest('div');
        expect(screen.queryByTestId('details')).not.toBeInTheDocument();
        if (card) {
            await user.click(card);
        }
        expect(screen.getByTestId('details')).toBeInTheDocument();
    });
});
