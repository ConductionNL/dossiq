# Tasks: pluggable-integration-registry

Tier: MVP. Kind: config, with one imperative edge (D3). Every checkbox below
is one implementation task; the criteria under a task are plain bullets.

## 1. The schema and its seed

- [ ] 1.1 `lib/Settings/dossiq_register.json`: schema `dossiqIntegration`
  per design D1 (`key`, `title`, `description`, `status` enum, `statusMessage`,
  `checkedAt`, `settingsUrl`, `order`), version 1.0.0; add the slug to
  `SchemaSlugMap`.
  - `@spec openspec/specs/admin-settings/spec.md`
  - `tests/schemas` round-trips the schema
- [ ] 1.2 `lib/Settings/register.d/96-integrations.json`: the ten rows in
  placement order; BRP and KvK `unavailable` with "Specified, not built yet",
  the other eight `unconfigured` with "Not checked yet"; no row `configured`.
  - `tests/unit/seed.spec.ts` (new if absent): ten rows, no `configured`,
    every `settingsUrl` points at an existing `section-<key>` anchor or is
    empty for `unavailable`

## 2. The page

- [ ] 2.1 `src/manifest.json`: page `Integrations` per design D2, route
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
- [ ] 2.2 [blocked: nextcloud-vue `CnIntegrationCard` is the leaf-widget
  card (`integrationId`, `surface`), not a connection card with a status
  and a settings link; and it is unverified that a `settings` section's
  `widgets[]` accepts an `object-list`] Interim: if 2.1 fails
  `check:manifest` on the widget, ship `Integrations` as a `type: "index"`
  page over `dossiqIntegration` with the same columns, `showViewAction:
  false` and the same row action, and defer the Required apps section
  (REQ-ADMIN-021 stays red, say so in the PR). A `custom` page is not an
  option. Drop the interim when nextcloud-vue ships a connection card or
  accepts the widget.
- [ ] 2.3 `src/formatters`: `integrationStatus` rendering the four states as
  a badge with the English label; fall back to the raw value where the
  column cannot take a formatter.
  - `tests/unit/formatters.spec.ts`: four values map to four labels; an
    unknown value renders itself
- [ ] 2.4 `src/menu-layout.json`: `IntegrationsMenu` appended to
  `settingsSection` after `SubstitutionAdminMenu`; menu entry in
  `src/manifest.json` with icon `PowerPlugOutline`, `permission: admin`.
  - `tests/unit/menu-layout.spec.ts` (extend): the id is in
    `settingsSection` and not in the main nav
- [ ] 2.5 `l10n/en.json`, `l10n/nl.json`: Integrations, Connections,
  Required apps, Open settings, Configured, Not configured, Not available,
  Error, "Not checked yet", "Specified, not built yet", Last checked.

## 3. The admin page anchors

- [ ] 3.1 `src/views/settings/AdminRoot.vue`: `id="section-<key>"` on the
  ZGW, StUF, KCC, DMN, mailbox, Store, financial and PDOK sections per
  design D4; fix the two duplicated headings (AI-Assisted Processing, Case
  Email) while there, since a duplicate id would break the anchor.
  - `@spec openspec/specs/admin-settings/spec.md`
  - `tests/unit/AdminRoot.spec.js` (extend): eight anchors present, ids
    unique

## 4. The probes write their result

- [ ] 4.1 `lib/Service/IntegrationStatusService.php` (new):
  `record(string $key, string $status, string $message): void` updating the
  `dossiqIntegration` object with that key through `ObjectService` with
  `_rbac: false` and `_multitenancy: false`, setting `checkedAt` to now;
  unknown key logs and returns. SPDX header, `@spec` tag.
  - `tests/Unit/Service/IntegrationStatusServiceTest.php` (new): a known
    key updates the three fields; an unknown key does not throw; the
    status must be one of the four values
- [ ] 4.2 `lib/Controller/EmailTemplateController.php::testImap` records
  `configured` on success and `error` with the exception message on
  failure; `lib/Controller/StufController.php::enrichEndpointWithHealth`
  records the endpoint's health under `stuf`.
  - `tests/Unit/Controller/EmailTemplateControllerTest.php` and
    `StufControllerTest.php` (extend): the service is called with the
    outcome
- [ ] 4.3 The save handlers of the KCC, Store, financial, DMN, ZGW and PDOK
  sections record `configured` when the required fields are filled and
  `unconfigured` when cleared (design D3).
  - unit test per handler: filled saves `configured`, cleared saves
    `unconfigured`
- [ ] 4.4 `composer check:strict` exits 0 (PHPCS, PHPMD, Psalm, PHPStan);
  read the exit code, and run phpmd per directory, not on all of `lib/`.

## 5. Verification

- [ ] 5.1 Add `tests/e2e/integrations-page.spec.ts` covering every scenario
  of the delta spec that names it: the ten cards in order, Open settings
  landing on `#section-stuf`, the non-admin who sees no entry and no cards,
  BRP and KvK Not available without Open settings, a fresh instance with no
  Configured card, the failed mailbox test showing Error with a recent
  checked-at, the saved KCC section showing Configured, and the missing
  dependency listed. Assert on ids and saved objects, not English labels;
  create the non-admin user in `ci-seed.sh`.
- [ ] 5.2 Run `npm run check:manifest`, the unit suite, the hydra gates and
  `composer check:strict` locally; read the exit codes, not the summaries.
