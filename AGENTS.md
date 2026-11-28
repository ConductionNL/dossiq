# AGENTS.md

Guidance for coding agents working in this repository. Humans can read it too; the
longer versions live in [README.md](README.md), [DEVELOPMENT.md](DEVELOPMENT.md) and
[CONTRIBUTING.md](CONTRIBUTING.md).

## What this app is

Dossiq is a Nextcloud app for case management (*zaakgericht werken*): case types with
configurable status lifecycles, deadlines, roles and formal decisions. It is a thin
client on [OpenRegister](https://github.com/ConductionNL/openregister): cases, case
types and their related records are stored as OpenRegister objects.

## Setup

```bash
composer install     # also points git at .githooks (post-install-cmd)
npm install          # also points git at .githooks (prepare)
```

Run it locally with Docker in one of two ways, both described in the README:

- Evaluation stack from this repo: `docker compose -f docker-compose.yml up -d --build`,
  then open http://localhost:8080/apps/dossiq/ (user `admin`, password `admin`).
  `docker compose -f docker-compose.yml down -v` removes it.
- Shared development environment: the app is volume-mounted into the
  OpenRegister dev stack, see [DEVELOPMENT.md](DEVELOPMENT.md).

Demo data: `occ dossiq:demo:seed` (see DEVELOPMENT.md).

## Build

```bash
npm run build        # production frontend build (webpack)
npm run dev          # development build
npm run watch        # development build, rebuilds on change
```

## Test

```bash
composer test:unit   # PHPUnit, tests/Unit (phpunit.xml)
npm test             # Vitest frontend unit tests
npm run test:e2e     # Playwright, tests/e2e (needs a running Nextcloud)
npm run test:e2e:install   # one-time Chromium download for Playwright
npm run test:zgw     # ZGW API conformance tests (tests/zgw)
```

Playwright has no default target, on purpose: the suite seeds and deletes OpenRegister
objects. Set `PLAYWRIGHT_BASE_URL` to an instance of your own, for example
`PLAYWRIGHT_BASE_URL=http://localhost:8095 npm run test:e2e`. Never point it at the
shared development container; read the header of `tests/e2e/base-url.ts` first.

## Lint and quality

```bash
composer check:strict   # lint, refusal-grounds snapshot, phpcs, phpmd, psalm, phpstan, tests
composer check          # quicker: lint, phpcs, psalm, unit tests
composer cs:fix         # php-cs-fixer
composer phpcs:fix      # phpcbf
npm run lint            # ESLint
npm run stylelint       # Stylelint
npm run format          # Prettier check
npm run test:l10n       # every t()/n() string has an en catalogue entry
npm run check:manifest  # validate src/manifest.json
```

`composer check:strict` is the gate CI enforces. PHPMD and PHPStan run without a
baseline: fix violations at the source, do not add a baseline file.

## OpenSpec flow

Work is specified before it is built, with [OpenSpec](openspec/config.yaml):

1. A change starts in `openspec/changes/<change-name>/` with `proposal.md`,
   `design.md`, `tasks.md` and delta specs under `specs/`.
2. When it is built, its requirements are merged into `openspec/specs/<capability>/`.
3. The finished change moves to `openspec/changes/archive/`.

## Architecture decisions

- `docs/adr/` holds this repo's numbered ADRs.
- `openspec/architecture/` holds the architecture records `adr-000` to `adr-005`.
- `docs/decisions/` holds ownership decisions against neighbouring apps.

## Conventions

- **Code is generic and named by what it does; procedures are configuration.**
  Recorded as decision 182 in
  `openspec/changes/archive/2026-11-24-hydra-gates-green-on-development/proposal.md`. A file named after a
  procedure term counts against `.procedure-code-baseline.json`, and a growing count
  fails the quality gate.
- **Pages are manifest config.** Pages and menu entries are declared in
  `src/manifest.json`. New pages go in a fragment under `src/manifest.d/`, which
  `src/main.js` merges on top of the base (see `src/manifest.d/README.md`).
- **Data lives in OpenRegister.** The register and its schemas ship as JSON in
  `lib/Settings/dossiq_register.json`; extend a schema there before reaching for
  app-owned storage.
- **Every user-visible string is translated** with `t('dossiq', ...)` and checked by
  `npm run test:l10n`.

## Commits

[Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/): `feat:`, `fix:`,
`docs:`, `refactor:`, `chore:`. Present tense, imperative mood, first line at most 72
characters. Pull requests target `development`.
