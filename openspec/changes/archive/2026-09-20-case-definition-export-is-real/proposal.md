---
kind: code
depends_on: []
---

# Proposal: case-definition-export-is-real

Gap scan of the parity ledger, 2026-09-18, pack d, row 11.2 "Config as code,
import and export of case types". Rated `no` for dossiq, `yes` for GZAC.

## Why

The endpoints answer and the package is empty. `CaseDefinitionExportService`
builds a real ZIP with a real `manifest.json`, then fills it from
`exportComponent()`, which returns `fields: []`, `statusTypes: []`,
`roles: []`, `documentTypes: []`, `resultTypes: []` and `workflows: []` for
every case type on every instance. The comment above the `match` says so:
"Placeholder: in a full implementation, this would query OpenRegister".

The import side is worse, because it reports success. `importComponent()`
reads the JSON, writes a log line and returns `status: 'success'` under the
comment "In a full implementation, this would create/update OpenRegister
objects". `importWorkflows()` counts the entries and answers "Imported 3
workflow(s)" having deployed none. An admin who moves a case type from
acceptance to production gets a green dialog and an empty instance.

Two things hid this. `openspec/specs/case-types/spec.md` REQ-CT-17 and
REQ-CT-18 already say SHALL, so the spec reads as satisfied. And
`openspec/changes/archive/2026-05-11-case-definition-portability/tasks.md`
ticks "Create CaseDefinitionExportService with exportCaseDefinition() method",
which is true of the method and false of the feature.

## What changes

- `exportComponent()` reads the real objects from OpenRegister: the case type
  itself, its status types and transitions, its role types and group bindings,
  its document types and templates, its result types and decision types.
- The manifest names what it carries. `caseType.slug` comes from the object,
  not from the id echoed back, and `dependencies` lists every object ref the
  package points at so the importer can refuse a broken one.
- `importComponent()` writes. Each component creates or updates OpenRegister
  objects under the caller's `conflictResolution` mode, and the response says
  what it created and what it replaced, which REQ-CT-18 already requires.
- `importWorkflows()` deploys through the existing workflow path or refuses.
  It never counts files and calls that an import.
- A round trip test: export a seeded case type, import it into an empty
  register, and compare the two objects field by field.

## Where this sits

Row 11.2 is not in the sibling-owned table of `competitor-parity-2026-09` and
not among the twelve rows its 2026-09-13 re-read closed. Nothing else carries
it. That is consistent with how it hid: a row whose spec says SHALL and whose
tasks are ticked reads as done from every angle except the code.

## Ownership

dossiq builds all of it. The objects are OpenRegister's and are read and
written through `ObjectService`, the way the rest of dossiq does. No new
storage, no new endpoint: the four routes already exist.

## ADRs

- Company ADR-022: dossiq consumes OpenRegister's object abstractions and
  writes no store of its own.
- Company ADR-031: the package is data, the service only moves it.

## Capabilities

- Modified: `case-types`: the package carries the case type, and the import
  refuses rather than reports a success it did not perform.

## Impact

`lib/Service/CaseDefinitionExportService.php`,
`lib/Service/CaseDefinitionImportService.php`, their unit tests, one round
trip test. `CaseDefinitionController` and `appinfo/routes.php` are unchanged.
No frontend.
