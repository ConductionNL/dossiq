---
kind: code
---

## Why

Dossiq has the pieces of a case flow and no case flow. It ships 19 flow nodes, a `case` and a `task` schema, a status engine, and a working decision delegation to decidiq — but nothing joins them into a single run that walks a case from intake to closure. Today each step is triggered by a separate transition or a person clicking something, so nobody can answer "where is this case, and what is it waiting on" without reading four screens.

The missing joint is the human step. OpenRegister's engine can already pause a run until somebody answers (`await-signal`), and the assignee it records is enforced on resume. What no leaf app does is **create the thing the person actually sees** and **wire its completion back to the waiting run**. A task that does not resume its run is a to-do item; a run that suspends with nothing on anyone's list is invisible work.

## What Changes

- **One flow per case, shipped with the app.** A `case-behandeling` flow declared as `x-openregister-flows` on the `case` schema, so it arrives with the register rather than being drawn by hand. Per the flow importer it arrives DISABLED and ownerless — adopting it stays a deliberate act.
- **Tasks carry their run.** `task` gains `flowRun` and `flowNode`. Completing a task posts the resume that wakes exactly the node that asked for it. Without this a task cannot wake a run at all: `workflowStepId` names a step in a definition, not a running instance of one.
- **A `dossiq.requestDecision` node.** Wraps the existing decidiq delegation (`AdviceDelegationService` → `decisionRef`) and suspends. The existing `DecisionConcludedListener` already receives decidiq's outcome; it gains the step that resumes the waiting run. Decisions stay decidiq's — dossiq asks and waits, it does not decide.
- **Status is a step, not a side effect.** Each status move is its own node, so the resident's view in portaliq is driven by the flow and the audit row says which node moved it.
- The flow covers: intake → completeness check → (incomplete: ask the indiener, loop, capped) → two employee decisions → a task → planning-commission approval → decision PDF attached to the case → closed.

## Capabilities

### New Capabilities
- `case-flow-human-steps`: how a case flow suspends on a human step, what the person sees while it waits, and how their answer resumes the run that asked.

### Modified Capabilities
- `task-management`: a task may belong to a suspended flow run and node, and completing such a task resumes it.
- `status-transition-engine`: a status move performed by a flow is attributable to the node that performed it.

## Impact

| Area | Change |
|---|---|
| `lib/Settings/dossiq_register.json` | `x-openregister-flows` on `case`; `flowRun`/`flowNode` on `task` |
| `lib/Flow/DossiqRequestDecisionNode.php` | new — delegate to decidiq and suspend |
| `lib/Listener/DecisionConcludedListener.php` | resume the waiting run on the concluded decision |
| `lib/Service/TaskCompletionService.php` | completing a task posts its run's resume |
| Frontend | a task shows what it is holding up; a case shows its flow run |

**Depends on** `openregister/flow-object-attribution` for the audit attribution that makes "which node moved this case" answerable. The flow itself runs without it; only the traceability read does not.

**Risk owned by this change:** the completeness loop can ping-pong if the indiener never supplies what is asked. It is capped, and the cap is a spec'd behaviour rather than a constant nobody reads.
