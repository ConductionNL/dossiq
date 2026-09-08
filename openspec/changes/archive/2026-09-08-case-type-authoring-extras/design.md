# Design: case-type-authoring-extras

## Context

`CaseTypeDetail` (`src/manifest.json`, type `detail`, schema `caseType`) is
a bare data page with a metadata sidebar and no widgets, no header actions.
`CaseTypes` (type `index`) lists eight columns with a View action.
`statusType` holds `name`, `description`, `caseType`, `order`, `isFinal` and
`role`. `propertyDefinition` holds `caseType` as a required `$ref`.
`workflowTemplate` holds `version`, `lifecycleStatus` and `parentWorkflow`.
`CaseDefinitionController` exposes `export`, `validate`, `import`, `copy`
and `delete`. `SeedVerwerkingsactiviteiten` upserts the AVG register by
`code`. `@conduction/nextcloud-vue` 2.40.0 resolves `data`, `object-list`
and `tabs` on a detail page, `folderSidebar` on an index, and `headerActions`
of type `open-form`, `run-action` and `handler`.

ADR-032 kind: **config**, with one small resolver as the thin-glue exception
(D2).

## Goals / Non-Goals

**Goals:**

- A status shows in its own colour everywhere, and closed statuses stay out
  of lists unless asked for.
- A type derives from a parent and declares only what differs.
- Types sit in folders, and one attribute serves several types.
- A type records its AVG facts next to its other classification.
- Export, import, duplicate and publish live on the case type page.

**Non-Goals:**

- The tab strip on `CaseTypeDetail`: `case-type-one-authoring-surface`.
- Semver on the type itself; the version stays on `workflowTemplate`.
- A phase mapping between parent and child (Zaaksysteem's Kinderen tab).
- Reusable option lists as a schema of their own (GZAC Keuzevelden).

## Decisions

### D1: colour and visibility are properties on the status

`statusType.colour` is `{"type": "string", "enum": [...]}` over the twelve
NL Design System hue names (`blue`, `green`, `orange`, `red`, `purple`,
`grey` and their light variants) rendered through `--nl-color-*` tokens, never
a hex value. `statusType.hiddenInLists` is a boolean, default `false`;
`isFinal` statuses seeded by the app get `true`. `CnStatusBadge` and the
`WorkflowBoard` column header read `colour`. The `Cases` index gains a
default filter `status.hiddenInLists: false` that the Closed chip of
`one-case-list` lifts. Alternative: colour by `role`. Rejected: two types
with the same role want different colours.

### D2: a child type resolves to an effective blueprint

`caseType.parentCaseType` is a `$ref` to `caseType`. A `CaseTypeResolver`
(new, `lib/Service/CaseTypeResolver.php`) returns the effective type: the
parent's `statusType`, `resultType` and `propertyDefinition` rows plus the
child's own, the child's row winning on `name`; `processingDeadline`,
`extensionPeriod` and the AVG block fall back to the parent when the child
leaves them empty. Every reader that today loads `statusType where caseType
= X` (`StatusTransitionService`, the New case form's status defaults, the
stepper of `case-lifecycle-on-the-page`) calls the resolver instead. A chain
of at most three levels; a cycle is refused on save. This is the one code
piece; a declarative merge over a `$ref` does not exist in OpenRegister and
is filed as a request. A `data` widget `case-type-parent` on
`CaseTypeDetail` shows `parentCaseType` and an Inherited badge on each row
that came from the parent (widget `object-list`, column `origin` computed by
the resolver).

### D3: folders and shared attributes

`caseType.category` is a plain string with `x-openregister-facet: true`.
`CaseTypes` gets `folderSidebar: {"source": "facet", "field": "category",
"allLabel": "All case types"}`. `propertyDefinition.caseType` leaves the
`required` list; a row without a type is shared, and the Properties tab on
`CaseTypeDetail` (from `property-definition-management`) lists the type's own
rows first and the shared rows under a heading Shared attributes, both from
one `object-list` with `filter: {"$or": [{"caseType": "@objectId"},
{"caseType": null}]}`.

### D4: the AVG block is a `data` widget

`caseType.processesPersonalData` (boolean), `personalDataCategories`
(array, enum of the eleven categories: `naw`, `bsn`, `contact`, `financieel`,
`gezondheid`, `strafrechtelijk`, `biometrisch`, `etniciteit`, `religie`,
`politiek`, `seksueel`), `legalBasis` (enum, OpenRegister's article 6
vocabulary: `consent`, `contract`, `legal_obligation`, `vital_interests`,
`public_task`, `legitimate_interest`) and `verwerkingsactiviteit` (string,
the activity `code` that `SeedVerwerkingsactiviteiten` upserts by). A `data`
widget `case-type-privacy` (title Personal data, icon ShieldAccount) shows
them. The `$ref` into the verwerkingsregister waits on OpenRegister exposing
it as a referenceable schema; until then the code is a string with a
datalist of the seeded codes.

### D5: export, import, duplicate and publish are header actions

`CaseTypeDetail.headerActions`:

| id | type | target | note |
|---|---|---|---|
| `export` | `run-action` | `CaseDefinitionController::export` | downloads the bundle |
| `import` | `run-action` | `CaseDefinitionController::import` | a file field in the confirm dialog |
| `duplicate` | `run-action` | `CaseDefinitionController::copy` | routes to the copy |
| `publish` | `run-action` | `CaseDefinitionController::publish` | validate first, a change note field |

`publish` is a new controller method: it runs `validate`, refuses with the
finding list when it is not empty, otherwise sets `isDraft: false` on the
type, `lifecycleStatus: published` on the active `workflowTemplate` and
writes the change note to `workflowTemplate.description`. The Versions tab
is an `object-list` `case-type-versions` over `workflowTemplate` where
`caseType = @objectId`, columns `version`, `lifecycleStatus`, `description`,
`updated`.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | reason |
|---|---|---|
| Colour, visibility, category, AVG block | declarative, schema properties and `data` widgets | data with no behaviour |
| Folder sidebar, Versions tab | declarative, manifest | presentation |
| Export, import, duplicate | declarative `run-action` over existing controller methods | the API exists |
| Publish | code, one controller method | validate-then-flip needs an order |
| Effective blueprint of a child | code, `CaseTypeResolver` | no declarative merge over a `$ref` |

## Seed Data

- The seeded `statusType` rows get a `colour` per role (`intake` blue,
  `in-progress` orange, `review` purple, `closed` green, `stranded` red) and
  `hiddenInLists: true` on `isFinal` rows.
- The bezwaar and subsidie types get a `category`, the AVG block filled, and
  one child type Bezwaar (verkort) with `parentCaseType` set.

## Risks / Trade-offs

- Every reader of `statusType where caseType = X` must move to the resolver
  or a child type silently has no statuses. The task lists the readers by
  grep and the E2E asserts the child's stepper.
- Making `propertyDefinition.caseType` optional is a schema loosening, not a
  breaking `format` change (memory: or-gotchas). Rows are not touched.
- A `run-action` that downloads needs the header action to accept a
  `DataDownloadResponse`; verify on a live instance, else `export` keeps a
  `handler` that opens the URL.
