// @ts-check

/**
 * Procest documentation site.
 *
 * Built on @conduction/docusaurus-preset for brand defaults (tokens,
 * theme swizzles for Navbar / Footer, four-locale i18n scaffolding,
 * KvK / BTW copyright). Site-specific overrides — locales, sidebar
 * path, mermaid theme, custom prism themes, procest-only navbar items —
 * are passed through createConfig() opts.
 */

const { createConfig, baseFooterLinks } = require('@conduction/docusaurus-preset');

/* createConfig replaces themes wholesale when `themes:` is passed, so
   we re-include the brand theme plugin alongside @docusaurus/theme-mermaid.
   Without the brand theme entry the Navbar/Footer swizzles and
   brand.css auto-load would silently drop. */
const BRAND_THEME = require.resolve('@conduction/docusaurus-preset/theme');

const config = createConfig({
  title: 'Procest',
  tagline: 'Case management for Nextcloud',
  url: 'https://procest.conduction.nl',
  baseUrl: '/',

  organizationName: 'ConductionNL',
  projectName: 'procest',

  i18n: {
    defaultLocale: 'en',
    locales: ['en', 'nl'],
    localeConfigs: {
      en: { label: 'English' },
      nl: { label: 'Nederlands' },
    },
  },

  /* The procest docs source lives at the repo root of `docs/` rather
     than under a `docs/` subfolder, so we override the preset's default
     `presets:` block to point `docs.path` at './' and disable the blog
     plugin. customCss carries procest-specific CSS only — brand tokens
     and the theme swizzles are auto-loaded by the brand theme entry in
     `themes:` below. */
  presets: [
    [
      'classic',
      {
        docs: {
          path: './',
          /* docs.path: './' makes plugin-content-docs scan every file
             in docs/, which collides with plugin-content-pages's own
             scan of docs/src/pages/. The same index would then get
             processed by both plugins; the docs side runs MDX-ESM
             over the JSX expression body and trips on it. Exclude
             src/ (pages live there) plus the standard node_modules
             bucket. */
          exclude: ['**/node_modules/**', 'src/**'],
          sidebarPath: require.resolve('./sidebars.js'),
          editUrl: 'https://github.com/ConductionNL/procest/tree/main/docs/',
          /* Compute a "Last updated on …" timestamp per doc from the
             git commit history. This populates the docs plugin's
             lastUpdatedAt metadata which the sitemap plugin reads
             when emitting <lastmod>. Without this, only pages with
             an explicit `last_update:` frontmatter field get a
             timestamp. Required for the AI-baseline validator
             threshold (>= 50% of URLs must carry <lastmod>). */
          showLastUpdateTime: true,
        },
        blog: false,
        sitemap: {
          /* Docusaurus 3.5+ made sitemap <lastmod> opt-in. Setting
             lastmod: 'date' tells the plugin to emit YYYY-MM-DD
             lastmod for every URL whose docs metadata carries a
             lastUpdatedAt (populated by showLastUpdateTime above
             on every commit-tracked source file). */
          lastmod: 'date',
        },
        theme: {
          customCss: require.resolve('./src/css/custom.css'),
        },
      },
    ],
    [
      'redocusaurus',
      {
        specs: [
          {
            spec: 'static/oas/procest.json',
            route: '/api/',
          },
        ],
      },
    ],
  ],

  themes: [BRAND_THEME, '@docusaurus/theme-mermaid'],

  /* Brand navbar provides locale dropdown + GitHub by default; we
     replace items[] with procest's own (Documentation sidebar link,
     procest GitHub link, locale dropdown). Object.assign in
     createConfig is shallow, so items: replaces wholesale. */
  navbar: {
    items: [
      {
        type: 'docSidebar',
        sidebarId: 'tutorialSidebar',
        position: 'left',
        label: 'Documentation',
      },
      {
        to: '/api/',
        label: 'API Documentation',
        position: 'left',
      },
      {
        href: 'https://github.com/ConductionNL/procest',
        label: 'GitHub',
        position: 'right',
      },
      { type: 'localeDropdown', position: 'right' },
    ],
  },

  /* Per-property footer override (preset 1.2.0+): we pass `links` only,
     so the brand `style: 'dark'` and the brand KvK/BTW/IBAN/address
     copyright string both inherit unchanged. Single column: the brand
     "Conduction" anchor. Site-specific Product / Support columns may
     be added later. */
  footer: {
    links: [
      ...baseFooterLinks().filter((column) => column.title === 'Conduction'),
    ],
  },

  /* Drop the canal-footer's boat-sinking + kade-cyclist mini-games
     on this product-page footer (preset 1.3.0+). The static skyline +
     canal decoration are kept; the interactive layer goes away. */
  minigames: false,

  /* themeConfig is shallow-merged into the preset's defaults
     (colorMode + navbar + footer). prism + mermaid land alongside. */
  themeConfig: {
    image: 'img/og-procest.png',
    prism: {
      theme: require('prism-react-renderer/themes/github'),
      darkTheme: require('prism-react-renderer/themes/dracula'),
    },
    mermaid: {
      theme: { light: 'default', dark: 'dark' },
    },
  },
});

/* createConfig doesn't pass-through arbitrary top-level fields; assign
   markdown directly so it makes it into the final Docusaurus config. */
config.markdown = {
  mermaid: true,
  /* Tutorial pages (docs/tutorials/) reference screenshots populated by
     tests/e2e/docs-screenshots.spec.ts. The Playwright capture run is
     separate from the docs build, so the build must succeed even when a
     fresh checkout doesn't have every PNG yet. Warn instead of failing
     (ADR-030); flip to 'throw' once all screenshots are committed. */
  hooks: {
    onBrokenMarkdownImages: 'warn',
  },
};

module.exports = config;
