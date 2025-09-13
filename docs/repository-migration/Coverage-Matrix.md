# Coverage & Binding Validation

| Model | Interface | Impl | Bound | Usages Replaced | Status |
|---|---|---|---|---|---|
| Account | AccountRepositoryInterface | AccountRepository | Yes | Controllers/Services | Partial |
| Transaction | TransactionRepositoryInterface | TransactionRepository | Yes | Importer/Some Controllers | Partial |
| Rule/RuleGroup/Condition/Action | RuleRepositoryInterface | RuleRepository | Yes | Rule Engine/Controller | Partial |
| Import | — | — | — | Controller usages | Missing |
| ImportFailure | — | — | — | ImportFailurePersister | Missing |
| ImportMapping | — | — | — | Controller usages | Missing |
| Category | — | — | — | RuleEngine ActionExecutor | Missing |
| Merchant | — | — | — | RuleEngine ActionExecutor | Missing |
| Tag | — | — | — | RuleEngine ActionExecutor | Missing |
| Analytics (query) | AnalyticsQueryRepositoryInterface | AnalyticsQueryRepository | — | AnalyticsController | Missing |
| User | — | — | — | Registration | Missing |
| RuleExecutionLog | — | — | — | RuleEngine | Missing |
| ConditionGroup | — | — | — | Part of Rule repo | Covered via Rule |
| RuleCondition | — | — | — | Part of Rule repo | Covered via Rule |
| RuleAction | — | — | — | Part of Rule repo | Covered via Rule |
