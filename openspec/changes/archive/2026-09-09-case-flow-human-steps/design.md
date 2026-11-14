## Context

See `proposal.md — Why`. The constraints that shape the approach:

- **The engine already suspends and resumes.** `AwaitSignalNode` suspends with a heartbeat and stamps an `assignee` into `context.resumeState.<node>`; `FlowRunService::signal(FlowRun, array $payload)` is the in-process resume; `FlowRunController::resume()` is the HTTP one and enforces the recorded assignee.
- **A run accumulates one awaiting slot PER NODE**, not one per run. Anything that resumes has to name the node as well as the run.
- **decidiq owns decisions.** `AdviceDelegationService::raiseAdviceDecision()` returns a `decisionRef`; `DecisionConcludedListener` already consumes decidiq's `DecisionConcludedEvent` — for both the `OCA\Decidiq\` and `OCA\Decidesk\` namespaces, since the rename is mid-flight.
- **A flow shipped on a schema arrives disabled and ownerless** (`SchemaFlowImportListener`), because a schema save is not a person volunteering to run a graph as themselves.
- **ADR-098's task entity does not exist.** Tasks here are dossiq `task` objects, per the decision recorded on this change. The fleet-wide inbox remains owed.

## Goals / Non-Goals

**Goals:**
- A case whose position is readable from one run rather than reconstructed from four screens.
- Human steps that produce something a person can actually see and answer.
- Failure modes that stop rather than proceed: no decision means no progress.

**Non-Goals:**
- ADR-098 Phase 1/2 (the shared task entity and inbox). This change deliberately does not build them, and its tasks stay dossiq-local as a result.
- Definition versioning under a suspended run (ADR-098 Phase 3). Editing this flow while cases are parked on it changes what people are approving, silently. Called out, not solved.
- A visual editor for this flow. It ships as a declaration.

## Decisions

### D1 — The flow ships as `x-openregister-flows` on the `case` schema

Not as seed objects, not as a repair step that draws it. The importer already materialises declared flows into the flow store, updates rather than duplicates on re-import, and lands them disabled.

*Alternative rejected:* a repair step creating the flow. It would have to reimplement update-vs-duplicate, and a hand-rolled importer is how two copies of one flow end up in the store.

### D2 — A task records `flowRun` and `flowNode`

Both, not either. `workflowStepId` already exists on `task` and is NOT the right field: it names a step in a *definition*, whereas resuming needs the *instance*. Two cases running the same flow have the same `workflowStepId` and must not resume each other.

### D3 — Resume in-process via `FlowRunService::signal()`, not over HTTP

dossiq and OpenRegister are in the same PHP process; posting to our own HTTP endpoint would add a request, a session and a CSRF token to a call that needs none.

🔴 **The consequence must be handled, not inherited.** The HTTP endpoint carries `refuseUnlessAssignee()`, and calling the service directly bypasses it. That guard is not optional — it is what stops one employee answering another's question. So **dossiq's task completion performs the equivalent check itself**: only the task's assignee (or a member of the assigned group) may complete it, checked before the signal is sent. This is the one place where the two paths must agree, and the test for it asserts the refusal, not the success.

### D4 — The decision round-trip resumes from the listener, matched on `decisionRef`

`DossiqRequestDecisionNode` raises the decision, stores the returned `decisionRef` on the case, and suspends. `DecisionConcludedListener` already resolves a case from a `decisionRef`; it gains the step that resumes that case's run at the node that asked.

Matching on `decisionRef` rather than on the case is what makes "an unrelated outcome does not resume the run" true — a case can have several decisions in its life, and one concluding must not wake a run waiting on another.

### D5 — The completeness loop is capped in flow state, and leaves by a declared edge

The counter lives in the run's flow state and is incremented by the ask step. The switch that closes the loop has three exits: complete, incomplete-and-under-cap, incomplete-and-at-cap. The third moves the case to a stalled status and ends the run.

*Why not rely on the engine ceiling:* `MAX_TRANSITIONS` exists as a backstop against an unbounded graph and reports as a FAILED run. A case nobody answered is not a broken flow, and recording it as one puts a support ticket in front of the wrong person.

### D6 — Status moves are their own nodes

Each stage boundary is a `dossiq.setField` step on `status`. It costs extra nodes and buys two things: the applicant's view is driven by the flow rather than by a side effect, and the audit row for the status change names the node that made it — which is what the companion `flow-object-attribution` change makes readable.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| The case flow itself | **declarative** | `x-openregister-flows` on the `case` schema in `lib/Settings/dossiq_register.json`. No service class authors it. |
| `flowRun` / `flowNode` on `task` | **declarative** | Schema-register property additions. |
| Status moves within the flow | **declarative** | Flow nodes in the declaration, not a new transition handler. |
| `dossiq.requestDecision` node | **imperative** | ADR-031's external-integration exception: it calls another app and handles its failure mode. |
| Task completion → resume | **imperative** | Lifecycle guard plus a cross-app service call; it also carries the authorization check D3 describes. |
| Decision document generation | **imperative** | ADR-031's document-generation exception; the existing handler already is. |

## Seed Data (ADR-001)

The change adds no schemas, but it does need demo data to be demonstrable, and dossiq already ships seed sets (`vth_seed_data.json`, `bezwaar_seed_data.json`). A `case-flow` seed provides, for a general municipality:

- one **caseType** (`omgevingsvergunning-kleinbouw`) with the status types the flow moves through: `ontvangen`, `wacht-op-aanvulling`, `in-behandeling`, `bij-commissie`, `afgehandeld`, `gestrand`
- two **cases** — one complete, one deliberately missing a required field so the applicant-task branch is exercised on first run
- one **applicant** and two **employees**, so an assignee exists for the tasks and the decisions have someone to be addressed to

Deliberately NOT seeded: tasks, decisions, runs. Those are what the flow PRODUCES. Seeding them would put rows in the system describing work that never happened, and would make a broken flow look like a working one.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| **Bypassing `refuseUnlessAssignee` by calling the service directly** (D3). One employee could answer another's question. | dossiq performs the equivalent check before signalling, and the test asserts the REFUSAL. A test that only proves the assignee can complete their own task passes with the check deleted. |
| **A definition edited while cases are suspended on it** changes what people are approving. No version is recorded at ask time. | Out of scope (ADR-098 Phase 3), stated here so it is a known gap rather than a discovered one. Operationally: do not edit an adopted case flow while runs are parked on it. |
| **Two runs per case** if the trigger fires twice (a create followed by an immediate update). | The trigger is scoped to object CREATE. The run is also recorded on the case, and the start step refuses when the case already names a run. |
| **decidiq absent** in an installation that adopted the flow. | The node fails closed (D4) and the run stops at the decision, which is the correct outcome — the alternative is a case approved by nobody. |
| **Tasks are dossiq-local**, so "what is waiting on me" is per-app until ADR-098 lands. | Accepted per the recorded decision. The `flowRun`/`flowNode` fields are exactly what a later shared entity would need, so this is additive rather than a detour. |
| **The applicant loop annoys the applicant** if the completeness rule is wrong. | The cap bounds it, and the stalled status makes a wrong rule visible as a pile of stalled cases rather than as an unbounded ask. |

## Migration Plan

1. Schema-register patch: `flowRun`/`flowNode` on `task`, `x-openregister-flows` on `case`. Applied by the existing register import.
2. The node and listener changes; no data migration — existing tasks have no run and behave exactly as before (spec scenario).
3. Seed data for the demo path.

**Rollback:** disable the flow. Cases already suspended on it stay suspended; their tasks remain completable and simply resume nothing (spec scenario). No data is orphaned.

## Open Questions

- Whether the completeness rule belongs in a DMN decision table (dossiq has a `dmn-decision-tables` spec) rather than a switch expression. Deferred: it changes neither the specs, the flow's shape, nor the task list — the switch node's condition is one edit either way.
