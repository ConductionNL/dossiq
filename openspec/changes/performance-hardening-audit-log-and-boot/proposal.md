# Proposal: performance-hardening-audit-log-and-boot

kind: code — performance hardening. Not covered by any active change (`remove-unused-map-clustering-dependency`
covers unused Leaflet deps only — a different bundle concern).

## Why

Three independent, verifiable performance issues were found while sweeping procest for
performance/bundle problems not covered by the 2026-07-07 code review:

**1. Unbounded audit-log full-table scan on every read (`lib/Controller/ArchiefController.php`)**

- `auditLog()` (line 359) calls `fetchAll(schemaConfigKey: 'overdracht_audit_log_schema')` — a
  private helper (line 637) that calls `searchObjectsAsArrays(...)` with **no `_limit`/`_offset`
  and no server-side filter**. This backs the public `GET /api/archief/audit-log` route
  (`appinfo/routes.php:571`) and returns the app's *entire* archiving audit-log register as one
  JSON payload, every time the page is opened.
- `batchAuditEvents()` (line 561) does the same full fetch, then filters the *entire* row set
  in PHP for one batch id via `str_contains($row['details'], 'batchId='.$jobId)` (lines 561–567)
  — a linear PHP-side scan of every audit-log row ever written, on every batch-detail view.
- The audit log is append-only and grows forever (one row per zaak/case archiving event across
  the municipality's lifetime), so both endpoints get monotonically slower and the response
  payload monotonically larger — this is not a fixed-size dataset that happens to be unbounded
  today, it is structurally guaranteed to degrade.
- `lib/Controller/SubstitutionController.php` has the same shape: `allSubstitutions()` (line 432)
  calls `searchObjectsAsArrays()` with no filters/limit, used by the substitution index (line 108).
  Lower severity (substitution rows are naturally bounded by staff headcount) but the same
  anti-pattern.

**2. Production build ships full source maps (`webpack.config.js:9`)**

  ```js
  webpackConfig.devtool = isDev ? 'cheap-source-map' : 'source-map'
  ```

  `'source-map'` is webpack's highest-fidelity, largest-artifact devtool setting — a separate
  `.js.map` file per bundle containing the full original source. Nextcloud apps typically serve
  `js/` as static, publicly-reachable assets; shipping full source maps in the production build
  both bloats the deployed artifact and exposes original (unminified, commented) source to anyone
  who requests `<bundle>.js.map`. There is no config narrowing this to a private/internal-only
  artifact.

**3. Manifest passed as a fully-reactive Vue prop with no `markRaw` (`src/main.js`, `src/App.vue`)**

  `src/main.js` builds `builtManifest` from an 82 KB `src/manifest.json` plus ~48 KB of
  `src/manifest.d/*.json` fragments (line 117–119), wraps it in `useAppManifest(...)`
  (line 129), and passes `resolvedManifest.value` as the root `manifest` prop (line 197) into
  `App.vue`, which re-declares it as a plain (line 72) — i.e. **deeply reactive — prop. Vue 2
  walks the entire nested pages/menu/widget tree with `Object.defineProperty` getters/setters on
  every property, on every boot, for a manifest whose content never needs to be reactive at the
  leaf level (only the top-level delta-merge from the backend, already handled by the
  `resolvedManifest` ref, needs reactivity). `grep -rn "markRaw" src/` returns zero results
  anywhere in the app — the reactivity cost is paid on the entire ~130 KB structure with no
  opt-out.

## What Changes

- **REQ-PERF-01**: `ArchiefController::auditLog()` and `batchAuditEvents()` MUST paginate via
  `_limit`/`_offset` (or an equivalent bounded page size) and MUST push the `batchId` filter into
  the `searchObjectsAsArrays()` call (object-field filter) instead of fetching every row and
  filtering in PHP.
- **REQ-PERF-01b**: `SubstitutionController::allSubstitutions()` MUST apply a `_limit` consistent
  with the other list endpoints in this app (same house pattern as `RaadsinformatieFeedController`,
  which already does this correctly).
- **REQ-PERF-02**: `webpack.config.js` MUST NOT emit full `'source-map'` output for production
  builds; use a smaller-footprint, non-source-exposing devtool (e.g. `'nosources-source-map'` or
  disable devtool entirely for `isDev === false`), matching Nextcloud app-store publishing norms.
- **REQ-PERF-03**: The manifest object passed into `App.vue`/`CnAppRoot` MUST be wrapped in Vue's
  `markRaw()` before being handed to the render tree (in `src/main.js`, at the point
  `resolvedManifest.value` — or the merged manifest it is built from — is read), so Vue does not
  recursively instrument the ~130 KB static/semi-static navigation and widget tree with reactivity
  it never needs at the leaf level.

## Impact

- Affected specs: new `performance-hardening` capability (no existing spec owns audit-log
  pagination or build/boot performance).
- Affected code: `lib/Controller/ArchiefController.php`, `lib/Controller/SubstitutionController.php`,
  `webpack.config.js`, `src/main.js`.
- Not BREAKING: all three are internal implementation changes; no API contract or route changes.
