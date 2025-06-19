import { render, screen } from '@testing-library/react';
import { DataTable } from './DataTable';

// Validates table renders headers and cells

describe('DataTable', () => {
    const columns = [
        { key: 'name', header: 'Name' },
        { key: 'age', header: 'Age' },
    ];
    const data = [
        { name: 'Alice', age: 30 },
        { name: 'Bob', age: 40 },
    ];

    it('renders provided data', () => {
        render(<DataTable columns={columns} data={data} rowKey={(r) => r.name} />);
        expect(screen.getByText('Name')).toBeInTheDocument();
        expect(screen.getByText('Alice')).toBeInTheDocument();
        expect(screen.getByText('40')).toBeInTheDocument();
    });

    it('shows empty message', () => {
        render(<DataTable columns={columns} data={[]} rowKey={(r) => r.name} />);
        expect(screen.getByText('No data.')).toBeInTheDocument();
    });
});
