import { Button } from '@/components/ui/button';
import { Transaction } from '@/types/index';
import { useEffect, useState } from 'react';

interface CleanStepProps {
    data: Partial<Transaction>[];
    onComplete: (cleanedData: Partial<Transaction>[]) => void;
}

export default function CleanStep({ data, onComplete }: CleanStepProps) {
    const [cleanedData, setCleanedData] = useState<Partial<Transaction>[]>(data);

    useEffect(() => {
        setCleanedData(data);
    }, [data]);

    const handleClean = () => {
        // In a real implementation, we would have functionality to clean the data here
        // For now, we'll just pass the data through unchanged
        onComplete(cleanedData);
    };

    return (
        <div className="mx-auto max-w-4xl">
            <h3 className="text-foreground mb-4 text-xl font-semibold">Clean your data</h3>
            <p className="text-foreground mb-6">Review your data before proceeding. You can remove any rows that you don't want to import.</p>

            <div className="border-foreground mb-6 overflow-x-auto rounded-lg border">
                <table className="w-full">
                    <thead className="bg-foreground border-foreground border-b">
                        <tr>
                            {Object.keys(cleanedData[0] || {})
                                .filter((key) => !key.startsWith('_'))
                                .map((key) => (
                                    <th key={key} className="px-4 py-2 text-left">
                                        {key}
                                    </th>
                                ))}
                        </tr>
                    </thead>
                    <tbody>
                        {cleanedData.map((row, index) => (
                            <tr key={index} className="border-muted-foreground border-b">
                                {Object.entries(row)
                                    .filter(([key]) => !key.startsWith('_'))
                                    .map(([key, value]) => (
                                        <td key={key} className="text-foreground truncate px-4 py-2 whitespace-nowrap">
                                            {typeof value === 'object' ? JSON.stringify(value) : value?.toString()}
                                        </td>
                                    ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="flex justify-end">
                <Button onClick={handleClean}>Continue to Category Mapping</Button>
            </div>
        </div>
    );
}
