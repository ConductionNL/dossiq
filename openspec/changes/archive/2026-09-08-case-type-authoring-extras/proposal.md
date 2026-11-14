---
kind: config
depends_on: []
---

# Proposal: case-type-authoring-extras

Round 2 competitor analysis, rows A10, A11, A29, A30 and A31 of
`concurrentie-analyse/procest/_round2/compare/placement.md`. Rung 1 for the
schema properties and rung 3 for the header actions and tabs on
`CaseTypeDetail`. One noun: the case type's blueprint. What a status looks
like, where a type gets its blueprint from, how types are grouped, what
personal data a type processes, and how a type leaves and enters the system.
Row A30 was drafted as its own change `case-type-inheritance`; it holds no
noun of its own and is folded in here as one requirement.

## Why

`statusType` carries `order`, `isFinal` and `role`, but no colour and no way
to keep closed statuses out of lists, so the Workflow board and every status
badge render grey. `CaseDefinitionController` exports, imports, validates and
copies a case type as API, and `CaseTypeDetail` offers none of it (baseline
journey J8). `CaseTypes` is a flat index of twenty rows; `propertyDefinition`
belongs to exactly one `caseType`, so a shared attribute is copied per type.
`caseType.subCaseTypes` and `workflowTemplate.parentWorkflow` exist, but a
type cannot derive its statuses, results and properties from a parent. The
AVG register is seeded per activity by `SeedVerwerkingsactiviteiten`, and no
case type says which personal data it processes.

Every competitor authors these on the case type:

- OpenCase: `opencase/round2/pages/Configuration-CodeLists.md` (case status
  as a code list, an Expired flag, seven code lists).
- GZAC: `valtimo/round2/pages/CaseDefinition-Statussen.md` (name, key,
  colour, default visibility, drag order),
  `valtimo/round2/pages/CaseDefinition-Versiebeheer.md` and
  `valtimo/round2/pages/Admin-Dossiers.md` (semver, draft from a release,
  import and export as zip), `valtimo/round2/pages/Admin-Keuzevelden.md`
  (reusable option lists), `valtimo/round2/code-census.md` (building blocks).
- Zaaksysteem: `xxllnc-zaken/round2/case-type-editor-anatomy.md` (Fasen
  configureren; Publiceren with Wijzigingsomschrijving; Moederzaaktype and
  the Kinderen tab; Documentatie: AVG with eleven categories, grondslag,
  doorgifte), `xxllnc-zaken/round2/pages/Catalogus.md` (folders and version
  management, export as XML).
- Dossiq baseline: `_round2/dossiq-baseline/admin-anatomy.md`,
  `_round2/dossiq-baseline/pages/CaseTypeDetail.md`,
  `_round2/dossiq-baseline/journeys.md` (J8).

## What Changes

- `statusType` gains `colour` (an enum from the NL Design System palette)
  and `hiddenInLists` (boolean). The Workflow board and the status badge read
  the colour; the Cases index leaves hidden statuses out by default.
- `caseType` gains `parentCaseType` (`$ref` caseType). A child inherits the
  parent's statuses, results, properties and deadlines and overrides only
  what it declares itself.
- `caseType` gains `category` (string); `CaseTypes` gets a `folderSidebar`
  by category. `propertyDefinition.caseType` becomes optional, so one
  attribute serves several types; the Properties tab lists the shared ones.
- `caseType` gains `processesPersonalData`, `personalDataCategories` (the
  eleven AVG categories), `legalBasis` (OpenRegister's article 6 vocabulary)
  and `verwerkingsactiviteit` (the code of the activity in OpenRegister's
  verwerkingsregister).
- `CaseTypeDetail` gets header actions Export, Import, Duplicate and Publish
  of type `run-action` on `CaseDefinitionController`, Publish running
  validate first and asking for a change note, and a Versions tab listing
  the type's `workflowTemplate` rows.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `case-types`: a status has a colour and a list visibility; a type derives
  from a parent.
- `property-definition-management`: types are grouped in folders; an
  attribute is shared across types.
- `avg-verwerkingenlogging`: a type records which personal data it processes
  and on what basis.
- `workflow-import-export`: export and import from the case type page.
- `zaaktype-versioning`: publish with a validation list and a change note;
  a Versions tab.

## Impact

- `lib/Settings/dossiq_register.json`: `statusType.colour`,
  `statusType.hiddenInLists`, `caseType.parentCaseType`, `caseType.category`,
  `caseType.processesPersonalData`, `caseType.personalDataCategories`,
  `caseType.legalBasis`, `caseType.verwerkingsactiviteit`;
  `propertyDefinition.caseType` no longer required.
- `src/manifest.json`: page `CaseTypeDetail` (widgets `case-type-statuses`,
  `case-type-versions`, `case-type-privacy`, header actions), page
  `CaseTypes` (`folderSidebar` on `category`), page `Cases` (default filter
  on `status.hiddenInLists`), page `WorkflowBoard` (column colour).
- `lib/Service/CaseTypeResolver.php` (new, small): resolves the effective
  blueprint of a child type. The one piece of code; see the design.
- `l10n/en.json`, `l10n/nl.json`: the new labels.
- E2E: `tests/e2e/case-type-authoring-extras.spec.ts` (new).
- Adjacent: `case-type-one-authoring-surface` owns the tab strip itself;
  this change adds rows to it, not a second strip.
