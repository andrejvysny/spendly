import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import React from 'react';
import { FormProvider, useForm } from 'react-hook-form';
import CreateAccountModal from './CreateAccountModal';

const submitMock = jest.fn();

jest.mock('@/components/ui/form-modal', () => ({
    FormModal: ({
        children,
        onSubmit,
        defaultValues = {},
    }: {
        children: () => React.ReactNode;
        onSubmit: (values: unknown) => void;
        defaultValues?: Record<string, unknown>;
    }) => {
        const methods = useForm({ defaultValues });
        return (
            <FormProvider {...methods}>
                <form data-testid="modal" onSubmit={methods.handleSubmit(onSubmit)}>
                    {children()}
                    <button type="submit">submit</button>
                </form>
            </FormProvider>
        );
    },
}));

describe('CreateAccountModal', () => {
    it('renders when open and submits', async () => {
        const user = userEvent.setup();
        render(<CreateAccountModal isOpen onClose={jest.fn()} onSubmit={submitMock} />);

        expect(screen.getByTestId('modal')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: /submit/i }));
        expect(submitMock).toHaveBeenCalled();
    });
});
