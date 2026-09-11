---
kind: code
---

# Proposal: workflow-definitions-to-flow

## Summary

Project every dossiq `workflowTemplate` onto an OpenRegister flow, so the state
machine a case travels has one home. The projection arrives DISABLED and the
existing engine keeps running; this change makes the flow exist, not take over.

## Motivation

ADR-065 is explicit: OpenRegister is the only home for a flow engine, and a leaf
app that grows a second one is an ADR-022 violation and a gate failure. dossiq's
`workflowTemplate` is a state machine — statuses joined by guarded transitions,
driven by `StatusTransitionService` — which is one of the two shapes that ADR
names, and the one `symfony/workflow`'s `StateMachine` models with `case.status`
as the marking. OpenRegister already requires `symfony/workflow ^6.4`.

The visible cost of not doing it is in this app's own navigation: the menu
carries **both** `Flows` (the shared native flow store) and `Workflow
definitions` (the bespoke status machine), side by side in one settings foldout.
Two authoring surfaces for one concept, which is the drift ADR-065 was written
about.

## Affected Projects

- [x] Project: `dossiq` — this change.
- [ ] Project: `openregister` — nothing asked. The engine, the node registry and
  `FlowService` all already exist.

## Scope

### In Scope

1. **`WorkflowTemplateFlowMigrator`** — reads each `workflowTemplate` and writes
   an OpenRegister flow whose nodes are its statuses and whose edges are its
   transitions.
2. **`occ dossiq:workflows:migrate-to-flows`** — with `--user` and `--dry-run`,
   mirroring the automatic-actions migration that already exists.

### Out of Scope

- **Retiring `workflowTemplate` or `StatusTransitionService`.** The projection
  has to be adopted and proven before the definition can go, and the definition
  carries things the projection does not yet: per-step SLAs, checklists, roles.
- **Collapsing the two menu entries.** That waits until a projected flow is the
  thing driving cases; removing the definitions page while it is still the
  authoring surface would take away the only way to edit a live workflow.

## Two decisions that decide whether this is safe

**The flow arrives DISABLED.** A `workflowTemplate` that is live today drives
cases through `StatusTransitionService`. The projected flow is a second thing
that could drive them too, and creating it enabled would mean every status
change fires twice from the moment the migration runs — while looking like it
worked. Adoption stays a deliberate act, which is also how the shipped
`x-openregister-flows` arrive.

**Statuses travel by NAME, never by id.** A `statusType` uuid is minted per
installation, so a flow carrying one is portable nowhere. `dossiq.setStatus`
takes a name and resolves it inside the case's own case type, which is precisely
why it exists as a node distinct from `dossiq.setField`.

## Risks

- ⚠️ **`steps` and `transitions` are JSON-encoded strings**, opaque to
  OpenRegister. ADR-065 names this as the sharpest edge of this model. The
  migrator decodes them and accepts native arrays too, because rows written
  before the schema declared them as strings hold arrays.
- ⚠️ **`fromStatus: '*'` is accepted by the seeder and used by nothing.** An
  edge with no source node is not drawable, so a wildcard transition is skipped
  rather than guessed at. ADR-065 records that a previous draft got the count of
  these wrong; measured again here, it is still zero in shipped templates.
- ⚠️ **A run is keyed on a provenance marker, not on the flow's name.** A name
  is editable in the flow editor, and a re-run matching on one would mint a
  second flow the moment somebody renamed the first.
