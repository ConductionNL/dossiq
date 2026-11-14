# Tasks: contacts-you-can-find

Tier: V1. Kind: config. Depends on `contacts-domain`, which shipped the two
detail pages this change makes reachable and recorded the deviations it
finishes.

## 0. Re-verify before building

- [x] 0.1 Re-rate the three capability rows DQ4 claims against `development`
  and the running instance, and drop what is already satisfied.
  - **5.2** person and organisation contact registry: STILL PARTIAL. Ten
    `kvkCompany` rows on the instance, listed by no page;
    `OrganisationDetail` is referenced by `src/components/initiator/
    InitiatorSection.vue` and nothing else.
  - **9.9** search over contacts and persons: HALF SATISFIED ALREADY, and the
    plan's stated cause is wrong. `OpenRegister\Db\Schema:331` defaults
    `searchable` to `true`, the schemas API reports `true` for both with no
    flag in the register JSON, and a provider search for `Stephan Janssen`
    returned that person. The gap is the destination:
    `/apps/openregister/api/objects/23/250/<uuid>`. Two `deepLinks` close it;
    no flag and no version bump are added.
  - **5.4** contact 360: HALF, and the other half stays deferred. Cases,
    contact moments and audit already ship on both detail pages; documents
    need the OpenRegister contacts leaf (B01).
- [x] 0.2 Re-measure the folder-sidebar block against the installed
  `@conduction/nextcloud-vue` 2.41.0 rather than trusting the note.
  `CnIndexPage.folderSidebarFolders()` returns `folderSidebar.folders`
  verbatim: no `hidden`, no per-folder `schema`. Still blocked. Sidestepped
  by REQ-ID-6 rather than waited on.

## 1. The organisations index

- [x] 1.1 `src/manifest.json` `pages`: add page `Organisations` (route
  `/organisations`, type `index`, register `dossiq`, schema `kvkCompany`),
  columns `tradeName`, `kvkNumber`, `legalForm`, `address.place`
  (`sortable: false`) and `description` (`sortable: false`), a `view` action
  routing at `OrganisationDetail`, `showViewAction: false`,
  `allowSavedViews: true` and the metadata sidebar. No `component`: a plain
  index spends no ADR-100 custom-page budget.
  - `@spec openspec/changes/contacts-you-can-find/specs/initiator-display/spec.md`
  - `npm run check:manifest` exits 0
- [x] 1.2 `src/manifest.json` `menu`: add `OrganisationsMenu` (label
  Organisations, icon `OfficeBuildingOutline`, route `Organisations`, order
  26), and add `"open": true` to the `Contacts` entry.
- [x] 1.3 `src/menu-layout.json`: `relocations.OrganisationsMenu = "Contacts"`,
  and record in `_meta.description` both why the relocation exists and the
  label collision with the removed `TenantsMenu`.

## 2. The deep links

- [x] 2.1 `src/manifest.json` `deepLinks`: `brpPerson` to
  `/apps/dossiq/contacts/{uuid}` (display name Contact) and `kvkCompany` to
  `/apps/dossiq/organisations/{uuid}` (display name Organisation).
  `GenericDeepLinkRegistrationListener` reads this array out of the deployed
  `src/manifest.json` verbatim, so no PHP is needed.
  - `@spec openspec/changes/contacts-you-can-find/specs/case-search-via-or-unified-search/spec.md`
- [x] 2.2 Do NOT add `searchable: true` to `brpPerson` or `kvkCompany`. It was
  written, measured to change nothing, and reverted: the OR default is already
  `true`, and making a flag land requires a schema version bump and a
  re-import on every instance.

## 3. Verification

- [x] 3.1 `tests/vitest/contactsYouCanFind.spec.js`: the page's shape, its
  columns read only properties `kvkCompany` has, its row action names a page
  that exists, and the deep links name routes the manifest carries. The
  navigation assertions run the REAL `buildManifest` by deep path (the bare
  package name is aliased to a stub in `vitest.config.js`), so they measure
  the menu CnAppNav receives rather than the two files it is assembled from.
  - mutation-checked: dropping `open: true` reddens only "is visible without
    expanding anything"; dropping the relocation reddens the three
    placement assertions; a one-character change to a `urlTemplate` reddens
    both deep-link assertions. Files restored and the diff re-read after.
- [x] 3.2 `tests/e2e/contacts-domain.spec.ts`: fix the seed that made the file
  fail in `beforeAll` on its first ever execution (`nature: 'vraag'` is not
  one of the six the enum allows, so ONE ✘ at the hook stood for nine tests
  that never started). Seed a `kvkCompany`, assert the index and the detail
  page, and assert the search result's `resourceUrl` over the OCS provider.
  Re-scope the folder assertion from a page-wide text match to
  `.cn-index-page__folder-pane`: this change puts an Organisations entry in
  the navigation of that page, so the old matcher would have found the fix
  and reported it as the defect.
- [x] 3.3 Run `npm run check:manifest`, `npx vitest run`, `npm run lint`,
  `npm run format` and the hydra gates locally, reading exit codes.
- [ ] 3.4 [blocked: shared instance] Playwright against `localhost:8080` and
  the procest checkout is not run from this branch without asking; a run
  sweeps every fixture object on the instance. The e2e evidence is the PR run.
