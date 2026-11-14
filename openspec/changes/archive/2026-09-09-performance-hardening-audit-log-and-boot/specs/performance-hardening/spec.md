# Performance Hardening — Audit-log Pagination and Boot Cost

**Spec refs**: New capability. No existing spec owns archief audit-log query shape, production
build devtool config, or manifest reactivity boot cost.

## ADDED Requirements

### Requirement: Audit-log endpoints are bounded and server-filtered

The system MUST NOT serve the full archief audit-log register in a single response, and MUST NOT
filter audit-log rows by loading the entire register into PHP memory first.

#### Scenario: Audit-log endpoint is paginated

- **GIVEN** the `overdracht_audit_log_schema` register holds more rows than one page
- **WHEN** a client calls `GET /api/archief/audit-log`
- **THEN** the response SHALL respect `_limit`/`_offset` query parameters
- **AND** SHALL NOT return every row in the register in one payload

#### Scenario: Batch audit lookup filters server-side

- **GIVEN** a client requests the audit trail for one archiving batch id
- **WHEN** `ArchiefController` resolves the matching audit-log rows
- **THEN** the batch-id filter SHALL be applied by the OpenRegister query (object-field filter),
  not by fetching all rows and scanning them in PHP with `array_filter`/`str_contains`

#### Scenario: Substitution index is bounded

- **GIVEN** the substitution schema register
- **WHEN** `SubstitutionController` resolves the full substitution list for its index/filter logic
- **THEN** the underlying `searchObjectsAsArrays()` call SHALL include a `_limit`, consistent with
  the pagination pattern used elsewhere in this app (e.g. `RaadsinformatieFeedController`)

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
