import { formatCurrency } from '@/utils/currency';

interface CounterpartyItem {
    name: string;
    amount: number;
    transaction_count: number;
}

interface Props {
    counterparties: CounterpartyItem[];
    currency: string;
}

export default function TopCounterpartiesCard({ counterparties, currency }: Props) {
    if (counterparties.length === 0) {
        return (
            <div className="bg-card rounded-xl p-6 shadow-xs">
                <h3 className="mb-4 text-lg font-semibold">Top Counterparties</h3>
                <p className="text-muted-foreground text-sm">No counterparty data this month.</p>
            </div>
        );
    }

    const maxAmount = counterparties[0]?.amount || 1;

    return (
        <div className="bg-card rounded-xl p-6 shadow-xs">
            <h3 className="mb-4 text-lg font-semibold">Top Counterparties</h3>
            <div className="space-y-3">
                {counterparties.map((c, i) => (
                    <div key={i}>
                        <div className="mb-1 flex items-center justify-between text-sm">
                            <span className="font-medium">{c.name}</span>
                            <div className="flex items-center gap-2">
                                <span className="text-muted-foreground text-xs">{c.transaction_count} txn</span>
                                <span className="font-medium">{formatCurrency(c.amount, currency)}</span>
                            </div>
                        </div>
                        <div className="bg-muted h-1.5 w-full overflow-hidden rounded-full">
                            <div className="h-full rounded-full bg-orange-400 transition-all" style={{ width: `${(c.amount / maxAmount) * 100}%` }} />
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
