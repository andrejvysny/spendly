import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import ConfirmStep from './ConfirmStep';

const post = jest.fn((_route: string, { onSuccess }: { onSuccess: () => void }) => onSuccess());

jest.mock('@inertiajs/react', () => ({
    useForm: () => ({
        data: {},
        setData: jest.fn(),
        post,
        processing: false,
        errors: {},
    }),
}));

describe('ConfirmStep', () => {
    const importData = { id: 1, original_filename: 'file.csv', total_rows: 10 };

    it('renders import details and handles submit', async () => {
        const user = userEvent.setup();
        const onBack = jest.fn();
        const onComplete = jest.fn();

        // stub ziggy route helper
        (global as unknown as { route: () => string }).route = () => 'imports.process';

        render(<ConfirmStep importData={importData} onBack={onBack} onComplete={onComplete} />);

        expect(screen.getByText('file.csv')).toBeInTheDocument();
        expect(screen.getByText('10 records')).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: /start import/i }));

        expect(post).toHaveBeenCalled();
        expect(onComplete).toHaveBeenCalled();
    });
});
