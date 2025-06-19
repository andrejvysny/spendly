import { formatDate, formatDateShort } from './date';

// Tests basic date formatting helpers

describe('date utilities', () => {
    const sample = '2024-05-15T13:45:00Z';

    it('formats long date', () => {
        const result = formatDate(sample);
        expect(result).toContain('2024');
        expect(result).toMatch(/15/);
    });

    it('formats short date', () => {
        const result = formatDateShort(sample);
        expect(result).toContain('2024');
        expect(result).toMatch(/15/);
    });
});
