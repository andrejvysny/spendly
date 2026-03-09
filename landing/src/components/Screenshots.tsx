import { useState } from 'react';

const tabs = [
    { id: 'dashboard', label: 'Dashboard', src: '/screenshots/dashboard.png' },
    { id: 'transactions', label: 'Transactions', src: '/screenshots/transactions_list.png' },
    { id: 'accounts', label: 'Accounts', src: '/screenshots/accounts_list.png' },
    { id: 'import', label: 'Import', src: '/screenshots/import_upload.png' },
    { id: 'categories', label: 'Categories', src: '/screenshots/categories_list.png' },
];

export default function Screenshots() {
    const [active, setActive] = useState('dashboard');
    const activeTab = tabs.find((t) => t.id === active) ?? tabs[0];

    return (
        <section id="screenshots" className="relative py-24 lg:py-32">
            <div className="mx-auto max-w-6xl px-6">
                <div className="mb-16">
                    <span className="font-mono text-xs tracking-widest text-white/30 uppercase">Screenshots</span>
                    <h2 className="mt-4 text-3xl font-bold tracking-tight text-white sm:text-4xl">See it in action</h2>
                </div>

                <div className="mb-8 flex gap-1 border-b border-white/10">
                    {tabs.map((tab) => (
                        <button
                            key={tab.id}
                            onClick={() => setActive(tab.id)}
                            className={`relative rounded-t-lg px-4 py-3 text-sm font-medium transition-colors ${
                                active === tab.id ? 'text-white' : 'text-white/30 hover:text-white/60'
                            }`}
                        >
                            {tab.label}
                            {active === tab.id && <span className="absolute right-0 bottom-0 left-0 h-px bg-white" />}
                        </button>
                    ))}
                </div>

                <div className="overflow-hidden rounded-2xl border border-white/10">
                    <div className="flex items-center gap-1.5 border-b border-white/10 px-4 py-3">
                        <div className="h-2.5 w-2.5 rounded-full bg-white/20" />
                        <div className="h-2.5 w-2.5 rounded-full bg-white/20" />
                        <div className="h-2.5 w-2.5 rounded-full bg-white/20" />
                        <span className="ml-3 font-mono text-[10px] text-white/30">spendly / {activeTab.id}</span>
                    </div>
                    <img src={activeTab.src} alt={`Spendly ${activeTab.label}`} className="w-full" loading="lazy" />
                </div>
            </div>
        </section>
    );
}
