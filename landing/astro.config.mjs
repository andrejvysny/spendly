import { defineConfig } from 'astro/config';
import react from '@astrojs/react';
import tailwind from '@astrojs/tailwind';
import starlight from '@astrojs/starlight';

export default defineConfig({
  site: 'https://spendly.dev',
  integrations: [
    starlight({
      title: 'Spendly Docs',
      description: 'Documentation for Spendly — open-source self-hosted personal finance tracker.',
      social: [
        { icon: 'github', label: 'GitHub', href: 'https://github.com/andrejvysny/spendly' },
      ],
      customCss: [],
      sidebar: [
        {
          label: 'Getting Started',
          items: [
            { slug: 'docs/getting-started/installation' },
            { slug: 'docs/getting-started/development' },
            { slug: 'docs/getting-started/contributing' },
          ],
        },
        {
          label: 'Guides',
          items: [
            { slug: 'docs/guides/deployment' },
            { slug: 'docs/guides/app-key' },
            { slug: 'docs/guides/csv-import' },
            { slug: 'docs/guides/bank-sync' },
            { slug: 'docs/guides/import-troubleshooting' },
          ],
        },
        {
          label: 'Architecture',
          items: [
            { slug: 'docs/architecture/overview' },
            { slug: 'docs/architecture/rule-engine' },
            { slug: 'docs/architecture/recurring-payments' },
            { slug: 'docs/architecture/fulltext-search' },
            { slug: 'docs/architecture/frontend' },
          ],
        },
        {
          label: 'API Reference',
          items: [
            { slug: 'docs/api' },
          ],
        },
        {
          label: 'Testing',
          items: [
            { slug: 'docs/testing' },
            { slug: 'docs/testing/laravel' },
            { slug: 'docs/testing/react' },
          ],
        },
      ],
    }),
    react(),
    tailwind({ applyBaseStyles: false }),
  ],
  output: 'static',
});
