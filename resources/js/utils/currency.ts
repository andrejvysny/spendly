import { Currency } from '@/types';

interface CurrencyFormat {
    symbol: string;
    symbolPosition: 'before' | 'after';
    decimalSeparator: string;
    thousandSeparator: string;
    decimalPlaces: number;
}

const currencyFormats: Record<Currency, CurrencyFormat> = {
    [Currency.EUR]: {
        symbol: '€',
        symbolPosition: 'after',
        decimalSeparator: ',',
        thousandSeparator: '.',
        decimalPlaces: 2,
    },
    [Currency.USD]: {
        symbol: '$',
        symbolPosition: 'before',
        decimalSeparator: '.',
        thousandSeparator: ',',
        decimalPlaces: 2,
    },
    [Currency.GBP]: {
        symbol: '£',
        symbolPosition: 'before',
        decimalSeparator: '.',
        thousandSeparator: ',',
        decimalPlaces: 2,
    },
    [Currency.CZK]: {
        symbol: 'Kč',
        symbolPosition: 'after',
        decimalSeparator: ',',
        thousandSeparator: ' ',
        decimalPlaces: 2,
    },
};

export const formatCurrency = (value: number, currency: string = Currency.EUR): string => {
    const format = currencyFormats[currency as Currency] || {
        symbol: currency,
        symbolPosition: 'after',
        decimalSeparator: ',',
        thousandSeparator: '.',
        decimalPlaces: 2,
    };

    // Format the number with the correct decimal and thousand separators
    const formattedNumber = Math.abs(value)
        .toLocaleString('en-US', {
            minimumFractionDigits: format.decimalPlaces,
            maximumFractionDigits: format.decimalPlaces,
            useGrouping: true,
        })
        .replace('.', format.decimalSeparator)
        .replace(/,/g, format.thousandSeparator);

    // Add the currency symbol in the correct position
    return format.symbolPosition === 'before' ? `${format.symbol}${formattedNumber}` : `${formattedNumber} ${format.symbol}`;
};

// Helper function to format amount with sign
export const formatAmount = (value: number, currency: string = Currency.EUR): string => {
    const formattedValue = formatCurrency(value, currency);
    return value < 0 ? `-${formattedValue}` : formattedValue;
};
