# Design: cmmn-adaptive-case

## 1. Scope and non-goals

In scope: a JSON case-model definition schema, a pure/deterministic runtime engine for plan-item
lifecycle + sentry evaluation, REST endpoints, a minimal case-detail panel, and proof that a real
case can be driven end-to-end by the engine.

Out of scope (documented follow-up, not silently dropped):
- Full CMMN-XML (OMG XSD) import/export — §7.
- A graphical case-plan editor (drag-and-drop stage/sentry authoring) — the JSON definition is
  authored directly (via the OR object CRUD the manifest renderer already gives every schema, same
  as `workflowTemplate` is authored today before `WorkflowEditor.vue` existed for it).
- Case-file items as first-class OR-linked documents/objects (case-file "items" here are named
  data slots inside the plan's own JSON state, not `caseDocument`/`caseObject` OR relations). A
  case-file item can reference a `caseDocument`/`caseObject` id as its *value*, but the item
  *definition* lives in the case model, not as a new OR relation type.
- `manualActivationRule` / auto-start configuration per item — every mandatory item auto-cascades
  `enabled → active` once its entry criteria are satisfied (§3); a future refinement could make
  this configurable per item.

## 2. Case-model definition (`caseModel` schema)

Mirrors the existing `workflowTemplate` conventions (`title`, `caseType` ref, `version`,
`lifecycleStatus: draft|published|deprecated`, one active per `caseType`) so `CaseModelLoader` can
be a straight port of `WorkflowTemplateLoader`'s lookup-by-caseType-and-active-flag pattern.

```json
{
  "title": "Adaptive enforcement trajectory",
  "caseType": "<uuid>",
  "lifecycleStatus": "published",
  "caseFileItems": [
    { "id": "inspectionReport", "name": "Inspection report", "type": "document" },
    { "id": "urgent", "name": "Urgent flag", "type": "boolean" }
  ],
  "planItems": [
    {
      "id": "intake",
      "type": "stage",
      "name": "Intake",
      "discretionary": false,
      "parentId": null,
      "entryCriteria": [],
      "exitCriteria": [],
      "children": ["registerReport", "urgentReview"]
    },
    {
      "id": "registerReport",
      "type": "humanTask",
      "name": "Register inspection report",
      "discretionary": false,
      "parentId": "intake",
      "entryCriteria": [],
      "exitCriteria": []
    },
    {
      "id": "urgentReview",
      "type": "humanTask",
      "name": "Urgent review",
      "discretionary": true,
      "parentId": "intake",
      "entryCriteria": [
        {
          "id": "s1",
          "onPart": { "caseFileItem": "urgent", "caseFileEvent": "set" },
          "ifPart": { "field": "urgent", "operator": "eq", "value": true }
        }
      ],
      "exitCriteria": []
    },
    {
      "id": "intakeComplete",
      "type": "milestone",
      "name": "Intake complete",
      "discretionary": false,
      "parentId": "intake",
      "entryCriteria": [
        { "id": "s2", "onPart": { "planItem": "registerReport", "standardEvent": "complete" } }
      ],
      "exitCriteria": []
    }
  ]
}
```

Plan-item `type`: `stage | humanTask | milestone`. Stages nest via `parentId`/`children` (a plain
tree, `children` is derived-and-redundant for O(1) lookup and validated against `parentId` at
load time — mismatches are rejected, never silently reconciled). `discretionary: true` marks an
optional item; everything else is mandatory. `entryCriteria`/`exitCriteria` are arrays of
**sentries** (§4). The top-level plan itself is an implicit root stage: items with `parentId: null`
are its direct children and become available as soon as the case starts.

## 3. Runtime plan-item state machine

Two state machines, per CMMN 1.1 §8: one for `stage`/`humanTask` (four-hop), one for `milestone`
(two-hop, no working state — a milestone is *achieved*, not *worked on*).

### 3.1 `stage` / `humanTask`

```
            entry sentry satisfied           auto-cascade (mandatory only)
available ───────────────────────► enabled ───────────────────────────────► active
    │                                  │                                      │
    │ parent stage exits,              │ exit sentry fires                    │ worker completes (humanTask)
    │ item never enabled               │ before start                        │ all mandatory children
    │ (discretionary only)             │                                      │ terminal (stage, auto)
    ▼                                  ▼                                      ▼
disabled                          terminated  ◄──────────────────────────  completed
                                       ▲            exit sentry fires
                                       │            while active, OR
                                       └────────────worker terminates (humanTask)
```

Legal transitions (the engine's single source of truth — see `PLAN_ITEM_TRANSITIONS` in
`CaseModelEngine`):

| From        | To          | Trigger                                                              |
|-------------|-------------|-----------------------------------------------------------------------|
| `available` | `enabled`   | entry sentry satisfied (or no entry criteria + parent active)        |
| `available` | `disabled`  | parent stage reaches `completed`/`terminated` while item still `available` **and** item is discretionary |
| `available` | `terminated`| exit sentry fires before the item was ever enabled                   |
| `enabled`   | `active`    | mandatory: auto-cascade, same engine pass as `available→enabled`. discretionary: REST "enable" call (worker opt-in) |
| `enabled`   | `terminated`| exit sentry fires before the item started, or parent stage terminates |
| `enabled`   | `disabled`  | parent stage reaches `completed`/`terminated` while item still `enabled` **and** item is discretionary |
| `active`    | `completed` | humanTask: worker "complete" call. stage: all mandatory children terminal (auto) |
| `active`    | `terminated`| exit sentry fires, or worker "terminate" call, or parent stage terminates |

`completed`, `terminated`, `disabled` are terminal — no outgoing transition exists for them in the
table, so any attempted transition out of them is illegal by construction, not by a separate
guard. Any transition not present in the table (including any same-state "transition", e.g.
`enabled → enabled`) throws `IllegalPlanItemTransitionException`; the engine never silently
no-ops a rejected request, per the task brief.

Discretionary items reaching `enabled` do **not** auto-cascade to `active` — that is exactly the
worker's optional choice, surfaced via `getEnableableDiscretionaryItems()` and executed by the
"enable a discretionary item" REST endpoint, which calls the same `enabled → active` transition a
mandatory item's auto-cascade uses internally. There is no separate discretionary-only code path
in the state machine — only in *what triggers* the `enabled → active` edge.

### 3.2 `milestone`

```
available ──entry sentry satisfied──► completed   (achieved — terminal)
available ──exit sentry / parent stage exits──► terminated   (never achieved — terminal)
```

No `enabled`/`active` — achieving a milestone *is* its completion event; there is no separate
"working on a milestone" state in CMMN, and inventing one would not be testable against anything
real.

## 4. Sentry evaluation

A sentry is `{ id, onPart?, ifPart? }`. At least one of `onPart`/`ifPart` must be present (a
sentry with neither is a definition error, rejected at load time). A sentry **fires** when:
`onPart` is absent OR its event has occurred, **AND** `ifPart` is absent OR its condition
evaluates true against the current case-file snapshot. (AND *within* one sentry.)

`entryCriteria`/`exitCriteria` are arrays; the item's entry (or exit) is satisfied when **any**
sentry in the array fires (OR *across* sentries in the same array) — the standard CMMN semantics.
An item with an empty `entryCriteria` array is trivially satisfied as soon as its parent is
`active` (or, for root items, as soon as the case plan starts).

`onPart` is one of:
- `{ "planItem": "<id>", "standardEvent": "complete" | "terminate" | "disable" }` — fires when the
  referenced plan item makes that transition. Evaluated synchronously, in the same engine pass, as
  part of every `transition()` call — so a chain of sentries (stage completes → milestone entry
  sentry fires → milestone completes → next stage's entry sentry fires …) resolves in one call,
  bounded by `MAX_CASCADE_DEPTH` (50) to fail loudly on an authoring cycle rather than looping
  forever.
- `{ "caseFileItem": "<id>", "caseFileEvent": "set" | "changed" }` — fires when
  `signalCaseFileEvent()` records that case-file item as touched in the current signal call.

`ifPart` is `{ field, operator, value }` evaluated against the case-file data snapshot
(`casePlanState.caseFile`), reusing the same `{field, operator, value}` shape
`Service/Transitions/RequiredFieldGuard` already uses for guard conditions, so there is exactly
one condition-evaluation vocabulary across both engines rather than two competing DSLs.

## 5. BPMN/CMMN coexistence

A `caseType.handlingModel` value of `bpmn` (default, unset = `bpmn` for every existing caseType —
zero migration) means: `case.status`/`statusHistory` are authoritative, `StatusTransitionService`
is the write path, `workflowTemplate` is the definition source, exactly as today. `cmmn` means:
`case.casePlanState` is authoritative, `CaseModelEngine` is the write path, `caseModel` is the
definition source. **A caseType is never both** — `CaseModelEngine` refuses to load/mutate a case
whose `caseType.handlingModel !== 'cmmn'` (`case_not_cmmn_managed`), and the reverse guard already
exists implicitly: `StatusTransitionService` looks up `workflowTemplate` by `caseType`, and a CMMN
caseType simply has none, so `getAvailableTransitions()` returns an empty list rather than
erroring — the two engines are inert no-ops on each other's caseTypes, not competing writers on
the same case.

Both engines share one thing on purpose: `case.status`. A CMMN-managed case still gets its initial
`status` set declaratively by the existing `x-openregister-lifecycle` on case creation (from
`caseType.initialStatus`, unconditional on `handlingModel`), so every status-agnostic part of
procest (case list filters, deadline tracking, archival rules, the dashboard) keeps working
unmodified. `CaseModelEngine` never writes `case.status` itself — it is a read-only field from the
CMMN engine's perspective, exactly as `casePlanState` is a read-only field from
`StatusTransitionService`'s perspective. This keeps each engine's write surface to exactly one
field (`status`/`statusHistory` for BPMN, `casePlanState` for CMMN) with no shared mutable state
between them. Reflecting overall case plan completion onto `case.status` for CMMN-managed cases
(e.g. via a computed/declarative field) is a natural follow-up once there is a concrete caseType
using `handlingModel: cmmn` in production to validate the mapping against; building it speculatively
now, with no scenario in `spec.md` to hold it accountable, is exactly the kind of orphaned-capability
risk this fleet has been bitten by before, so it is deliberately left out of this change.

Existing CMMN-flavoured vocabulary already in procest is preserved, not duplicated:
- `task.status`'s `available/active/completed/terminated/disabled` enum (missing `enabled`) is the
  **BPMN-side** `task` OR object created by `workflowTemplate` steps/`CreateTaskHandler` — a
  different object entirely from a CMMN `humanTask` plan item, which has no OR-object backing (it
  lives inside `casePlanState`, per the "single OR write path" requirement). No relation is added
  between them in this change; a future change could let a CMMN `humanTask` optionally spawn a
  `task` OR object for cross-cutting task-list UIs, called out as follow-up, not built here.
- `milestoneDefinition`/`milestoneRecord` (`retrofit-2026-05-24-milestone-tracking`) is a
  **BPMN-side** business-progress-marker feature: it maps a `statusType` to a human label for
  dashboards, and is populated by `StatusTransitionService`'s status writes. CMMN milestones are a
  different concept — plan items *achieved by sentries within an adaptive plan*, with no relation
  to `statusType` at all (a CMMN caseType has no `workflowTemplate`/`statusType` graph to map).
  They are namespaced separately (`casePlanState.milestones`, not `milestoneRecord` rows) so the
  two features never collide on the same case, and the design doc calls this out explicitly so a
  future reader doesn't "fix" the apparent duplication by merging them.
- `workflowTemplate`'s `x-cmmn-equivalent: CasePlanModel` annotation is metadata-only (documents
  which real-world CMMN concept a BPMN construct loosely maps to for readers coming from a CMMN
  background) — it does not imply any runtime CMMN behaviour and is unaffected by this change.

## 6. Discretionary-item enablement gating

`getEnableableDiscretionaryItems(caseId)` returns every plan item where: `discretionary === true`
AND current state is `enabled` (i.e. its own entry criteria, if any, are already satisfied) AND
its parent stage's current state is `active` (an item nested inside a stage that hasn't started —
or has already finished — is never enable-able, matching CMMN's `Available` items only existing
meaningfully inside an active containing stage). The REST layer additionally enforces the same
OR-RBAC group-authorization convention `StatusTransitionService::isTransitionGroupAuthorized()`
uses, reading an optional `authorization: string[]` list off the plan item (absent/empty = open),
so a case-plan action is gated by the same trusted `IGroupManager` check as a BPMN transition, not
a bespoke scheme.

## 7. Follow-up: CMMN-XML import

Full CMMN-XML (the OMG XSD, `casePlanModel`/`planItem`/`sentry`/`entryCriterion` elements with
namespace-qualified attributes) is a straightforward but non-trivial XML→JSON mapping onto the
schema in §2 — each `<planItem definitionRef="...">` resolves to one entry in `planItems[]`, each
`<sentry>` inside an `<entryCriterion>`/`<exitCriterion>` maps to one sentry object, `<stage>`
nesting maps to `parentId`. Deferred because: (a) no evidence yet that procest's users author case
models in a CMMN-XML-capable external tool rather than directly in procest, (b) the JSON shape in
§2 already captures the full semantic surface XML import would target, so import is purely an
authoring-convenience feature layered on top of an unchanged runtime, not a runtime dependency.
Tracked as a follow-up issue at ship time.
