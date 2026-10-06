# performance-hardening Specification

## Purpose

Three ways dossiq used to get slow, closed. Register reads behind list
endpoints are bounded. The production build ships no full source maps. The app
manifest is not made deeply reactive at boot.

The first requirement used to be about the archief audit log as well: its
`GET /api/archief/audit-log` endpoint was to be paginated and its batch lookup
filtered server-side. That endpoint and its `ArchiefController` were retired
in `8d5c4b1c0` (archival moved to OpenRegister, ADR-022), and nothing in dossiq
serves `overdracht_audit_log_schema` any more, so those two scenarios retired
with the surface (dossiq#2583).

## Requirements

### Requirement: Register reads behind list endpoints are bounded

The system MUST NOT load an entire register into PHP memory to serve a list
endpoint. A read that fetches a whole schema's rows for the caller to filter
SHALL carry a `_limit`.

#### Scenario: Substitution index is bounded

- **GIVEN** the substitution schema register
- **WHEN** `SubstitutionController::index()` lists substitutions, which it delegates to
  `SubstitutionAccessGuard::listVisibleTo()`
- **THEN** the guard's underlying `searchObjectsAsArrays()` call for the full substitution list SHALL
  include a `_limit` (`SubstitutionAccessGuard::SUBSTITUTION_LIMIT`), and the controller SHALL
  make no object search of its own

### Requirement: Production build does not ship full source maps

The webpack production build MUST NOT emit a full-fidelity `'source-map'` devtool artifact that
exposes original, unminified source alongside the deployed bundle.

#### Scenario: Production devtool is not full source-map

- **GIVEN** `webpack.config.js` builds with `isDev === false`
- **WHEN** the `devtool` option is resolved
- **THEN** it SHALL NOT be `'source-map'`
- **AND** SHALL either be a non-source-exposing variant (e.g. `'nosources-source-map'`) or absent

### Requirement: App manifest is not deeply reactive at boot

The manifest object passed into the Vue render tree MUST be marked non-reactive (`markRaw`) so
Vue does not instrument the entire navigation/widget tree with per-property reactivity on boot.

#### Scenario: Manifest prop is markRaw'd

- **GIVEN** `src/main.js` builds the merged manifest from `manifest.json` + `manifest.d/*.json`
  fragments and the backend `/api/manifest` delta
- **WHEN** the manifest is passed as a prop into the root `App` component
- **THEN** the object SHALL be wrapped in Vue's `markRaw()` before assignment
- **AND** the case-type-navigation backend delta SHALL still update the rendered nav without a
  full page reload (via ref reassignment, not deep-property reactivity)
