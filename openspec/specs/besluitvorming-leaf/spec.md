# besluitvorming-leaf Specification

## Purpose

dossiq shows decidesk's Besluitvorming on the case page instead of building a
second one. The surface arrives as an OpenRegister integration leaf. The
standalone Besluitvorming navigation retires, and its pages stay routable so no
link breaks.

## Requirements

### Requirement: REQ-BVL-001 — The case-detail page MUST surface decidesk's Besluitvorming as an OR integration leaf

The dossiq `CaseDetail` page MUST surface decidesk's decision-making via the shared OpenRegister integration registry leaf `decidesk-decisions` as a sidebar tab labelled "Besluitvorming". dossiq MUST resolve the registered provider's tab component from `window.OCA.OpenRegister.integrations` at render time and forward the case's `{ register, schema, objectId }` context. dossiq MUST NOT re-implement the decision list, create, or open-in-decidesk behaviour — it only references the registry id (ADR-019 / ADR-022).

#### Scenario: Besluitvorming tab appears on the case detail

- GIVEN the decidesk app is enabled and its decisions leaf is registered on the OR integration registry
- WHEN the user opens a dossiq case detail page
- THEN a "Besluitvorming" tab is shown in the case sidebar tab strip

#### Scenario: The leaf reads decisions linked to the case

- GIVEN the "Besluitvorming" tab is open on a case detail
- WHEN the leaf loads
- THEN it lists the decidesk proposals/advice/decisions whose `subjectId` matches the case uuid (read path), using the `{ register, schema, objectId }` context forwarded by dossiq

#### Scenario: Graceful fallback when decidesk's leaf is absent

- GIVEN the decidesk decisions leaf is NOT registered on the OR integration registry
- WHEN the user opens the "Besluitvorming" tab on a case detail
- THEN a quiet "Besluitvorming unavailable" notice is shown instead of a broken tab

### Requirement: REQ-BVL-002 — The standalone Besluitvorming nav MUST be retired while its pages stay routable

The `Voorstellen`, `Advies` (`Advice`) and `Agenda` (`BesluitvormingAgenda`) top-level navigation entries and the `BesluitvormingGroup` group MUST NOT appear in the dossiq left navigation. Their underlying pages (`/voorstellen`, `/voorstellen/:id`, `/advice`, `/advice/:id`, `/besluitvorming/agenda`, `/besluitvorming/vergaderingen/:id`) and the `voorstel` / `adviesAanvraag` schemas and data MUST remain intact and reachable as deep links (ADR-044).

#### Scenario: Besluitvorming nav entries are gone

- GIVEN the user opens the dossiq app
- WHEN the sidebar navigation renders
- THEN no "Voorstellen", "Advies", "Agenda" or "Besluitvorming" group entries appear in the navigation

#### Scenario: Former pages stay reachable by deep link

- GIVEN the Besluitvorming nav has been retired
- WHEN the user navigates directly to `/voorstellen` (or `/advice`, `/besluitvorming/agenda`)
- THEN the corresponding page still renders (the route is registered)

### Requirement: REQ-BVL-003 — The voorstel → decidesk migration MUST reuse the existing delegation repair step

The migration of dossiq `voorstel` records into decidesk's decision model MUST be performed by the existing `dossiq-delegate-remaining-decisions-to-decidesk` mechanism (`AdviceDelegationService::raiseVoorstelBesluit` invoked from `LinkInFlightRemainingDecisionsRepair`), which raises a decidesk Decision with `subjectId = voorstel.uuid`, `externalReference = case`, `subjectLabel = onderwerp`, and records the `decisionRef` back on the voorstel. This change MUST NOT introduce a second migration mechanism, MUST NOT drop data (terminal/already-linked records are kept), and MUST be idempotent.

#### Scenario: Migration delegates to the existing repair step

- GIVEN dossiq `voorstel` records exist that are not yet linked to a decidesk Decision and are not in a terminal status
- WHEN `LinkInFlightRemainingDecisionsRepair` runs (on `occ upgrade`)
- THEN each is linked forward to a decidesk Decision via `raiseVoorstelBesluit` and its `decisionRef` is persisted, without dropping any voorstel field

#### Scenario: Migration warns and skips when the decidesk write path is unavailable

- GIVEN the decidesk decision write path is unavailable (e.g. the `decisionType: format: uuid` 422 caveat)
- WHEN the repair step attempts to link a voorstel
- THEN it warns and skips that record rather than failing the migration, leaving the voorstel data intact for a later re-run

### Requirement: REQ-BVL-004 A document on a case carries decidiq's approval chain

dossiq SHALL surface decidiq's `decidiq-approval-chain` render-surface leaf on
the document record a file row resolves to, forwarding that record's
`{ register, schema, objectId }` as the integration context. The wrapper SHALL
resolve the leaf from `window.OCA.OpenRegister.integrations` at render time
and SHALL support both render modes the registry carries, the component mode
and the mount mode, the same way the decisions leaf is consumed today.

dossiq SHALL NOT re-implement the route, the steps, the actions or the
reasons. Holding a route, approving and rejecting SHALL run through decidiq's
own service, and dossiq SHALL read.

When decidiq is not installed, or its leaf is absent from the registry, the
surface SHALL render a notice saying so and SHALL NOT render an empty
timeline. An empty timeline reads as "nobody has approved anything", which is
a claim about the document that nobody made.

**Feature tier**: MVP

#### Scenario: A reviewer works the route from the case
@e2e exclude no test signs in AS the current actor and approves, so nothing here proves the route advances. The suite covers the bystander's read-only view and the two lock outcomes. Acting on a route is decidiq's own surface and its suite owns it.

- **GIVEN** a case whose concept letter is held in a route of three reviewers, and the signed-in user is the current actor
- **WHEN** the user opens that document from the Files tab
- **THEN** the approval chain SHALL show the current step, its due date, and approve and reject
- **AND** approving SHALL advance the route to the next actor

#### Scenario: Somebody who is not the current actor sees the timeline only
@e2e tests/e2e/approval-chain-on-the-document.spec.ts

- **GIVEN** a document in a route whose current step belongs to somebody else
- **WHEN** a colleague opens it
- **THEN** the timeline SHALL show every action taken so far with its reason
- **AND** no approve or reject action SHALL be offered

#### Scenario: decidiq is not installed
@e2e tests/e2e/approval-chain-on-the-document.spec.ts

- **GIVEN** an instance running dossiq without decidiq
- **WHEN** a handler opens a document
- **THEN** the surface SHALL say the approval chain is unavailable
- **AND** the rest of the document properties SHALL render normally

### Requirement: REQ-BVL-005 The case list of files says what is waiting on somebody

The Files tab of a case SHALL show, per document that is in a route, that it
is in one and which step it is on. A handler SHALL NOT have to open each
document to find out which of them is waiting on them.

The marker SHALL be a read of decidiq's route state and SHALL NOT be a stored
copy on the document. A copy would be written once and would then disagree
with the route the first time somebody approves from decidiq's own page.

**Feature tier**: MVP

#### Scenario: A document in a route is marked in the list
@e2e tests/e2e/approval-chain-on-the-document.spec.ts

- **GIVEN** a case with four documents, one of them held in a route at step two of three
- **WHEN** the handler opens the Files tab
- **THEN** that row SHALL show it is in an approval chain at step two of three
- **AND** the other three rows SHALL show no marker

### Requirement: REQ-BVL-006 The route is the ground for making a document definitief, never the act

A document whose approval route completed approved SHALL offer the final
transition, and the offer SHALL name the route as its ground. dossiq SHALL NOT
make that transition on its own.

A document with an open route SHALL NOT be moved to final. The refusal SHALL
name the route and the step it is on.

The status is `final`, and this is not a translation note. `definitief` is
what a ZGW reader calls it; `InformatieobjectStatusLifecycle::VALID_STATUSES`
stores `draft`, `final` and `archived`, so a guard written against the Dutch
spelling would never fire because no document's status is ever `definitief`.

A document with NO route SHALL be unaffected, and so SHALL an instance without
decidiq. Most documents have never been near an approval route, and refusing
them would break the forward-only lifecycle for everybody to serve a feature
almost nobody uses. "No route", "no decidiq" and "a route that cleared" all
permit the transition, and only the third is an approval: they SHALL remain
distinguishable, because the Files-tab marker is the difference between a
document nobody reviewed and one three people agreed to.

Making a document `definitief` locks it and stamps `lockedOn`. The person who
has to defend the lock is the handler on the case, not the last approver in a
widget, so the act stays theirs.

**Feature tier**: MVP

#### Scenario: An approved route offers the lock
@e2e tests/e2e/approval-chain-on-the-document.spec.ts

- **GIVEN** a concept document whose three-step route completed approved
- **WHEN** the handler opens it
- **THEN** the final transition SHALL be offered, naming the route
- **AND** the document SHALL still be `draft` until the handler makes the transition

#### Scenario: An open route refuses the lock
@e2e tests/e2e/approval-chain-on-the-document.spec.ts

- **GIVEN** a concept document in a route at step one of three
- **WHEN** somebody tries to make it final
- **THEN** the transition SHALL be refused
- **AND** the refusal SHALL name the route and the step it is waiting on
