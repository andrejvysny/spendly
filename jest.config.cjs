/* eslint-env node, commonjs */
/* eslint-disable no-undef */
module.exports = {
  preset: 'ts-jest',
  testEnvironment: 'jsdom',
  // Only Spendly's own frontend tests — never crawl vendored research repos or sub-projects.
  roots: ['<rootDir>/resources/js'],
  testPathIgnorePatterns: ['/node_modules/', '/spendly-research/', '/ml-intern/', '/vendor/'],
  modulePathIgnorePatterns: ['<rootDir>/spendly-research/', '<rootDir>/ml-intern/'],
  transform: {
    '^.+\\.(ts|tsx)$': 'ts-jest',
  },
  moduleNameMapper: {
    '^@/(.*)$': '<rootDir>/resources/js/$1',
    '\\.(css|scss)$': 'identity-obj-proxy',
  },
  setupFilesAfterEnv: ['<rootDir>/jest.setup.ts'],
};
