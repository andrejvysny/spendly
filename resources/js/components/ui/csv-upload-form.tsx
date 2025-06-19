import { Button } from '@/components/ui/button';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { zodResolver } from '@hookform/resolvers/zod';
import * as React from 'react';
import { SubmitHandler, useForm } from 'react-hook-form';
import { z } from 'zod';

// Define the schema for CSV upload
const csvUploadSchema = z.object({
    account: z.string().min(1, { message: 'Please select an account' }),
    delimiter: z.string().min(1, { message: 'Please select a delimiter' }),
    quoteCharacter: z.string().min(1, { message: 'Please select a quote character' }),
    csvFile: z.instanceof(File).optional(),
});

export type CsvUploadFormValues = z.infer<typeof csvUploadSchema>;

interface CsvUploadFormProps {
    onSubmit: SubmitHandler<CsvUploadFormValues>;
    accounts: { id: string; name: string }[];
}

export function CsvUploadForm({ onSubmit, accounts }: CsvUploadFormProps) {
    const form = useForm<CsvUploadFormValues>({
        resolver: zodResolver(csvUploadSchema),
        defaultValues: {
            account: '',
            delimiter: ';',
            quoteCharacter: '"',
            csvFile: undefined,
        },
    });

    const [fileName, setFileName] = React.useState<string>('');

    const handleFileChange = (event: React.ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];
        if (file) {
            form.setValue('csvFile', file);
            setFileName(file.name);
        }
    };

    const handleDrop = (event: React.DragEvent<HTMLDivElement>) => {
        event.preventDefault();
        const file = event.dataTransfer.files?.[0];
        if (file) {
            form.setValue('csvFile', file);
            setFileName(file.name);
        }
    };

    const handleDragOver = (event: React.DragEvent<HTMLDivElement>) => {
        event.preventDefault();
    };

    return (
        <div className="mx-auto w-full max-w-3xl">
            <h1 className="mb-4 text-3xl font-bold">Upload your transaction data</h1>
            <p className="mb-8 text-lg">
                Upload a CSV file containing your transaction data. We'll help you map the columns to fields in our system.
            </p>

            <Form {...form}>
                <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-6">
                    <div>
                        <FormField
                            control={form.control}
                            name="account"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Account</FormLabel>
                                    <Select onValueChange={field.onChange} defaultValue={field.value}>
                                        <FormControl>
                                            <SelectTrigger className="w-full">
                                                <SelectValue placeholder="Select an account" />
                                            </SelectTrigger>
                                        </FormControl>
                                        <SelectContent>
                                            {accounts.map((account) => (
                                                <SelectItem key={account.id} value={account.id}>
                                                    {account.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                    </div>

                    <div>
                        <h3 className="mb-4 text-xl font-semibold">CSV Options</h3>
                        <div className="grid grid-cols-2 gap-6">
                            <FormField
                                control={form.control}
                                name="delimiter"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Delimiter</FormLabel>
                                        <Select onValueChange={field.onChange} defaultValue={field.value}>
                                            <FormControl>
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Select delimiter" />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                <SelectItem value=";">Semicolon (;)</SelectItem>
                                                <SelectItem value=",">Comma (,)</SelectItem>
                                                <SelectItem value="\t">Tab</SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />

                            <FormField
                                control={form.control}
                                name="quoteCharacter"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Quote Character</FormLabel>
                                        <Select onValueChange={field.onChange} defaultValue={field.value}>
                                            <FormControl>
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Select quote character" />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                <SelectItem value='"'>Double quote (")</SelectItem>
                                                <SelectItem value="'">Single quote (')</SelectItem>
                                                <SelectItem value="none">None</SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                    </div>

                    <div>
                        <Label>CSV File</Label>
                        <div
                            className="mt-2 cursor-pointer rounded-lg border-2 border-dashed border-gray-300 p-6 text-center"
                            onDrop={handleDrop}
                            onDragOver={handleDragOver}
                            onClick={() => document.getElementById('csvFileInput')?.click()}
                        >
                            {fileName ? (
                                <p className="text-sm">{fileName}</p>
                            ) : (
                                <>
                                    <p className="mb-2 text-lg">Drag & drop your file here</p>
                                    <p className="text-sm text-gray-500">or</p>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="mt-2"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            document.getElementById('csvFileInput')?.click();
                                        }}
                                    >
                                        Browse Files
                                    </Button>
                                </>
                            )}
                            <input id="csvFileInput" type="file" accept=".csv" className="hidden" onChange={handleFileChange} />
                        </div>
                    </div>

                    <div className="flex justify-end">
                        <Button type="submit">Continue to Configure</Button>
                    </div>
                </form>
            </Form>
        </div>
    );
}
