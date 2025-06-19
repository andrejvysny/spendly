## Summary

This guide explains how to implement **Unit**, **Integration**, and **E2E** tests for your **React 18 + TypeScript** app using **Jest** and **React Testing Library**. It covers setup, best practices, dependencies, and example commands. By following these steps, you’ll build **reliable**, **maintainable**, and **fast** test suites for your React components.

## Prerequisites

* **Node.js** and **npm** installed
* A **React 18** project bootstrapped with **Vite** and **TypeScript**
* **jest.config.js** and **tsconfig.json** files

## 1. Setup & Dependencies

* Install core testing libraries:

  ````bash
  npm install --save-dev jest @types/jest ts-jest
  npm install --save-dev @testing-library/react @testing-library/jest-dom @testing-library/user-event
  ```  ([jestjs.io](https://jestjs.io/docs/tutorial-react?utm_source=chatgpt.com)) ([testing-library.com](https://testing-library.com/docs/react-testing-library/intro/?utm_source=chatgpt.com))

  ````
* Configure Jest for TypeScript and Vite: in `jest.config.js`:

  ````js
  module.exports = {
    preset: 'ts-jest',
    testEnvironment: 'jsdom',
    transform: {
      '^.+\\.(ts|tsx)$': 'ts-jest',
    },
    moduleNameMapper: {
      '\\.(css|scss)$': 'identity-obj-proxy',
    },
    setupFilesAfterEnv: ['<rootDir>/jest.setup.ts'],
  };
  ```  ([medium.com](https://medium.com/%40vitor.vicen.te/setting-up-jest-js-for-a-vite-ts-js-react-project-the-ultimate-guide-7816f4c8b738?utm_source=chatgpt.com))

  ````
* In `jest.setup.ts`, add custom matchers:

  ````ts
  import '@testing-library/jest-dom';
  ```  ([kentcdodds.com](https://kentcdodds.com/blog/common-mistakes-with-react-testing-library?utm_source=chatgpt.com))
  ````

## 2. Unit Tests

### Scope

* **Single components**, **hooks**, and **utility functions** in **isolation** ([kentcdodds.com](https://kentcdodds.com/blog/static-vs-unit-vs-integration-vs-e2e-tests?utm_source=chatgpt.com)).

### Best Practices

* **Mock** external modules and API calls with `jest.mock()` or **Mock Service Worker (msw)** to isolate behavior ([daily.dev](https://daily.dev/blog/react-functional-testing-best-practices?utm_source=chatgpt.com)).
* Use **snapshot testing** sparingly for UI that rarely changes ([jestjs.io](https://jestjs.io/docs/tutorial-react?utm_source=chatgpt.com)).
* Test **props** and **callback behavior**, not implementation details ([medium.com](https://medium.com/%40ignatovich.dm/best-practices-for-using-react-testing-library-0f71181bb1f4?utm_source=chatgpt.com)).

### Example Test

```tsx
import { render, screen } from '@testing-library/react';
import Button from './Button';

test('renders label', () => {
  render(<Button label="Click me" onClick={() => {}} />);
  expect(screen.getByText(/click me/i)).toBeInTheDocument();
});
```

* Run with: `npm run test -- --testPathPattern=src/components/Button.test.tsx` ([blog.bitsrc.io](https://blog.bitsrc.io/understanding-the-differences-between-unit-tests-and-integration-tests-in-react-component-8e51a1c8aa93?utm_source=chatgpt.com)).

## 3. Integration Tests

### Scope

* **Component interactions**, such as parent-child rendering, context providers, and reducer hooks ([medium.com](https://medium.com/%40ian-white/testing-in-react-75827be47bea?utm_source=chatgpt.com)).
* Verify hooks like **useState**, **useEffect**, and **custom hooks** work together.

### Best Practices

* Use **React Testing Library** queries (`getBy`, `findBy`) to mimic user behavior ([medium.com](https://medium.com/%40ignatovich.dm/best-practices-for-using-react-testing-library-0f71181bb1f4?utm_source=chatgpt.com)).
* Replace manual `act()` calls by letting **RTL** handle async updates ([medium.com](https://medium.com/%40ignatovich.dm/best-practices-for-using-react-testing-library-0f71181bb1f4?utm_source=chatgpt.com)).
* Clean up with `cleanup()` automatically via `@testing-library/react` v13+ ([testing-library.com](https://testing-library.com/docs/react-testing-library/intro/?utm_source=chatgpt.com)).

### Example Test

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

* Run with: `npm run test -- --testPathPattern=src/forms/Form.test.tsx`.

## 4. E2E Tests (Optional)

* For **full app flows**, use **Cypress** or **Playwright**, not Jest ([medium.com](https://medium.com/%40ian-white/testing-in-react-75827be47bea?utm_source=chatgpt.com)).
* Keep **E2E** tests minimal to cover **critical paths**.
* Example: `npx cypress open` and write tests in `cypress/integration`.

---

**Next Steps:**

* Tag tests with `@unit` or `@integration` in Jest config for filtered runs.
* Integrate **msw** to mock REST or GraphQL backends in integration tests.
* Continually refactor tests to keep them **fast**, **reliable**, and **descriptive**.
