# Spendly API Documentation - WIP

This document describes the Spendly REST API endpoints for managing personal finance data.

## Base URL

```
Production: https://api.spendly.app/api
Development: http://localhost:8000/api
```

## Authentication

Spendly uses Laravel Sanctum for API authentication. Include the bearer token in the Authorization header:

```http
Authorization: Bearer your-api-token
```

### Getting an API Token

```http
POST /auth/tokens
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "password",
  "token_name": "My API Token"
}
```

**Response:**
```json
{
  "token": "1|your-api-token-here",
  "expires_at": "2024-12-31T23:59:59.000000Z"
}
```

## Rate Limiting

- **Authenticated requests**: 1000 requests per hour
- **Unauthenticated requests**: 100 requests per hour

Rate limit headers are included in responses:
```http
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 999
X-RateLimit-Reset: 1640995200
```

## Error Handling

All errors return JSON with the following structure:

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "The given data was invalid.",
    "details": {
      "email": ["The email field is required."]
    }
  }
}
```

### HTTP Status Codes

- `200` - Success
- `201` - Created
- `400` - Bad Request
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not Found
- `422` - Validation Error
- `429` - Too Many Requests
- `500` - Internal Server Error

## Accounts

### List Accounts

```http
GET /accounts
```

**Parameters:**
- `type` (optional) - Filter by account type: `checking`, `savings`, `credit_card`, `investment`
- `currency` (optional) - Filter by currency code (ISO 4217)
- `include` (optional) - Include related data: `transactions`, `categories`

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Main Checking",
      "bank_name": "Chase Bank",
      "type": "checking",
      "currency": "USD",
      "balance": "2500.00",
      "iban": "GB29NWBK60161331926819",
      "is_gocardless_synced": true,
      "gocardless_last_synced_at": "2024-01-15T10:30:00.000000Z",
      "created_at": "2024-01-01T00:00:00.000000Z",
      "updated_at": "2024-01-15T10:30:00.000000Z"
    }
  ],
  "meta": {
    "total": 1,
    "current_page": 1,
    "per_page": 15
  }
}
```

### Get Account

```http
GET /accounts/{id}
```

**Response:**
```json
{
  "data": {
    "id": 1,
    "name": "Main Checking",
    "bank_name": "Chase Bank",
    "type": "checking",
    "currency": "USD",
    "balance": "2500.00",
    "iban": "GB29NWBK60161331926819",
    "is_gocardless_synced": true,
    "gocardless_last_synced_at": "2024-01-15T10:30:00.000000Z",
    "transactions_count": 45,
    "created_at": "2024-01-01T00:00:00.000000Z",
    "updated_at": "2024-01-15T10:30:00.000000Z"
  }
}
```

### Create Account

```http
POST /accounts
Content-Type: application/json

{
  "name": "Savings Account",
  "bank_name": "Wells Fargo",
  "type": "savings",
  "currency": "USD",
  "balance": "10000.00",
  "iban": "US64SVBKUS6S3300958879"
}
```

**Response:**
```json
{
  "data": {
    "id": 2,
    "name": "Savings Account",
    "bank_name": "Wells Fargo",
    "type": "savings",
    "currency": "USD",
    "balance": "10000.00",
    "iban": "US64SVBKUS6S3300958879",
    "is_gocardless_synced": false,
    "gocardless_last_synced_at": null,
    "created_at": "2024-01-15T12:00:00.000000Z",
    "updated_at": "2024-01-15T12:00:00.000000Z"
  }
}
```

### Update Account

```http
PUT /accounts/{id}
Content-Type: application/json

{
  "name": "Updated Account Name",
  "balance": "2750.00"
}
```

### Delete Account

```http
DELETE /accounts/{id}
```

**Response:**
```json
{
  "message": "Account deleted successfully"
}
```

## Transactions

### List Transactions

```http
GET /transactions
```

**Parameters:**
- `account_id` (optional) - Filter by account ID
- `category_id` (optional) - Filter by category ID
- `type` (optional) - Filter by transaction type
- `date_from` (optional) - Start date (YYYY-MM-DD)
- `date_to` (optional) - End date (YYYY-MM-DD)
- `amount_min` (optional) - Minimum amount
- `amount_max` (optional) - Maximum amount
- `search` (optional) - Search in description
- `sort` (optional) - Sort field: `date`, `amount`, `description`
- `order` (optional) - Sort order: `asc`, `desc`
- `page` (optional) - Page number
- `per_page` (optional) - Items per page (max 100)

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "account_id": 1,
      "category_id": 5,
      "merchant_id": 10,
      "amount": "-45.67",
      "currency": "USD",
      "description": "Grocery shopping at Whole Foods",
      "date": "2024-01-15",
      "type": "expense",
      "status": "cleared",
      "reference": "TXN123456",
      "gocardless_transaction_id": "gc_txn_789",
      "account": {
        "id": 1,
        "name": "Main Checking"
      },
      "category": {
        "id": 5,
        "name": "Groceries",
        "color": "#4CAF50"
      },
      "merchant": {
        "id": 10,
        "name": "Whole Foods Market"
      },
      "tags": [
        {
          "id": 1,
          "name": "organic",
          "color": "#8BC34A"
        }
      ],
      "created_at": "2024-01-15T14:30:00.000000Z",
      "updated_at": "2024-01-15T14:30:00.000000Z"
    }
  ],
  "meta": {
    "total": 156,
    "current_page": 1,
    "per_page": 15,
    "last_page": 11
  }
}
```

### Get Transaction

```http
GET /transactions/{id}
```

### Create Transaction

```http
POST /transactions
Content-Type: application/json

{
  "account_id": 1,
  "category_id": 5,
  "merchant_id": 10,
  "amount": "-89.99",
  "currency": "USD",
  "description": "Online purchase",
  "date": "2024-01-15",
  "type": "expense",
  "reference": "ORDER123",
  "tag_ids": [1, 2]
}
```

### Update Transaction

```http
PUT /transactions/{id}
Content-Type: application/json

{
  "category_id": 6,
  "description": "Updated description",
  "tag_ids": [1, 3]
}
```

### Delete Transaction

```http
DELETE /transactions/{id}
```

### Bulk Operations

#### Bulk Categorize

```http
POST /transactions/bulk/categorize
Content-Type: application/json

{
  "transaction_ids": [1, 2, 3],
  "category_id": 5
}
```

#### Bulk Tag

```http
POST /transactions/bulk/tag
Content-Type: application/json

{
  "transaction_ids": [1, 2, 3],
  "tag_ids": [1, 2]
}
```

## Categories

### List Categories

```http
GET /categories
```

**Parameters:**
- `type` (optional) - Filter by type: `income`, `expense`
- `parent_id` (optional) - Filter by parent category ID
- `include` (optional) - Include related data: `children`, `transactions_count`

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Food & Dining",
      "type": "expense",
      "color": "#FF5722",
      "icon": "utensils",
      "parent_id": null,
      "budget_amount": "400.00",
      "is_active": true,
      "children": [
        {
          "id": 2,
          "name": "Groceries",
          "type": "expense",
          "color": "#4CAF50",
          "parent_id": 1
        }
      ],
      "transactions_count": 23,
      "total_amount": "345.67",
      "created_at": "2024-01-01T00:00:00.000000Z",
      "updated_at": "2024-01-15T10:00:00.000000Z"
    }
  ]
}
```

### Create Category

```http
POST /categories
Content-Type: application/json

{
  "name": "Transportation",
  "type": "expense",
  "color": "#2196F3",
  "icon": "car",
  "parent_id": null,
  "budget_amount": "300.00"
}
```

## Budgets

### List Budgets

```http
GET /budgets
```

**Parameters:**
- `period` (optional) - Filter by period: `monthly`, `yearly`
- `year` (optional) - Filter by year
- `month` (optional) - Filter by month (1-12)
- `category_id` (optional) - Filter by category ID

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "category_id": 5,
      "amount": "400.00",
      "spent": "234.56",
      "remaining": "165.44",
      "period": "monthly",
      "year": 2024,
      "month": 1,
      "percentage_used": 58.64,
      "is_exceeded": false,
      "category": {
        "id": 5,
        "name": "Groceries",
        "color": "#4CAF50"
      },
      "created_at": "2024-01-01T00:00:00.000000Z",
      "updated_at": "2024-01-15T20:00:00.000000Z"
    }
  ]
}
```

### Create Budget

```http
POST /budgets
Content-Type: application/json

{
  "category_id": 5,
  "amount": "500.00",
  "period": "monthly",
  "year": 2024,
  "month": 2
}
```

## Reports

### Spending Report

```http
GET /reports/spending
```

**Parameters:**
- `date_from` (required) - Start date (YYYY-MM-DD)
- `date_to` (required) - End date (YYYY-MM-DD)
- `group_by` (optional) - Group by: `category`, `month`, `week`, `day`
- `account_ids[]` (optional) - Array of account IDs to include

**Response:**
```json
{
  "data": {
    "total_income": "3500.00",
    "total_expenses": "2456.78",
    "net_income": "1043.22",
    "by_category": [
      {
        "category_id": 5,
        "category_name": "Groceries",
        "amount": "456.78",
        "percentage": 18.6,
        "transaction_count": 12
      }
    ],
    "by_month": [
      {
        "month": "2024-01",
        "income": "3500.00",
        "expenses": "2456.78",
        "net": "1043.22"
      }
    ]
  }
}
```

### Account Balance History

```http
GET /reports/balance-history
```

**Parameters:**
- `account_id` (required) - Account ID
- `date_from` (required) - Start date (YYYY-MM-DD)
- `date_to` (required) - End date (YYYY-MM-DD)
- `interval` (optional) - Interval: `daily`, `weekly`, `monthly`

**Response:**
```json
{
  "data": [
    {
      "date": "2024-01-01",
      "balance": "2500.00"
    },
    {
      "date": "2024-01-02",
      "balance": "2445.33"
    }
  ]
}
```

## Bank Integration (GoCardless)

### List Supported Banks

```http
GET /banks/institutions
```

**Parameters:**
- `country` (required) - ISO 3166-1 alpha-2 country code

**Response:**
```json
{
  "data": [
    {
      "id": "BARCLAYS_GB",
      "name": "Barclays",
      "bic": "BARCGB22",
      "logo": "https://cdn.gocardless.com/logos/barclays.png",
      "countries": ["GB"]
    }
  ]
}
```

### Initiate Bank Connection

```http
POST /banks/connect
Content-Type: application/json

{
  "institution_id": "BARCLAYS_GB"
}
```

**Response:**
```json
{
  "data": {
    "link": "https://bankaccountdata.gocardless.com/link/...",
    "requisition_id": "req_123456"
  }
}
```

### Sync Bank Account

```http
POST /accounts/{id}/sync
```

**Response:**
```json
{
  "data": {
    "synced_transactions": 15,
    "last_synced_at": "2024-01-15T16:30:00.000000Z"
  }
}
```

## CSV Import/Export

### Import Transactions

```http
POST /import/csv
Content-Type: multipart/form-data

file: transactions.csv
mapping: {
  "date": "Date",
  "amount": "Amount",
  "description": "Description",
  "category": "Category"
}
account_id: 1
```

**Response:**
```json
{
  "data": {
    "imported_count": 45,
    "skipped_count": 2,
    "errors": [
      {
        "row": 3,
        "message": "Invalid date format"
      }
    ]
  }
}
```

### Export Transactions

```http
GET /export/csv
```

**Parameters:**
- `account_ids[]` (optional) - Array of account IDs
- `date_from` (optional) - Start date (YYYY-MM-DD)
- `date_to` (optional) - End date (YYYY-MM-DD)
- `format` (optional) - Export format: `csv`, `xlsx`, `json`

**Response:**
Returns a CSV file download.

## Webhooks

Spendly can send webhooks for various events. Configure webhook URLs in your account settings.

### Events

- `transaction.created`
- `transaction.updated`
- `transaction.deleted`
- `account.synced`
- `budget.exceeded`

### Webhook Payload

```json
{
  "event": "transaction.created",
  "data": {
    "id": 123,
    "account_id": 1,
    "amount": "-45.67",
    "description": "Coffee shop",
    "date": "2024-01-15"
  },
  "timestamp": "2024-01-15T14:30:00.000000Z",
  "signature": "sha256=..."
}
```

### Verifying Webhooks

```php
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_SIGNATURE'];
$expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $webhookSecret);

if (!hash_equals($signature, $expectedSignature)) {
    http_response_code(400);
    exit('Invalid signature');
}
```

## SDKs and Libraries

### Official SDKs

- **PHP**: `composer require spendly/php-sdk`
- **JavaScript**: `npm install @spendly/js-sdk`
- **Python**: `pip install spendly-python`

### Community SDKs

- **Ruby**: `gem install spendly-ruby`
- **Go**: `go get github.com/spendly/go-sdk`

### Example Usage (PHP)

```php
use Spendly\SDK\Client;

$client = new Client('your-api-token');

// Get accounts
$accounts = $client->accounts()->list();

// Create transaction
$transaction = $client->transactions()->create([
    'account_id' => 1,
    'amount' => '-25.50',
    'description' => 'Coffee',
    'date' => '2024-01-15'
]);

// Get spending report
$report = $client->reports()->spending([
    'date_from' => '2024-01-01',
    'date_to' => '2024-01-31',
    'group_by' => 'category'
]);
```

## API Versioning

The API uses versioning through the Accept header:

```http
Accept: application/vnd.spendly.v1+json
```

Current version: **v1**

## Financial Data Precision

All monetary amounts are returned as strings to preserve precision:

```json
{
  "amount": "123.45",
  "balance": "1000.00"
}
```

Always parse monetary values as decimal/BigDecimal in your application to avoid floating-point precision issues.

## Security Considerations

- Always use HTTPS in production
- Store API tokens securely
- Implement proper error handling
- Use rate limiting on your end
- Validate all data before processing
- Never log sensitive financial data

## Support

- **API Issues**: api-support@spendly.app
- **Documentation**: [api.spendly.app](https://api.spendly.app)
- **SDKs**: [github.com/spendly/sdks](https://github.com/spendly/sdks)
- **Postman Collection**: [Download here](https://api.spendly.app/postman)

## Changelog

### v1.0.0 (2024-01-15)
- Initial API release
- Basic CRUD operations for accounts, transactions, categories
- GoCardless integration
- CSV import/export
- Reporting endpoints 
