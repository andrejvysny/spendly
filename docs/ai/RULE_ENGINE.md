# Rule Engine Documentation

## Overview

The Rule Engine is a powerful feature that allows automatic processing of transactions based on configurable rules. It supports event-driven actions, complex conditions with regex and wildcard support, and multiple actions per rule.

## Architecture

### Core Components

1. **Models**
   - `RuleGroup` - Organizes rules into logical groups
   - `Rule` - Core rule entity with trigger type and processing flags
   - `ConditionGroup` - Groups conditions with AND/OR logic
   - `RuleCondition` - Individual conditions with various operators
   - `RuleAction` - Actions to execute when rules match
   - `RuleExecutionLog` - Audit trail of rule executions

2. **Services**
   - `RuleEngine` - Main engine implementing `RuleEngineInterface`
   - `ConditionEvaluator` - Evaluates conditions including regex/wildcard
   - `ActionExecutor` - Executes actions on matched transactions

3. **Events & Listeners**
   - `TransactionCreated` - Fired when a transaction is created
   - `TransactionUpdated` - Fired when a transaction is updated
   - `ProcessTransactionRules` - Listener that processes rules

## Features

### Trigger Types
- `transaction_created` - Automatically runs when new transactions are created
- `transaction_updated` - Automatically runs when transactions are updated  
- `manual` - Only runs when manually triggered

### Condition Fields
- `amount` - Transaction amount
- `description` - Transaction description
- `partner` - Transaction partner/counterparty
- `category` - Associated category name
- `merchant` - Associated merchant name
- `account` - Account name
- `type` - Transaction type
- `note` - Transaction note
- `recipient_note` - Recipient note
- `place` - Transaction location
- `target_iban` - Target IBAN
- `source_iban` - Source IBAN
- `date` - Transaction date
- `tags` - Associated tags

### Condition Operators

#### String Operators
- `equals` - Exact match
- `not_equals` - Not exact match
- `contains` - Contains substring
- `not_contains` - Does not contain substring
- `starts_with` - Starts with string
- `ends_with` - Ends with string
- `regex` - Matches regular expression
- `wildcard` - Matches wildcard pattern (* and ?)
- `is_empty` - Field is empty
- `is_not_empty` - Field is not empty
- `in` - Value in comma-separated list
- `not_in` - Value not in comma-separated list

#### Numeric Operators
- `equals` - Equal to value
- `not_equals` - Not equal to value
- `greater_than` - Greater than value
- `greater_than_or_equal` - Greater than or equal
- `less_than` - Less than value
- `less_than_or_equal` - Less than or equal
- `between` - Between two values (format: "min,max")

### Actions
- `set_category` - Set transaction category
- `set_merchant` - Set transaction merchant
- `add_tag` - Add a tag
- `remove_tag` - Remove a tag
- `remove_all_tags` - Remove all tags
- `set_description` - Replace description
- `append_description` - Append to description
- `prepend_description` - Prepend to description
- `set_note` - Set note
- `append_note` - Append to note
- `set_type` - Change transaction type
- `mark_reconciled` - Mark as reconciled
- `send_notification` - Send notification (logs for now)
- `create_tag_if_not_exists` - Create and add tag
- `create_category_if_not_exists` - Create and set category
- `create_merchant_if_not_exists` - Create and set merchant

## API Endpoints

### Rule Management

#### List Rules
```
GET /api/rules
```
Query parameters:
- `active_only` (boolean) - Only return active rules

#### Get Rule Options
```
GET /api/rules/options
```
Returns available fields, operators, and action types.

#### Create Rule Group
```
POST /api/rules/groups
```
Body:
```json
{
  "name": "Shopping Rules",
  "description": "Rules for shopping transactions",
  "is_active": true
}
```

#### Create Rule
```
POST /api/rules
```
Body:
```json
{
  "rule_group_id": 1,
  "name": "Grocery Store Rule",
  "description": "Categorize grocery transactions",
  "trigger_type": "transaction_created",
  "stop_processing": false,
  "is_active": true,
  "condition_groups": [
    {
      "logic_operator": "AND",
      "conditions": [
        {
          "field": "description",
          "operator": "contains",
          "value": "WALMART",
          "is_case_sensitive": false
        },
        {
          "field": "amount",
          "operator": "greater_than",
          "value": "10"
        }
      ]
    }
  ],
  "actions": [
    {
      "action_type": "set_category",
      "action_value": 5
    },
    {
      "action_type": "add_tag",
      "action_value": 3
    }
  ]
}
```

#### Update Rule
```
PUT /api/rules/{id}
```

#### Delete Rule
```
DELETE /api/rules/{id}
```

#### Duplicate Rule
```
POST /api/rules/{id}/duplicate
```
Body:
```json
{
  "name": "New Rule Name (optional)"
}
```

#### Get Rule Statistics
```
GET /api/rules/{id}/statistics?days=30
```

### Rule Execution

#### Execute on Transactions
```
POST /api/rules/execute/transactions
```
Body:
```json
{
  "transaction_ids": [1, 2, 3],
  "rule_ids": [1, 2],  // Optional, all rules if not specified
  "dry_run": true      // Optional, false by default
}
```

#### Execute on Date Range
```
POST /api/rules/execute/date-range
```
Body:
```json
{
  "start_date": "2024-01-01",
  "end_date": "2024-01-31",
  "rule_ids": [1, 2],  // Optional
  "dry_run": false
}
```

#### Execute Individual Rule
```
POST /api/rules/{id}/execute
```
Executes a single rule on all user transactions. Useful for testing or manual processing.
Body:
```json
{
  "dry_run": false  // Optional, false by default
}
```

#### Execute Rule Group
```
POST /api/rules/groups/{id}/execute
```
Executes all rules in a rule group on all user transactions. Useful for bulk processing.
Body:
```json
{
  "dry_run": false  // Optional, false by default
}
```

#### Test Rule Configuration
```
POST /api/rules/test
```
Body:
```json
{
  "transaction_ids": [1, 2],
  "condition_groups": [
    {
      "logic_operator": "AND",
      "conditions": [
        {
          "field": "description",
          "operator": "regex",
          "value": "/UBER|LYFT/i"
        }
      ]
    }
  ],
  "actions": [
    {
      "action_type": "create_category_if_not_exists",
      "action_value": "Transportation"
    }
  ]
}
```

## Usage Examples

### Example 1: Categorize Grocery Transactions
```php
$rule = $ruleRepository->createRule($user, [
    'rule_group_id' => $groceryGroup->id,
    'name' => 'Grocery Stores',
    'trigger_type' => 'transaction_created',
    'condition_groups' => [
        [
            'logic_operator' => 'OR',
            'conditions' => [
                [
                    'field' => 'description',
                    'operator' => 'wildcard',
                    'value' => '*GROCERY*'
                ],
                [
                    'field' => 'description',
                    'operator' => 'regex',
                    'value' => '/(WALMART|TARGET|KROGER)/i'
                ]
            ]
        ]
    ],
    'actions' => [
        [
            'action_type' => 'set_category',
            'action_value' => $groceryCategory->id
        ]
    ]
]);
```

### Example 2: Tag Large Transactions
```php
$rule = $ruleRepository->createRule($user, [
    'rule_group_id' => $alertGroup->id,
    'name' => 'Large Transaction Alert',
    'trigger_type' => 'transaction_created',
    'condition_groups' => [
        [
            'logic_operator' => 'AND',
            'conditions' => [
                [
                    'field' => 'amount',
                    'operator' => 'greater_than',
                    'value' => '1000'
                ]
            ]
        ]
    ],
    'actions' => [
        [
            'action_type' => 'create_tag_if_not_exists',
            'action_value' => 'Large Transaction'
        ],
        [
            'action_type' => 'send_notification',
            'action_value' => 'Large transaction detected'
        ]
    ]
]);
```

### Example 3: Complex Rule with Multiple Condition Groups
```php
$rule = $ruleRepository->createRule($user, [
    'rule_group_id' => $travelGroup->id,
    'name' => 'Travel Expenses',
    'trigger_type' => 'transaction_created',
    'condition_groups' => [
        // Group 1: Airlines
        [
            'logic_operator' => 'OR',
            'conditions' => [
                [
                    'field' => 'description',
                    'operator' => 'regex',
                    'value' => '/(AIRLINE|AIRWAYS|DELTA|UNITED)/i'
                ],
                [
                    'field' => 'merchant',
                    'operator' => 'in',
                    'value' => 'American Airlines,Southwest,JetBlue'
                ]
            ]
        ],
        // Group 2: Hotels
        [
            'logic_operator' => 'OR',
            'conditions' => [
                [
                    'field' => 'description',
                    'operator' => 'wildcard',
                    'value' => '*HOTEL*'
                ],
                [
                    'field' => 'description',
                    'operator' => 'regex',
                    'value' => '/(MARRIOTT|HILTON|HYATT)/i'
                ]
            ]
        ]
    ],
    'actions' => [
        [
            'action_type' => 'create_category_if_not_exists',
            'action_value' => 'Travel'
        ],
        [
            'action_type' => 'add_tag',
            'action_value' => $businessTag->id
        ]
    ]
]);
```

## Batch Processing

For processing large numbers of transactions, use the queue:

```php
use App\Jobs\ProcessRulesJob;

// Process specific date range
ProcessRulesJob::dispatch(
    $user,
    $ruleIds = [1, 2, 3],
    Carbon::parse('2024-01-01'),
    Carbon::parse('2024-01-31'),
    $transactionIds = [],
    $dryRun = false
);

// Process specific transactions
ProcessRulesJob::dispatch(
    $user,
    $ruleIds = [],
    null,
    null,
    $transactionIds = [1, 2, 3, 4, 5],
    $dryRun = true
);
```

## Testing

Run the test suite:
```bash
php artisan test tests/Feature/RuleEngineTest.php
```

## Performance Considerations

1. **Indexing**: All foreign keys and commonly queried fields are indexed
2. **Eager Loading**: Rules load with all relationships to minimize queries
3. **Chunking**: Large date ranges are processed in chunks of 100 transactions
4. **Queue Processing**: Use jobs for batch processing to avoid timeouts
5. **Caching**: Consider caching rule configurations for frequently used rules

## Security

1. All rules are scoped to the authenticated user
2. Cross-user rule execution is prevented at multiple levels
3. Action values are validated before execution
4. Regex patterns are validated to prevent ReDoS attacks

## Future Enhancements

1. **Rule Templates**: Pre-configured rules for common scenarios
2. **Rule Import/Export**: Share rules between users
3. **Machine Learning**: Suggest rules based on transaction patterns
4. **Advanced Scheduling**: Time-based rule activation
5. **Rule Versioning**: Track changes and allow rollbacks
6. **Performance Dashboard**: Monitor rule execution metrics 