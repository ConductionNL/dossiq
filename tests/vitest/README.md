# Frontend unit tests (Vitest)

Frontend unit tests for Dossiq: pure helpers under `src/utils/`, components,
and the manifest declarations the pages are built from.

## Running

```bash
npm run test          # vitest run (one-shot)
npm run test:unit     # alias
npm run test:unit:watch
```

Or directly: `npx vitest run`.

## Config

`vitest.config.js` runs in the **`node`** environment (the helpers need no
DOM). `@nextcloud/l10n` is aliased to a deterministic stub
(`tests/vitest/stubs/nextcloud-l10n.js`) that returns the English source
string with `{placeholder}` substitution, so translated output is exactly
assertable. Specs live under `tests/vitest/**` as `*.spec.js`; the PHP
`tests/Unit` and ZGW dirs are excluded.

To add component (`.vue`) tests later, switch to the jsdom environment and
add `@vitejs/plugin-vue2` plus a css-noop plugin — see
`launchpad/vitest.config.js` for the full Vue 2 component harness.

## What the suite locks

- The processing-time arithmetic moved to the server with the report that
  shapes it: `tests/Unit/Service/Reporting/ProcessingTimeCalculatorTest.php`
  and `ProcessingTimeRowsTest.php` lock what `doorlooptijdHelpers.js` did
  here. `reportDashboardsDeclaration.spec.js` holds the dashboards'
  manifest declarations to the shapes those endpoints send.
- `durationHelpers.js`: duration validation, component parsing, singular vs
  plural human formatting and Y/M/W/D join order, and error text.

## Pattern (reusable across apps)

1. `npm install -D vitest@^1.6.1 --legacy-peer-deps` (matches the openbuild /
   launchpad pin; `--legacy-peer-deps` only because of a pre-existing eslint
   peer conflict, unrelated to vitest).
2. Add `vitest.config.js` (node env for pure logic; jsdom + plugin-vue2 for
   components) and stub `@nextcloud/*` runtime deps via `resolve.alias`.
3. Add `test` / `test:unit` / `test:unit:watch` scripts to `package.json`.
4. Specs under `tests/vitest/**`, stubs under `tests/vitest/stubs/`.
