---
title: React Testing
description: Jest and React Testing Library patterns for the Spendly frontend.
---

## Setup

Spendly uses Jest with ts-jest and React Testing Library:

```bash
npm install --save-dev jest @types/jest ts-jest
npm install --save-dev @testing-library/react @testing-library/jest-dom @testing-library/user-event
```

### Configuration

`jest.config.js`:

```js
module.exports = {
    preset: 'ts-jest',
    testEnvironment: 'jsdom',
    transform: {
        '^.+\\.(ts|tsx)$': 'ts-jest',
    },
    moduleNameMapper: {
        '\\.(css|scss)$': 'identity-obj-proxy',
        '^@/(.*)$': '<rootDir>/resources/js/$1',
    },
    setupFilesAfterEnv: ['<rootDir>/jest.setup.ts'],
};
```

`jest.setup.ts`:

```ts
import '@testing-library/jest-dom';
```

## Unit Tests

**Scope**: Single components, hooks, and utility functions in isolation.

### Best Practices

- Mock external modules and API calls with `jest.mock()` or MSW
- Use snapshot testing sparingly for rarely-changing UI
- Test props and callback behavior, not implementation details

### Example

```tsx
import { render, screen } from '@testing-library/react';
import Button from './Button';

test('renders label', () => {
    render(<Button label="Click me" onClick={() => {}} />);
    expect(screen.getByText(/click me/i)).toBeInTheDocument();
});
```

```bash
npm run test -- --testPathPattern=src/components/Button.test.tsx
```

## Integration Tests

**Scope**: Component interactions, parent-child rendering, context providers.

### Best Practices

- Use RTL queries (`getBy`, `findBy`) to mimic user behavior
- Let RTL handle async updates instead of manual `act()` calls
- Cleanup is automatic with `@testing-library/react` v13+

### Example

```tsx
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import Form from './Form';

test('submits form data', async () => {
    render(<Form />);
    await userEvent.type(screen.getByLabelText(/name/i), 'John');
    await userEvent.click(screen.getByRole('button', { name: /submit/i }));
    expect(await screen.findByText(/submitted: john/i)).toBeInTheDocument();
});
```

## E2E Tests

For full app flows, use Cypress or Playwright (not Jest):

```bash
npx cypress open
```

Keep E2E tests minimal — cover critical paths only.

## Running Tests

```bash
npm run test                    # Watch mode
npm test -- path/to/file       # Single file
npm run test -- --coverage     # With coverage
```
