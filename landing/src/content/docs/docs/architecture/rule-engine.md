---
title: Rule Engine
description: Automatic transaction processing with configurable rules, conditions, and actions.
---

## Overview

The Rule Engine automatically processes transactions based on configurable rules. It supports event-driven actions, complex conditions with regex and wildcard support, and multiple actions per rule.

## Architecture

### Core Components

1. **Models**: `RuleGroup`, `Rule`, `ConditionGroup`, `RuleCondition`, `RuleAction`, `RuleExecutionLog`
2. **Services**: `RuleEngine`, `ConditionEvaluator`, `ActionExecutor`
3. **Events**: `TransactionCreated`, `TransactionUpdated` → `ProcessTransactionRules` listener

## Trigger Types

- `transaction_created` — Runs when new transactions are created
- `transaction_updated` — Runs when transactions are updated
- `manual` — Only runs when manually triggered

## Condition Fields

`amount`, `description`, `partner`, `category`, `merchant`, `account`, `type`, `note`, `recipient_note`, `place`, `target_iban`, `source_iban`, `date`, `tags`

## Condition Operators

### String Operators

`equals`, `not_equals`, `contains`, `not_contains`, `starts_with`, `ends_with`, `regex`, `wildcard`, `is_empty`, `is_not_empty`, `in`, `not_in`

### Numeric Operators

`equals`, `not_equals`, `greater_than`, `greater_than_or_equal`, `less_than`, `less_than_or_equal`, `between` (format: `"min,max"`)

## Actions

| Action                                                           | Description              |
| ---------------------------------------------------------------- | ------------------------ |
| `set_category`                                                   | Set transaction category |
| `set_merchant`                                                   | Set transaction merchant |
| `add_tag` / `remove_tag` / `remove_all_tags`                     | Tag management           |
| `set_description` / `append_description` / `prepend_description` | Modify description       |
| `set_note` / `append_note`                                       | Modify notes             |
| `set_type`                                                       | Change transaction type  |
| `mark_reconciled`                                                | Mark as reconciled       |
| `send_notification`                                              | Send notification        |
| `create_tag_if_not_exists`                                       | Create and add tag       |
| `create_category_if_not_exists`                                  | Create and set category  |
| `create_merchant_if_not_exists`                                  | Create and set merchant  |

## API Endpoints

### Rule Management

```
GET    /api/rules              # List rules (filter: active_only)
GET    /api/rules/options      # Available fields, operators, action types
POST   /api/rules/groups       # Create rule group
POST   /api/rules              # Create rule
PUT    /api/rules/{id}         # Update rule
DELETE /api/rules/{id}         # Delete rule
POST   /api/rules/{id}/duplicate  # Duplicate rule
GET    /api/rules/{id}/statistics  # Rule statistics
```

### Rule Execution

```
POST /api/rules/execute/transactions    # Execute on specific transactions
POST /api/rules/execute/date-range      # Execute on date range
POST /api/rules/{id}/execute            # Execute single rule
POST /api/rules/groups/{id}/execute     # Execute rule group
POST /api/rules/test                    # Test rule configuration
```

## Creating a Rule

```json
POST /api/rules
{
  "rule_group_id": 1,
  "name": "Grocery Store Rule",
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
    { "action_type": "set_category", "action_value": 5 },
    { "action_type": "add_tag", "action_value": 3 }
  ]
}
```

## Batch Processing

For large datasets, use the queue:

```php
use App\Jobs\ProcessRulesJob;

ProcessRulesJob::dispatch(
    $user,
    $ruleIds,
    Carbon::parse('2024-01-01'),
    Carbon::parse('2024-01-31'),
    $transactionIds,
    $dryRun
);
```

## Performance

Rules are processed via Laravel Pipeline pattern by priority order:

- All rules with relationships loaded in one optimized query
- Multi-level caching (rules, condition groups, actions, field values)
- Batched logging (queued, written in batches of 100)
- Chunked processing for large datasets (configurable, default 100)
- Memory management with automatic cleanup

### Optimization Results

- 90%+ reduction in database queries through caching
- Sub-second response times for typical processing
- Batch processing capable of handling millions of transactions

## Security

- All rules scoped to authenticated user
- Cross-user execution prevented at multiple levels
- Action values validated before execution
- Regex patterns validated to prevent ReDoS attacks
