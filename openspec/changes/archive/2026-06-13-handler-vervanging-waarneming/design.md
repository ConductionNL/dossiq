# Design: handler-vervanging-waarneming

## Architecture

Substitution is **domain logic on top of OpenRegister RBAC, not new auth machinery**. The design has three layers:

1. **Substitution records** — `substitution` objects in OpenRegister describing *who covers whom, when, and for what scope*. Pure data; creating one grants nothing by itself.
2. **Resolution** — `SubstitutionService::getActiveSubstitutionsFor(userId, ?date)` answers "whose work should this user also see right now?". Called by the My Work query layer and the notification fan-out. Results are cached per-request.
3. **Capacity stamping** — when a user mutates a case/task that is in their werkvoorraad *only because of* an active substitution, the mutation's audit metadata is stamped with `actedOnBehalfOf` + `substitutionId`. The OR object audit trail remains the system of record; procest adds the capacity dimension.

Permanent transfer (bulk reassignment) reuses the same `CaseReassignmentService` that substitution-aware views use for single-case handovers, executed as one previewed batch.

## Data Model

One new schema in `procest_register.json`:

**substitution**
| field | type | notes |
|---|---|---|
| `absentee` | string (NC user id) | the handler being covered |
| `substitute` | string (NC user id) | the waarnemer |
| `startDate` | date | inclusive |
| `endDate` | date | inclusive; required (open-ended absences must be re-issued or converted to bulk reassignment) |
| `scope` | enum: `all`, `caseTypes`, `cases` | what is covered |
| `scopeRefs` | array | caseType or case UUIDs when scope is narrowed |
| `reason` | enum: `verlof`, `ziekte`, `anders` | |
| `comment` | string | optional |
| `status` | enum: `active`, `ended`, `revoked` | `ended` is set lazily on resolution past endDate |
| `createdBy` | string | self-service or coordinator |

Capacity stamping adds two optional properties to mutation audit metadata (no schema change to `case`/`task`): `actedOnBehalfOf` (user id) and `substitutionId` (UUID).

## Resolution Semantics

- A substitution is **active** when `status == active` AND `startDate <= today <= endDate`.
- Multiple active substitutions per absentee are allowed only with disjoint scopes (e.g. one waarnemer for VTH case types, another for bezwaar). Overlapping `all`+`all` for the same period is rejected at create time.
- Chains do NOT resolve transitively: if A is covered by B and B is covered by C, C does not see A's work. This mirrors mandaat-matrix subdelegation conservatism and keeps the audit story simple.
- Self-substitution (`absentee == substitute`) is rejected.

## RBAC Boundary

`SubstitutionService` filters resolved work items through OR RBAC **as the substitute**: items the substitute cannot read under their own OR RBAC effective permissions are silently excluded from the substituted werkvoorraad. The service never elevates: no impersonation tokens, no permission grants. Municipalities that want the waarnemer to have full access pair the substitution with the appropriate OR RBAC group membership (existing machinery, per `migrate-role-routing-to-or-rbac`).

## Notifications

While a substitution is active, deadline/signalering notifications targeting the absentee are additionally fanned out to the substitute via the OR notification engine (schema-rule, additive recipient — the absentee still receives theirs so nothing is lost on return). Fan-out stops at the moment of expiry/revocation; no catch-up replay.

## Bulk Reassignment

`CaseReassignmentService::preview(fromUser, filter)` returns the affected open cases/tasks (case list with status, deadline, caseType) without mutating. `execute(fromUser, toUser, filter, actorId)` reassigns in a batch: per item it updates the handler/assignee, writes an audit entry (`reassignedFrom`, `reassignedBy`, batch id), and notifies the new handler once per batch (single digest notification, not N). Closed/archived cases are never touched. The operation is coordinator-only (procest coordinator role via OR RBAC) and is itself logged as a single auditable event with the batch id.

## Why not reuse mandaat-matrix waarneming?

Mandaat waarneming answers "who may *decide* in X's place" and is bound to mandaat rows and decision dates. Workload waarneming answers "who *works* X's queue" — different lifecycle (leave periods vs. mandate validity), different scope axis (cases/caseTypes vs. decision categories), different consumers (my-work/notifications vs. MandaatCheckService). Conflating them would force every leave registration through the mandate register. They stay separate; the proposal documents that a deciding waarnemer needs both.
