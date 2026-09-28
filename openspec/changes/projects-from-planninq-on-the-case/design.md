# Design: projects-from-planninq-on-the-case

Read at dossiq `development` `c8ac7427e`; planninq `integration-case-bridge`
read at planninq `development` `4248783`.

## Context

- dossiq `lib/Settings/dossiq_register.json`, schema `case`,
  `configuration.linkedTypes`: `mail`, `calendar`, `forms`, `photos`, `maps`,
  `shares`, `decidesk-decisions`, `talk`, `deck`, `collectives`, `xwiki`.
  Fragments append to it (`lib/Settings/register.d/68-pipelinq-leaves.json`
  adds `pipelinq-contact-moments` and `pipelinq-party`).
- dossiq `src/manifest.json`, page `CaseDetail`: other apps' leaves are placed
  as `type: integration` widgets with an `integrationId`, for example
  `humaniq-hours` and `shillinq-payment-requests`, whose notes say "A leaf
  whose app is absent is never registered, so the surface goes missing rather
  than lying". The host forwards `register`, `schema` and `objectId` to every
  leaf it mounts; on this page they are `dossiq`, `case` and the case uuid.
- planninq `integration-case-bridge` D1: the `planninq-projects` leaf gains a
  case scope, chosen from the host schema the leaf receives in its props;
  "New project" links to `/apps/planninq/projects?new=1&case={id}&title={caseTitle}`.
  D4: "The action and the leaf scope check `IAppManager::isInstalled('procest')`."
- dossiq `appinfo/info.xml` `<id>` is `dossiq` at `c8ac7427e`.

## D1. Declare and place the leaf

A fragment `lib/Settings/register.d/79-planninq-projects-leaf.json` appends
`planninq-projects` to `case.linkedTypes`. `CaseDetail` gets a
`type: integration` widget `case-projects`, `integrationId`
`planninq-projects`, title "Projects", beside the humaniq and shillinq
panels. Nothing else: the leaf reads `project.caseReference` itself.

## D2. The app id planninq checks

planninq's D4 hides the case scope and the handover unless `procest` is
installed. dossiq's id is already `dossiq`, so on a current instance the
panel would mount and show no case scope. This change does not work around
it in dossiq: the check is planninq's to move (fleet rule: an app's id moves
per app, and a duck-typed check on the old id silently no-ops). The live
check in tasks.md fails until planninq checks `dossiq`, and the finding is
reported to the coordinator.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| The linked type and the placement | Declarative, a register fragment and the manifest | A leaf placement. |

## Risks

- **An empty panel read as "no projects".** planninq's leaf answers an
  unreachable scope differently from an empty list (its own
  `projects-leaf.spec.ts`); dossiq adds no reader of its own.
