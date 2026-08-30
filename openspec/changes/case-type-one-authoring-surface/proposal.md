# One authoring surface for a case type

## Why

A case type is not a record with 32 fields. It is the blueprint a category of
cases is governed by, and most of what it governs lives in *other* records that
point back at it: the statuses a case moves through, the results it can end in,
the roles that may act on it, the properties it carries, the documents and
decisions it permits, its sub-case types, and the process model that drives it.

`openspec/specs/case-types/spec.md` already specifies all of that
(REQ-CT-01…09), and the schemas exist and carry a `caseType` back-reference:
`statusType`, `resultType`, `roleType`, `propertyDefinition`, `documentType`,
`decisionType`, `zaaktypeInformatieobjecttype`, `workflowTemplate`, `caseModel`.

The problem is not the model. It is that the app now has **two** places that
claim to author a case type, and they are not the same thing.

### Surface 1 — Admin Settings (`/settings/admin/dossiq`)

`AdminRoot → CaseTypeAdmin → CaseTypeList / CaseTypeDetail`, with eight tabs:
Statuses, Results, Roles, Properties, Document types, Decision types,
Sub-case types, Workflow. This is the real one. It can express the blueprint.

### Surface 2 — the in-app pages (`/settings/case-types`)

The manifest's `CaseTypes` index and `CaseTypeDetail` detail page, both plain
schema-driven pages over `caseType`'s 32 scalar columns. No tabs. No
composition. Until the change that accompanies this proposal, the index's row
menu opened an **edit modal** over those 32 scalars — a surface that cannot
express the record it claims to edit, on a record whose whole point is what it
composes.

Surface 2 exists because of an accident, and its own manifest note records it:
`page-topology-cleanup` B1 correctly retired the in-app `/settings` route that
used to mount `AdminRoot` through the in-app router (ADR-004 forbids that — it
exposes admin components as publicly-routed frontend pages). The capability
went with the page, and the replacement rebuilt only the flat half.

So a user who finds Case types in the app navigation gets the poorer of two
surfaces, with no indication the richer one exists.

## The CMMN gap

`caseType.handlingModel` is an enum of `bpmn | cmmn`. Choosing `cmmn` hands the
case to `CaseModelEngine`, which loads the single `published` `caseModel` for
that case type via `CaseModelLoader`.

`openspec/specs/cmmn-adaptive-case/spec.md` specifies the engine across
REQ-CMMN-001…009. `lib/Service/Cmmn/{CaseModelEngine,CaseModelLoader}.php`
implement it. `appinfo/routes.php` exposes the runtime surface
(`/api/case/{caseId}/cmmn-plan`, `…/enable`, …). The `caseModel` schema is
declared, tagged `x-cmmn-equivalent: CasePlanModel`, and carries case-file
items, stages, human tasks, milestones and sentries.

**Nothing anywhere can author one.** Measured: zero files under `src/` mention
`caseModel`; the eight admin tabs do not include it; the manifest has no page
bound to it; `appinfo/routes.php` has no CRUD route for it.

`CaseModelLoader::getActiveModel()` returns `null` when no published model
exists. So switching a case type to `cmmn` today produces case types the engine
has nothing to run — silently, because null is a normal return.

This is the whole of item "we build on CMMN but extend with ZGW": the ZGW/ZTC
half has a working authoring surface (in the wrong place); the CMMN half has a
working *engine* and no authoring surface at all.

## How CMMN and ZGW line up

The two vocabularies are not competitors. CMMN describes *how a case proceeds*;
ZGW ZTC describes *what a Dutch government case type must publish about
itself*. dossiq's schemas already sit on both, which is why the export in
`openspec/specs/zgw-api-mapping/spec.md` is possible at all.

| dossiq schema | CMMN | ZGW ZTC (Catalogi API) |
|---|---|---|
| `caseType` | `CasePlanModel` owner | `zaaktype` |
| `caseModel` | `CasePlanModel` | — (no ZTC equivalent; internal) |
| `caseModel.stages[]` | `Stage` | — (projects onto `statustypen` order) |
| `caseModel.…humanTask` | `HumanTask` | — (surfaces as `task`) |
| `caseModel.milestones[]` | `Milestone` | — (projects onto `statustype`) |
| `caseModel.sentries[]` | `Sentry` (entry/exit criteria) | — |
| `caseModel.caseFileItems[]` | `CaseFileItem` | `eigenschap` when persisted |
| `statusType` | Milestone projection | `statustype` |
| `resultType` | — | `resultaattype` (+ archival: `archiefnominatie`, `archiefactietermijn`, `brondatumArchiefprocedure`) |
| `roleType` | CMMN role | `roltype` |
| `propertyDefinition` | `CaseFileItem` definition | `eigenschap` |
| `documentType` / `zaaktypeInformatieobjecttype` | — | `informatieobjecttype` / `zaaktype-informatieobjecttype` |
| `decisionType` | — | `besluittype` |
| `workflowTemplate` | (the BPMN alternative) | — |

The asymmetry is the point: **CMMN is the richer runtime model and ZGW is the
publication contract**, so CMMN is the core and ZGW the projection — which is
the direction already chosen. A `caseModel` stage or milestone must be able to
name the `statusType` it corresponds to, or the export cannot be derived and
the two drift into the "validator and executor each owning a copy of the
grammar" failure this codebase has been bitten by before.

## What changes

1. **One authoring surface, not two.** The in-app `CaseTypeDetail` gains the
   same composition the admin surface has, and the in-app pages stop being a
   flat duplicate. Whichever way round it is resolved — lift the tabs into the
   manifest detail page, or have the in-app page defer to the admin one — the
   requirement is that a case type has exactly one place it is authored, and
   that place can express what a case type is. ADR-004 stands: admin components
   are not to be mounted through the in-app router; the fix is to make the
   in-app page a first-class surface, not to revive the retired route.

2. **A `caseModel` authoring surface.** CRUD for the CMMN case plan, so
   `handlingModel: 'cmmn'` becomes a choice a user can actually complete. At
   minimum: create/edit/publish a `caseModel` per case type, with its stages,
   human tasks, milestones and sentries, and the draft → published →
   deprecated lifecycle the schema already declares.

3. **A stage/milestone → `statusType` binding**, so the ZGW projection is
   derived from the CMMN model rather than maintained beside it.

4. **A guard against the silent-null path.** A case type set to `cmmn` with no
   published `caseModel` must say so — at minimum where it is authored, and
   ideally as a publish-time validation, rather than producing cases whose
   engine finds nothing to run.

## Out of scope

- The OpenRegister import defect that leaves this app with no demo cases
  (ConductionNL/openregister#2935). Independent of this change.
- The ZGW export itself, which `zgw-api-mapping` already specifies.
