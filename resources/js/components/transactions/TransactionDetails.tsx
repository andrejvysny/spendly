import { Transaction } from '@/types/index';
import { formatDate } from '@/utils/date';
import { icons } from '../ui/icon-picker';
import { Info, Icon, SquarePen, Save } from 'lucide-react';
import { useState } from 'react';
interface Props {
    transaction: Transaction;
}

export default function TransactionDetails({ transaction }: Props) {


    const [isEditable, setIsEditable] = useState(false);
    const [editedDescription, setEditedDescription] = useState(transaction.description || '');
    const [editedNote, setEditedNote] = useState(transaction.note || '');
    const [editedPlace, setEditedPlace] = useState(transaction.place || '');
    const [editedPartner, setEditedPartner] = useState(transaction.partner || '');

    const handleSave = () => {
        const updatedTransaction = {
            ...transaction,
            description: transaction.description?.trim() || null,
            note: transaction.note?.trim() || null,
        };

        //TODO: Implement the save logic here
        // Here you would typically send the updatedTransaction to your backend API
        // For example:
        // api.updateTransaction(updatedTransaction).then(() => {
        //     if (closeDetails) {
        //         setIsEditable(false);
        //     }
        // });

        console.log('Updated Transaction:', updatedTransaction);
        setIsEditable(false);
    }

    return (
        <div className="bg-background relative mt-2 rounded-lg p-4 text-base">
            <span className="absolute top-0 right-0 p-1">
                {isEditable ? (
                    <Save className="cursor-pointer text-yellow-600" onClick={() => handleSave()} />
                ) : (
                    <SquarePen className="text-muted-foreground hover:text-primary cursor-pointer" onClick={() => setIsEditable(true)} />
                )}
            </span>

            <div className="border-muted-foreground mb-2 grid grid-cols-1 gap-4 border-b border-dashed pb-2">
                <div>
                    <p className="text-muted-foreground text-sm">Transaction ID</p>
                    <p>{transaction.transaction_id}</p>
                </div>
                {/* Description field */}
                <div>
                    <p className="text-muted-foreground text-sm">Description</p>
                    {isEditable ? (
                        <textarea
                            rows={1}
                            value={editedDescription}
                            onChange={(e) => setEditedDescription(e.target.value)}
                            className="w-full rounded bg-gray-200 p-1 outline-0 dark:bg-gray-500"
                        />
                    ) : (
                        <p>{transaction.description || '-'}</p>
                    )}
                </div>

                {/* Note field */}
                <div>
                    <p className="text-muted-foreground text-sm">Note</p>
                    {isEditable ? (
                        <textarea
                            rows={1}
                            value={editedNote}
                            onChange={(e) => setEditedNote(e.target.value)}
                            className="w-full rounded bg-gray-200 p-1 outline-0 dark:bg-gray-500"
                        />
                    ) : (
                        <p>{transaction.note ?? '-'}</p>
                    )}
                </div>
            </div>

            {/* Dates */}
            <div className="border-muted-foreground mb-4 grid grid-cols-2 gap-4 border-b border-dashed pb-2">
                <div>
                    <p className="text-muted-foreground text-sm">Booked Date</p>
                    <p>{formatDate(transaction.booked_date)}</p>
                </div>
                <div>
                    <p className="text-muted-foreground text-sm">Processed Date</p>
                    <p>{formatDate(transaction.processed_date)}</p>
                </div>

                {/* Partner Information */}
                <div>
                    <p className="text-muted-foreground text-sm">Partner</p>
                    {isEditable ? (
                        <textarea
                            rows={1}
                            value={editedPartner}
                            onChange={(e) => setEditedPartner(e.target.value)}
                            className="w-full rounded bg-gray-200 p-1 outline-0 dark:bg-gray-500"
                        />
                    ) : (
                        <p>{transaction.partner || '-'}</p>
                    )}
                </div>


                <div>
                <p className="text-muted-foreground text-sm">Place</p>
                {isEditable ? (
                    <textarea
                        rows={1}
                        value={editedPlace}
                        onChange={(e) => setEditedPlace(e.target.value)}
                        className="w-full rounded bg-gray-200 p-1 outline-0 dark:bg-gray-500"
                    />
                ) : (
                    <p>{transaction.place || '-'}</p>
                )}
            </div>
                <div>
                    <p className="text-muted-foreground text-sm">Target IBAN</p>
                    <p>{transaction.target_iban || '-'}</p>
                </div>
                <div>
                    <p className="text-muted-foreground text-sm">Source IBAN</p>
                    <p>{transaction.source_iban || '-'}</p>
                </div>
            </div>

            <div className="border-muted-foreground mb-4 grid grid-cols-2 gap-4 border-b border-dashed pb-2">
                {/* Basic Information */}
                <div>
                    <p className="text-muted-foreground text-sm">Type</p>
                    <p>{transaction.type}</p>
                </div>
                <div>
                    <p className="text-muted-foreground text-sm">Balance After</p>
                    <p>
                        {Number(transaction.balance_after_transaction).toFixed(2)} {transaction.currency || '-'}
                    </p>
                </div>
            </div>

            {transaction.metadata && (
                    <SimpleCollapse title="Additional Information" className="mb-4">
                        <pre className="text-sm whitespace-pre-wrap text-gray-300">{JSON.stringify(transaction.metadata, null, 2)}</pre>
                    </SimpleCollapse>
            )}

            {transaction.import_data && (
                    <SimpleCollapse title={"Original Imported Data"}>
                        <pre className="text-sm whitespace-pre-wrap text-gray-300">
                                {(() => {
                                    try {
                                        const parsed =
                                            typeof transaction.import_data === 'string'
                                                ? JSON.parse(transaction.import_data)
                                                : transaction.import_data;
                                        return JSON.stringify(parsed, null, 2);
                                    } catch {
                                        return String(transaction.import_data);
                                    }
                                })()}
                            </pre>
                    </SimpleCollapse>
            )}
        </div>
    );
}


export function SimpleCollapse({ children, title, ...props }: { children: React.ReactNode; title: string }) {

    return (
        <div {...props}>
            <details className="group">
                <summary className="flex cursor-pointer items-center justify-between">
                    <h4 className="text-muted-foreground text-sm">{title}</h4>
                    <span className="transition-transform group-open:rotate-180">
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    width="24"
                                    height="24"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="2"
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    className="h-4 w-4"
                                >
                                    <path d="m6 9 6 6 6-6" />
                                </svg>
                            </span>
                </summary>
                <div className="mt-2 rounded-lg">
                    {children}
                </div>
            </details>
        </div>
    )
}

