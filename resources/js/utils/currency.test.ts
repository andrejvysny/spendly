import { Currency } from '@/types';
import { formatAmount, formatCurrency } from './currency';

// Ensures currency formatting works for different locales

describe('formatCurrency', () => {
    it('formats EUR with symbol after value', () => {
        expect(formatCurrency(1234.5, Currency.EUR)).toBe('1.234.50 €');
    });

    it('formats USD with symbol before value', () => {
        expect(formatCurrency(99.99, Currency.USD)).toBe('$99.99');
    });
});

describe('formatAmount', () => {
    it('adds minus sign when value negative', () => {
        expect(formatAmount(-5, Currency.EUR)).toBe('-5.00 €');
    });
});
