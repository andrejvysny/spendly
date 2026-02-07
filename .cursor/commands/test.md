# Run tests

Run backend and frontend tests for Spendly.

1. If Docker is available, prefer: `./scripts/test.sh`
2. Otherwise run separately:
   - Backend: `php artisan test` (or `composer run test`)
   - Frontend: `npm test`
3. If the user mentioned a specific file or test class, run with filter/path:
   - PHP: `php artisan test --filter=ClassNameOrMethodName`
   - Jest: `npm test -- path/to/file.test.tsx`
4. Report pass/fail counts and any failure output.
