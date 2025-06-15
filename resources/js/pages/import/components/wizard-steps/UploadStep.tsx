import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import axios from 'axios';
import React, { useCallback, useState } from 'react';

interface UploadStepProps {
    onComplete: (data: { importId: number; headers: string[]; sampleRows: string[][]; accountId: number; totalRows: number }) => void;
}

export default function UploadStep({ onComplete }: UploadStepProps) {
    const [file, setFile] = useState<File | null>(null);
    const [accountId, setAccountId] = useState<string>('');
    const [accounts, setAccounts] = useState<{ id: number; name: string }[]>([]);
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [delimiter, setDelimiter] = useState<string>(';');
    const [quoteChar, setQuoteChar] = useState<string>('"');

    // Load accounts on component mount
    React.useEffect(() => {
        axios
            .get('/accounts')
            .then((response) => {
                setAccounts(response.data.accounts);
                if (response.data.accounts.length > 0) {
                    setAccountId(response.data.accounts[0].id.toString());
                }
            })
            .catch((err) => {
                console.error('Failed to load accounts', err);
                setError('Failed to load accounts');
            });
    }, []);

    const handleFileChange = useCallback((e: React.ChangeEvent<HTMLInputElement>) => {
        const selectedFile = e.target.files?.[0];
        if (selectedFile) {
            setFile(selectedFile);
        }
    }, []);

    const handleSubmit = useCallback(
        async (e: React.FormEvent) => {
            e.preventDefault();
            if (!file || !accountId) return;

            setIsLoading(true);
            setError(null);

            const formData = new FormData();
            formData.append('file', file);
            formData.append('account_id', accountId);
            formData.append('delimiter', delimiter);
            formData.append('quote_char', quoteChar);

            try {
                const response = await axios.post(route("imports.wizard.upload"), formData, {
                    headers: {
                        'Content-Type': 'multipart/form-data',
                    },
                });

                onComplete({
                    importId: response.data.import_id,
                    headers: response.data.headers,
                    sampleRows: response.data.sample_rows,
                    accountId: parseInt(accountId),
                    totalRows: response.data.total_rows,
                });
            } catch (err) {
                const axiosError = err as import('axios').AxiosError<{ message: string }>;
                console.error('Upload error:', axiosError);
                setError(axiosError.response?.data?.message || 'Failed to upload file');
            } finally {
                setIsLoading(false);
            }
        },
        [file, accountId, delimiter, quoteChar, onComplete],
    );

    return (
        <div className="mx-auto max-w-xl">
            <h3 className="text-foreground mb-4 text-xl font-semibold">Upload your transaction data</h3>
            <p className="text-foreground mb-6">
                Upload a CSV file containing your transaction data. We'll help you map the columns to fields in our system.
            </p>

            <form onSubmit={handleSubmit} className="text-foreground bg-card space-y-6 rounded-lg p-6 shadow-md">
                {/* Account Selection */}
                <div className="space-y-2">
                    <Label htmlFor="account">Account</Label>
                    <Select value={accountId} onValueChange={setAccountId}>
                        <SelectTrigger>
                            <SelectValue placeholder="Select an account" />
                        </SelectTrigger>
                        <SelectContent>
                            {accounts.map((account) => (
                                <SelectItem key={account.id} value={account.id.toString()}>
                                    {account.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {/* CSV Options */}
                <div className="space-y-4">
                    <h4 className="font-medium">CSV Options</h4>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <Label htmlFor="delimiter">Delimiter</Label>
                            <Select value={delimiter} onValueChange={setDelimiter}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Select delimiter" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value=",">Comma (,)</SelectItem>
                                    <SelectItem value=";">Semicolon (;)</SelectItem>
                                    <SelectItem value="\t">Tab</SelectItem>
                                    <SelectItem value="|">Pipe (|)</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="quote-char">Quote Character</Label>
                            <Select value={quoteChar} onValueChange={setQuoteChar}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Select quote character" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value='"'>Double quote (")</SelectItem>
                                    <SelectItem value="'">Single quote (')</SelectItem>
                                    <SelectItem value="none">None</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                </div>

                {/* File Upload */}
                <div className="space-y-2">
                    <Label htmlFor="file">CSV File</Label>
                    <div className="rounded-md border border-dashed border-gray-700 p-6 text-center">
                        <Input id="file" type="file" accept=".csv,.txt" onChange={handleFileChange} className="hidden" />
                        <div className="flex flex-col items-center justify-center gap-2">
                            {file ? (
                                <div className="font-medium text-green-500">{file.name}</div>
                            ) : (
                                <>
                                    <div className="text-gray-400">Drag & drop your file here, or</div>
                                    <Button type="button" variant="outline" onClick={() => document.getElementById('file')?.click()}>
                                        Browse Files
                                    </Button>
                                </>
                            )}
                        </div>
                    </div>
                    {file && (
                        <div className="flex justify-end">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => setFile(null)}
                                className="text-gray-400 hover:text-red-500"
                            >
                                Remove file
                            </Button>
                        </div>
                    )}
                </div>

                {error && <div className="rounded-md border border-red-800 bg-red-900/20 p-3 text-red-300">{error}</div>}

                <div className="flex justify-end">
                    <Button type="submit" disabled={isLoading || !file || !accountId}>
                        {isLoading ? 'Uploading...' : 'Continue to Configure'}
                    </Button>
                </div>
            </form>
        </div>
    );
}
