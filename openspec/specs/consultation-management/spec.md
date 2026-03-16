# consultation-management Specification

## Purpose
Implement structured inter-departmental consultation (adviesaanvraag) as a first-class entity in Procest. A consultation is a mini-case linked to a parent case, with its own lifecycle, assigned participants, documents, due dates, and formal response. This replaces informal email-based advice requests with tracked, auditable departmental coordination.

Structured consultation management -- where consultations are linked objects with their own status workflow, participant tracking, document exchange, and due date enforcement -- is an established pattern in enterprise case management. In Dutch government practice, adviesaanvragen between departments (e.g., requesting fire safety advice from the brandweer for a building permit) are common and currently lack formal tracking in most case management systems.

## Requirements

### Requirement: Consultations MUST be first-class entities linked to parent cases
A consultation (adviesaanvraag) is stored as an OpenRegister object with a dedicated schema.

#### Scenario: Create a consultation for a case
- GIVEN case `zaak-1` (type: `omgevingsvergunning`) requires fire safety advice
- WHEN a case worker creates a consultation
- THEN a consultation object MUST be created with:
  - `parentZaak`: reference to `zaak-1`
  - `adviesInstantie`: the department or organization being consulted (e.g., "Brandweer")
  - `onderwerp`: subject of the consultation
  - `vraagstelling`: the specific question(s) being asked
  - `uiterlijkeReactiedatum`: the deadline for response
  - `status`: initial status `open`
  - `aanvrager`: the case worker who initiated the request

#### Scenario: Multiple consultations per case
- GIVEN case `zaak-1` needs advice from both Brandweer and Welstandscommissie
- WHEN two consultations are created
- THEN both MUST be visible in the case's consultation list
- AND each MUST have independent lifecycles

### Requirement: Consultations MUST have their own lifecycle
A consultation progresses through statuses independently of the parent case.

#### Scenario: Consultation lifecycle
- GIVEN consultation `cons-1` is created with status `open`
- WHEN the consulted department acknowledges receipt
- THEN the status MUST change to `in_behandeling`
- WHEN the department submits their advice
- THEN the status MUST change to `advies_uitgebracht`
- WHEN the case worker reviews and closes the consultation
- THEN the status MUST change to `afgesloten`

#### Scenario: Overdue consultation
- GIVEN consultation `cons-1` has `uiterlijkeReactiedatum` of 2026-03-20
- AND the current date is 2026-03-21
- AND the status is still `in_behandeling`
- THEN the consultation MUST be flagged as overdue
- AND a notification MUST be sent to both the requesting case worker and the consulted department

### Requirement: Consultations MUST support document exchange
Both the requester and the consulted party can attach documents.

#### Scenario: Attach context documents to consultation
- GIVEN case `zaak-1` has building plans as documents
- WHEN creating consultation `cons-1` for fire safety advice
- THEN the case worker MUST be able to link relevant case documents to the consultation
- AND the consulted department MUST be able to view those documents

#### Scenario: Consulted party uploads advice document
- GIVEN consultation `cons-1` is `in_behandeling`
- WHEN the Brandweer uploads their formal advice as a PDF
- THEN the document MUST be linked to the consultation
- AND it MUST also be accessible from the parent case's document list

### Requirement: Consultation responses MUST be structured
The advice response includes a formal conclusion and optional conditions.

#### Scenario: Submit positive advice
- GIVEN consultation `cons-1` asks "Is the building fire-safe?"
- WHEN the Brandweer submits their response
- THEN the response MUST include:
  - `advies`: enum value (`positief`, `positief_met_voorwaarden`, `negatief`, `niet_van_toepassing`)
  - `toelichting`: explanation text
  - `voorwaarden`: optional list of conditions (if `positief_met_voorwaarden`)
  - `datum`: date the advice was given

#### Scenario: Advice with conditions flows back to parent case
- GIVEN consultation `cons-1` has advice `positief_met_voorwaarden` with conditions
- WHEN the case worker views the parent case
- THEN the conditions MUST be visible as action items on the case
- AND the case worker MUST be able to mark conditions as addressed

### Requirement: Consultations MUST be visible in the parent case timeline
All consultation events appear in the parent case's activity feed.

#### Scenario: Consultation events in case timeline
- GIVEN case `zaak-1` has consultation `cons-1`
- WHEN viewing the case timeline
- THEN the following events MUST appear:
  - "Adviesaanvraag created for Brandweer" (with date and requester)
  - "Brandweer acknowledged consultation" (with date)
  - "Brandweer submitted advice: positief met voorwaarden" (with date)
  - "Consultation closed" (with date and closer)

### Requirement: Dashboard MUST show pending consultations
Case workers and department heads need oversight of open consultations.

#### Scenario: My pending consultations view
- GIVEN a Brandweer user has 3 open consultations assigned to their department
- WHEN they view the consultations dashboard
- THEN all 3 MUST be listed with parent case reference, subject, and deadline
- AND overdue items MUST be highlighted

### Current Implementation Status

**Not yet implemented.** No consultation-specific (adviesaanvraag) schemas, controllers, services, or Vue components exist in the Procest codebase.

**Foundation available:**
- Case detail view (`src/views/cases/CaseDetail.vue`) provides the integration point where a "Consultations" panel could be added.
- Activity timeline component (`src/views/cases/components/ActivityTimeline.vue`) could display consultation events.
- Task management infrastructure (`src/views/tasks/`) could model consultation steps as tasks assigned to the consulted department.
- The `role` schema in OpenRegister could represent the consulted party.
- The object store with `relationsPlugin` supports linking objects (consultations to parent cases).
- Document management (filesPlugin) supports attaching documents to consultations.

**Partial implementations:** None.

### Standards & References

- **Awb (Algemene wet bestuursrecht)**: Administrative law provisions for inter-departmental consultation (adviesrecht, 3:5-3:9 Awb).
- **ZGW Zaken API (VNG)**: Consultations could be modeled as related zaken or as custom zaakobjecten.
- **GEMMA**: Adviesaanvraag is a standard interaction pattern in GEMMA ketenprocessen.
- **Common Ground**: Inter-organizational data exchange follows Common Ground API-first principles.
- **BIO**: Security requirements for sharing case information between departments/organizations.

### Specificity Assessment

This spec provides a solid functional overview with clear lifecycle, document exchange, and structured response requirements.

**What's missing:**
- No OpenRegister schema definition for the consultation entity (formal fields, types, validations).
- No specification of how consulted parties receive and interact with consultations (separate view, shared case access, or email notification with link).
- No API endpoints for consultation CRUD.
- No specification of permission model (can the consulted party see the full case or only the consultation context?).
- No specification of how conditions from advice flow back as actionable items on the parent case.
- No UI wireframes for the consultation panel, creation dialog, or department inbox.

**Open questions:**
1. Should consultations be modeled as sub-cases, as OpenRegister objects with a dedicated schema, or as tasks?
2. How do external organizations (e.g., Brandweer) access the consultation -- via Nextcloud account, share link, or email?
3. Should the system support parallel consultations with a "wait for all" or "wait for any" completion rule?
4. How are departments defined in the system -- Nextcloud groups, OpenRegister objects, or configuration?
