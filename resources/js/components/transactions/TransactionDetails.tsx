import { Transaction } from '@/types/index';
import { formatDate } from '@/utils/date';

interface Props {
    transaction: Transaction;
}

export default function TransactionDetails({ transaction }: Props) {
    return (
        <div className="bg-background mt-2 rounded-lg p-4 text-base">
            <div className="grid grid-cols-2 gap-4">
                {/* Basic Information */}
                <div>
                    <p className="text-muted-foreground text-sm">Amount</p>
                    <p className={`font-medium ${transaction.amount < 0 ? 'text-destructive-foreground' : 'text-green-500'}`}>
                        {Number(transaction.amount).toFixed(2)} {transaction.currency}
                    </p>
                </div>
                <div>
                    <p className="text-muted-foreground text-sm">Type</p>
                    <p>{transaction.type}</p>
                </div>
                <div>
                    <p className="text-muted-foreground text-sm">Description</p>
                    <p>{transaction.description || '-'}</p>
                </div>
                <div>
                    <p className="text-muted-foreground text-sm">Transaction ID</p>
                    <p>{transaction.transaction_id}</p>
                </div>

                {/* Dates */}
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
                    <p>{transaction.partner || '-'}</p>
                </div>
                <div>
                    <p className="text-muted-foreground text-sm">Place</p>
                    <p>{transaction.place || '-'}</p>
                </div>

                {/* IBAN Information */}
             
                    <div>
                        <p className="text-muted-foreground text-sm">Target IBAN</p>
                        <p>{transaction.target_iban || '-'}</p>
                    </div>
                
              
                    <div>
                        <p className="text-muted-foreground text-sm">Source IBAN</p>
                        <p>{transaction.source_iban || '-'}</p>
                    </div>
               

                {/* Notes */}
                
                    <div>
                        <p className="text-muted-foreground text-sm">Note</p>
                        <p>{transaction.note}</p>
                    </div>
              
               
                    <div>
                        <p className="text-muted-foreground text-sm">Recipient Note</p>
                        <p>{transaction.recipient_note || '-'}</p>
                    </div>
              

                {/* Balance Information */}
                <div>
                    <p className="text-muted-foreground text-sm">Balance After</p>
                    <p>
                        {Number(transaction.balance_after_transaction).toFixed(2)} {transaction.currency || '-'}
                    </p>
                </div>
            </div>

            {/* Additional Information */}
            {transaction.metadata && (
                <div className="mt-4 border-t border-gray-700 pt-4">
                    <h4 className="text-muted-foreground mb-2 text-sm">Additional Information</h4>
                    <div className="rounded-lg bg-gray-900 p-3">
                        <pre className="text-sm whitespace-pre-wrap text-gray-300">{JSON.stringify(transaction.metadata, null, 2)}</pre>
                    </div>
                </div>
            )}

            {/* Original Imported Data */}
            {transaction.import_data && (
                <div className="mt-4 border-t border-gray-700 pt-4">
                    <details className="group">
                        <summary className="flex cursor-pointer items-center justify-between">
                            <h4 className="text-muted-foreground text-sm">Original Imported Data</h4>
                            <span className="transition-transform group-open:rotate-180">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
                                    <path d="m6 9 6 6 6-6"/>
                                </svg>
                            </span>
                        </summary>
                        <div className="mt-2 rounded-lg bg-gray-900 p-3">
                            <pre className="text-sm whitespace-pre-wrap text-gray-300">
                                {(() => {
                                    try {
                                        const parsed = typeof transaction.import_data === 'string' 
                                            ? JSON.parse(transaction.import_data) 
                                            : transaction.import_data;
                                        return JSON.stringify(parsed, null, 2);
                                    } catch {
                                        return String(transaction.import_data);
                                    }
                                })()}
                            </pre>
                        </div>
                    </details>
                </div>
            )}
        </div>
    );
}
