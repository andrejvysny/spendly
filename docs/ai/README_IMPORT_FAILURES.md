# Import Failure Management System

## Overview

This system provides comprehensive tracking and management of failed/skipped transactions during CSV imports, enabling manual review and resolution of import issues.

## Features

✅ **Store every failed/skipped transaction** in the database  
✅ **Track detailed error information** with categorization  
✅ **Provide manual review workflows** for failed imports  
✅ **Enhanced import status tracking** (completed/partial/failed)  
✅ **Bulk operations** for efficient failure management  
✅ **CSV export** for external processing  

## Database Schema

### `import_failures` Table

| Column | Type | Description |
|--------|------|-------------|
| `id` | Primary Key | Unique identifier |
| `import_id` | Foreign Key | Links to `imports` table |
| `row_number` | Integer | Original CSV row number |
| `raw_data` | JSON | Original CSV row data |
| `error_type` | String | Error category (validation_failed, duplicate, processing_error, parsing_error) |
| `error_message` | Text | Human-readable error description |
| `error_details` | JSON | Structured error information |
| `parsed_data` | JSON | Partially parsed data (if available) |
| `metadata` | JSON | Processing context (headers, config, etc.) |
| `status` | String | Review status (pending, reviewed, resolved, ignored) |
| `review_notes` | Text | Manual review notes |
| `reviewed_at` | Timestamp | When review occurred |
| `reviewed_by` | Foreign Key | User who reviewed |

## Models

### `ImportFailure` Model

```php
use App\Models\Import\ImportFailure;

// Constants
ImportFailure::ERROR_TYPE_VALIDATION_FAILED
ImportFailure::ERROR_TYPE_DUPLICATE  
ImportFailure::ERROR_TYPE_PROCESSING_ERROR
ImportFailure::ERROR_TYPE_PARSING_ERROR

ImportFailure::STATUS_PENDING
ImportFailure::STATUS_REVIEWED
ImportFailure::STATUS_RESOLVED
ImportFailure::STATUS_IGNORED

// Relationships
$failure->import()     // BelongsTo Import
$failure->reviewer()   // BelongsTo User

// Scopes
ImportFailure::pending()
ImportFailure::reviewed()
ImportFailure::byErrorType('duplicate')

// Methods
$failure->markAsReviewed($user, $notes)
$failure->markAsResolved($user, $notes)
$failure->markAsIgnored($user, $notes)
```

### `Import` Model Updates

```php


// New relationships
$import->failures()        // HasMany ImportFailure
$import->pendingFailures() // HasMany ImportFailure (pending only)

// New methods
$import->getFailureStats() // Returns statistics array
```

## Services

### `ImportFailurePersister`

Handles storing failed/skipped transactions during import processing.

```php
use App\Services\TransactionImport\ImportFailurePersister;

$persister = new ImportFailurePersister();
$stats = $persister->persistFailures($batchResult, $import);
// Returns: ['failures_stored' => X, 'skipped_stored' => Y, 'total_stored' => Z]
```

### Updated `TransactionImportService`

Now automatically persists failures during import process and updates status logic:

```php
// Enhanced status determination:
// - completed: All transactions successful
// - completed_skipped_duplicates: Success with minor skips
// - partially_failed: Some failures or >10% skipped
// - failed: All transactions failed
```

## API Endpoints

### List Import Failures
```
GET /api/imports/{import}/failures
Parameters:
- error_type: Filter by error type
- status: Filter by review status  
- search: Search in error messages/data
- per_page: Pagination size (default: 15)
```

### Get Failure Statistics
```
GET /api/imports/{import}/failures/stats
Returns: Counts by status and error type
```

### Get Specific Failure
```
GET /api/imports/{import}/failures/{failure}
Returns: Detailed failure information
```

### Mark as Reviewed
```
PATCH /api/imports/{import}/failures/{failure}/reviewed
Body: { "notes": "Optional review notes" }
```

### Mark as Resolved
```
PATCH /api/imports/{import}/failures/{failure}/resolved
Body: { "notes": "Optional resolution notes" }
```

### Mark as Ignored
```
PATCH /api/imports/{import}/failures/{failure}/ignored
Body: { "notes": "Optional ignore reason" }
```

### Bulk Update
```
PATCH /api/imports/{import}/failures/bulk
Body: {
  "failure_ids": [1, 2, 3],
  "action": "reviewed|resolved|ignored",
  "notes": "Optional bulk notes"
}
```

### Export as CSV
```
GET /api/imports/{import}/failures/export
Parameters: Same filters as list endpoint
Returns: CSV file download
```

## Usage Examples

### Manual Review Workflow

```php
// Get pending failures for review
$import = Import::find(1);
$pendingFailures = $import->pendingFailures()->paginate(20);

// Review individual failures
foreach ($pendingFailures as $failure) {
    if ($failure->error_type === ImportFailure::ERROR_TYPE_DUPLICATE) {
        $failure->markAsIgnored($user, 'Acceptable duplicate');
    } else {
        $failure->markAsReviewed($user, 'Needs correction');
    }
}

// Get statistics
$stats = $import->getFailureStats();
/*
[
    'total_failures' => 25,
    'pending_review' => 15,
    'reviewed' => 10,
    'by_type' => [
        'duplicate' => 8,
        'validation_failed' => 12,
        'processing_error' => 5
    ]
]
*/
```

### Querying Failures

```php
// Get validation failures
$validationFailures = ImportFailure::byErrorType('validation_failed')
    ->where('import_id', $importId)
    ->pending()
    ->get();

// Search in error messages
$searchResults = ImportFailure::where('import_id', $importId)
    ->where('error_message', 'like', '%amount%')
    ->get();

// Get failures with parsed data
$recoverableFailures = ImportFailure::where('import_id', $importId)
    ->whereNotNull('parsed_data')
    ->pending()
    ->get();
```

## Integration Points

### Existing Import Flow
The system integrates seamlessly with the existing import process:

1. `TransactionImportService::processImport()` calls both:
   - `TransactionPersister::persistBatch()` for successful transactions
   - `ImportFailurePersister::persistFailures()` for failed/skipped transactions

2. Status determination updated to account for failures
3. Logging enhanced with failure statistics

### Authorization
Uses existing `ImportPolicy` for access control - users can only access failures for their own imports.

## Testing

### Factory Usage
```php
// Create various failure types
ImportFailure::factory()->count(5)->create(['import_id' => $import->id]);
ImportFailure::factory()->duplicate()->create(['import_id' => $import->id]);
ImportFailure::factory()->validationFailed()->create(['import_id' => $import->id]);
ImportFailure::factory()->reviewed()->create(['import_id' => $import->id]);
```

### Feature Tests
Run comprehensive tests:
```bash
php artisan test tests/Feature/Import/ImportFailureTest.php
```

## Performance Considerations

### Batch Processing
- Failures are processed in batches of 100 for optimal performance
- Bulk insert operations used for database efficiency
- Graceful fallback to individual inserts if batch fails

### Indexing
Strategic database indexes for efficient querying:
- `import_id` + `error_type`
- `import_id` + `status`  
- `status` + `created_at`

### Memory Management
- CSV export uses chunked processing (100 records at a time)
- Failure persister flushes batches automatically
- JSON fields properly encoded to prevent memory bloat

## Error Types Classification

| Error Type | When Used | Examples |
|------------|-----------|----------|
| `validation_failed` | Data validation errors | Missing fields, invalid formats |
| `duplicate` | Duplicate transaction detection | Same transaction already exists |
| `processing_error` | Business logic errors | Account not found, currency issues |
| `parsing_error` | CSV parsing issues | Malformed CSV, encoding problems |

## Migration Path

For existing systems:
1. Run migration: `php artisan migrate`
2. Deploy updated code
3. Existing imports continue working
4. New imports automatically store failures
5. Optionally backfill historical failure data if needed

## Monitoring & Alerting

Consider monitoring:
- High failure rates (>20% of transactions failing)
- Failures pending review for >24 hours
- Specific error types increasing over time
- Import completion rates by user/source 
