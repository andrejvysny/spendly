import { Dialog } from '@headlessui/react';
import { XMarkIcon } from '@heroicons/react/24/outline';
import axios from 'axios';
import { useState } from 'react';

interface Institution {
    id: string;
    name: string;
    bic: string;
    logo: string;
    countries: string[];
}

interface GoCardlessImportWizardProps {
    isOpen: boolean;
    onClose: () => void;
    onSuccess: () => void;
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

export default function GoCardlessImportWizard({ isOpen, onClose, onSuccess }: GoCardlessImportWizardProps) {
    const [step, setStep] = useState(1);
    const [selectedCountry, setSelectedCountry] = useState('');
    const [selectedInstitution, setSelectedInstitution] = useState<Institution | null>(null);
    const [institutions, setInstitutions] = useState<Institution[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    const handleCountrySelect = async (countryCode: string) => {
        setSelectedCountry(countryCode);
        setLoading(true);
        setError('');

        try {
            const { data } = await axios.get(`/api/gocardless/institutions?country=${countryCode}`);
            setInstitutions(data);
            setStep(2);
        } catch (err) {
            setError('Failed to load institutions. Please try again.');
        } finally {
            setLoading(false);
        }
    };

    const handleInstitutionSelect = (institution: Institution) => {
        setSelectedInstitution(institution);
        handleSubmit(institution.id);
    };

    const handleSubmit = async (institutionId: string) => {
        setLoading(true);
        setError('');

        try {
            const { data } = await axios.post('/api/gocardless/import', {
                institution_id: institutionId,
            });

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

    return (
        <Dialog open={isOpen} onClose={() => {}} className="relative z-50">
            <div className="fixed inset-0 bg-black/30" aria-hidden="true" />

            <div className="fixed inset-0 flex items-center justify-center p-4">
                <Dialog.Panel className="mx-auto w-full max-w-2xl rounded-xl bg-gray-900 p-6">
                    <div className="mb-6 flex items-center justify-between">
                        <Dialog.Title className="text-xl font-semibold text-white">Import Account via GoCardless</Dialog.Title>
                        <button onClick={onClose} className="text-gray-400 hover:text-white">
                            <XMarkIcon className="h-6 w-6" />
                        </button>
                    </div>

                    {error && <div className="mb-4 rounded-lg border border-red-500/20 bg-red-500/10 p-3 text-sm text-red-400">{error}</div>}

                    <div className="space-y-6">
                        {loading ? (
                            <div className="flex flex-col items-center justify-center py-12">
                                <div className="h-12 w-12 animate-spin rounded-full border-4 border-blue-600 border-t-transparent"></div>
                                <p className="mt-4 text-gray-400">Loading...</p>
                            </div>
                        ) : (
                            <>
                                {/* Step 1: Country Selection */}
                                {step === 1 && (
                                    <div>
                                        <h3 className="mb-4 text-lg font-medium text-white">Select Country</h3>
                                        <div className="grid grid-cols-2 gap-4">
                                            {COUNTRIES.map((country) => (
                                                <button
                                                    key={country.code}
                                                    onClick={() => handleCountrySelect(country.code)}
                                                    className="rounded-lg bg-gray-800 p-4 text-white transition-colors hover:bg-gray-700"
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
                                        <h3 className="mb-4 text-lg font-medium text-white">Select Bank</h3>
                                        <div className="grid max-h-96 grid-cols-2 gap-4 overflow-y-auto">
                                            {institutions.map((institution) => (
                                                <button
                                                    key={institution.id}
                                                    onClick={() => handleInstitutionSelect(institution)}
                                                    className="flex items-center gap-3 rounded-lg bg-gray-800 p-4 text-white transition-colors hover:bg-gray-700"
                                                >
                                                    {institution.logo && (
                                                        <img src={institution.logo} alt={institution.name} className="h-8 w-8 object-contain" />
                                                    )}
                                                    <span>{institution.name}</span>
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                <div className="flex justify-between pt-4">
                                    {step > 1 && (
                                        <button onClick={() => setStep(step - 1)} className="px-4 py-2 text-gray-400 hover:text-white">
                                            Back
                                        </button>
                                    )}
                                    <div className="ml-auto text-gray-400">Step {step} of 2</div>
                                </div>
                            </>
                        )}
                    </div>
                </Dialog.Panel>
            </div>
        </Dialog>
    );
}
