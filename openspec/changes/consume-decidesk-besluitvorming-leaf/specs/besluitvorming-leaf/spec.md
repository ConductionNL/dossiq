# Besluitvorming — consume decidesk decisions leaf on the case detail

## ADDED Requirements

### Requirement: REQ-BVL-001 — The case-detail page MUST surface decidesk's Besluitvorming as an OR integration leaf

The procest `CaseDetail` page MUST surface decidesk's decision-making via the shared OpenRegister integration registry leaf `decidesk-decisions` as a sidebar tab labelled "Besluitvorming". procest MUST resolve the registered provider's tab component from `window.OCA.OpenRegister.integrations` at render time and forward the case's `{ register, schema, objectId }` context. procest MUST NOT re-implement the decision list, create, or open-in-decidesk behaviour — it only references the registry id (ADR-019 / ADR-022).

#### Scenario: Besluitvorming tab appears on the case detail

- GIVEN the decidesk app is enabled and its decisions leaf is registered on the OR integration registry
- WHEN the user opens a procest case detail page
- THEN a "Besluitvorming" tab is shown in the case sidebar tab strip

#### Scenario: The leaf reads decisions linked to the case

- GIVEN the "Besluitvorming" tab is open on a case detail
- WHEN the leaf loads
- THEN it lists the decidesk proposals/advice/decisions whose `subjectId` matches the case uuid (read path), using the `{ register, schema, objectId }` context forwarded by procest

#### Scenario: Graceful fallback when decidesk's leaf is absent

- GIVEN the decidesk decisions leaf is NOT registered on the OR integration registry
- WHEN the user opens the "Besluitvorming" tab on a case detail
- THEN a quiet "Besluitvorming unavailable" notice is shown instead of a broken tab

### Requirement: REQ-BVL-002 — The standalone Besluitvorming nav MUST be retired while its pages stay routable

The `Voorstellen`, `Advies` (`Advice`) and `Agenda` (`BesluitvormingAgenda`) top-level navigation entries and the `BesluitvormingGroup` group MUST NOT appear in the procest left navigation. Their underlying pages (`/voorstellen`, `/voorstellen/:id`, `/advice`, `/advice/:id`, `/besluitvorming/agenda`, `/besluitvorming/vergaderingen/:id`) and the `voorstel` / `adviesAanvraag` schemas and data MUST remain intact and reachable as deep links (ADR-044).

#### Scenario: Besluitvorming nav entries are gone

- GIVEN the user opens the procest app
- WHEN the sidebar navigation renders
- THEN no "Voorstellen", "Advies", "Agenda" or "Besluitvorming" group entries appear in the navigation

#### Scenario: Former pages stay reachable by deep link

- GIVEN the Besluitvorming nav has been retired
- WHEN the user navigates directly to `/voorstellen` (or `/advice`, `/besluitvorming/agenda`)
- THEN the corresponding page still renders (the route is registered)

### Requirement: REQ-BVL-003 — The voorstel → decidesk migration MUST reuse the existing delegation repair step

The migration of procest `voorstel` records into decidesk's decision model MUST be performed by the existing `procest-delegate-remaining-decisions-to-decidesk` mechanism (`AdviceDelegationService::raiseVoorstelBesluit` invoked from `LinkInFlightRemainingDecisionsRepair`), which raises a decidesk Decision with `subjectId = voorstel.uuid`, `externalReference = case`, `subjectLabel = onderwerp`, and records the `decisionRef` back on the voorstel. This change MUST NOT introduce a second migration mechanism, MUST NOT drop data (terminal/already-linked records are kept), and MUST be idempotent.

#### Scenario: Migration delegates to the existing repair step

- GIVEN procest `voorstel` records exist that are not yet linked to a decidesk Decision and are not in a terminal status
- WHEN `LinkInFlightRemainingDecisionsRepair` runs (on `occ upgrade`)
- THEN each is linked forward to a decidesk Decision via `raiseVoorstelBesluit` and its `decisionRef` is persisted, without dropping any voorstel field

#### Scenario: Migration warns and skips when the decidesk write path is unavailable

- GIVEN the decidesk decision write path is unavailable (e.g. the `decisionType: format: uuid` 422 caveat)
- WHEN the repair step attempts to link a voorstel
- THEN it warns and skips that record rather than failing the migration, leaving the voorstel data intact for a later re-run
