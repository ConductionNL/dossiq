# Tasks: portal-citizen-writes-on-the-case

Tier: V1. Kind: code. Halves: portaliq `what-the-citizen-may-write-on-their-own-case`,
`case-actions-withdraw-screen`, `withdrawing-your-own-case-from-the-portal`;
dossiq#3152.

## 1. The declaration

- [x] 1.1 `amendCase` update action with `citizenWrite` in `citizenActions()` (design D1).
  - unit: `PortalContributionProviderTest` asserts the action, its `fields` and that exactly one update action on `case` carries `citizenWrite`

## 2. The case and the case type

- [x] 2.1 (with `withdrawnAt` and `withdrawalReason`, which portaliq's withdrawal writes; none of them `readOnly`, because OpenRegister refuses an update that changes a readOnly property) `portalWrites` and `portalDocuments` on `case`; `portalWritable`, `portalAmendmentWindow`, `portalDocumentWindow` and `portalWithdrawal` on `caseType`, in `lib/Settings/register.d/76-portal-citizen-writes.json` with Dutch and English labels (design D2, D3).
  - unit: a schema test asserts the shapes against portaliq's `portalCaseType`; `npm run check:schema-l10n`
- [x] 2.2 The "Portal" section of the case type editor (design D3).
  - vitest: the section saves a writable field, both windows and a withdrawal
  - `src/components/caseType/CaseTypePortalWidget.vue` (custom widget `case-type-portal` on #CaseTypeDetail, status pickers over the blueprint), `src/services/caseTypePortalSettings.js`; `tests/vitest/caseTypePortalSettings.spec.js` sends the save through the real function and validates the body against the 76-portal-citizen-writes fragment. Not rendered in a browser (no live instance on this lane).
- [x] 2.3 The pre-save guard on `caseType` for the withdrawal target (design D4).
  - unit: an unreachable target is refused with its sentence; a reachable one saves
  - `lib/Service/CaseType/PortalWithdrawalTarget.php` (the rule: a move straight from each open status to the target; a type without a workflow only needs the target to be its own status), `lib/Listener/CaseTypePortalWithdrawalListener.php` on ObjectCreating/UpdatingEvent through `CaseTypeListenerRegistrar`; `tests/Unit/Listener/CaseTypePortalWithdrawalListenerTest.php` over the real resolver and store (3 red with the rule disabled). A type whose statuses are not stored yet (same import) is not judged.

## 3. Live check

- [ ] 3.1 As a DigiD dev session with a seeded case: portaliq's `GET /portal/api/citizen/cases/dossiq/case/{id}` answers the case (no `portal-writes-not-declared`), an amendment and a withdrawal succeed, and each lands on the dossiq timeline through `PortalClientWriteListener` (closes dossiq#3152's second half).

## 4. Validation

- [ ] 4.1 `openspec validate portal-citizen-writes-on-the-case --strict`, `npm run lint`, `composer check:strict` once before push.
