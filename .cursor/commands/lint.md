# Run lint and type checks

Run backend and frontend code quality checks.

1. Backend:
   - `./vendor/bin/phpstan analyse` (or `composer run phpstan`)
   - `./vendor/bin/pint` (or `composer run pint`)
2. Frontend:
   - `npm run types` (TypeScript)
   - `npm run lint` (ESLint)
3. Report any errors; fix issues or summarize what needs to be addressed.
