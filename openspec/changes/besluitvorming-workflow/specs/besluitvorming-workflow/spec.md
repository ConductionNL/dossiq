---
status: proposed
---
# besluitvorming-workflow Specification

## Purpose

Configure the Procest workflow engine with workflows and data for the municipal formal decision-making process (bestuurlijke besluitvorming). This covers the lifecycle of formal decisions by College van B&W, Gemeenteraad, and other governing bodies — from proposal drafting through approval chains (parafering), agenda management, formal decision recording, official publication (DROP/LVBB), and archival.

## Context

In Dutch municipal practice, bestuurlijke besluitvorming follows a strict governance chain defined by the Gemeentewet and the Wet elektronische bekendmaking (Wab). A voorstel must travel through an approval chain (parafering) before it can be placed on a vergadering agenda. The vergadering produces a formal besluit that must be recorded with structured metadata (stemuitslag, governingBody) and published via DROP (Decentrale Regelgeving en Officiële Publicaties) or its successor LVBB (Landelijke Voorziening Bekendmaken en Beschikbaarstellen). The entire dossier must be archived per the gemeentelijke selectielijst.

Procest has generic case infrastructure (caseType, workflowTemplate, statusType) and parafering primitives (voorstel, parafeerroute, parafeeractie) from the `bw-parafering` spec. This change assembles those primitives into ready-to-use besluitvorming zaaktype bundles and adds three targeted services: a parafering chain orchestrator, an agenda compiler, and a publication dispatcher.

Market evidence: 264 requirements and 126 tenders explicitly request bestuurlijke besluitvorming support. Representative: "De gemeente Coevorden wil het bestuurlijke besluitvormingsproces onderbrengen en ondersteunen binnen de Oplossing."

## ADDED Requirements

### Requirement REQ-BVW-001: Zaaktype templates MUST be pre-configured for the three core besluitvorming types

The system SHALL ship pre-configured `caseType` + `workflowTemplate` bundles for College-besluit, Raadsbesluit, and Mandaatbesluit, activated via the repair step. Each bundle MUST include `statusType`, `propertyDefinition`, `roleType`, `documentType`, and `resultType` records.

#### Scenario REQ-BVW-001-A: Activate College-besluit template

- **GIVEN** the workflow engine is installed and the besluitvorming templates are present
- **WHEN** an administrator activates the "College-besluit" template via `POST /api/besluitvorming/templates/college-besluit/activate`
- **THEN** a `caseType` object MUST be created with `title = 'College-besluit'` and `publicationRequired = true`
- **AND** a `workflowTemplate` MUST be created with the nine standard process steps in order (Voorstel opstellen, Ambtelijk advies, Parafering, Gereed voor agendering, Geagendeerd, Vergadering, Besluit genomen, Bekendmaking, Gearchiveerd)
- **AND** `statusType` records MUST be created for each step with the correct `order` and `isFinal` values
- **AND** `propertyDefinition` records MUST be created for: `stemuitslag`, `portefeuillehouder`, `vergadergremium`, `agendanummer`, `publicatieReferentie`
- **AND** `roleType` records MUST be created for: Steller, Portefeuillehouder, Beleidsadviseur, Afdelingshoofd
- **AND** the activation MUST be idempotent (re-running does not create duplicate records)

#### Scenario REQ-BVW-001-B: Raadsbesluit template includes griffier role and extended deadline

- **GIVEN** the besluitvorming templates are installed
- **WHEN** an administrator activates the "Raadsbesluit" template
- **THEN** the resulting `caseType` MUST have `processingDeadline = 'P60D'`
- **AND** the `parafeerroute` MUST include a Griffier step as the final approval step
- **AND** a `roleType` for "Griffier" MUST exist on this `caseType`

#### Scenario REQ-BVW-001-C: Mandaatbesluit template has confidentiality set to intern

- **GIVEN** the besluitvorming templates are installed
- **WHEN** an administrator activates the "Mandaatbesluit" template
- **THEN** the resulting `caseType` MUST have `confidentiality = 'intern'` and `publicationRequired = false`
- **AND** the workflow MUST include a mandate-authority guard on the "Besluit genomen" transition

---

### Requirement REQ-BVW-002: Parafering chain MUST activate automatically when a voorstel is submitted

When a `voorstel` is submitted for parafering, the system SHALL activate the configured `parafeerroute`, snapshot the steps, and create a `task` for the first parafeerder. Each subsequent parafeerder MUST receive a task only after the previous step is completed.

#### Scenario REQ-BVW-002-A: Parafering chain activates on voorstel submission

- **GIVEN** a College-besluit case with a `voorstel` in status `concept` and a configured `parafeerroute` with 3 steps (Beleidsadviseur → Afdelingshoofd → Gemeentesecretaris)
- **WHEN** the steller submits the voorstel (changes `voorstel.status` to `ingediend`)
- **THEN** the workflow engine MUST trigger `BesluitvormingParafeerService.activate()`
- **AND** `voorstel.routeSnapshot` MUST be populated with a snapshot of the parafeerroute steps
- **AND** `voorstel.currentStep` MUST be set to `1`
- **AND** a `task` MUST be created for the Beleidsadviseur with title "Paraaf vereist: [voorstel.onderwerp]"
- **AND** the Beleidsadviseur MUST receive a Nextcloud notification

#### Scenario REQ-BVW-002-B: Sequential task creation — next parafeerder receives task after previous approves

- **GIVEN** the parafering chain for voorstel "Beleidsplan Duurzaamheid" is active at step 1 (Beleidsadviseur)
- **WHEN** the Beleidsadviseur creates a `parafeeractie` with `action = 'goedgekeurd'` and `step = 1`
- **THEN** `voorstel.currentStep` MUST increment to `2`
- **AND** a new `task` MUST be created for the Afdelingshoofd
- **AND** the task for the Beleidsadviseur MUST be marked as completed
- **AND** the Afdelingshoofd MUST receive a Nextcloud notification

#### Scenario REQ-BVW-002-C: Voorstel returned by parafeerder sends back to steller

- **GIVEN** the parafering chain is active at step 2 (Afdelingshoofd) for voorstel "Beleidsplan Duurzaamheid"
- **WHEN** the Afdelingshoofd creates a `parafeeractie` with `action = 'retour'` and a mandatory `comment`
- **THEN** `voorstel.status` MUST change to `retour`
- **AND** `voorstel.returnedFromStep` MUST be set to `2`
- **AND** the steller MUST receive a notification including the Afdelingshoofd's comment
- **AND** when the steller resubmits, the chain MUST resume from step 2 (not step 1)

#### Scenario REQ-BVW-002-D: Delegate paraaf with mandate reference

- **GIVEN** the Gemeentesecretaris is absent and has delegated to the Loco-gemeentesecretaris
- **WHEN** the Loco-gemeentesecretaris creates a `parafeeractie` with `actorType = 'gemachtigde'` and `onBehalfOf = <Gemeentesecretaris-uid>` and `mandate = 'mandaatregister-ref-2026-003'`
- **THEN** the paraaf MUST be accepted as valid for step 3
- **AND** the `parafeeractie` record MUST store the delegate, the principal, and the mandate reference
- **AND** the paraaf chain MUST proceed normally

---

### Requirement REQ-BVW-003: Case MUST auto-transition to "Gereed voor agendering" when all parafen are collected

When the final parafeeractie in the route is completed with `action = 'goedgekeurd'`, the system SHALL automatically update the voorstel status and trigger the case workflow transition.

#### Scenario REQ-BVW-003-A: Automatic status transition after final paraaf

- **GIVEN** a College-besluit case with a 3-step parafeerroute
- **AND** parafen at steps 1 and 2 have been collected
- **WHEN** the Gemeentesecretaris creates a `parafeeractie` with `action = 'goedgekeurd'` at step 3
- **THEN** `voorstel.status` MUST change to `gereed_voor_agendering`
- **AND** the case MUST automatically transition to status "Gereed voor agendering"
- **AND** the agenda manager MUST receive a notification that a new item is available for agenda compilation
- **AND** the case MUST appear in the `AgendaService.getReadyItems()` queue

#### Scenario REQ-BVW-003-B: Case is not transitioned until all required steps are complete

- **GIVEN** a 3-step parafeerroute where step 2 is optional (`required = false`) and steps 1 and 3 are required
- **WHEN** only steps 1 and 3 are parafeered (step 2 was skipped)
- **THEN** the case MUST still transition to "Gereed voor agendering"
- **AND** the `parafeeractie` for the skipped step MUST have `action = 'overgeslagen'`

---

### Requirement REQ-BVW-004: Agenda compiler MUST support hamerstukken and bespreekstukken with configurable ordering

The system SHALL allow an agenda manager to compile multiple ready-for-agendering cases into a meeting agenda, classify each item as `hamerstuk` or `bespreekstuk`, and reorder items.

#### Scenario REQ-BVW-004-A: Compile cases into a vergadering agenda

- **GIVEN** 4 College-besluit cases with status "Gereed voor agendering"
- **WHEN** the agenda manager opens the `AgendaCompilerView` and selects a meeting date
- **THEN** the 4 cases MUST be listed as available agenda items
- **AND** the manager MUST be able to drag cases into the agenda and set their order
- **AND** each item MUST be classifiable as `hamerstuk` or `bespreekstuk` via a toggle
- **AND** the classification and order MUST be stored as `caseProperty` values (`agendanummer`, `behandeling`)

#### Scenario REQ-BVW-004-B: Cases transition to "Geagendeerd" when added to agenda

- **GIVEN** the agenda manager adds case "Vaststelling Beleidsplan Duurzaamheid" to the College vergadering of 2026-06-10
- **WHEN** the manager confirms the agenda
- **THEN** the case MUST transition to status "Geagendeerd"
- **AND** `caseProperty.agendanummer` MUST be set (e.g. `'5.2'`)
- **AND** the steller and portefeuillehouder of the case MUST receive a notification

#### Scenario REQ-BVW-004-C: Generate agenda document via Docudesk

- **GIVEN** a confirmed agenda with 6 items (3 hamerstukken, 3 bespreekstukken) for the College vergadering of 2026-06-10
- **WHEN** the agenda manager clicks "Agenda genereren"
- **THEN** a `document` MUST be created via Docudesk with the agenda in PDF format
- **AND** the document MUST list hamerstukken first, followed by bespreekstukken, each with the correct `agendanummer`
- **AND** the document MUST be linked to the vergadering case via `caseDocument`

#### Scenario REQ-BVW-004-D: Multiple vergadergremia each have independent agendas

- **GIVEN** the municipality has configured College B&W and Gemeenteraad as separate vergadergremia
- **WHEN** the agenda manager compiles an agenda for the Gemeenteraad
- **THEN** only cases with `caseType.title = 'Raadsbesluit'` MUST appear in the available items list
- **AND** College-besluit cases MUST NOT appear in the Raadsbesluit agenda compiler

---

### Requirement REQ-BVW-005: Decision MUST be recorded with structured metadata including stemuitslag and attending members

When a vergadering concludes, the system SHALL require the recording of the formal `decision` object including stemuitslag, governingBody, and attending members before allowing the case to advance.

#### Scenario REQ-BVW-005-A: Record decision with stemuitslag after vergadering

- **GIVEN** a College-besluit case in status "Vergadering" for the College meeting of 2026-06-10
- **WHEN** the griffier or secretaris opens the `VergaderingDetailView` and records the outcome
- **THEN** a `decision` object MUST be created with:
  - `case`: reference to this case
  - `decisionDate`: the date of the meeting
  - `governingBody`: the configured vergadergremium (e.g. "College van Burgemeester en Wethouders")
  - `decisionType`: reference to the applicable `decisionType` (goedgekeurd/verworpen/aangehouden)
  - `explanation`: the decision text
- **AND** `caseProperty.stemuitslag` MUST be set (e.g. "Unaniem", "5 voor / 2 tegen")
- **AND** the attending members MUST be recorded as `role` objects with roleType "Aanwezig lid"
- **AND** the case MUST transition to "Besluit genomen" only after the `decision` object is saved

#### Scenario REQ-BVW-005-B: Raadsbesluit records voting result with voor/tegen counts

- **GIVEN** a Raadsbesluit case in status "Vergadering" with 31 raadsleden present
- **WHEN** the griffier records the stemming with 23 voor and 8 tegen
- **THEN** `caseProperty.stemuitslag` MUST store `'23 voor / 8 tegen'`
- **AND** the `decision.explanation` MUST include the stemuitslag text
- **AND** the case MUST transition to "Besluit genomen"

#### Scenario REQ-BVW-005-C: Aangehouden besluit does not proceed to Bekendmaking

- **GIVEN** a College-besluit case in status "Vergadering"
- **WHEN** the decision is recorded with `decisionType` set to "Aangehouden" (decision deferred)
- **THEN** the case MUST NOT transition to "Bekendmaking"
- **AND** the case status MUST change to a terminal-like "Aangehouden" sub-status or cycle back to "Gereed voor agendering" for a future meeting
- **AND** a notification MUST be sent to the steller and portefeuillehouder indicating the deferral

---

### Requirement REQ-BVW-006: Publication MUST provide an integration point for DROP/LVBB with required metadata

When a besluit must be published, the system SHALL assemble the publication payload and dispatch it to the configured DROP or LVBB endpoint, then store the publication reference on the case.

#### Scenario REQ-BVW-006-A: Trigger DROP publication on Bekendmaking transition

- **GIVEN** a College-besluit case in status "Besluit genomen" with a signed `decision` object and an attached besluitdocument
- **WHEN** the handler advances the case to "Bekendmaking"
- **THEN** `PublicationService.dispatch()` MUST be triggered automatically by the workflow engine's auto-action
- **AND** the service MUST assemble a publication payload containing:
  - `title`: from `decision.title`
  - `decisionDate`: from `decision.decisionDate`
  - `effectiveDate`: from `decision.effectiveDate`
  - `governingBody`: from `decision.governingBody`
  - `documentUrl`: the signed besluitdocument URL
  - `caseIdentifier`: the case `identifier`
- **AND** the payload MUST be dispatched to the configured DROP/LVBB endpoint via OpenConnector
- **AND** on success, `decision.publicationDate` MUST be set and `caseProperty.publicatieReferentie` MUST store the returned URI

#### Scenario REQ-BVW-006-B: Publication failure is surfaced without blocking the case

- **GIVEN** the DROP/LVBB endpoint is unavailable
- **WHEN** `PublicationService.dispatch()` fails with a connection error
- **THEN** the case MUST NOT be blocked in status "Bekendmaking"
- **AND** a failed publication event MUST be logged in the case `activity` trail
- **AND** the handler MUST be notified of the failure with a retry button in `BesluitPublicatiePanel.vue`
- **AND** the handler MUST be able to manually trigger a retry via `POST /api/besluitvorming/cases/{id}/publish`

#### Scenario REQ-BVW-006-C: Mandaatbesluit skips publication when publicationRequired is false

- **GIVEN** a Mandaatbesluit case with `caseType.publicationRequired = false`
- **WHEN** the case reaches "Besluit genomen"
- **THEN** the workflow MUST skip the "Bekendmaking" step automatically
- **AND** the case MUST transition directly to "Gearchiveerd" (or the applicable next step)
- **AND** no DROP/LVBB payload MUST be dispatched

---

### Requirement REQ-BVW-007: Mandaatbesluit MUST validate signing authority against the mandaatregister

Before a Mandaatbesluit case can advance to "Besluit genomen", the system SHALL verify that the signing official has sufficient delegated authority for the subject matter of the decision.

#### Scenario REQ-BVW-007-A: Valid mandate allows transition to Besluit genomen

- **GIVEN** a Mandaatbesluit case for "vergunningverlening kleine bouwwerken" where the signing official is the Afdelingshoofd Vergunningen
- **WHEN** the workflow guard checks the mandate via `MandaatValidationService.validate()`
- **AND** the mandaatregister confirms the Afdelingshoofd has authority for category "VTH-M-04" (small permits up to EUR 250.000)
- **THEN** the transition guard MUST pass
- **AND** the case MUST advance to "Besluit genomen"

#### Scenario REQ-BVW-007-B: Insufficient mandate blocks transition with clear error

- **GIVEN** a Mandaatbesluit case for a decision exceeding the mandate limit of the signing official
- **WHEN** the workflow guard queries the mandaatregister
- **AND** the mandaatregister returns that the official's mandate does not cover the decision scope
- **THEN** the transition to "Besluit genomen" MUST be blocked
- **AND** the case handler MUST see an error message: "De ondertekenende ambtenaar heeft onvoldoende mandaat voor dit besluit. Raadpleeg het mandaatregister."
- **AND** a link to the relevant mandaatregister entry MUST be shown

#### Scenario REQ-BVW-007-C: Mandaatregister unreachable falls back to manual confirmation

- **GIVEN** the mandaatregister endpoint is configured but currently unreachable
- **WHEN** the workflow guard attempts validation
- **THEN** the guard MUST NOT silently pass
- **AND** the handler MUST be prompted to confirm manually that the signing official has sufficient authority
- **AND** the manual confirmation MUST be logged in the case audit trail

---

### Requirement REQ-BVW-008: Archival MUST link all case documents in the dossier before closing

When a besluitvorming case is archived, the system SHALL verify that all required documents (voorstel, adviezen, parafen, besluit, bekendmaking record) are linked in the case dossier before setting the final archived status.

#### Scenario REQ-BVW-008-A: Archiving requires all mandatory documents to be present

- **GIVEN** a College-besluit case in status "Bekendmaking" with `publicationRequired = true`
- **WHEN** the handler advances the case to "Gearchiveerd"
- **THEN** the workflow guard MUST check that the following `documentType` records are satisfied:
  - Collegeadvies (the voorstel document)
  - Besluitdocument (signed)
  - Bekendmakingsbewijs (publication confirmation)
- **AND** if any required document is missing, the transition MUST be blocked with a list of missing documents
- **AND** when all documents are present, the case `archiveStatus` MUST be set to `gearchiveerd` and `archiveNomination` MUST be populated per the configured `resultType.archivalPeriod`

#### Scenario REQ-BVW-008-B: Archived case dossier is accessible via case API

- **GIVEN** a completed and archived College-besluit case "Vaststelling Beleidsplan Duurzaamheid 2027-2031"
- **WHEN** an authorized user retrieves the case via the OpenRegister API
- **THEN** the `files` collection MUST include references to:
  - The primary voorstelnotitie
  - All received adviesdocumenten (via linked `adviesAanvraag.adviesDocument`)
  - The parafering record (via linked `parafeeractie` objects)
  - The signed besluitdocument
  - The bekendmakingsbewijs (DROP/LVBB publication confirmation)
- **AND** the case `statusHistory` MUST show all status transitions with timestamps

#### Scenario REQ-BVW-008-C: Archival period is set per resultType configuration

- **GIVEN** a College-besluit case with `resultType` "Besluit genomen" configured with `archivalPeriod = 'P20Y'` and `archivalAction = 'keep'`
- **WHEN** the case is archived
- **THEN** `case.archiveActionDate` MUST be set to today + 20 years
- **AND** `case.archiveNomination` MUST be set to `'blijvend_bewaren'`

---

## Non-Requirements

- This spec does NOT cover the generic workflow engine — that is `workflow-engine-enhancement`.
- This spec does NOT cover Open Raadsinformatie (public ORI API) — that is `openspec/changes/open-raadsinformatie/`.
- This spec does NOT cover document template design (Docudesk configuration is a separate concern).
- This spec does NOT cover financial impact tracking of decisions (ERP domain).
- This spec does NOT cover citizen-facing publicatie portals.
- This spec does NOT cover raadsinformatie system integration beyond publication hooks.

## Dependencies

- **workflow-engine-enhancement** (REQUIRED) — workflow engine with guard types (requiredField, requiredDocument, roleGuard) and automatic actions (webhook, notify).
- **bw-parafering** spec — `voorstel`, `parafeerroute`, `parafeeractie` entities and their service primitives.
- **roles-decisions** spec — `roleType`, `decisionType` patterns.
- OpenRegister — data layer for all entities.
- Docudesk — agenda document and besluit document generation.
- Nextcloud Calendar — vergadering scheduling (`OCP\Calendar\IManager`).
- OpenConnector — DROP/LVBB webhook dispatch.

---

## Standards & References

- **Gemeentewet art. 54–60b**: Bevoegdheden en besluitvorming college en raad.
- **Wet elektronische bekendmaking (Wab)**: Verplichting tot bekendmaking via DROP/LVBB voor besluiten van algemene strekking.
- **DROP API v2**: Decentrale Regelgeving en Officiële Publicaties — endpoint for municipal besluit publication.
- **LVBB STOP-TPOD**: Landelijke Voorziening Bekendmaken en Beschikbaarstellen — successor to DROP for certain publication types.
- **GEMMA SGC 1 — Bestuurlijke besluitvorming**: Reference component describing the bestuurlijke besluitvormingsketen (voorstel → advies → vaststelling → publicatie → archivering).
- **VNG Raadsinformatie**: Standard for council information publication (raadsagenda, stukken, besluiten).
- **Awb art. 3:42–3:44**: Bekendmaking en mededeling van besluiten.
- **Gemeentelijke selectielijst**: Archival retention periods for bestuurlijke documenten (typically 20 years for besluiten of general importance).
