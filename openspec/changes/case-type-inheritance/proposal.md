---
kind: code
depends_on: []
---

# Proposal: case-type-inheritance

Gap scan of the parity ledger, 2026-09-18, pack d, row 11.21 "Mother and child
case types (inheritance)". Rated `partial` for dossiq, `yes` for xxllnc
Zaaksysteem.

## Why

A municipality runs eleven kinds of vergunning that differ in four fields and
agree on thirty. Today it copies. dossiq offers two gestures and both cut the
cord: `DerivedCaseTypePayload` says it plainly in its own header, a DUPLICATE
is a second case type with its own chain, a VERSION is the same case type one
number later. Neither keeps the two bound.

So a rule that changes for every vergunning changes eleven times, or ten
times and once forgotten. The forgotten one is the finding an auditor writes
down.

What exists nearby is not this. `caseType.subCaseTypes` and `DeelzaakService`
split one case into parts at runtime. `workflowTemplate.parentWorkflow` with
`variant` binds workflows, not case types. `CaseTypeVersionChain` walks one
case type through time.

## What changes

- `caseType.parentCaseType` references the mother. A case type with a mother
  is a child; a case type without one is unchanged in every respect.
- A child resolves at publish, not at read. Publishing a child compiles the
  mother's properties, status types, transitions, role types, document types
  and result types into the child, then applies the child's own additions and
  overrides. A running case therefore never changes underneath its handler,
  which is the whole reason for compiling rather than inheriting live.
- A child declares what it overrides. An overridden element carries
  `inheritedFrom` plus the property it replaced, so the page can say what came
  from the mother and what this case type decided for itself.
- Publishing a mother offers to republish its children. Each child gets a new
  draft version through the existing `CaseTypeVersionChain`, so a child is
  never republished behind the back of the person who owns it.
- A child cannot remove an element the mother declares required, and cannot
  point its `parentCaseType` at its own descendant. Both are refused at
  publish with a named reason.

## Decisions

- D1, resolved here: compile at publish, not inherit at read. A statutory
  term that changes mid case is the failure this avoids. If Ruben wants live
  inheritance instead, the compile step becomes a view and the republish flow
  disappears.

## Ownership

dossiq. `caseType` is dossiq's schema and the publish path is
`CaseDefinitionController` plus `case-type-publish-validation`. OpenRegister
stores the objects and enforces nothing about this.

## ADRs

- Company ADR-031: the link is schema data, the compile is a service.
- Company ADR-022: no new storage.

## Capabilities

- Added: `case-type-inheritance`: a child case type follows its mother.

## Impact

`lib/Settings/dossiq_register.json` (`caseType.parentCaseType`,
`inheritedFrom`), a new `lib/Service/CaseType/CaseTypeInheritanceCompiler.php`,
`case-type-publish-validation` gains two refusals, `src/manifest.json`
`#CaseTypeDetail` gains the mother panel, unit tests and one e2e spec.
