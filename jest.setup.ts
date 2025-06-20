import '@testing-library/jest-dom';

// Stub lucide-react icons for tests
import React from 'react';

jest.mock('lucide-react', () => {
  return new Proxy(
    {},
    {
      get: (_target, prop: string) => (props: React.SVGProps<SVGSVGElement>) =>
        React.createElement('svg', { ...props, 'data-icon': prop }),
    },
  );
});
