import { Dialog } from '@headlessui/react';
import { XMarkIcon } from '@heroicons/react/24/outline';
import axios from 'axios';
import { useState } from 'react';

interface Institution {
    id: string;
    name: string;
    bic: string;
    logo_url?: string;
    logo?: string;
    countries: string[];
}

interface GoCardlessImportWizardProps {
    isOpen: boolean;
    onClose: () => void;
    onSuccess: () => void;
    /** When true, render only inner content (no Dialog); parent provides the dialog wrapper. */
    embed?: boolean;
    /** When 'accounts', callback after GoCardless auth will redirect to accounts page instead of bank data settings. */
    returnTo?: 'accounts' | 'bank_data';
}

const COUNTRIES = [
    { code: 'GB', name: 'United Kingdom' },
    { code: 'DE', name: 'Germany' },
    { code: 'FR', name: 'France' },
    { code: 'ES', name: 'Spain' },
    { code: 'IT', name: 'Italy' },
    { code: 'NL', name: 'Netherlands' },
    { code: 'BE', name: 'Belgium' },
    { code: 'SE', name: 'Sweden' },
    { code: 'NO', name: 'Norway' },
    { code: 'DK', name: 'Denmark' },
    { code: 'IE', name: 'Ireland' },
    { code: 'AT', name: 'Austria' },
    { code: 'SK', name: 'Slovakia' },
    { code: 'CZ', name: 'Czech Republic' },
    { code: 'PL', name: 'Poland' },
    { code: 'RO', name: 'Romania' },
    { code: 'HU', name: 'Hungary' },
    { code: 'BG', name: 'Bulgaria' },
];

/**
 * Displays a two-step modal wizard for connecting bank accounts via GoCardless.
 *
 * Guides users through selecting a country and then a bank institution, initiating the GoCardless requisition process upon selection. On successful initiation, triggers the provided success and close callbacks, then redirects the user to the GoCardless authorization link.
 *
 * @param isOpen - Whether the modal is visible.
 * @param onClose - Callback to close the modal.
 * @param onSuccess - Callback invoked after a successful import initiation.
 */
export default function GoCardlessImportWizard({ isOpen, onClose, onSuccess, embed = false, returnTo }: GoCardlessImportWizardProps) {
    const [step, setStep] = useState(1);
    const [institutions, setInstitutions] = useState<Institution[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    const handleCountrySelect = async (countryCode: string) => {
        setLoading(true);
        setError('');

        try {
            const { data } = await axios.get(`/api/bank-data/gocardless/institutions?country=${countryCode}`);
            setInstitutions(data);
            setStep(2);
        } catch {
            setError('Failed to load institutions. Please try again.');
        } finally {
            setLoading(false);
        }
    };

    const handleInstitutionSelect = (institution: Institution) => {
        handleSubmit(institution.id);
    };

    const handleSubmit = async (institutionId: string) => {
        setLoading(true);
        setError('');

        try {
            const body: { institution_id: string; return_to?: string } = { institution_id: institutionId };
            if (returnTo) {
                body.return_to = returnTo;
            }
            const { data } = await axios.post('/api/bank-data/gocardless/requisitions', body);

            onSuccess();
            onClose();

            window.location.href = data.link;
        } catch (err) {
            console.error(err);
            setError('Failed to import account. Please try again.');
        } finally {
            setLoading(false);
        }
    };

    const content = (
        <>
            {!embed && (
                <div className="mb-6 flex items-center justify-between">
                    <Dialog.Title className="text-foreground text-xl font-semibold">Connect Bank Accounts via GoCardless</Dialog.Title>
                    <button onClick={onClose} className="hover:text-foreground text-gray-400">
                        <XMarkIcon className="h-6 w-6" />
                    </button>
                </div>
            )}
            {embed && (
                <div className="mb-4 flex items-center justify-between">
                    <h3 className="text-foreground text-lg font-semibold">Connect new bank</h3>
                    <button onClick={onClose} type="button" className="text-muted-foreground hover:text-foreground text-sm">
                        Back to list
                    </button>
                </div>
            )}

            {error && <div className="mb-4 rounded-lg border border-red-500/20 bg-red-500/10 p-3 text-sm text-red-400">{error}</div>}

            <div className="space-y-6">
                {loading ? (
                    <div className="flex flex-col items-center justify-center py-12">
                        <div className="border-foreground h-12 w-12 animate-spin rounded-full border-4 border-t-transparent"></div>
                        <p className="text-muted-foreground mt-4">Loading...</p>
                    </div>
                ) : (
                    <>
                        {/* Step 1: Country Selection */}
                        {step === 1 && (
                            <div>
                                <h3 className="text-foreground mb-4 text-lg font-medium">Select Country</h3>
                                <div className="grid max-h-96 grid-cols-2 gap-4 overflow-y-auto">
                                    {COUNTRIES.map((country) => (
                                        <button
                                            key={country.code}
                                            onClick={() => handleCountrySelect(country.code)}
                                            className="bg-muted text-foreground hover:bg-card cursor-pointer rounded-lg border-1 p-4 transition-colors hover:border-black"
                                        >
                                            {country.name}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* Step 2: Institution Selection */}
                        {step === 2 && (
                            <div>
                                <h3 className="text-foreground mb-4 text-lg font-medium">Select Bank</h3>
                                <div className="grid max-h-96 grid-cols-2 gap-4 overflow-y-auto">
                                    {institutions.map((institution) => (
                                        <button
                                            key={institution.id}
                                            onClick={() => handleInstitutionSelect(institution)}
                                            className="bg-muted text-foreground hover:bg-card flex cursor-pointer items-center gap-3 rounded-lg border-1 p-4 transition-colors hover:border-black"
                                        >
                                            {(institution.logo_url ?? institution.logo) && (
                                                <img
                                                    src={institution.logo ?? institution.logo_url ?? ''}
                                                    alt={institution.name}
                                                    className="h-8 w-8 object-contain"
                                                />
                                            )}
                                            <span>{institution.name}</span>
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}

                        <div className="flex justify-between pt-4">
                            {step > 1 && (
                                <button onClick={() => setStep(step - 1)} className="text-muted-foreground hover:text-foreground px-4 py-2">
                                    Back
                                </button>
                            )}
                            <div className="ml-auto text-gray-400">Step {step} of 2</div>
                        </div>
                    </>
                )}
            </div>
        </>
    );

    if (embed) {
        return content;
    }

    return (
        <Dialog open={isOpen} onClose={() => {}} className="relative z-50">
            <div className="fixed inset-0 bg-black/30" aria-hidden="true" />

            <div className="fixed inset-0 flex items-center justify-center p-4">
                <Dialog.Panel className="bg-card mx-auto w-full max-w-2xl rounded-xl p-6">{content}</Dialog.Panel>
            </div>
        </Dialog>
    );
}
