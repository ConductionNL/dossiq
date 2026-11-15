# Tasks: case-grants-name-their-source

Tier: V1. Kind: code. Size M. Round 4 discovery clusters 11 and 54,
candidates C-access-and-privacy-45 (matrix hole),
C-access-and-privacy-62 (matrix hole), C-access-and-privacy-46 and
C-access-and-privacy-47; C-access-and-privacy-64 and
C-access-and-privacy-79 already pass and are protected here rather than
built. Decision D22. Waits on openregister
`permission-provenance-and-deny` and `rbac-inherits-to-children`, both
open on openregister `development`.

- [x] 1.1 The case access panel: who holds which right and where each
  grant came from, read from openregister, stored nowhere (D-1, D-5).
  - `tests/vitest/caseAccessPanel.spec.js`
  - `@spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md`
- [x] 1.2 A test that fails when dossiq gains an effective-permission
  evaluator of its own (D-1).
- [x] 2.1 `lib/Lifecycle/CaseActionProvider.php`: read openregister's
  effective grants beside its own guards, and carry the refusing rule into
  the refusal (D-2).
  - `tests/unit/Lifecycle/CaseActionProviderTest.php`
- [x] 3.1 `caseType` and `register.d/61-mandaat-matrix.json`: department by
  role by confidentiality, with confidentiality as an axis and not a role
  name (D-3).
  - `tests/unit/Service/MandaatMatrixTest.php`
- [x] 3.2 Case-type groups, with the grant on the group and inheritance
  left to openregister (D-4).
- [x] 4.1 Remind the openregister lane, in the hand-off, of D22's own
  instruction: say in `permission-provenance-and-deny` that the condition
  is compiled into the query and not checked on the result, because the
  two are the same sentence in English and different products in practice.
- [x] 4.2 `tests/e2e/case-grants-name-their-source.spec.ts`: read the
  access panel, be refused with a named rule, list cases as a department
  without the confidential ones, and grant a group once;
  `openspec validate case-grants-name-their-source --strict`.


## What was built, and where each task landed

Task 1.1 is `src/views/cases/components/CaseAccessTab.vue` over
`src/services/caseAccessApi.js`, registered in `src/registry.js` and wired as
the `access` sidebar tab on the case page in `src/manifest.json`. Tested in
`tests/vitest/caseAccessPanel.spec.js`. The assertions sit on the shaping
function and on the wiring rather than on a mounted component: the panel's whole
risk is what it does with four answers, and whether the tab is reachable at all.

Task 1.2 is `tests/Unit/Architecture/NoSecondPermissionEvaluatorTest.php`. It
enumerates the three evaluators that predate this change, with a reason each,
and fails when a fourth appears. One of the three,
`Mcp\Tool\DossiqCaseAuthorizer::canReadCase()`, does decide on a case and is
named there as the one to retire; retiring it is its own change, because an MCP
surface that stops checking is worse than one that checks twice.

Task 2.1 is `lib/Service/Access/OpenRegisterGrantsGateway.php` plus the read in
`CaseActionProvider::availableActions()`. The refusal already names its rule:
`RefusedException` carries a rule slug and a sentence, from the
`refusals-carry-a-status` change, and `classify()` passes it through untouched.
What this change adds is the OTHER authority: OpenRegister's verdict on the
write verb, read and not derived.

Tasks 3.1 and 3.2 are `caseType.rightsMatrix` and `caseType.caseTypeGroup` in
`lib/Settings/dossiq_register.json`, and `caseTypeGroup` plus
`mandate.terms.confidentialityLevels` and `mandate.terms.caseTypeGroups` in
`lib/Settings/register.d/61-mandaat-matrix.json`.

Task 4.1 needed no reminder in the end. The openregister lane had already
written it: `permission-provenance-and-deny/design.md` D-8 is titled "Compiled
into the query, because a post-filter has already lied", and the proposal
quotes D22's instruction verbatim.

Task 4.2 is `tests/e2e/case-grants-name-their-source.spec.ts`, tagged and not
run: there is no Playwright on this host.


## Wave 2: the object's own answer, its history, and a grant that ends

openregister closed `permission-provenance-and-deny` after this change
shipped: #3744 added the object's permission set, its history and
`@self.actions`, and #3750 added `until`, `scopedTo` and claim-derived
grants. The hand-off is ConductionNL/dossiq#2792. These tasks consume it.

- [x] 5.1 `src/services/caseAccessApi.js`: read
  `GET /api/objects/{r}/{s}/{id}/permissions`, keep the four older reads as
  the fallback for an openregister that answers 404 to it, and carry a 403
  through as a refusal rather than as a failed read (D-6, D-7).
  - `tests/vitest/caseAccessPanel.spec.js`
  - `@spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md`
- [x] 5.2 The same module: read
  `GET .../permissions/history?at=` and shape the set as it stood at a
  moment, with who set it and what changed it afterwards (D-6).
  - `tests/vitest/caseAccessPanel.spec.js`
- [x] 5.3 `src/views/cases/components/CaseAccessTab.vue`: a date to ask the
  panel about, the holders of that date, and the rule level, role and
  catalogue standing on every row (REQ-CGP-05, REQ-CGP-06).
- [x] 5.4 The same component and module: render `until` and `scopedTo` on a
  grant, and compare neither to a clock (D-8).
  - `tests/vitest/caseAccessPanel.spec.js`
- [x] 5.5 `lib/Settings/dossiq_register.json`: a rights-matrix row may
  declare `until`, in openregister's own key and format (REQ-CGP-08, D-8).
  - `tests/Unit/Settings/CaseTypeRightsMatrixTest.php`
- [x] 5.6 `tests/Unit/Architecture/NoSecondPermissionEvaluatorTest.php`:
  record why `DossiqCaseAuthorizer::canReadCase()` does not retire here,
  against #3744 and #3750, and keep the entry (D-9).
- [x] 5.7 `tests/e2e/case-grants-history-and-scope.spec.ts`: read the
  object's permission set, be refused the review as a reader without
  `manage`, ask for a past date, and read a grant that ends;
  `openspec validate case-grants-name-their-source --strict`.

### What wave 2 built, and where each task landed

Task 5.1 and 5.2 are `fetchObjectPermissions()`, `fetchAccessHistory()`,
`objectPermissionRows()` and `asOfRows()` in `src/services/caseAccessApi.js`,
over a reader that now returns the status beside the body. `grantRows()` takes
the new answer when it has one and falls back to the four older reads when it
does not, and both paths emit the same row keys.

Tasks 5.3 and 5.4 are `CaseAccessTab.vue`: a date field, a second table for the
moment asked about, and `until` and `scopedTo` rendered beside the rule. The
component compares no date to the clock, which is what the vitest case on
`objectPermissionRows()` pins.

Task 5.5 is `caseType.rightsMatrix.items.properties.until` in
`lib/Settings/dossiq_register.json`, spelled the way `GrantConstraints::UNTIL_KEY`
reads it.

Task 5.6 is the reason recorded in the architecture test and in design D-9:
`canReadCase()` narrows an MCP read further than openregister's RBAC does, and
retiring it widens the surface. That widening is `dossiq-mcp-adoption`'s, which
already carries the argument for it.

Task 5.7 is `tests/e2e/case-grants-history-and-scope.spec.ts`, tagged and not
run: there is no Playwright on this host.
