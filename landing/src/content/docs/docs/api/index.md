---
title: REST API
description: Spendly REST API reference — endpoints, authentication, and data formats.
---

:::note
This API documentation is a work in progress and will be updated with more details.
:::

## Base URL

```
Production: https://your-domain.com/api
Development: http://localhost:8000/api
```

## Authentication

Spendly uses Laravel Sanctum for API authentication:

```http
Authorization: Bearer your-api-token
```

### Getting a Token

```http
POST /auth/tokens
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "password",
  "token_name": "My API Token"
}
```

## Rate Limiting

- **Authenticated**: 1000 requests/hour
- **Unauthenticated**: 100 requests/hour

## Error Format

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

`200` Success, `201` Created, `400` Bad Request, `401` Unauthorized, `403` Forbidden, `404` Not Found, `422` Validation Error, `429` Too Many Requests, `500` Server Error

## Accounts

### List Accounts

```http
GET /accounts
```

Parameters: `type` (checking/savings/credit_card/investment), `currency` (ISO 4217), `include` (transactions, categories)

### Create Account

```http
POST /accounts
{
  "name": "Savings Account",
  "bank_name": "Wells Fargo",
  "type": "savings",
  "currency": "USD",
  "balance": "10000.00"
}
```

### Update / Delete

```
PUT /accounts/{id}
DELETE /accounts/{id}
```

## Transactions

### List Transactions

```http
GET /transactions
```

Parameters: `account_id`, `category_id`, `type`, `date_from`, `date_to`, `amount_min`, `amount_max`, `search`, `sort`, `order`, `page`, `per_page`

### Create Transaction

```http
POST /transactions
{
  "account_id": 1,
  "category_id": 5,
  "amount": "-89.99",
  "currency": "USD",
  "description": "Online purchase",
  "date": "2024-01-15",
  "type": "expense",
  "tag_ids": [1, 2]
}
```

### Bulk Operations

```http
POST /transactions/bulk/categorize
{ "transaction_ids": [1, 2, 3], "category_id": 5 }

POST /transactions/bulk/tag
{ "transaction_ids": [1, 2, 3], "tag_ids": [1, 2] }
```

## Categories

```http
GET /categories            # List (filter: type, parent_id, include)
POST /categories           # Create
PUT /categories/{id}       # Update
DELETE /categories/{id}    # Delete
```

## Budgets

```http
GET /budgets               # List (filter: period, year, month, category_id)
POST /budgets              # Create
PUT /budgets/{id}          # Update
DELETE /budgets/{id}       # Delete
```

Budget response includes computed fields: `spent`, `remaining`, `percentage_used`, `is_exceeded`.

## Reports

### Spending Report

```http
GET /reports/spending?date_from=2024-01-01&date_to=2024-01-31&group_by=category
```

Returns: `total_income`, `total_expenses`, `net_income`, breakdowns by category and time period.

### Balance History

```http
GET /reports/balance-history?account_id=1&date_from=2024-01-01&date_to=2024-01-31&interval=daily
```

## Bank Integration (GoCardless)

```http
GET  /banks/institutions?country=GB    # List supported banks
POST /banks/connect                    # Initiate connection
POST /accounts/{id}/sync              # Sync bank account
```

## CSV Import/Export

### Import

```http
POST /import/csv
Content-Type: multipart/form-data

file: transactions.csv
mapping: {"date": "Date", "amount": "Amount", "description": "Description"}
account_id: 1
```

### Export

```http
GET /export/csv?account_ids[]=1&date_from=2024-01-01&format=csv
```

## Financial Data Precision

All monetary amounts are returned as strings to preserve precision:

```json
{ "amount": "123.45", "balance": "1000.00" }
```

Always parse monetary values as decimal/BigDecimal to avoid floating-point issues.
