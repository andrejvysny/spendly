/* eslint-env node, commonjs */
/* eslint-disable no-undef */
module.exports = {
  preset: 'ts-jest',
  testEnvironment: 'jsdom',
  transform: {
    '^.+\\.(ts|tsx|js|jsx)$': 'ts-jest',
  },
  moduleNameMapper: {
    '^@/Components/(.*)$': '<rootDir>/resources/js/components/$1',
    '^@/(.*)$': '<rootDir>/resources/js/$1',
    '\\.(css|scss)$': 'identity-obj-proxy',
  },
  setupFilesAfterEnv: ['<rootDir>/jest.setup.ts'],
};
