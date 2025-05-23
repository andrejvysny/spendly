import { CsvUploadForm, CsvUploadFormValues } from '@/components/ui/csv-upload-form';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

interface Props {
    accounts: {
        id: string;
        name: string;
    }[];
}

export default function CsvUpload({ accounts }: Props) {
    const handleSubmit = (data: CsvUploadFormValues) => {
        console.log('Form submitted with data:', data);
        // Here you would typically handle the file upload to the server
        // For example using FormData and fetch/axios

        const formData = new FormData();
        formData.append('account', data.account);
        formData.append('delimiter', data.delimiter);
        formData.append('quoteCharacter', data.quoteCharacter);
        if (data.csvFile) {
            formData.append('csvFile', data.csvFile);
        }

        // Example of how you might send this to your backend
        // fetch('/api/upload-csv', {
        //   method: 'POST',
        //   body: formData
        // })
        // .then(response => response.json())
        // .then(data => console.log('Success:', data))
        // .catch(error => console.error('Error:', error));
    };

    return (
        <AppLayout>
            <Head title="Upload CSV" />
            <div className="container py-6">
                <CsvUploadForm onSubmit={handleSubmit} accounts={accounts} />
            </div>
        </AppLayout>
    );
}
