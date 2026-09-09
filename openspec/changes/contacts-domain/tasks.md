# Tasks: contacts-domain

Tier: V1. Kind: config. Every checkbox below is one implementation task; the
criteria under a task are plain bullets. Depends on `requester-on-the-case`
(the picker on `requester`, the initiator card link) and reuses
`parties-on-the-case` nothing but its e2e helpers.

## 1. The menu entry

- [x] 1.1 `src/manifest.json` `menu`: add `{"id": "Contacts", "label":
  "Contacts", "icon": "AccountGroupOutline", "route": "Contacts", "order":
  25}`. Do not touch `src/menu-layout.json`.
  - `@spec openspec/specs/nav-dedup-and-grouping/spec.md`
  - `npm run check:manifest` exits 0
- [x] 1.2 Unit test in `tests/vitest/contactsDomain.spec.js`, NOT
  `tests/unit/` — `tests/unit/**` is excluded from the vitest run, so a spec
  written there would never execute and would look like a passing guard.
  `Contacts` appears in none of `relocations`, `removals`, `settingsSection`
  or `integrationsSection`. THE EFFECTIVE TOP LEVEL IS FIVE, NOT THREE, and
  the test says five: `CaseObjectsMenu` (Objects, from
  `custom-objects-on-the-case`) was never relocated into the work group, and
  `SettingsGroup` is the gear group's own header. So this change spends the
  FIFTH of ADR-097's six slots, and the next domain is the last that fits.
  The design and REQ-PNDG-006's scenario both say three; asserting three
  would have been a test that fails on a correct tree.
- [x] 1.3 Update the navigation e2e: `tests/e2e/navigation.spec.ts` (the
  loop over top-level leaves, today `['Dashboard']`, gains `Contacts`) and
  `tests/e2e/spec-coverage/work-navigation.spec.ts` (the top-level order
  assertion, if it counts leaves). Grep `tests/e2e` for `Contacts` first:
  the only hit today is the retired case-panels tab in
  `case-detail-kpis-and-tabs.spec.ts`, which `parties-on-the-case` removes.

## 2. The Contacts index

- [x] 2.1 (minus the folderSidebar, see 2.2) `src/manifest.json` page
  `Contacts` (`route: /contacts`, `type:
  index`, `title: Contacts`, `config.register: dossiq`, `config.schema:
  brpPerson`, `columns` `displayName`, `citizenServiceNumber`,
  `residence.address`, `description`; `allowSavedViews: true`;
  `showViewAction: false`; `actions` with a `view` handler `navigate` to
  `ContactDetail`; `sidebar.enabled`; `folderSidebar` `source: custom`,
  `filterField: @self.schema`, `allLabel: All contacts`, folders People
  (`id: brpPerson`, `schema: brpPerson`) and Organisations (`id: kvkCompany`,
  `schema: kvkCompany`, `hidden: true`, its own `columns`).
  - `@spec openspec/specs/initiator-display/spec.md`
  - `npm run check:manifest` exits 0; `_note` on the page says why the
    second folder is hidden
- [ ] 2.2 STILL BLOCKED, and the folderSidebar of 2.1 is NOT SHIPPED AT ALL
  rather than shipped hidden, because two things measured against the
  installed dist say the hidden form would be a defect. FIRST, there is no
  `hidden`: `CnFolderTree` renders every entry of `folders[]`, so the
  Organisations folder would appear and, on click, filter this page's
  `brpPerson` rows by a kvkCompany value and show an empty list — the exact
  opposite of REQ-ID-4's scenario. SECOND, `filterField: "@self.schema"` is
  not a filter OpenRegister answers: the wire form is a NESTED `@self` object
  (`VthSeedLookup` uses `['@self' => ['slug' => ...]]`), so a flat
  `@self.schema` key reads as a filter on a field no object has and returns
  nothing — the People folder would have emptied the list on its first click.
  The block to restore is quoted verbatim in the page's
  `_folderSidebarNote`. Original blocker text: [blocked: nextcloud-vue
  `CnIndexPage.folderSidebar.folders[]`
  reading `schema` and `columns` per folder, the seam B10 also asks for]
  Drop `hidden` from the Organisations folder; the index then lists people
  and organisations under one entry. No manifest change beyond that key.

## 3. The contact pages

- [x] 3.1 `src/manifest.json` page `ContactDetail` (`route: /contacts/:id`,
  `type: detail`, `config.register: dossiq`, `config.schema: brpPerson`):
  widget `contact-card` (`data`, `include` `displayName`,
  `citizenServiceNumber`, `name`, `birth`, `residence`, `description`,
  `editable: false`), widget `contact-cases` (`object-list`, schema `case`,
  `filter.requester: @objectId`, columns `identifier`, `title`, `status`,
  `deadline`, sort `deadline` asc, `rowRoute: CaseDetail`, `viewAllRoute:
  Cases`, `viewAllQuery.requester: @objectId`, `emptyText`), widget
  `contact-moments` (`object-list`, schema `contactmoment`, `filter.contact:
  @objectId`, columns `startTime`, `notificationChannel`, `direction`,
  `summary`, sort `startTime` desc, `emptyText`), `sidebar` with the audit
  tab, and a `layout` that fills the grid (ADR-062: no voids).
  - `@spec openspec/specs/initiator-display/spec.md`
- [x] 3.2 `src/manifest.json` page `OrganisationDetail` (`route:
  /organisations/:id`, schema `kvkCompany`): the same three widgets and
  sidebar, the card including `tradeName`, `kvkNumber`, `legalForm`,
  `address`, `description`.
  - `@spec openspec/specs/initiator-display/spec.md`
- [x] 3.3 Header actions on both pages: `new-case-for-contact` (`open-form`,
  schema `case`, `props.requester: @objectId`, label New case, icon
  `FolderPlusOutline`, `successMessage`) and `log-contact` (`open-form`,
  schema `contactmoment`, `props.contact: @objectId`, `includeFields`
  `notificationChannel`, `direction`, `startTime`, `nature`, `summary`,
  `relatedCases`, label Log contact, icon `PhoneLogOutline`,
  `successMessage`).
  - `@spec openspec/specs/initiator-selection/spec.md`
  - `@spec openspec/specs/kcc-klantcontact-integratie/spec.md`
- [x] 3.4 `src/registry.js`: the `InitiatorPicker` entry's `appliesTo` gains
  `contactmoment.contact` so the Log contact form renders the contact the
  way the case form renders the requester. One array element, no component
  change.
- [x] 3.5 `src/components/initiator/InitiatorSection.vue`: the
  identifying-number link targets `/contacts/:id` for `initiatorType:
  person` and `/organisations/:id` for `company`, in place of the
  OpenRegister object viewer, and loses `target="_blank"` — an in-app route
  is not somewhere else. Built with `generateUrl` rather than
  `$router.resolve`: the router base is `generateUrl('/apps/dossiq')`, which
  carries `/index.php` only where front-controller URLs are in play, so a
  hard-coded prefix falls outside the base on a pretty-URL instance and the
  catch-all quietly redirects to the Dashboard.
  - `@spec openspec/specs/initiator-display/spec.md`
- [ ] 3.6 [blocked: nextcloud-vue `CnIndexPage` column `link` naming a route,
  a param field and a route chosen by a sibling field] `src/manifest.json`
  page `Cases`: the Requester column links to `ContactDetail` or
  `OrganisationDetail` by `initiatorType`; until then the column stays text
  and 3.5 carries the link.

## 4. The contact reference on a contact moment

- [x] 4.1 `lib/Settings/register.d/40-kcc-werkplek.json` and
  `lib/Settings/dossiq_mock_register.json`: property `contact` on
  `contactmoment` (`string`, `format: uuid`, `referenceSemanticType:
  https://openregister.app/ns#Requester`, title Contact, `facetable: true`),
  optional. Version 1.2.0 to **1.3.0**, not the design's 1.1.0 to 1.2.0:
  development already holds 1.2.0, and OpenRegister FAST-SKIPS a schema whose
  version did not move, so a bump to what is already there would have left
  the property inert on every install while every gate stayed green. The mock
  register was at 1.1.0 and moves to 1.3.0 with it. `geidentificeerdeBurgerId`
  is untouched.
  - the assertion is in `tests/vitest/contactsDomain.spec.js` rather than a
    new PHPUnit test: it reads two JSON files and needs no PHP, and it sits
    beside the manifest assertions that fail for the same reason
  - `@spec openspec/specs/kcc-klantcontact-integratie/spec.md`
- [ ] 4.2 [blocked: Tier B B20, the KCC panel with caller context] Link the
  identified caller to `ContactDetail` from the panel; the spec scenario is
  excluded until then.
- [ ] 4.3 [blocked: Tier B B22, BRP and KvK subscriptions through integriq]
  Refresh `brpPerson` and `kvkCompany` rows from the source; the index reads
  whatever the register set holds until then.

## 5. Verification

- [x] 5.1 Add `tests/e2e/contacts-domain.spec.ts` covering every scenario of
  the four delta specs that names it: the Contacts entry after My work and
  the top level of three, the People folder with no Organisations folder,
  finding a seeded person by name, the person's cases and the empty state,
  the case linking back to the contact, New case with the requester
  prefilled and the saved `requester`, Log contact with the saved `contact`
  and the empty moments state. Seed through `createObject` and `seedCase`
  from `tests/e2e/helpers/fixtures.ts`, navigate through `navTo` in
  `tests/e2e/helpers/nav.ts`, clean up with `cleanupRunObjects`. Assert
  routes and ids, not English labels, where a Dutch instance could differ.
- [x] 5.2 Run `composer check:strict`, `npm run check:manifest`, the hydra
  gates (gate-53 reads the manifest for nav labels) and the unit suite
  locally; read the exit codes, not the summaries. Run by exit code:
  eslint 0, prettier 0, vitest 0, `check:manifest` 0, `check-l10n` 0,
  `check:schema-l10n` 0 (at baseline), `check:l10n-js` 0, phpcs 0, phpmd 0
  per directory, psalm 0, phpstan 0, phpunit 0, hydra gates with
  `--base origin/development`. `composer check:strict` itself was NOT run: it
  exceeds the 300s tool timeout and phpmd printing nothing inside it is its
  OOM signature rather than a pass, so the legs ran individually. Playwright
  was NOT run; `tests/e2e/contacts-domain.spec.ts` is new and unexecuted.

## Archive-time hazard, recorded 2026-09-09

This change's delta declares **REQ-ID-4 under ADDED** on `initiator-display`. So did
`contacts-you-can-find`, which declared it under MODIFIED against a spec that did not yet hold
it. Only that one archived, in #2057, so its block became the ADDED one and REQ-ID-4 now exists
in `openspec/specs/initiator-display/spec.md`.

Whoever archives this change has to turn its REQ-ID-4 block into MODIFIED first, or the archiver
refuses the duplicate. Discovered by the 2026-09-09 triage, not by a failing run: nothing fails
until the archive is attempted.
