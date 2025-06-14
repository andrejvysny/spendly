import { Icon } from '@/components/ui/icon';
import { RoundCheckbox } from '@/components/ui/round-checkbox';
import { Transaction as TransactionType } from '@/types/index';
import { formatAmount } from '@/utils/currency';
import { formatDate } from '@/utils/date';
import { useState } from 'react';
import { icons } from '../ui/icon-picker';
import TransactionDetails from './TransactionDetails';

interface Props extends TransactionType {
    isSelected?: boolean;
    onSelect?: (id: string, selected: boolean) => void;
}

export default function Transaction({ isSelected = false, onSelect, ...transaction }: Props) {
    const [isExpanded, setIsExpanded] = useState(false);

    return (
        <div className="flex flex-col">
            <div className="relative mx-auto w-full max-w-xl">
                {/* Floating Checkbox */}
                <div className="absolute top-1/2 -left-7 z-10 -translate-y-1/2">
                    <RoundCheckbox checked={isSelected} onChange={(checked) => onSelect?.(String(transaction.id), checked)} />
                </div>

                {/* Transaction Card */}
                <div className="bg-card rounded-xl border-1 p-2 shadow-xs transition-colors hover:border-current">
                    <div className="flex w-full cursor-pointer items-center gap-4" onClick={() => setIsExpanded(!isExpanded)}>
                        <div
                            className="flex h-14 w-14 items-center justify-center rounded-full p-3"
                            style={{ backgroundColor: transaction.category?.color || '#333333' }}
                            title={transaction.category?.name || 'Uncategorized'}
                        >
                            {transaction.category?.icon ? (
                                <Icon iconNode={icons[transaction.category.icon || '']} className="h-8 w-8 text-white" />
                            ) : (
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    strokeWidth={1.5}
                                    stroke="currentColor"
                                    className="h-8 w-8 text-white"
                                >
                                    <path
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z"
                                    />
                                </svg>
                            )}
                        </div>
                        <div className="flex-1">
                            <div className="font-medium">{transaction.partner || transaction.merchant?.name || transaction.type}</div>
                            <small className="text-gray-500">{formatDate(transaction.processed_date)}</small>
                            <div className="mt-1 flex gap-2">
                                {transaction.account && (
                                    <span className="bg-background rounded-full border-1 border-black px-2 py-1 text-base text-xs">
                                        {transaction.account?.name}
                                    </span>
                                )}

                                <span className="bg-background rounded-full border-1 border-black px-2 py-1 text-base text-xs">
                                    {transaction.type}
                                </span>

                                {transaction.merchant?.name && (
                                    <span
                                        className={`bg-background flex items-center rounded-full border-1 border-black ${transaction.merchant.logo ? 'h-6 p-1' : 'px-2 py-1'}`}
                                    >
                                        {transaction.merchant.logo ? (
                                            <img
                                                src={transaction.merchant.logo}
                                                alt={transaction.merchant.name}
                                                className="h-5 w-auto rounded-full object-contain"
                                            />
                                        ) : (
                                            <span className="text-xs font-bold">{transaction.merchant.name}</span>
                                        )}
                                    </span>
                                )}
                            </div>
                        </div>
                        {transaction.amount < 0 ? (
                            <div className="text-destructive-foreground text-lg font-semibold">
                                ▼ {formatAmount(transaction.amount, transaction.currency)}
                            </div>
                        ) : (
                            <div className="text-lg font-semibold text-green-500">▲ {formatAmount(transaction.amount, transaction.currency)}</div>
                        )}
                    </div>

                    {isExpanded && <TransactionDetails transaction={transaction} />}
                </div>
            </div>
        </div>
    );
}
