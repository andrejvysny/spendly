# Coverage & Binding Validation

Updated after repository cleanup (Phase 1–5). All repositories use interfaces and are bound in `RepositoryServiceProvider`.

| Model / Domain | Interface | Impl | Bound | Status |
|---|---|---|---|---|
| Account | AccountRepositoryInterface | AccountRepository | Yes | Implemented |
| Transaction | TransactionRepositoryInterface | TransactionRepository | Yes | Implemented |
| Rule / RuleGroup / Condition / Action | RuleRepositoryInterface, RuleGroupRepositoryInterface, etc. | RuleRepository, RuleGroupRepository, etc. | Yes | Implemented |
| Import | ImportRepositoryInterface | ImportRepository | Yes | Implemented |
| ImportFailure | ImportFailureRepositoryInterface | ImportFailureRepository | Yes | Implemented |
| ImportMapping | ImportMappingRepositoryInterface | ImportMappingRepository | Yes | Implemented |
| Category | CategoryRepositoryInterface | CategoryRepository | Yes | Implemented |
| Merchant | MerchantRepositoryInterface | MerchantRepository | Yes | Implemented |
| Tag | TagRepositoryInterface | TagRepository | Yes | Implemented |
| Analytics (query) | AnalyticsRepositoryInterface | AnalyticsRepository | Yes | Implemented |
| User | UserRepositoryInterface | UserRepository | Yes | Implemented |
| RuleExecutionLog | RuleExecutionLogRepositoryInterface | RuleExecutionLogRepository | Yes | Implemented |
| ConditionGroup | ConditionGroupRepositoryInterface | ConditionGroupRepository | Yes | Implemented |
| RuleCondition | RuleConditionRepositoryInterface | RuleConditionRepository | Yes | Implemented |
| RuleAction | RuleActionRepositoryInterface | RuleActionRepository | Yes | Implemented |
| GoCardlessSyncFailure | GoCardlessSyncFailureRepositoryInterface | GoCardlessSyncFailureRepository | Yes | Implemented |

## Sub-interfaces

- **BaseRepositoryContract**: transaction, find, all, count, exists, forceDelete, create, update, delete.
- **UserScopedRepositoryInterface**: extends BaseRepositoryContract, adds findByUser.
- **NamedRepositoryInterface**: extends UserScopedRepositoryInterface, adds findByUserAndName, firstOrCreate.
- **RuleScopedRepositoryInterface**: extends BaseRepositoryContract, adds findByRule, deleteByRule.

## Traits

- **UserScoped**: findByUser, findByUserAndName, firstOrCreate.
- **RuleScoped**: findByRule, deleteByRule.
- **BatchInsert**: batchInsert (JSON encoding + bulk insert).
