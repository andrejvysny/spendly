import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import GoCardlessImportWizard from './GoCardlessImportWizard';

jest.mock('axios');
const mockedAxios = axios as jest.Mocked<typeof axios>;

mockedAxios.get.mockResolvedValue({ data: [] });

describe('GoCardlessImportWizard', () => {
    it('advances to bank selection after choosing country', async () => {
        const user = userEvent.setup();
        render(<GoCardlessImportWizard isOpen onClose={jest.fn()} onSuccess={jest.fn()} />);

        await user.click(screen.getByRole('button', { name: 'United Kingdom' }));
        await waitFor(() => expect(mockedAxios.get).toHaveBeenCalled());
        expect(screen.getByText(/select bank/i)).toBeInTheDocument();
    });
});
