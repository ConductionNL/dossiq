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

- [ ] 2.1 `src/manifest.json` page `CaseDetail`: widget `case-communication`
  per design D2 and its tab entry Communication in `case-panels`, without a
  `layout` cell.
  - `@spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md`
  - `npm run check:manifest` exits 0
- [ ] 2.2 [blocked: nextcloud-vue a `tabs` entry that renders several widgets
  as stacked sections] Fold `case-notes` and `case-email` into the
  Communication tab as sections per design D4. Until it lands they keep
  their own tabs and this task stays open.

## 3. Log contact

- [ ] 3.1 `src/manifest.json` page `CaseDetail`: header action `log-contact`
  per design D3 with `props: {case: "@objectId"}`.
  - `@spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md`
- [ ] 3.2 [blocked: nextcloud-vue `CnObjectListWidget` and `CnDetailPage`
  passing a filter or `props` into the create form as initial data (triage
  #6, Tier D05)] Interim: 3.1 passes the case in `props`; the e2e asserts
  the saved object's `case`, not the prefilled field. When the change lands,
  drop the interim note and enable the prefill scenario.
- [ ] 3.3 `l10n/en.json` and `l10n/nl.json`: Communication, Log contact,
  Channel, Direction, Summary, "No contact logged on this case yet".

## 4. Seed and verification

- [ ] 4.1 `lib/Settings/register.d/46-demo-cases-english.json`: two contact
  moments on one demo case per design Seed Data, with `case` set.
- [ ] 4.2 Add `tests/e2e/case-communication.spec.ts` covering every scenario
  of the delta spec that names it: the saved contact carrying the case, the
  tab listing two rows and not the other case's, newest first, the empty
  state, a logged call showing up, and the form without the KCC fields.
  Assert on ids and saved objects, not English labels.
- [ ] 4.3 Run `composer check:strict`, `npm run check:manifest`, the hydra
  gates and the unit suite locally; read the exit codes, not the summaries.
