# Tasks: contact-moments

Tier: MVP. Kind: config. Every checkbox below is one implementation task; the
criteria under a task are plain bullets.

## 1. The case on the contact moment

- [x] 1.1 `lib/Settings/register.d/40-kcc-werkplek.json`, schema
  `contactmoment`: property `case` per design D1 and bump `version` to
  1.2.0.
  - `@spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md`
  - `tests/schemas` round-trips the new property
- [x] 1.2 `lib/Service/ContactMomentService.php`, `create`: copy `case`
  through and seed `relatedCases` with it when that list is empty; leave a
  filled list alone.
  - extend `tests/Unit/Service/ContactMomentServiceTest.php` (new if
    absent): `case` set and list empty seeds the list; list filled stays;
    no `case` writes none
- [x] 1.3 `lib/Service/ContactMomentService.php`, `create`: default
  `kccEmployeeId` to the session user and `identificationMethod` to
  `non_geidentificeerd` and `nature` to `informatieverzoek` when the form
  path leaves them out, so a contact logged from the case passes the
  schema's required list.
  - the same test file: a payload with channel, direction and summary only
    saves

## 2. The Communication tab

- [x] 2.1 `src/manifest.json` page `CaseDetail`: widget `case-communication`
  per design D2 and its tab entry Communication in `case-panels`, without a
  `layout` cell.
  - `@spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md`
  - `npm run check:manifest` exits 0
- [x] 2.2 CLOSED, not folded, because both halves of the premise moved.
  THE BLOCKER IS GONE: a tab does hold several widgets as stacked sections
  now, through the `case-sections` container type registered in
  `src/components/case/registerCaseSections.js` over the library's own
  `registerDashboardWidget(..., container: true)` seam. `case-communication`
  is already one of those sections, under Communication in the People tab
  (`src/manifest.json` widget `case-people-panel`), and
  `tests/e2e/helpers/case-panels.ts` maps `communication` to it. THE FOLD
  ITSELF NO LONGER APPLIES: `case-notes` and `case-email` are not body tabs
  waiting to be folded, they are sidebar tabs. `page-topology-cleanup` moved
  them there on the rule that one log in two places is duplication rather
  than coverage, which is the opposite decision to design D4's. Folding them
  back into the body would restore the duplication that change removed.
  Verified against `@conduction/nextcloud-vue` 2.42.0 as installed, not
  against its changelog.

## 3. Log contact

- [x] 3.1 `src/manifest.json` page `CaseDetail`: header action `log-contact`
  per design D3 with `props: {case: "@objectId"}`.
  - `@spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md`
- [x] 3.2 UNBLOCKED, and 3.1 was never an interim: passing `props` into the
  create form as initial data is the shipped mechanism, not a stand-in for
  it. `CnActionButtons.formInitialValues` resolves an `open-form` action's
  `props` through the same filter-token grammar the filters use, and hands
  the result to `CnFormDialog` as `initial-data` (and to
  `CnAdvancedFormDialog` as `initial-values`). Read out of the installed
  `@conduction/nextcloud-vue` 2.42.0 tree, and present in 2.41.0 as well, so
  this task has been closable for longer than the version bump.
  What is NOT shipped, and is not wanted, is the case rendered as a form
  field. REQ-KWZ-13 names the five fields the form asks for and says the
  case is PASSED, so a sixth field holding a value nobody may change would
  contradict the requirement it was written under. The spec's prefill
  scenario asserted that field, so it is rewritten to assert what the
  requirement actually promises: the form never asks which case, and the
  saved contact carries it anyway. `tests/e2e/case-communication.spec.ts`
  covers it by adding `case` to the fields the form must not render.
- [x] 3.3 `l10n/en.json` and `l10n/nl.json`: Communication, Log contact,
  Channel, Direction, Summary, "No contact logged on this case yet".

## 4. Seed and verification

- [x] 4.1 `lib/Settings/register.d/46-demo-cases-english.json`: two contact
  moments on one demo case per design Seed Data, with `case` set.
- [x] 4.2 Add `tests/e2e/case-communication.spec.ts` covering every scenario
  of the delta spec that names it: the saved contact carrying the case, the
  tab listing two rows and not the other case's, newest first, the empty
  state, a logged call showing up, and the form without the KCC fields.
  Assert on ids and saved objects, not English labels.
- [x] 4.3 Run `composer check:strict`, `npm run check:manifest`, the hydra
  gates and the unit suite locally; read the exit codes, not the summaries.
