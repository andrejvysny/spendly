# Artifact Inventory

| Location | Type | Notes | Risk |
|---|---|---|---|
| app/Models/** | Models | 16 Eloquent models | Coverage required |
| app/Contracts/Repositories/** | Interfaces | Base, Account, Transaction, Rule | Missing for others (P1) |
| app/Repositories/** | Implementations | Account, Transaction, Rule, Base | Missing for others (P1) |
| app/Providers/RepositoryServiceProvider.php | Provider | Binds 3 interfaces | Needs more bindings (P1) |
| app/Services/TransactionImport/TransactionPersister.php | Service | Now uses TransactionRepository | Good, verify behavior |
| app/Services/TransactionImport/ImportFailurePersister.php | Service | Uses DB::transaction + DB::table insert | Needs ImportFailureRepository (P1) |
| app/Services/TransactionSyncService.php | Service | Uses DB::transaction | Switch to repo transaction (P2) |
| app/Services/RuleEngine/ActionExecutor.php | Service | Uses firstOrCreate for Tag/Category/Merchant | Add lookups via repos (P2) |
| app/Http/Controllers/** | Controllers | Various direct Model::create / DB::table usage | Replace with repos (P1) |
| app/Http/Controllers/AnalyticsController.php | Controller | Heavy DB::table aggregations | Move to AnalyticsQueryRepository (P1) |
| tests/** | Tests | Ensure unaffected | Validate post-refactor |
| phpstan.neon, pint | Tooling | Lint/analyze | Run and fix |
