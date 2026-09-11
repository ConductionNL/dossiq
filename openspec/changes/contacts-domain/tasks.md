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

  RE-MEASURED 2026-09-10 against `@conduction/nextcloud-vue` **2.42.0** as
  installed, because the version bump carried a folder fix and a folder fix
  is not the same as this one. STILL BLOCKED, on all three counts, read out
  of `src/components/CnIndexPage/CnIndexPage.vue` in the installed package:
  `folderSidebarFolders()` returns `this.folderSidebar.folders` verbatim for
  `source: custom`, so there is no per-folder `schema`, no per-folder
  `columns` and no `hidden`; `CnFolderTree.vue` matches no `hidden` at all;
  and `onFolderSelect()` still fires the selected id at the single flat key
  `filterField || field`. What 2.42.0 did fix is a DIFFERENT folder defect,
  `source: field` folders built from facet values rather than the loaded
  page (`folderSidebarFacetValues` / `folderSidebarPartial`), which this
  page does not use.

  **RE-MEASURED 2026-09-11 against nextcloud-vue `development`, ahead of
  2.47.0. STILL BLOCKED, and now with the size of the fix measured rather than
  guessed.** Nothing in #1083, #1084 or #1090 touches `folderSidebarFolders()`,
  `CnFolderTree` or `onFolderSelect()`, so all three counts above stand.

  The seam this task needs is a folder that carries its own **schema**, not
  only its own columns. `index-columns-per-scope` (nextcloud-vue, opened by
  PR #1031 as B10) specifies `columns`, `defaultSort` and `searchFields` per
  scope and is the right home for the columns half — but it does NOT specify a
  per-folder `schema`, and the columns half alone does not unblock this page:
  People and Organisations are different schemas, and a filter is not what
  tells them apart.

  Why the schema half is bigger than it reads. `CnIndexPage`'s self-fetch binds
  its store slice at setup:
  `useSelfFetchList.js` computes `const objectType = \`${props.register}-${props.schema}\``
  as a PLAIN STRING and hands it to `useListView(objectType, …)` and
  `useObjectSubscription(…)`, neither of which watches it. Switching the
  queried schema at runtime therefore means making `objectType` reactive
  through both composables, in the component every index page in the fleet
  renders. That is a real change with real blast radius, and it should be its
  own openspec change with its own mutation-checked tests — not a rushed edit
  bolted onto this one.

  Until then 2.1 stays as shipped: no `folderSidebar`, and organisations are
  reached through the separate Organisations index that `contacts-you-can-find`
  added.

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
- [x] 3.6 `src/manifest.json` page `Cases`: the Requester column links to
  `ContactDetail` or `OrganisationDetail` by `initiatorType`.

  **UNBLOCKED AND DONE 2026-09-11.** The seam shipped in nextcloud-vue #1083
  and reaches this app in **2.47.0**. `CnCellRenderer`'s built-in
  `widget: "link"` now takes `widgetProps.routeField` (a sibling field on the
  row) and `widgetProps.routeMap` (that field's values → page ids), so one
  column resolves two pages. `params` maps the route's `:id` to the case's
  `requester` uuid and applies to every page in the map.

  `contact` is deliberately absent from the map. A Nextcloud contact is not a
  register row and has no detail page here, so such a row falls back to plain
  text — the same answer `InitiatorSection.contactRouteBase()` already gives by
  returning null. A value the map does not hold is never used as a route name
  itself, so row data cannot link to a page the manifest did not name.

  The app-side cell widget in `src/cellWidgets.js` stayed rejected, for the
  reason this task recorded: it would have reimplemented in dossiq the seam
  every fleet app needs.
  - `npm run check:manifest` exits 0
  - e2e in `tests/e2e/contacts-domain.spec.ts`: the Cases index is opened twice,
    once per seeded case, by exact `?title=` deep link, and the Requester cell
    is asserted to link to `/contacts/:id` for the person and
    `/organisations/:id` for the company. The company link is then followed, so
    an href that reads right and resolves to nothing fails here. TWO rows are
    needed because one cannot tell a working `routeMap` apart from a fixed
    `route`; two NAVIGATIONS rather than one shared filter because the column
    definition is static in the manifest, so a fixed route still fails the
    second, while a shared filter would return every other case of the same
    type and could push either row onto a second page.

    Both seeded cases carry the WHOLE initiator projection: `initiatorType`,
    `initiatorDisplayName` and `initiatorSourceId`. It took two CI runs to learn
    why all three, and the lesson is not about the test:

    - The projection is NOT derived by OpenRegister. `InitiatorSection`
      back-fills it in the browser the first time a case's detail page opens,
      so an API-seeded case has none until somebody visits it.
    - Seeding `requester` alone left the COMPANY row with an empty Requester
      cell, because no test opens that case. The person row only passed by the
      accident of an earlier test visiting its case.
    - Seeding `initiatorType` and `initiatorDisplayName` without
      `initiatorSourceId` is worse: `fillProjectionFromRequester()` skips any
      case that already has a display name, so the source id is never
      supplied, `resolveSource()` returns before its lookup, and 3.5's
      initiator-card link disappeared while passing on `development` with the
      same application code.

    That last point is a latent PRODUCT defect, recorded here rather than fixed
    under this task: the back-fill keys on `initiatorDisplayName` alone, so any
    case written with a name but no source id is never repaired and its card
    link stays dead.

    A third run found the cause that made the WHOLE projection fail too: the
    spec's person used BSN `999990627`, which the shipped register already holds
    as the seeded persona "Stephan Janssen" (`25-brp-kvk.json`). With
    `initiatorSourceId` supplied up front, the card resolves its row BY NUMBER
    with `_limit: 1`, found Stephan Janssen, and linked the card to him. The
    back-fill path never showed it, because it takes the row's id straight from
    `requester` and never searches by number. The organisation's KvK
    `90004760` was seeded as well, under a comment calling it unique to the run.
    Both now use numbers absent from every seed file and every other spec:
    BSN `999990019` (valid under the 11-proef) and KvK `90004800`.

    A second latent PRODUCT defect sits in the same place, also recorded rather
    than fixed here: `InitiatorSection.resolveSource()` takes the FIRST row
    matching an identifying number. A real BSN is unique per person, so this
    holds in production; it does not hold for any register that carries a
    duplicate, and when it fails it links to a stranger without a warning.
  - `@spec openspec/specs/initiator-display/spec.md`

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

**Fixed 2026-09-10.** The block is now MODIFIED, and its BODY is the text that stands in
`openspec/specs/initiator-display/spec.md` today rather than the text this change proposed. That
distinction is the whole fix. A MODIFIED block replaces the requirement wholesale, so archiving
this change with its ORIGINAL wording would have quietly put the folderSidebar requirement back
over the measured one that replaced it, turning a refused archive into a successful regression.
`openspec validate contacts-domain --strict` no longer reports the archive-refusal INFO.
