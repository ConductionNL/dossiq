# Design: migrate-parafering-to-or-approval-workflow

## Context

OR's `approval-workflow` spec provides `ApprovalChain` CRUD and `ApprovalStep` decision
endpoints. The exact PHP DI class for approval-chain CRUD is to be confirmed during task
OR-1.1 in the umbrella change; this design refers to it as `ApprovalWorkflowService`
(or the concrete mapper/service discovered during that task). The spec intentionally uses
the OR REST API endpoint surface as the stable reference; if the DI class is unavailable,
procest MAY call OR's REST API from the backend via an HTTP client.

## File-by-File Mapping

### `lib/Controller/ParaferingController.php` — endpoint surface unchanged

The controller's public API is preserved in full. All existing routes, request parameters,
and response shapes stay the same so that callers (including the procest frontend) require
no changes.

The controller stops managing step state directly. It delegates to `ParaferingService`
for all chain and step operations. No business logic moves into the controller; it becomes
a thin adapter between the HTTP layer and `ParaferingService`.

### `lib/Service/ParaferingService.php` — rewrite to delegate to OR ApprovalChain

`ParaferingService` is rewritten to translate parafeerroute concepts into OR's ApprovalChain
model:

| Existing operation | New implementation |
|---|---|
| Create parafeerroute (chain) | `POST /api/approval-chains` (or OR DI class) with steps derived from the route definition |
| Get parafeerroute status | `GET /api/approval-chains/{id}/objects` |
| Advance step on parafering | `POST /api/approval-steps/{id}/approve` with optional comment |
| Return voorstel (terugsturen) | `POST /api/approval-steps/{id}/reject` with mandatory comment |
| Skip step | `POST /api/approval-steps/{id}/approve` with `_meta.action: skipped` in JSON comment |
| Delegate parafering | `POST /api/approval-steps/{id}/approve` with `_meta.actorType: delegate`, `_meta.onBehalfOf`, `_meta.mandate` in JSON comment |
| Advisory step | `POST /api/approval-steps/{id}/approve` with `_meta.action: advised`, `_meta.advice` in JSON comment |

App-specific parafering semantics (delegation actorType, mandate reference, advisory text,
skipped reason) are encoded in the `comment` field as a JSON object with `text` (human-readable)
and `_meta` (machine-readable structured fields). This is the metadata-in-comment pattern
defined in the umbrella design.

The `_parafeerRouteId` stored on the voorstel object is updated to store the OR `ApprovalChain`
UUID rather than a local parafeerroute UUID. Existing parafeerroute UUIDs in legacy rows are
left as-is (frozen, read-only).

### `lib/Service/ParaferingNotificationService.php` — keep; update event source

Notifications are a procest concern and remain in procest. The service is updated to listen
on OR's `ApprovalStep` state-change events (`ApprovalStepUpdated` dispatched by OR via
Nextcloud's `IEventDispatcher`) instead of parafeer-local events.

OR dispatches `ApprovalStepApprovedEvent` and `ApprovalStepRejectedEvent` after each step
state change (see `openregister/openspec/changes/add-approval-step-events`). The service
registers `IEventListener` implementations on these two event classes; polling is no longer
required.

The notification payload (actor display name, step label, voorstel title) is unchanged from
the user perspective.

### `lib/Settings/procest_register.json` — deprecate parafeerroute schema

The `Parafeerroute` schema in `procest_register.json` is marked deprecated by adding
`"deprecated": true` and `"deprecatedSince": "<migration-release>"` fields to the schema
object. The schema is NOT deleted — existing rows remain readable via the OR API until sunset
(one major release after the migration ships).

New code MUST NOT create `Parafeerroute` objects. The repair step is updated to skip
`Parafeerroute` schema registration on new installs after migration.

## Concept Mapping Reference

| Parafeerroute concept | OR ApprovalChain equivalent |
|---|---|
| Parafeerroute (named route) | `ApprovalChain.name` |
| Parafeerder/adviseur per step | `ApprovalStep.role` = NC group ID |
| Step order | `ApprovalStep.order` |
| `pending` (active step) | `ApprovalStep.status: pending` |
| `waiting` (not yet active) | `ApprovalStep.status: waiting` |
| Paraferen | `POST .../approve` |
| Terugsturen | `POST .../reject` |
| Advance-on-parafering | OR's automatic advance-on-approval |
| Comment/reden | `comment` plain string or `{"text": "..."}` |
| actorType / onBehalfOf / mandate | `comment._meta.actorType` / `.onBehalfOf` / `.mandate` |
| Advisory text | `comment._meta.action: "advised"`, `comment._meta.advice` |
| Skip reason | `comment._meta.action: "skipped"`, `comment.text` |

## DEFERRED_QUESTIONS

1. **OR DI class name**: confirm whether `OCA\OpenRegister\Service\ApprovalChainService` or
   `OCA\OpenRegister\Db\ApprovalChainMapper` is the correct DI entry point for ApprovalChain
   CRUD from a PHP app (resolved during umbrella task OR-1.1 before `opsx-apply` starts).
2. **OR ApprovalStep IEventDispatcher event**: RESOLVED — OR dispatches
   `OCA\OpenRegister\Event\ApprovalStepApprovedEvent` and
   `OCA\OpenRegister\Event\ApprovalStepRejectedEvent` after each step state change, defined
   in `openregister/openspec/changes/add-approval-step-events`. Polling is not required;
   `ParaferingNotificationService` registers as an `IEventListener` on both event classes.

## Seed Data

No new schemas are introduced in OR. The `Parafeerroute` schema is deprecated (not deleted)
in `procest_register.json`. The only data-layer change is the deprecation annotation on
that schema.

Existing `Parafeerroute` rows in OR are frozen and remain accessible read-only until the
sunset release removes the schema entirely.

## Related ADRs

- **ADR-022** (primary) — apps consume OR abstractions; approval-chain is the specific
  abstraction this migration delegates to.
- **ADR-031** — schema-declarative business logic; marking the schema deprecated is the
  correct pattern (deprecation annotation in the register JSON, not a code-side guard).
- **ADR-008** — testing contract; end-to-end test exercising OR's approval-workflow store
  is required.
- **Umbrella spec** — `hydra/openspec/changes/consume-or-approval-workflow-fleet-wide`
  (policy contract this migration satisfies).
- **OR approval-workflow spec** — `openregister/openspec/specs/approval-workflow/spec.md`
  (the API this migration consumes).
