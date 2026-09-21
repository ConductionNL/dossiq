# Tasks: case-definition-export-is-real

Tier: V1. Kind: code. Row 11.2.

- [x] 1.1 `CaseDefinitionExportService::exportComponent()` reads from
  OpenRegister per component: `schema` (the case type object and its property
  definitions), `statuses` (status types and transitions of the case type),
  `permissions` (role types and their `ncGroupId` bindings), `documents`
  (document types and templates), `metadata` (result types and decision
  types), `workflows` (the workflow templates bound to the case type).
  - Unit test per component: a seeded case type exports non-empty data
  - `@spec openspec/changes/case-definition-export-is-real/specs/case-types/spec.md`
  - Read through `CaseTypeStore`, which is the app's one reader of a case type
    and the rows that belong to it, so the package answers the same rows the
    authoring screens show rather than a second query that could disagree.
  - TWO CORRECTIONS the proposal could not have known:
    - TRANSITIONS ARE THE WORKFLOW TEMPLATES', not the status types'.
      `statusType` declares no transition at all, so a `transitions` read off
      it would have been `[]` beside a populated `statusTypes`, which reads as
      a case type whose statuses connect to nothing.
    - DECISION TYPES ARE A REFERENCE LIST on the case type, not rows carrying
      a `caseType` back-reference. Exported as rows they would have answered
      the empty set on every instance, which is the defect this change is
      about, reintroduced one component to the left.
- [x] 1.2 `buildManifest()` takes `caseType.slug` and `caseType.title` from
  the object and fills `dependencies` with every object ref the components
  point at.
  - Precedence is the object's own `slug`, then the store's `@self.slug`, then
    `identifier`. OpenRegister mints the slug and a municipality writes the
    identificatie, and the two disagree on a case type whose identificatie was
    corrected; the package is named after the thing the store can find again.
- [x] 1.3 An export of an unknown case type throws rather than writing a ZIP
  of empty components.
  - Refused BEFORE the temporary file is created, and the test asserts no file
    appeared. An empty package and a package of a case type with no statuses
    are identical on disk.
- [x] 2.1 `CaseDefinitionImportService::importComponent()` creates or updates
  the objects of its component under `conflictResolution`, and returns the
  created and replaced ids.
  - THE PACKAGE'S IDS ARE KEPT. Every child row carries a `caseType`
    back-reference by id, so minting a new id for the case type would leave
    every status, role and document type pointing at nothing. A CONFLICT is
    this instance already holding that id, which is a different question from
    the package carrying one; the first draft conflated them and skipped every
    row of every package.
  - A component whose rows were all skipped answers `skipped`, not `success`:
    leaving what is already here alone is not a write and must not read as one.
- [x] 2.2 `importWorkflows()` deploys each workflow file through the existing
  workflow path, or returns `status: 'error'` naming what it could not deploy.
  Counting files is never a success.
  - The templates are `workflowTemplate` objects in the register, which is
    where the export reads them, so deploying them is writing them back
    through the same store. There is no separate deployment API to call.
- [x] 2.3 A component whose objects cannot all be written leaves none of them
  written, so a half imported case type cannot exist.
  - Rolled back per component, best effort, logged rather than reported: a
    roll-back that itself fails must not turn one error into two, and the
    response the administrator reads already says `error`.
- [x] 3.1 Round trip test: seed a case type with two statuses, one role, one
  document type and one workflow template; export; import into an empty
  register; compare the two case types field by field.
  - `tests/Unit/Service/CaseDefinitionPortabilityTest.php`, 8 tests. The round
    trip is what an export test and an import test cannot do between them:
    each can pass against a shape the other does not speak. It also asserts
    that `@self` does NOT travel, because writing the exporting instance's
    register and organisation ids onto a row in another instance would be
    wrong in every field.
  - Mutation checked: restoring the old `'statusTypes' => []` placeholder
    reddens three assertions, the first of them naming the empty component.
- [x] 3.2 Remove the two placeholder comments. They were the only honest part
  of the old code and they must not survive the change that makes them false.
  - Both gone, and quoted in the new docblocks as history: "this used to
    return a fixed empty shape", "this used to report success having written
    nothing". A THIRD was found and fixed with them:
    `buildDependencyWarnings()` warned about every dependency by name
    `unknown` and type `unknown` under the same kind of comment. A warning on
    every ref is a warning on none. It now names only the refs the package
    does not itself carry, which are the ones the target instance has to
    already hold.
