import { render, screen } from '@testing-library/react';
import { Line } from 'react-chartjs-2';
import AccountDetailMonthlyComparisonChart from './AccountDetailMonthlyComparisonChart';

jest.mock('react-chartjs-2', () => ({
    Line: jest.fn(() => <div data-testid="chart" />),
}));

describe('AccountDetailMonthlyComparisonChart', () => {
    it('renders chart', () => {
        render(
            <AccountDetailMonthlyComparisonChart
                currentMonthData={[{ date: '2024-06-01', balance: 10 }]}
                previousMonthData={[{ date: '2024-05-01', balance: 5 }]}
            />,
        );
        expect(screen.getByTestId('chart')).toBeInTheDocument();
        expect(Line).toHaveBeenCalled();
    });
});
