## Model & Facade Usage Audit (Step 1)

This document inventories Eloquent models and current direct data-access usages across the app codebase to inform a migration to the Repository Pattern.

Generated: 2025-09-13

---

## Models overview

Model | Relations | Traits | SoftDeletes | Timestamps | Primary/Keys
--- | --- | --- | --- | --- | ---
Account | belongsTo(User), hasMany(Transaction) | HasFactory | No | Yes | Default
Category | belongsTo(User), belongsTo(Category as parent), hasMany(Category as children), hasMany(Transaction) | HasFactory | No | Yes | Default
ConditionGroup | belongsTo(Rule), hasMany(RuleCondition) | HasFactory | No | Yes | Default
Import | belongsTo(User), hasMany(ImportFailure), scope pendingFailures | HasFactory | No | Yes | Default
ImportFailure | belongsTo(Import), belongsTo(User as reviewer) | HasFactory | No | Yes | Default
ImportMapping | belongsTo(User) | HasFactory | No | Yes | Default
Merchant | belongsTo(User), hasMany(Transaction) | HasFactory | No | Yes | Default
Rule | belongsTo(User), belongsTo(RuleGroup), hasMany(ConditionGroup, RuleAction, RuleExecutionLog) | HasFactory | No | Yes | Default
RuleAction | belongsTo(Rule) | HasFactory | No | Yes | Default
RuleCondition | belongsTo(ConditionGroup) | HasFactory | No | Yes | Default
RuleExecutionLog | belongsTo(Rule) | HasFactory | No | Yes | Default
RuleGroup | belongsTo(User), hasMany(Rule) | HasFactory | No | Yes | Default
Tag | belongsTo(User), belongsToMany(Transaction) | HasFactory | No | Yes | Default
Transaction | belongsTo(Account, Merchant, Category), belongsToMany(Tag) | HasFactory | No | Yes | Default
TransactionRule | belongsTo(User) | (none) | No | Yes | Default
User | hasMany(Account, Category, Merchant, Tag, RuleGroup, Rule), hasManyThrough(Transaction via Account) | HasFactory, Notifiable | No | Yes | Default

Notes:
- No models use SoftDeletes or override timestamps/primary keys.

---

## Facade/Builder usage scan (app/**/*.php)

Format: file:line — operation

### DB::table
- app/Services/TransactionImport/TransactionPersister.php:116 — DB::table('transactions')->insert(...)
- app/Services/TransactionImport/ImportFailurePersister.php:124 — DB::table('import_failures')->insert(...)
- app/Http/Controllers/Accounts/AccountController.php:154 — DB::table('transactions')->select(...)->groupBy(...)->orderBy(...)->get()
- app/Http/Controllers/AnalyticsController.php:135 — DB::table('transactions')->select(...)
- app/Http/Controllers/AnalyticsController.php:250 — DB::table('transactions')->select(...)
- app/Http/Controllers/AnalyticsController.php:266 — DB::table('transactions')->select(...)
- app/Http/Controllers/AnalyticsController.php:293 — DB::table('transactions')->select(...)
- app/Http/Controllers/AnalyticsController.php:309 — DB::table('transactions')->select(...)
- app/Repositories/TransactionRepository.php:45 — DB::table('transactions')->insert(...)

### DB::transaction
- app/Services/TransactionImport/TransactionPersister.php:84 — DB::transaction(...) (batch insert path)
- app/Services/TransactionImport/TransactionPersister.php:158 — DB::transaction(...) (create path)
- app/Http/Controllers/Import/ImportController.php:41 — DB::transaction(...) (revert import)
- app/Http/Controllers/RuleEngine/RuleController.php:276 — DB::transaction(...) (create rule + children)
- app/Repositories/RuleRepository.php:45 — DB::transaction(...)
- app/Repositories/RuleRepository.php:106 — DB::transaction(...)
- app/Repositories/RuleRepository.php:225 — DB::transaction(...)
- app/Repositories/TransactionRepository.php:77 — DB::transaction(...)
- app/Services/TransactionSyncService.php:122 — DB::transaction(...)
- app/Services/TransactionImport/ImportFailurePersister.php:103 — DB::transaction(...)

### firstOrCreate
- app/Services/RuleEngine/ActionExecutor.php:251 — Tag::firstOrCreate([...])
- app/Services/RuleEngine/ActionExecutor.php:268 — Category::firstOrCreate([...])
- app/Services/RuleEngine/ActionExecutor.php:284 — Merchant::firstOrCreate([...])

### paginate
- app/Http/Controllers/Import/ImportFailureController.php:55 — $query->paginate($perPage)
- app/Http/Controllers/Import/ImportFailureController.php:104 — $query->paginate($perPage)
- app/Http/Controllers/Accounts/AccountController.php:85 — ->paginate(100)
- app/Http/Controllers/Transactions/TransactionController.php:54 — $query->paginate(...)
- app/Http/Controllers/Transactions/TransactionController.php:118 — $query->paginate(...)
- app/Http/Controllers/Transactions/TransactionController.php:193 — $query->paginate(...)

### pluck
- app/Services/RuleEngine/RuleEngine.php:137 — ->pluck('rules')
- app/Services/RuleEngine/ConditionEvaluator.php:64 — $transaction->tags->pluck('name')->toArray()
- app/Http/Controllers/AnalyticsController.php:22 — $user_accounts->pluck('id')
- app/Models/Import.php:106 — ->pluck('count', 'error_type')->toArray()
- app/Http/Controllers/Import/ImportFailureController.php:114 — ->pluck('count', 'error_type')
- app/Http/Controllers/Transactions/TransactionController.php:410 — Auth::user()->accounts()->pluck('id')
- app/Http/Controllers/DashboardController.php:16 — $accounts->pluck('id')
- app/Repositories/TransactionRepository.php:64 — ->pluck('transaction_id')
- app/Http/Controllers/RuleEngine/RuleExecutionController.php:364 — $ruleGroup->rules->pluck('id')->toArray()
- app/Http/Controllers/RuleEngine/RuleController.php:416 — collect(...)->pluck('id')
- app/Http/Controllers/RuleEngine/RuleController.php:419 — ->pluck('id')

### update
- app/Http/Controllers/Import/ImportMappingsController.php:73 — $mapping->update([...])
- app/Http/Controllers/CategoryController.php:78 — $category->update($data)
- app/Http/Controllers/CategoryController.php:94 — $category->transactions()->update([...])
- app/Http/Controllers/CategoryController.php:99 — $category->transactions()->update([...])
- app/Http/Controllers/TagController.php:41 — $tag->update($validated)
- app/Http/Controllers/Import/ImportWizardController.php:155 — $import->update([...])
- app/Http/Controllers/Import/ImportWizardController.php:249 — $import->update([...])
- app/Http/Controllers/Accounts/AccountController.php:197 — $account->update([...])
- app/Http/Controllers/MerchantController.php:41 — $merchant->update($validated)
- app/Http/Controllers/MerchantController.php:57 — $merchant->transactions()->update([...])
- app/Http/Controllers/MerchantController.php:62 — $merchant->transactions()->update([...])
- app/Http/Controllers/Transactions/TransactionController.php:324 — $transaction->update($validated)
- app/Http/Controllers/Transactions/TransactionController.php:365 — $transaction->update($updateData)
- app/Http/Controllers/Transactions/TransactionController.php:395 — $transaction->update($validated)
- app/Services/TokenManager.php:196 — $this->user->update([...])
- app/Http/Controllers/Settings/PasswordController.php:33 — $request->user()->update([...])
- app/Http/Controllers/RuleEngine/RuleController.php:427 — Rule::where('id', ...)->update([...])
- app/Http/Controllers/RuleEngine/RuleController.php:442 — $ruleGroup->update([...])
- app/Http/Controllers/RuleEngine/RuleController.php:459 — $rule->update([...])
- app/Services/TransactionImport/TransactionImportService.php:38 — $import->update([...])
- app/Services/TransactionImport/TransactionImportService.php:79 — $import->update([...])
- app/Services/TransactionImport/TransactionImportService.php:162 — $import->update([...])
- app/Services/RuleEngine/RuleEngine.php:296 — RuleExecutionLog::where(...)?->update([...])
- app/Models/ImportFailure.php:113 — $this->update([...])
- app/Models/ImportFailure.php:126 — $this->update([...])
- app/Models/ImportFailure.php:139 — $this->update([...])
- app/Models/ImportFailure.php:152 — $this->update([...])
- app/Repositories/AccountRepository.php:45 — $account->update([...])
- app/Repositories/RuleRepository.php:35 — $ruleGroup->update([...])
- app/Repositories/RuleRepository.php:108 — $rule->update([...])
- app/Repositories/RuleRepository.php:260 — Rule::where('id', ...)->update([...])
- app/Repositories/TransactionRepository.php:80 — Transaction::where(...)->update([...])

### delete / destroy
- app/Repositories/RuleRepository.php:120 — $rule->conditionGroups()->delete()
- app/Repositories/RuleRepository.php:149 — $rule->actions()->delete()
- app/Repositories/RuleRepository.php:173 — $rule->delete()
- app/Http/Controllers/TagController.php:50 — $tag->delete()
- app/Http/Controllers/CategoryController.php:105 — $category->delete()
- app/Http/Controllers/Accounts/AccountController.php:139 — $account->transactions()->delete()
- app/Http/Controllers/Accounts/AccountController.php:142 — $account->delete()
- app/Http/Controllers/Import/ImportController.php:47 — $transaction->delete()
- app/Http/Controllers/Import/ImportController.php:73 — $import->delete()
- app/Http/Controllers/MerchantController.php:68 — $merchant->delete()
- app/Http/Controllers/Import/ImportMappingsController.php:88 — $mapping->delete()
- app/Http/Controllers/RuleEngine/RuleController.php:232 — $ruleGroup->delete()
- app/Http/Controllers/Settings/BankDataController.php:238 — $user->delete()
- app/Http/Controllers/Settings/ProfileController.php:56 — $user->delete()

---

## Static Model usages (selected highlights)

Note: This section highlights representative locations where models are used directly via Eloquent builders or static methods. It is not exhaustive of every `Model::` call but covers primary hotspots to refactor.

- Transaction
  - app/Services/TransactionImport/TransactionPersister.php:120 — Transaction::query()->whereIn(...)->get()
  - app/Services/TransactionImport/TransactionPersister.php:163 — Transaction::create([...])
  - app/Http/Controllers/Import/ImportController.php:42 — Transaction::where('metadata->import_id', ...)->get()
  - app/Http/Controllers/Transactions/TransactionController.php:276 — Transaction::create([...])
  - app/Http/Controllers/Transactions/TransactionController.php:355 — Transaction::whereIn('id', ...)->get()
  - app/Http/Controllers/Transactions/TransactionController.php:412 — Transaction::with([...])->where(...)
  - app/Services/RuleEngine/RuleEngine.php:76 — Transaction::where('user_id', ...)

- Account
  - app/Http/Controllers/Accounts/AccountController.php:21 — Account::where('user_id', ...)->get()
  - app/Http/Controllers/Accounts/AccountController.php:52 — Account::create([...])
  - app/Http/Controllers/Accounts/AccountController.php:75 — Account::all()->find($id)
  - app/Http/Controllers/Accounts/AccountController.php:136 — Account::where('user_id', ...)->findOrFail($id)
  - app/Http/Controllers/Settings/BankDataController.php:404 — Account::create([...])

- Category / Merchant / Tag (used heavily in Rule Engine actions)
  - app/Services/RuleEngine/ActionExecutor.php:99 — Category::find($id)
  - app/Services/RuleEngine/ActionExecutor.php:114 — Merchant::find($id)
  - app/Services/RuleEngine/ActionExecutor.php:129 — Tag::find($id)
  - app/Services/RuleEngine/ActionExecutor.php:251 — Tag::firstOrCreate([...])
  - app/Services/RuleEngine/ActionExecutor.php:268 — Category::firstOrCreate([...])
  - app/Services/RuleEngine/ActionExecutor.php:284 — Merchant::firstOrCreate([...])

- Import / ImportMapping / ImportFailure
  - app/Http/Controllers/Import/ImportWizardController.php:93 — Import::create([...])
  - app/Http/Controllers/Import/ImportController.php:21 — Import::where('user_id', ...)
  - app/Services/TransactionImport/ImportFailurePersister.php:139 — ImportFailure::create([...])
  - app/Http/Controllers/Import/ImportMappingsController.php:51 — ImportMapping::create([...])

- Rule, RuleGroup, ConditionGroup, RuleAction, RuleExecutionLog
  - app/Services/RuleEngine/RuleEngine.php:63 — Rule::with([...])->active()...
  - app/Services/RuleEngine/RuleEngine.php:125 — RuleGroup::with([...])->active()...
  - app/Services/RuleEngine/RuleEngine.php:262 — RuleExecutionLog::create([...])
  - app/Http/Controllers/RuleEngine/RuleExecutionController.php:56 — Rule::whereIn('id', ...)
  - app/Repositories/RuleRepository.php — centralizes some create/update/delete logic (partial repository already)

- User
  - app/Http/Controllers/Auth/RegisteredUserController.php:39 — User::create([...])
  - Various controllers read relations via $user->accounts(), etc.

---

## Observations

- Batch paths (imports/sync) use DB::table(...) inserts and explicit DB::transaction(...) blocks; these should be wrapped via repositories that expose insertBulk and bulk insert-or-update plus transaction closures.
- Analytics and cashflow endpoints use query builder on transactions; plan domain-specific repository queries for these aggregations to avoid leaking builders.
- Rule Engine performs create-if-not-exists across Tag/Category/Merchant; repositories should expose get-or-create semantics with user scoping.
- Partial repositories already exist (AccountRepository, TransactionRepository, RuleRepository). The migration should consolidate and standardize interfaces and usage across the app.

---

End of audit.
