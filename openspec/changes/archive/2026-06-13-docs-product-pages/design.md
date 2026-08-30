# Design: docs-product-pages

## Overview

This is a `kind: config` change — no PHP, no Vue, no OpenRegister
schemas. The deliverables are:

- A compliant folder taxonomy under `docs/`
- Two new markdown files (`index.md`, `installation.md`)
- A wired-up `redocusaurus` plugin in `docusaurus.config.js`
- A declared `nl` locale
- Zero em-dash characters in `docs/`

## Target folder structure

```
docs/
  index.md                        # id: intro, sidebar_position: 1
  installation.md                 # prerequisites + App Store install

  Features/
    _category_.json               # label: Features, position: 2
    case-management.md
    task-management.md
    workflow-engine.md

  user-guide/
    _category_.json               # label: Gebruikershandleiding, position: 3
    admin/
      _category_.json
      configure-case-types.md
      manage-users.md
    user/
      _category_.json
      submit-case.md
      track-status.md

  Technical/
    _category_.json               # label: Technisch, position: 4
    architecture.md               # moved from docs root ARCHITECTURE.md
    adr/
      (ADR stubs)

  UseCases/
    _category_.json               # label: Gebruiksscenarios, position: 5
    index.md                      # draft: true stub

  Integrations/
    _category_.json               # label: Integraties, position: 6
    index.md                      # draft: true stub
```

## docusaurus.config.js changes

### i18n block

```js
i18n: {
  defaultLocale: 'en',
  locales: ['en', 'nl'],
  // SSR escape hatch: if nl build fails, fall back to en (ADR-030)
},
```

### plugins block (redocusaurus)

```js
plugins: [
  [
    'redocusaurus',
    {
      specs: [
        {
          id: 'procest-api',
          spec: 'openapi.yaml',
          route: '/api',
        },
      ],
      theme: {
        primaryColor: '#1890ff',
      },
    },
  ],
],
```

### navbar item

```js
{
  label: 'API Documentation',
  to: '/api',
  position: 'right',
},
```

## Seed content examples

The following examples illustrate the expected content for each new
file. Dutch values are used where locale-sensitive.

### docs/index.md

```markdown
---
id: intro
title: Introduction
sidebar_position: 1
---

# Procest

Procest is een Nextcloud-app voor zaakgericht werken. Het stelt
gemeenten en overheidsorganisaties in staat om zaken, taken, rollen en
besluiten te beheren vanuit een vertrouwde Nextcloud-omgeving.

## Aan de slag

- [Installatie](./installation.md)
- [Gebruikershandleiding](./user-guide/user/submit-case.md)
- [Beheerdershandleiding](./user-guide/admin/configure-case-types.md)
```

### docs/installation.md

```markdown
---
id: installation
title: Installation
sidebar_position: 2
---

# Installatie

## Vereisten

- Nextcloud 28 of hoger
- PHP 8.1 of hoger
- OpenRegister app (verplicht)

## Installatie via de App Store

1. Ga naar **Instellingen > Apps > App Store**.
2. Zoek op "Procest".
3. Klik op **Installeren**.
4. Ga na installatie naar **Instellingen > Beheer > Procest** om de
   registers te configureren.

## Configuratie na installatie

Na installatie initialiseert Procest automatisch de benodigde
OpenRegister-registers en schemas via de repair-stap.

## Problemen oplossen

Zie [Technical/architecture.md](./Technical/architecture.md) voor
meer informatie over de systeemarchitectuur.
```

### docs/UseCases/index.md (stub)

```markdown
---
id: use-cases-intro
title: Gebruiksscenarios
sidebar_position: 1
draft: true
---

# Gebruiksscenarios

Documentatie voor gebruiksscenarios wordt hier gepubliceerd zodra
de scenarios zijn uitgewerkt.
```

### docs/Integrations/index.md (stub)

```markdown
---
id: integrations-intro
title: Integraties
sidebar_position: 1
draft: true
---

# Integraties

Integratiegidsen voor externe systemen (OpenConnector, ZGW-APIs,
Pipelinq) worden hier gepubliceerd.
```

### docs/Features/_category_.json

```json
{
  "label": "Features",
  "position": 2,
  "link": {
    "type": "generated-index"
  }
}
```

## Em-dash sweep strategy

Run the following command to locate em-dash characters before commit:

```bash
git grep -E '—' docs/
```

Each occurrence is replaced with one of:
- A hyphen (`-`) for compound adjectives and list separators.
- An en-dash or double-hyphen (`--`) for ranges.
- A colon (`:`) when the em-dash introduces a definition or clause.

The sweep is manual: each replacement is read in context to choose
the grammatically correct substitute. Automated `sed` replacement is
avoided to prevent contextually wrong substitutions.

## Risks and mitigations

| Risk | Mitigation |
|---|---|
| Renaming `tutorials/` to `user-guide/` breaks inbound links | Add `@docusaurus/plugin-client-redirects` entries for any URLs referenced in external systems |
| `redocusaurus` requires a valid `openapi.yaml` at build time | Stub `openapi.yaml` committed alongside the plugin config; replaced with the real spec once the API spec change merges |
| SSR locale build failure for `nl` | ADR-030 escape hatch: `onBrokenMarkdownLinks: 'warn'` + build script that falls back to `en`-only build if nl locale fails |
| `UseCases/` and `Integrations/` stubs cause Docusaurus sidebar warnings | `draft: true` frontmatter suppresses the pages from the sidebar in production builds |
