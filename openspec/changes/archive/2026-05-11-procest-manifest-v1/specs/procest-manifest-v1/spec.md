# Spec — procest-manifest-v1: JSON manifest renderer migration

## ADDED Requirements

### Requirement: REQ-PMV1-1 — Manifest validates against schema v1.2.0

`procest/src/manifest.json` MUST validate against
`node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`
(schema version 1.2.0, draft 2020-12) when checked with Ajv. The
top-level `version` field MUST be `"1.0.0"`. The top-level
`dependencies` field MUST contain `"openregister"`.

#### Scenario: Validator script returns success

- **WHEN** the developer runs `node tests/validate-manifest.js`
- **THEN** the script exits with code `0`
- **AND** prints `[validate-manifest] Ajv validation: PASS (0 errors)`

### Requirement: REQ-PMV1-2 — All current router routes are present in the manifest

The system SHALL satisfy the behaviour described as "REQ-PMV1-2 — All current router routes are present in the manifest".

Every route in the original `src/router/index.js` (`Dashboard`,
`Doorlooptijd`, `MyWork`, `Werkvoorraad`, `Cases`, `CaseDetail`,
`Tasks`, `TaskNew`, `TaskDetail`, `CaseMap`, `Voorstellen`,
`VoorstelDetail`, `Settings`, `CaseTypes`) MUST appear as a
`pages[].id` in the manifest. Page `id`s MUST be unique. Route paths
MUST match the original router's path (so existing
`$router.push({ name: ... })` calls resolve unchanged).

#### Scenario: Existing route names resolve

- **GIVEN** any of the legacy route names listed above
- **WHEN** the renderer builds the vue-router config from the manifest
- **THEN** a route with that `name` exists with the same path

### Requirement: REQ-PMV1-3 — customComponents registry holds documented exceptions only

`src/customComponents.js` MUST export exactly the entries listed in
`design.md`'s "Surviving" section: `MyWorkView`, `WerkvoorraadView`,
`CaseMapView`, `DoorlooptijdView`, `VoorstellenView`,
`VoorstelDetailView`, `AdminRootView`, `PublicCaseView`,
`PublicAppointmentPage`, `PublicStatusPage`, plus the three sidebar-tab
stubs `CaseTasksTab`, `CaseDecisionsTab`, `CaseDocumentsTab`. Adding a
new entry MUST require an updated row in the design doc's
"Custom-fallback inventory" table.

#### Scenario: Each entry has a documented justification

- **GIVEN** any export from `customComponents.js`
- **WHEN** a reviewer cross-references `design.md`
- **THEN** the entry appears in either the Custom-fallback inventory or the
      Sidebar-tab inventory with a one-line justification

### Requirement: REQ-PMV1-4 — Mount-survivable bootstrap

`src/main.js` MUST NOT block Vue mount on translation loading. The
boot sequence MUST:

1. Call `registerTranslations()` inside a `try/catch` block; failures
   log a non-fatal warning and continue.
2. Call `loadTranslations()` fire-and-forget — the returned promise's
   rejection MUST NOT propagate to the mount step.
3. Mount the Vue instance unconditionally.

This mirrors decidesk commit `50e4df7c` where `loadTranslations`
rejecting on a 404 was found to silently kill boot in standard
Nextcloud installs that don't serve `/custom_apps/<app>/l10n/*.json`
through Apache.

#### Scenario: Translation 404 does not crash boot

- **GIVEN** the dev container that returns 404 for `/custom_apps/procest/l10n/en.json`
- **WHEN** the user loads the app
- **THEN** the Vue shell mounts and renders English source strings
- **AND** no uncaught promise rejection appears in the browser console

### Requirement: REQ-PMV1-5 — Vue.extend frozen-component workaround

`src/main.js` MUST shallow-clone `CnPageRenderer` (and the
`defaultPageTypes` / `customComponents` registry maps) before passing
them to vue-router routes / `CnAppRoot` props. This works around Vue 2
`Vue.extend()` mutating the component definition to attach a `_Ctor`
cache, which throws "Cannot add property `_Ctor`, object is not
extensible" against non-extensible barrel exports.

#### Scenario: Router loads without throwing

- **WHEN** the app boots
- **THEN** no `_Ctor` extensibility error appears in the browser console
- **AND** every route's component resolves to a `CnPageRenderer` clone

### Requirement: REQ-PMV1-6 — en_US locale alias mirrors en

`l10n/en_US.json` MUST exist and be byte-identical to `l10n/en.json`.
This prevents the `en_US`-locale `loadTranslations` request from
resolving against a non-existent file (which on the dev container 404s
the file but the on-disk presence is what governs behaviour in
production installs).

#### Scenario: en_US users get English strings

- **GIVEN** a user with browser locale `en_US`
- **WHEN** they load procest
- **THEN** every `t('procest', '...')` call returns the English source

### Requirement: REQ-PMV1-7 — @nextcloud/axios pin via overrides

`package.json` MUST keep `@nextcloud/axios` pinned at `~2.5.2` via the
top-level `overrides` block. Version 2.5.2 still declares both `import`
and `require` export conditions, so `@nextcloud/vue`'s CJS bundle can
`require('@nextcloud/axios')` without webpack 5 tripping on the
exports field. No webpack alias is required when the pin is in place.
Mirrors decidesk's working webpack/package.json setup.

#### Scenario: Production build succeeds

- **WHEN** the developer runs `NODE_ENV=production npx webpack`
- **THEN** the build exits with code `0`
- **AND** no `not exported under conditions` errors appear in the output

### Requirement: REQ-PMV1-8 — Library version pin

`package.json` MUST declare `@conduction/nextcloud-vue` at `^1.0.0-beta.12`
or higher. This pin guarantees the published `Vue.extend` frozen-component
fix and the seven manifest page types
(`index | detail | dashboard | logs | settings | chat | files | custom`)
are present.

#### Scenario: npm install resolves a compatible version

- **GIVEN** `package.json` declares `^1.0.0-beta.12`
- **WHEN** `npm install --legacy-peer-deps` runs
- **THEN** `node_modules/@conduction/nextcloud-vue/package.json` reports a
      version `>= 1.0.0-beta.12`

### Requirement: REQ-PMV1-9 — Catch-all redirect preserved

The renderer MUST add a catch-all route `*` redirecting to `/` after
the manifest pages. This preserves the original router's
`{ path: '*', redirect: '/' }` behaviour (any unknown deep link
returns the user to the dashboard).

#### Scenario: Unknown route redirects

- **GIVEN** a user visits `/apps/procest/some-nonexistent-path`
- **WHEN** vue-router resolves the path
- **THEN** they are redirected to `/`

### Requirement: REQ-PMV1-10 — appinfo version bump

`appinfo/info.xml` `<version>` MUST be bumped to `0.2.0` (minor bump
marking the manifest migration). The Nextcloud app store reads this
field; a version bump triggers cache invalidation for client app
shells.

#### Scenario: info.xml reports new version

- **GIVEN** this change is applied
- **WHEN** a reviewer reads `appinfo/info.xml`
- **THEN** the `<version>` element contains `0.2.0`
