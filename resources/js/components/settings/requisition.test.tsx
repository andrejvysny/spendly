import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import Requisition from './requisition';

const requisition = {
    id: '1',
    created: '2024-06-01',
    redirect: '',
    status: 'LN',
    institution_id: 'Bank',
    agreement: '2',
    reference: '',
    accounts: [],
    user_language: 'en',
    link: '/',
    ssn: null,
    account_selection: false,
    redirect_immediate: false,
};

describe('Requisition', () => {
    it('opens delete dialog', async () => {
        const user = userEvent.setup();
        render(<Requisition requisition={requisition} setRequisitions={jest.fn()} />);
        await user.click(screen.getByRole('button', { name: /delete/i }));
        expect(screen.getByRole('dialog')).toBeInTheDocument();
    });
});
