# complaint-management Specification

## Purpose
Implement klachtafhandeling (complaint management) as a first-class entity in Procest with its own lifecycle, escalation to formal cases, disposition tracking, and frequency analysis. Complaints are a distinct intake channel from regular cases: they follow a lighter process, have legal response deadlines (Awb), and can escalate to formal cases when the complaint reveals a larger issue.

Mature case management platforms implement complaint management with separate complaint entities, close/approval workflows, disposition tracking per complaint, and frequency tracking to detect systemic issues. In Dutch municipal practice, the Algemene wet bestuursrecht (Awb) chapter 9 mandates a formal klachtenprocedure with specific timelines and process requirements.

## Requirements

### Requirement: Complaints MUST be first-class entities separate from cases
A complaint (klacht) has its own schema and lifecycle, distinct from a zaak.

#### Scenario: Register a new complaint
- GIVEN the Procest complaints module is enabled
- WHEN a citizen submits a complaint (via intake form, phone, or in person)
- THEN a complaint object MUST be created with:
  - `klager`: the person filing the complaint (name, contact info)
  - `onderwerp`: subject of the complaint
  - `omschrijving`: detailed description
  - `ontvangstdatum`: date the complaint was received
  - `categorie`: complaint category (e.g., service quality, waiting time, employee behavior)
  - `betrokkenMedewerker`: optional reference to the employee the complaint is about
  - `status`: initial status `ontvangen`
  - `behandelaar`: assigned complaint handler

#### Scenario: Complaint numbering
- GIVEN complaints need sequential tracking numbers
- WHEN a new complaint is created
- THEN a complaint number MUST be generated (format: `KL-{year}-{sequence}`, e.g., `KL-2026-0042`)

### Requirement: Complaints MUST follow a defined lifecycle with legal deadlines
The Awb prescribes complaint handling timelines.

#### Scenario: Complaint lifecycle with Awb deadlines
- GIVEN complaint `kl-1` is received on 2026-03-01
- THEN the system MUST calculate:
  - `ontvangstbevestigingDeadline`: 5 working days (2026-03-08) for acknowledgment
  - `afhandelDeadline`: 6 weeks (2026-04-12) for resolution
  - `verdagingMogelijk`: optional 4-week extension (to 2026-05-10)
- AND status transitions MUST be: `ontvangen` -> `in_behandeling` -> `hoorgesprek_gepland` -> `afgehandeld`

#### Scenario: Overdue complaint alert
- GIVEN complaint `kl-1` has `afhandelDeadline` 2026-04-12
- AND the current date is 2026-04-10 (2 days before deadline)
- THEN the system MUST alert the complaint handler
- AND the complaint MUST appear highlighted in the overdue dashboard

### Requirement: Complaints MUST support a hearing (hoorgesprek)
The Awb gives the complainant the right to be heard.

#### Scenario: Schedule a hearing
- GIVEN complaint `kl-1` is `in_behandeling`
- WHEN the handler schedules a hearing
- THEN a hearing record MUST be created with:
  - `datum`: scheduled date and time
  - `locatie`: location (physical or video link)
  - `deelnemers`: list of participants (complainant, handler, subject employee, witnesses)
- AND the complaint status MUST change to `hoorgesprek_gepland`

#### Scenario: Record hearing outcome
- GIVEN the hearing for `kl-1` has taken place
- WHEN the handler records the outcome
- THEN the hearing record MUST be updated with:
  - `verslag`: summary of the hearing
  - `conclusie`: preliminary conclusion

### Requirement: Complaints MUST support escalation to formal cases
When a complaint reveals a larger issue, it can escalate to a formal case (zaak).

#### Scenario: Escalate complaint to case
- GIVEN complaint `kl-1` reveals a systemic service failure
- WHEN the handler escalates to a formal case
- THEN a new zaak MUST be created in Procest
- AND the zaak MUST reference the originating complaint
- AND the complaint MUST reference the created zaak
- AND the complaint's documents and history MUST be accessible from the zaak

### Requirement: Disposition tracking MUST record how complaints are resolved
Each complaint ends with a formal disposition (oordeel).

#### Scenario: Close complaint with disposition
- GIVEN complaint `kl-1` has been investigated
- WHEN the handler closes the complaint
- THEN a disposition MUST be recorded:
  - `oordeel`: enum (`gegrond`, `deels_gegrond`, `ongegrond`, `ingetrokken`, `niet_ontvankelijk`)
  - `maatregelen`: actions taken or promised (free text + checklist)
  - `afsluitdatum`: date of closure
  - `afsluitbrief`: reference to the formal response letter

### Requirement: Frequency analysis MUST detect patterns
Recurring complaints about the same subject, department, or employee signal systemic issues.

#### Scenario: Detect complaint pattern
- GIVEN 5 complaints in the last quarter are about waiting times at the balie
- WHEN a manager views the complaint analytics dashboard
- THEN the system MUST show:
  - Complaint frequency by category (bar chart)
  - Complaint frequency by department
  - Trend over time (increasing/decreasing)
  - Average resolution time by category
- AND categories with significantly increased frequency MUST be flagged

### Current Implementation Status

**Not yet implemented.** No complaint-specific schemas, controllers, services, or Vue components exist in the Procest codebase. There is no "klacht" schema in `procest_register.json`.

**Foundation available:**
- The case management infrastructure could model complaints as a specialized case type with specific status types (ontvangen, in_behandeling, hoorgesprek_gepland, afgehandeld) and properties.
- Case type configuration (`src/views/settings/CaseTypeDetail.vue`) could define a "Klacht behandeling" case type with Awb-mandated deadlines.
- The dashboard (`src/views/Dashboard.vue`) already shows KPI cards that could be extended with complaint-specific metrics.
- Task management (`src/views/tasks/`) could model hearing scheduling as tasks.
- The `caseType` schema supports `processingDeadline` which could enforce the 6-week Awb deadline.

**Partial implementations:** The case management system could handle complaints as a case type configuration exercise without any code changes for basic complaint tracking. The specialized features (hearing management, disposition tracking, escalation, frequency analysis) would require new code.

### Standards & References

- **Awb Chapter 9 (Algemene wet bestuursrecht)**: Legal framework mandating the klachtenprocedure with specific timelines (5 working days acknowledgment, 6 weeks resolution, 4-week extension).
- **Nationale ombudsman**: Oversight body for complaint handling; municipalities must comply with ombudsman recommendations.
- **VNG Model Klachtenverordening**: Standard complaint ordinance template used by Dutch municipalities.
- **GEMMA**: Klachtafhandeling is a standard process in the GEMMA reference architecture.
- **ZGW Zaken API**: Complaints could be modeled as a specific zaaktype with their own catalogi entry.
- **ISO 10002**: Quality management -- Customer satisfaction -- Guidelines for complaints handling.

### Specificity Assessment

This spec is well-structured with clear Awb-based requirements but lacks data model and technical detail.

**What's missing:**
- No OpenRegister schema definition for the complaint entity (beyond the informal field list in the scenario).
- No specification of the complaint-specific admin configuration (categories, hearing templates, disposition options).
- No specification of the frequency analysis dashboard UI (charts, filters, time ranges).
- No specification of the escalation workflow (automatic or manual, which case type is created).
- No specification of the complaint numbering system implementation.
- No hearing data model (separate schema or embedded in complaint).

**Open questions:**
1. Should complaints be modeled as a separate OpenRegister schema or as a case type configuration?
2. How does the frequency analysis aggregate data -- real-time queries or periodic batch calculation?
3. Should the hearing (hoorgesprek) support video conferencing integration?
4. How does the complaint system interact with the Nationale ombudsman reporting requirements?
