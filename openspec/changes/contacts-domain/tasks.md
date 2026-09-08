# Tasks: contacts-domain

Tier: V1. Kind: config. Every checkbox below is one implementation task; the
criteria under a task are plain bullets. Depends on `requester-on-the-case`
(the picker on `requester`, the initiator card link) and reuses
`parties-on-the-case` nothing but its e2e helpers.

## 1. The menu entry

- [ ] 1.1 `src/manifest.json` `menu`: add `{"id": "Contacts", "label":
  "Contacts", "icon": "AccountGroupOutline", "route": "Contacts", "order":
  25}`. Do not touch `src/menu-layout.json`.
  - `@spec openspec/specs/nav-dedup-and-grouping/spec.md`
  - `npm run check:manifest` exits 0
- [ ] 1.2 Unit test `tests/unit/menu-layout-contacts.spec.js` (beside the
  existing manifest checks under `tests/validate-manifest.js`): `Contacts`
  appears in none of `relocations`, `removals`, `settingsSection` or
  `integrationsSection`, and the effective top level outside `footer` and
  `settings` is Dashboard, WorkGroup and Contacts.
- [ ] 1.3 Update the navigation e2e: `tests/e2e/navigation.spec.ts` (the
  loop over top-level leaves, today `['Dashboard']`, gains `Contacts`) and
  `tests/e2e/spec-coverage/work-navigation.spec.ts` (the top-level order
  assertion, if it counts leaves). Grep `tests/e2e` for `Contacts` first:
  the only hit today is the retired case-panels tab in
  `case-detail-kpis-and-tabs.spec.ts`, which `parties-on-the-case` removes.

## 2. The Contacts index

- [ ] 2.1 `src/manifest.json` page `Contacts` (`route: /contacts`, `type:
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
- [ ] 2.2 [blocked: nextcloud-vue `CnIndexPage.folderSidebar.folders[]`
  reading `schema` and `columns` per folder, the seam B10 also asks for]
  Drop `hidden` from the Organisations folder; the index then lists people
  and organisations under one entry. No manifest change beyond that key.

## 3. The contact pages

- [ ] 3.1 `src/manifest.json` page `ContactDetail` (`route: /contacts/:id`,
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
- [ ] 3.2 `src/manifest.json` page `OrganisationDetail` (`route:
  /organisations/:id`, schema `kvkCompany`): the same three widgets and
  sidebar, the card including `tradeName`, `kvkNumber`, `legalForm`,
  `address`, `description`.
  - `@spec openspec/specs/initiator-display/spec.md`
- [ ] 3.3 Header actions on both pages: `new-case-for-contact` (`open-form`,
  schema `case`, `props.requester: @objectId`, label New case, icon
  `FolderPlusOutline`, `successMessage`) and `log-contact` (`open-form`,
  schema `contactmoment`, `props.contact: @objectId`, `includeFields`
  `notificationChannel`, `direction`, `startTime`, `nature`, `summary`,
  `relatedCases`, label Log contact, icon `PhoneLogOutline`,
  `successMessage`).
  - `@spec openspec/specs/initiator-selection/spec.md`
  - `@spec openspec/specs/kcc-klantcontact-integratie/spec.md`
- [ ] 3.4 `src/registry.js`: the `InitiatorPicker` entry's `appliesTo` gains
  `contactmoment.contact` so the Log contact form renders the contact the
  way the case form renders the requester. One array element, no component
  change.
- [ ] 3.5 `src/components/InitiatorSection.vue` (the `initiator` widget on
  `CaseDetail`): the identifying-number link targets route `ContactDetail`
  for `initiatorType: person` and `OrganisationDetail` for `company`, in
  place of the register object detail. One route id per branch; the
  component is `requester-on-the-case`'s.
  - `@spec openspec/specs/initiator-display/spec.md`
- [ ] 3.6 [blocked: nextcloud-vue `CnIndexPage` column `link` naming a route,
  a param field and a route chosen by a sibling field] `src/manifest.json`
  page `Cases`: the Requester column links to `ContactDetail` or
  `OrganisationDetail` by `initiatorType`; until then the column stays text
  and 3.5 carries the link.

## 4. The contact reference on a contact moment

- [ ] 4.1 `lib/Settings/register.d/40-kcc-werkplek.json` and
  `lib/Settings/dossiq_mock_register.json`: property `contact` on
  `contactmoment` (`string`, `format: uuid`, `referenceSemanticType:
  https://openregister.app/ns#Requester`, title Contact, `facetable: true`),
  optional; version 1.1.0 to 1.2.0. Leave `geidentificeerdeBurgerId` alone.
  - unit test `tests/Unit/Settings/ContactmomentContactPropertyTest.php`:
    both files carry the property with that semantic type and version
  - `@spec openspec/specs/kcc-klantcontact-integratie/spec.md`
- [ ] 4.2 [blocked: Tier B B20, the KCC panel with caller context] Link the
  identified caller to `ContactDetail` from the panel; the spec scenario is
  excluded until then.
- [ ] 4.3 [blocked: Tier B B22, BRP and KvK subscriptions through integriq]
  Refresh `brpPerson` and `kvkCompany` rows from the source; the index reads
  whatever the register set holds until then.

## 5. Verification

- [ ] 5.1 Add `tests/e2e/contacts-domain.spec.ts` covering every scenario of
  the four delta specs that names it: the Contacts entry after My work and
  the top level of three, the People folder with no Organisations folder,
  finding a seeded person by name, the person's cases and the empty state,
  the case linking back to the contact, New case with the requester
  prefilled and the saved `requester`, Log contact with the saved `contact`
  and the empty moments state. Seed through `createObject` and `seedCase`
  from `tests/e2e/helpers/fixtures.ts`, navigate through `navTo` in
  `tests/e2e/helpers/nav.ts`, clean up with `cleanupRunObjects`. Assert
  routes and ids, not English labels, where a Dutch instance could differ.
- [ ] 5.2 Run `composer check:strict`, `npm run check:manifest`, the hydra
  gates (gate-53 reads the manifest for nav labels) and the unit suite
  locally; read the exit codes, not the summaries.
