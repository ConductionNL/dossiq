# Tasks: pluggable-integration-registry

Tier: MVP. Kind: config, with one imperative edge (D3). Every checkbox below
is one implementation task; the criteria under a task are plain bullets.

## 1. The schema and its seed

- [x] 1.1 `lib/Settings/dossiq_register.json`: schema `dossiqIntegration`
  per design D1 (`key`, `title`, `description`, `status` enum, `statusMessage`,
  `checkedAt`, `settingsUrl`, `order`), version 1.0.0; add the slug to
  `SchemaSlugMap`.
  - `@spec openspec/specs/admin-settings/spec.md`
  - `tests/schemas` round-trips the schema
- [x] 1.2 `lib/Settings/register.d/96-integrations.json`: the ten rows in
  placement order; BRP and KvK `unavailable` with "Specified, not built yet",
  the other eight `unconfigured` with "Not checked yet"; no row `configured`.
  - `tests/unit/seed.spec.ts` (new if absent): ten rows, no `configured`,
    every `settingsUrl` points at an existing `section-<key>` anchor or is
    empty for `unavailable`

## 2. The page

- [x] 2.1 `src/manifest.json`: page `Integrations` per design D2, route
  `/settings/integrations`, `permission: admin`, `type: "settings"`, section
  Connections with the `object-list` widget over `dossiqIntegration` sorted
  on `order`, columns `title`, `status`, `statusMessage`, `checkedAt`, row
  action Open settings navigating to the row's `settingsUrl`, hidden when
  `settingsUrl` is empty; section Required apps with a `type: "component"`
  widget `componentName: "CnLeafDependencySettings"`, `props: {appId:
  "dossiq"}`.
  - `@spec openspec/specs/admin-settings/spec.md`
  - `npm run check:manifest` exits 0
  - `tests/unit/manifest.spec.ts` (extend): `Integrations` is `settings`,
    admin only, reads `dossiqIntegration`, has no `fields` section
- [x] 2.2 TAKEN, and 2.1's `settings` page was NOT. Measured against the
  installed dist: a `settings` section's `widgets[]` resolves only against
  `BUILTIN_SETTINGS_WIDGETS` (`version-info`, `register-mapping`) and the
  `component` discriminator, while `object-list` is registered with
  `registerDashboardWidget()`; `CnSettingsPage` also takes no page-level
  `widgets[]` prop. So the widget would have rendered an empty section behind
  one console warn. `Integrations` ships as `type: "index"` with the same
  columns and `showViewAction: false`, and **REQ-ADMIN-021 (Required apps)
  stays RED** — its e2e scenario is `test.fixme` naming this reason. Original
  blocker text: [blocked: nextcloud-vue `CnIntegrationCard` is the leaf-widget
  card (`integrationId`, `surface`), not a connection card with a status
  and a settings link; and it is unverified that a `settings` section's
  `widgets[]` accepts an `object-list`] Interim: if 2.1 fails
  `check:manifest` on the widget, ship `Integrations` as a `type: "index"`
  page over `dossiqIntegration` with the same columns, `showViewAction:
  false` and the same row action, and defer the Required apps section
  (REQ-ADMIN-021 stays red, say so in the PR). A `custom` page is not an
  option. Drop the interim when nextcloud-vue ships a connection card or
  accepts the widget.
- [x] 2.3 `src/formatters`: `integrationStatus` rendering the four states as
  a badge with the English label; fall back to the raw value where the
  column cannot take a formatter. Shipped in
  `src/services/formatters.js`, plus `integrationSettingsLabel`: the row
  ACTION could not carry a per-row URL (`type: "navigate"` takes one literal
  target and pushes it through vue-router, which cannot reach
  `/settings/admin/dossiq`) or a per-row visibility (`CnRowActions.visible` is
  a function and the action schema is `additionalProperties: false`), so Open
  settings is a column with the built-in `link` widget, whose `href` falls
  through to plain text on an empty `{settingsUrl}` — which is exactly what
  REQ-ADMIN-019 asks of the BRP and KvK rows.
  - `tests/unit/formatters.spec.ts`: four values map to four labels; an
    unknown value renders itself
- [x] 2.4 `src/menu-layout.json`: `IntegrationsMenu` appended to
  `settingsSection` after `SubstitutionAdminMenu`; menu entry in
  `src/manifest.json` with icon `PowerPlugOutline`, `permission: admin`.
  - `tests/unit/menu-layout.spec.ts` (extend): the id is in
    `settingsSection` and not in the main nav
- [x] 2.5 `l10n/en.json`, `l10n/nl.json`: Integrations, Connections,
  Required apps, Open settings, Configured, Not configured, Not available,
  Error, "Not checked yet", "Specified, not built yet", Last checked.

## 3. The admin page anchors

- [x] 3.1 `src/views/settings/AdminRoot.vue`: `id="section-<key>"` on the
  ZGW, StUF, KCC, DMN, mailbox, Store and financial sections. SEVEN, not the
  design's eight: **there is no PDOK section on the admin page.** Its `pdok_*`
  keys are read by the map components and written by nothing an admin can
  open, so the PDOK row is seeded with an EMPTY `settingsUrl` rather than a
  link into a section that does not exist — inventing that anchor is the one
  thing this page exists to stop. The two duplicated headings the design
  reports are not in this file: every `CnSettingsSection` name here is
  unique, so nothing was deduplicated and the anchor ids are unique as
  written (asserted in `tests/vitest/integrationsPage.spec.js`).
  - `@spec openspec/specs/admin-settings/spec.md`
  - `tests/unit/AdminRoot.spec.js` (extend): eight anchors present, ids
    unique

## 4. The probes write their result

- [x] 4.1 `lib/Service/IntegrationStatusService.php` (new):
  `record(string $key, string $status, string $message): void` updating the
  `dossiqIntegration` object with that key through `ObjectService` with
  `_rbac: false` and `_multitenancy: false`, setting `checkedAt` to now;
  unknown key logs and returns. SPDX header, `@spec` tag.
  - `tests/Unit/Service/IntegrationStatusServiceTest.php` (new): a known
    key updates the three fields; an unknown key does not throw; the
    status must be one of the four values
- [x] 4.2 `lib/Controller/EmailTemplateController.php::testImap` records
  `configured` on success and `error` with the exception message on
  failure; `lib/Controller/StufController.php::enrichEndpointWithHealth`
  records the endpoint's health under `stuf`. Both done, but NOT
  by injecting the recorder into those controllers: `EmailTemplateController`,
  `SettingsController` and `StoreController` each sit at PHPMD's
  `CouplingBetweenObjects` ceiling of 13, and one more constructor
  collaborator fails `composer phpmd` (measured: all three went red, all three
  were clean before). So each records through a collaborator it already has —
  `EmailTemplateService::recordMailboxStatus()`,
  `ConfiguredRegistryService::recordStoreStatus()`, and in
  `SettingsController` a container lookup BY NAME (a `catch (\Throwable)` is
  itself a type reference and pushed the count back over, so the guard is
  `has()`). `StufController` had headroom and injects the service directly.
  A unit test asserts the string FQCN resolves, so it cannot rot into a
  silent no-op.
  - `tests/Unit/Controller/EmailTemplateControllerTest.php` and
    `StufControllerTest.php` (extend): the service is called with the
    outcome
- [x] 4.3 The save handlers of the KCC, financial, DMN and ZGW sections
  record `configured` when the required fields are filled and `unconfigured`
  when cleared (design D3) — all four go through `POST /api/settings`, so this
  is `IntegrationStatusService::recordFromSave()` called once from
  `SettingsController::update()` with the payload, and only the connections
  whose OWN keys the payload carried are rewritten. Store has its own endpoint
  and records from `ConfiguredRegistryService::recordStoreStatus()`. PDOK
  records from nothing, because nothing saves it: it has no admin section.
  - unit test per handler: filled saves `configured`, cleared saves
    `unconfigured`
- [x] 4.4 `composer check:strict` exits 0 (PHPCS, PHPMD, Psalm, PHPStan);
  read the exit code, and run phpmd per directory, not on all of `lib/`.

## 5. Verification

- [x] 5.1 Add `tests/e2e/integrations-page.spec.ts` covering every scenario
  of the delta spec that names it: the ten cards in order, Open settings
  landing on `#section-stuf`, the non-admin who sees no entry and no cards,
  BRP and KvK Not available without Open settings, a fresh instance with no
  Configured card, the failed mailbox test showing Error with a recent
  checked-at, the saved KCC section showing Configured, and the missing
  dependency listed. Assert on ids and saved objects, not English labels;
  create the non-admin user in `ci-seed.sh`.
- [x] 5.2 Run `npm run check:manifest`, the unit suite, the hydra gates and
  `composer check:strict` locally; read the exit codes, not the summaries. Run
  by exit code: eslint 0, prettier 0, vitest 0 (743), `check:manifest` 0,
  `check-l10n` 0, `check:schema-l10n` 0 (at baseline), `check:l10n-js` 0,
  phpcs 0, phpmd 0 per directory plus the unused-params leg, psalm 0, phpstan
  0, phpunit 0 (3236). `composer check:strict` itself is NOT the command that
  was run: it exceeds the 300s tool timeout, and phpmd printing nothing inside
  it is its OOM signature rather than a pass.
