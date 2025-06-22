<?php

namespace Tests\Unit\Services\Csv;

use App\Services\Csv\CsvData;
use InvalidArgumentException;
use Tests\Unit\UnitTestCase;

class CsvDataTest extends UnitTestCase
{
    public function test_constructor_creates_csv_data_with_headers_and_rows()
    {
        $headers = ['name', 'email', 'age'];
        $rows = [
            ['John Doe', 'john@example.com', '30'],
            ['Jane Smith', 'jane@example.com', '25'],
        ];

        $csvData = new CsvData($headers, $rows);

        $this->assertEquals($headers, $csvData->getHeaders());
        $this->assertEquals($rows, $csvData->getRows());
        $this->assertEquals(2, $csvData->count());
    }

    public function test_from_array_creates_csv_data_from_array_structure()
    {
        $arrayData = [
            'headers' => ['name', 'email'],
            'rows' => [
                ['John Doe', 'john@example.com'],
                ['Jane Smith', 'jane@example.com'],
            ],
        ];

        $csvData = CsvData::fromArray($arrayData);

        $this->assertEquals(['name', 'email'], $csvData->getHeaders());
        $this->assertEquals(2, $csvData->count());
    }

    public function test_get_header_returns_header_at_index()
    {
        $headers = ['name', 'email', 'age'];
        $csvData = new CsvData($headers, []);

        $this->assertEquals('name', $csvData->getHeader(0));
        $this->assertEquals('email', $csvData->getHeader(1));
        $this->assertNull($csvData->getHeader(10));
    }

    public function test_get_header_index_returns_index_by_name()
    {
        $headers = ['name', 'email', 'age'];
        $csvData = new CsvData($headers, []);

        $this->assertEquals(0, $csvData->getHeaderIndex('name'));
        $this->assertEquals(1, $csvData->getHeaderIndex('email'));
        $this->assertNull($csvData->getHeaderIndex('nonexistent'));
    }

    public function test_has_header_checks_header_existence()
    {
        $headers = ['name', 'email', 'age'];
        $csvData = new CsvData($headers, []);

        $this->assertTrue($csvData->hasHeader('name'));
        $this->assertTrue($csvData->hasHeader('email'));
        $this->assertFalse($csvData->hasHeader('nonexistent'));
    }

    public function test_get_row_returns_row_at_index()
    {
        $headers = ['name', 'email'];
        $rows = [
            ['John Doe', 'john@example.com'],
            ['Jane Smith', 'jane@example.com'],
        ];
        $csvData = new CsvData($headers, $rows);

        $this->assertEquals(['John Doe', 'john@example.com'], $csvData->getRow(0));
        $this->assertEquals(['Jane Smith', 'jane@example.com'], $csvData->getRow(1));
        $this->assertNull($csvData->getRow(10));
    }

    public function test_get_row_as_assoc_returns_associative_array()
    {
        $headers = ['name', 'email', 'age'];
        $rows = [
            ['John Doe', 'john@example.com', '30'],
        ];
        $csvData = new CsvData($headers, $rows);

        $expected = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'age' => '30',
        ];

        $this->assertEquals($expected, $csvData->getRowAsAssoc(0));
        $this->assertNull($csvData->getRowAsAssoc(10));
    }

    public function test_get_rows_as_assoc_returns_all_rows_as_associative_arrays()
    {
        $headers = ['name', 'email'];
        $rows = [
            ['John Doe', 'john@example.com'],
            ['Jane Smith', 'jane@example.com'],
        ];
        $csvData = new CsvData($headers, $rows);

        $expected = [
            0 => ['name' => 'John Doe', 'email' => 'john@example.com'],
            1 => ['name' => 'Jane Smith', 'email' => 'jane@example.com'],
        ];

        $this->assertEquals($expected, $csvData->getRowsAsAssoc());
    }

    public function test_get_column_returns_column_by_header_name()
    {
        $headers = ['name', 'email', 'age'];
        $rows = [
            ['John Doe', 'john@example.com', '30'],
            ['Jane Smith', 'jane@example.com', '25'],
        ];
        $csvData = new CsvData($headers, $rows);

        $this->assertEquals(['John Doe', 'Jane Smith'], $csvData->getColumn('name'));
        $this->assertEquals(['john@example.com', 'jane@example.com'], $csvData->getColumn('email'));
    }

    public function test_get_column_throws_exception_for_nonexistent_header()
    {
        $headers = ['name', 'email'];
        $rows = [['John Doe', 'john@example.com']];
        $csvData = new CsvData($headers, $rows);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Header 'nonexistent' not found");

        $csvData->getColumn('nonexistent');
    }

    public function test_get_column_by_index_returns_column_by_index()
    {
        $headers = ['name', 'email', 'age'];
        $rows = [
            ['John Doe', 'john@example.com', '30'],
            ['Jane Smith', 'jane@example.com', '25'],
        ];
        $csvData = new CsvData($headers, $rows);

        $this->assertEquals(['John Doe', 'Jane Smith'], $csvData->getColumnByIndex(0));
        $this->assertEquals(['john@example.com', 'jane@example.com'], $csvData->getColumnByIndex(1));
    }

    public function test_get_column_by_index_throws_exception_for_invalid_index()
    {
        $headers = ['name', 'email'];
        $rows = [['John Doe', 'john@example.com']];
        $csvData = new CsvData($headers, $rows);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Column index 10 out of bounds');

        $csvData->getColumnByIndex(10);
    }

    public function test_filter_returns_filtered_csv_data()
    {
        $headers = ['name', 'age'];
        $rows = [
            ['John Doe', '30'],
            ['Jane Smith', '25'],
            ['Bob Johnson', '35'],
        ];
        $csvData = new CsvData($headers, $rows);

        $filtered = $csvData->filter(function ($row) {
            return $row[1] >= 30; // age >= 30
        });

        $this->assertEquals(2, $filtered->count());
        $this->assertEquals(['John Doe', '30'], $filtered->getRow(0));
        $this->assertEquals(['Bob Johnson', '35'], $filtered->getRow(1));
    }

    public function test_map_returns_mapped_csv_data()
    {
        $headers = ['name', 'age'];
        $rows = [
            ['John Doe', '30'],
            ['Jane Smith', '25'],
        ];
        $csvData = new CsvData($headers, $rows);

        $mapped = $csvData->map(function ($row) {
            return [$row[0], (int) $row[1] + 1]; // increment age
        });

        $this->assertEquals(['John Doe', 31], $mapped->getRow(0));
        $this->assertEquals(['Jane Smith', 26], $mapped->getRow(1));
    }

    public function test_slice_returns_subset_of_rows()
    {
        $headers = ['name', 'age'];
        $rows = [
            ['John Doe', '30'],
            ['Jane Smith', '25'],
            ['Bob Johnson', '35'],
            ['Alice Brown', '28'],
        ];
        $csvData = new CsvData($headers, $rows);

        $sliced = $csvData->slice(1, 2);

        $this->assertEquals(2, $sliced->count());
        $this->assertEquals(['Jane Smith', '25'], $sliced->getRow(0));
        $this->assertEquals(['Bob Johnson', '35'], $sliced->getRow(1));
    }

    public function test_take_returns_first_n_rows()
    {
        $headers = ['name', 'age'];
        $rows = [
            ['John Doe', '30'],
            ['Jane Smith', '25'],
            ['Bob Johnson', '35'],
        ];
        $csvData = new CsvData($headers, $rows);

        $taken = $csvData->take(2);

        $this->assertEquals(2, $taken->count());
        $this->assertEquals(['John Doe', '30'], $taken->getRow(0));
        $this->assertEquals(['Jane Smith', '25'], $taken->getRow(1));
    }

    public function test_skip_returns_rows_after_n()
    {
        $headers = ['name', 'age'];
        $rows = [
            ['John Doe', '30'],
            ['Jane Smith', '25'],
            ['Bob Johnson', '35'],
        ];
        $csvData = new CsvData($headers, $rows);

        $skipped = $csvData->skip(1);

        $this->assertEquals(2, $skipped->count());
        $this->assertEquals(['Jane Smith', '25'], $skipped->getRow(0));
        $this->assertEquals(['Bob Johnson', '35'], $skipped->getRow(1));
    }

    public function test_add_row_adds_new_row()
    {
        $headers = ['name', 'email'];
        $csvData = new CsvData($headers, []);

        $csvData->addRow(['John Doe', 'john@example.com']);

        $this->assertEquals(1, $csvData->count());
        $this->assertEquals(['John Doe', 'john@example.com'], $csvData->getRow(0));
    }

    public function test_add_row_throws_exception_for_invalid_column_count()
    {
        $headers = ['name', 'email'];
        $csvData = new CsvData($headers, []);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Row must have 2 columns, got 1');

        $csvData->addRow(['John Doe']);
    }

    public function test_add_row_assoc_adds_row_from_associative_array()
    {
        $headers = ['name', 'email'];
        $csvData = new CsvData($headers, []);

        $csvData->addRowAssoc([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $this->assertEquals(1, $csvData->count());
        $this->assertEquals(['John Doe', 'john@example.com'], $csvData->getRow(0));
    }

    public function test_remove_row_removes_row_at_index()
    {
        $headers = ['name', 'email'];
        $rows = [
            ['John Doe', 'john@example.com'],
            ['Jane Smith', 'jane@example.com'],
        ];
        $csvData = new CsvData($headers, $rows);

        $csvData->removeRow(0);

        $this->assertEquals(1, $csvData->count());
        $this->assertEquals(['Jane Smith', 'jane@example.com'], $csvData->getRow(0));
    }

    public function test_is_empty_checks_if_csv_data_is_empty()
    {
        $headers = ['name', 'email'];

        $emptyCsv = new CsvData($headers, []);
        $this->assertTrue($emptyCsv->isEmpty());

        $nonEmptyCsv = new CsvData($headers, [['John Doe', 'john@example.com']]);
        $this->assertFalse($nonEmptyCsv->isEmpty());
    }

    public function test_to_array_returns_array_structure()
    {
        $headers = ['name', 'email'];
        $rows = [['John Doe', 'john@example.com']];
        $csvData = new CsvData($headers, $rows);

        $expected = [
            'headers' => ['name', 'email'],
            'rows' => [['John Doe', 'john@example.com']],
        ];

        $this->assertEquals($expected, $csvData->toArray());
    }

    public function test_get_stats_returns_statistics()
    {
        $headers = ['name', 'email'];
        $rows = [
            ['John Doe', 'john@example.com'],
            ['Jane Smith', 'jane@example.com'],
        ];
        $csvData = new CsvData($headers, $rows);

        $stats = $csvData->getStats();

        $this->assertEquals(2, $stats['total_rows']);
        $this->assertEquals(2, $stats['total_columns']);
        $this->assertTrue($stats['has_headers']);
        $this->assertEquals(['name', 'email'], $stats['headers']);
    }

    public function test_array_access_implementation()
    {
        $headers = ['name', 'email'];
        $rows = [['John Doe', 'john@example.com']];
        $csvData = new CsvData($headers, $rows);

        // Test offsetExists
        $this->assertTrue(isset($csvData[0]));
        $this->assertFalse(isset($csvData[10]));

        // Test offsetGet
        $this->assertEquals(['John Doe', 'john@example.com'], $csvData[0]);

        // Test offsetSet
        $csvData[1] = ['Jane Smith', 'jane@example.com'];
        $this->assertEquals(2, $csvData->count());

        // Test offsetUnset
        unset($csvData[0]);
        $this->assertEquals(1, $csvData->count());
    }

    public function test_iterator_implementation()
    {
        $headers = ['name', 'email'];
        $rows = [
            ['John Doe', 'john@example.com'],
            ['Jane Smith', 'jane@example.com'],
        ];
        $csvData = new CsvData($headers, $rows);

        $iteratedRows = [];
        foreach ($csvData as $row) {
            $iteratedRows[] = $row;
        }

        $this->assertEquals($rows, $iteratedRows);
    }
}
