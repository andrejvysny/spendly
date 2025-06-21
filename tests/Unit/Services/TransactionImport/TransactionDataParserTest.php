<?php

namespace Tests\Unit\Services\TransactionImport;

use App\Services\TransactionImport\TransactionDataParser;
use Tests\Unit\UnitTestCase;

class TransactionDataParserTest extends UnitTestCase
{
    private TransactionDataParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new TransactionDataParser;
    }

    public function test_parse_with_basic_mapping()
    {
        $row = ['2023-12-25', '100.50', 'John Doe', 'Christmas payment', 'TX123'];
        $configuration = [
            'column_mapping' => [
                'booked_date' => 0,
                'amount' => 1,
                'partner' => 2,
                'description' => 3,
                'transaction_id' => 4,
            ],
            'date_format' => 'Y-m-d',
            'amount_format' => '1,234.56',
            'currency' => 'USD',
            'account_id' => 123,
            'import_id' => 456,
        ];

        $result = $this->parser->parse($row, $configuration);

        $this->assertStringStartsWith('2023-12-25', $result['booked_date']);
        $this->assertEquals(100.50, $result['amount']);
        $this->assertEquals('John Doe', $result['partner']);
        $this->assertEquals('Christmas payment', $result['description']);
        $this->assertEquals('TX123', $result['transaction_id']);
        $this->assertEquals('USD', $result['currency']);
        $this->assertEquals(123, $result['account_id']);
        $this->assertEquals(456, $result['import_id']);
    }

    public function test_parse_date_formats()
    {
        $testCases = [
            ['date' => '25.12.2023', 'format' => 'd.m.Y'],
            ['date' => '2023-12-25', 'format' => 'Y-m-d'],
            ['date' => '25/12/2023', 'format' => 'd/m/Y'],
            ['date' => '12/25/2023', 'format' => 'm/d/Y'],
            ['date' => '2023.12.25', 'format' => 'Y.m.d'],
        ];

        foreach ($testCases as $testCase) {
            $row = [$testCase['date'], '100', 'Partner'];
            $configuration = [
                'column_mapping' => [
                    'booked_date' => 0,
                    'amount' => 1,
                    'partner' => 2,
                ],
                'date_format' => $testCase['format'],
                'amount_format' => '1,234.56',
            ];

            $result = $this->parser->parse($row, $configuration);

            $this->assertStringStartsWith('2023-12-25', $result['booked_date']);
        }
    }

    public function test_parse_amount_formats()
    {
        $testCases = [
            ['amount' => '1,234.56', 'format' => '1,234.56', 'expected' => 1234.56],
            ['amount' => '1.234,56', 'format' => '1.234,56', 'expected' => 1234.56],
            ['amount' => '1234,56', 'format' => '1234,56', 'expected' => 1234.56],
            ['amount' => '-1,234.56', 'format' => '1,234.56', 'expected' => -1234.56],
            ['amount' => '+1,234.56', 'format' => '1,234.56', 'expected' => 1234.56],
        ];

        foreach ($testCases as $testCase) {
            $row = ['2023-12-25', $testCase['amount'], 'Partner'];
            $configuration = [
                'column_mapping' => [
                    'booked_date' => 0,
                    'amount' => 1,
                    'partner' => 2,
                ],
                'date_format' => 'Y-m-d',
                'amount_format' => $testCase['format'],
            ];

            $result = $this->parser->parse($row, $configuration);

            $this->assertEquals($testCase['expected'], $result['amount']);
        }
    }

    public function test_parse_amount_type_strategy()
    {
        $row = ['2023-12-25', '100.00', 'Partner'];

        // Test expense_positive strategy
        $configuration = [
            'column_mapping' => [
                'booked_date' => 0,
                'amount' => 1,
                'partner' => 2,
            ],
            'date_format' => 'Y-m-d',
            'amount_format' => '1,234.56',
            'amount_type_strategy' => 'expense_positive',
        ];

        $result = $this->parser->parse($row, $configuration);
        $this->assertEquals(-100.00, $result['amount']);

        // Test signed_amount strategy (default)
        $configuration['amount_type_strategy'] = 'signed_amount';
        $result = $this->parser->parse($row, $configuration);
        $this->assertEquals(100.00, $result['amount']);
    }

    public function test_parse_with_missing_optional_fields()
    {
        $row = ['2023-12-25', '100.00', 'John Doe'];
        $configuration = [
            'column_mapping' => [
                'booked_date' => 0,
                'amount' => 1,
                'partner' => 2,
                'description' => 3, // This index doesn't exist in row
            ],
            'date_format' => 'Y-m-d',
            'amount_format' => '1,234.56',
        ];

        $result = $this->parser->parse($row, $configuration);

        $this->assertEquals('John Doe', $result['description']); // Should default to partner
        $this->assertStringStartsWith('2023-12-25', $result['processed_date']); // Should default to booked_date
    }

    public function test_parse_throws_exception_for_missing_required_fields()
    {
        $row = ['2023-12-25', '100.00']; // Missing partner
        $configuration = [
            'column_mapping' => [
                'booked_date' => 0,
                'amount' => 1,
                'partner' => 2, // This index doesn't exist
            ],
            'date_format' => 'Y-m-d',
            'amount_format' => '1,234.56',
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Missing required field: partner');

        $this->parser->parse($row, $configuration);
    }

    public function test_parse_with_empty_values()
    {
        $row = ['2023-12-25', '100.00', 'John Doe', ''];
        $configuration = [
            'column_mapping' => [
                'booked_date' => 0,
                'amount' => 1,
                'partner' => 2,
                'description' => 3,
            ],
            'date_format' => 'Y-m-d',
            'amount_format' => '1,234.56',
        ];

        $result = $this->parser->parse($row, $configuration);

        $this->assertEquals('John Doe', $result['description']); // Empty description should default to partner
    }

    public function test_parse_generates_transaction_id_if_not_provided()
    {
        $row = ['2023-12-25', '100.00', 'John Doe'];
        $configuration = [
            'column_mapping' => [
                'booked_date' => 0,
                'amount' => 1,
                'partner' => 2,
            ],
            'date_format' => 'Y-m-d',
            'amount_format' => '1,234.56',
        ];

        $result = $this->parser->parse($row, $configuration);

        $this->assertNotEmpty($result['transaction_id']);
        $this->assertStringStartsWith('IMP-', $result['transaction_id']);
    }

    public function test_parse_stores_import_data()
    {
        $row = ['2023-12-25', '100.00', 'John Doe'];
        $headers = ['Date', 'Amount', 'Partner'];
        $configuration = [
            'column_mapping' => [
                'booked_date' => 0,
                'amount' => 1,
                'partner' => 2,
            ],
            'date_format' => 'Y-m-d',
            'amount_format' => '1,234.56',
            'headers' => $headers,
        ];

        $result = $this->parser->parse($row, $configuration);

        $this->assertArrayHasKey('import_data', $result);
        $this->assertEquals([
            'Date' => '2023-12-25',
            'Amount' => '100.00',
            'Partner' => 'John Doe',
        ], $result['import_data']);
    }

    public function test_parse_with_special_characters()
    {
        $row = ["2023-12-25\x00", "100.00\x1F", "John Doe\x7F", "Test\x00Description"];
        $configuration = [
            'column_mapping' => [
                'booked_date' => 0,
                'amount' => 1,
                'partner' => 2,
                'description' => 3,
            ],
            'date_format' => 'Y-m-d',
            'amount_format' => '1,234.56',
        ];

        $result = $this->parser->parse($row, $configuration);

        // Special characters should be cleaned for dates and amounts
        $this->assertStringStartsWith('2023-12-25', $result['booked_date']);
        $this->assertEquals(100.00, $result['amount']);
        // Other fields are only trimmed, not cleaned of special characters
        $this->assertEquals("John Doe\x7F", $result['partner']);
        $this->assertEquals("Test\x00Description", $result['description']);
    }

    public function test_parse_with_currency_symbols()
    {
        $row = ['2023-12-25', '$1,234.56', 'John Doe'];
        $configuration = [
            'column_mapping' => [
                'booked_date' => 0,
                'amount' => 1,
                'partner' => 2,
            ],
            'date_format' => 'Y-m-d',
            'amount_format' => '1,234.56',
        ];

        $result = $this->parser->parse($row, $configuration);

        $this->assertEquals(1234.56, $result['amount']);
    }
}
