---
status: proposed
---
# Spec: complaint-management

**Status:** proposed
**Scope:** procest
**Depends on:** case-management, case-types, openregister (RBAC + audit + lifecycle + relations per ADR-022), docudesk (letter generation), n8n (intake + deadline monitoring), nextcloud-calendar, nextcloud-talk

## Purpose

Implement klachtafhandeling (complaint management) as a first-class entity in Procest with its own lifecycle, Awb-mandated deadlines, escalation to formal cases, disposition tracking, and frequency analysis. Complaints are a distinct intake channel from regular cases: they follow a lighter process, have legal response deadlines (Awb chapter 9), and can escalate to formal zaken when a complaint reveals a larger systemic issue.

## Context

Awb chapter 9 mandates a formal klachtenprocedure with specific timelines. Citizens have the right to file complaints about government conduct; municipalities must acknowledge within 5 working days, resolve within 6 weeks (with one optional 4-week verdaging), offer the complainant the right to be heard (hoorgesprek), and issue a written disposition (oordeel). Complaints are distinct from bezwaar (objection to a decision) and from regular service requests. Procest currently has no dedicated complaint infrastructure — complaints are logged as generic cases, losing channel-specific intake, Awb deadline math, disposition classification, and frequency pattern detection.

## ADDED Requirements

### REQ-CM-001: The system SHALL store complaints as first-class OpenRegister objects with a dedicated `complaint` schema

Complaints MUST be declared as a register in `lib/Settings/procest_register.json` with the `complaint` schema as the canonical entity. No custom PHP model, no custom database table, no parallel storage (ADR-001, ADR-022). The register is exposed through OpenRegister's generic CRUD API; procest adds no per-app `ComplaintMapper` for basic complaint CRUD.

**Schema.org annotation:** `schema:Message`

Fields: `klachtnummer`, `klager`, `onderwerp`, `omschrijving`, `ontvangstdatum`, `ontvangstkanaal`, `categorie`, `betrokkenMedewerker`, `betrokkenAfdeling`, `behandelaar`, `prioriteit`, `ontvangstbevestigingDeadline`, `afhandelDeadline`, `verdagingMogelijk`, `geescaleerdeZaak`. Full schema definition in `design.md`.

#### Scenario: Register a new complaint via the intake form

- **GIVEN** the complaint-management module is enabled and `complaint_schema` is configured
- **WHEN** a medewerker registers a complaint received from a citizen
- **THEN** a `complaint` object MUST be created in OpenRegister with `klachtnummer` set to `KL-{year}-{sequence}` (e.g. `KL-2026-0042`), `ontvangstdatum` populated, `status` set to `ontvangen`, and `ontvangstkanaal` set to the selected channel

#### Scenario: Complaint numbering resets each calendar year

- **GIVEN** 41 complaints have been registered in 2026
- **WHEN** a new complaint is created on 2026-05-20
- **THEN** the complaint number MUST be `KL-2026-0042`
- **AND** on 2027-01-01 the next complaint MUST receive number `KL-2027-0001`

#### Scenario: Email intake creates a draft complaint automatically

- **GIVEN** a message arrives at klachten@gemeente.nl
- **WHEN** the n8n email-intake workflow processes it
- **THEN** a `complaint` object MUST be created with `ontvangstkanaal: "email"`, `omschrijving` set to the email body, and `klager.email` set to the sender's address
- **AND** the `behandelaar` group MUST receive a notification to review and complete the intake

#### Scenario: Reviewer confirms no parallel storage

- **GIVEN** the procest codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `complaint_`, `klacht_`, or `klachten_`
- **THEN** no such classes SHALL exist; all complaint data flows through the OR object API

---

### REQ-CM-002: The system SHALL enforce Awb chapter 9 deadlines using working-day arithmetic

A `WorkingDayCalculator` helper MUST centralize all working-day math. On complaint creation, the system MUST automatically compute `ontvangstbevestigingDeadline` (5 working days from `ontvangstdatum`) and `afhandelDeadline` (30 working days from `ontvangstdatum`, equivalent to 6 calendar weeks). The calculator MUST respect Dutch public holidays (Nieuwjaarsdag, Pasen, Koningsdag, Bevrijdingsdag, Hemelvaartsdag, Pinksterdag, Kerstmis).

#### Scenario: Deadline calculation on complaint creation

- **GIVEN** complaint `KL-2026-0042` is received on 2026-04-28 (Monday)
- **WHEN** the complaint is saved
- **THEN** `ontvangstbevestigingDeadline` MUST be 2026-05-05 (skipping Koningsdag 2026-04-27 is already passed; 5 working days forward = 2026-05-05)
- **AND** `afhandelDeadline` MUST be 30 working days from 2026-04-28 = 2026-06-09
- **AND** `verdagingMogelijk` MUST be set to `true`

#### Scenario: Verdaging extends the deadline exactly once

- **GIVEN** complaint `KL-2026-0042` has `afhandelDeadline` 2026-06-09 and `verdagingMogelijk` is `true`
- **WHEN** the handler requests a 4-week extension with written justification
- **THEN** `afhandelDeadline` MUST be updated to 2026-07-07 (4 calendar weeks later)
- **AND** `verdagingMogelijk` MUST be set to `false`
- **AND** the complainant MUST be notified of the extension and the reason

#### Scenario: Second verdaging attempt is rejected

- **GIVEN** complaint `KL-2026-0042` has `verdagingMogelijk: false`
- **WHEN** the handler attempts a second extension
- **THEN** the system MUST return an error: "Verdaging reeds toegepast — slechts één verlenging toegestaan (Awb art. 9:11 lid 2)"
- **AND** the deadline MUST remain unchanged

#### Scenario: Deadline warning fires at T-3 working days (acknowledgment)

- **GIVEN** complaint `KL-2026-0042` has `ontvangstbevestigingDeadline` 2026-05-05 and status is still `ontvangen`
- **WHEN** the n8n deadline-monitor job runs on 2026-04-30 (3 working days before deadline)
- **THEN** the `behandelaar` MUST receive a Nextcloud notification: "Klacht KL-2026-0042: Ontvangstbevestiging deadline over 3 werkdagen"

#### Scenario: Overdue complaint is escalated to coordinator

- **GIVEN** complaint `KL-2026-0042` has passed its `afhandelDeadline` without reaching `afgehandeld`
- **WHEN** the n8n deadline-monitor job runs
- **THEN** the `klachten-coordinator` group MUST receive an escalation notification
- **AND** the complaint MUST appear in the "Verlopen" section of the complaint list

---

### REQ-CM-003: The system SHALL support the hoorgesprek (hearing) process per Awb art. 9:10

A `hearing` schema MUST be declared in `procest_register.json`. `HearingService` creates the hearing record and dispatches calendar invitations via `OCP\Calendar\IManager`. For `videogesprek` type, a Nextcloud Talk room MUST be created via `OCP\Talk\IBroker` and the URL stored on the hearing record.

**Schema.org annotation:** `schema:Event`

Fields: `complaint`, `datum`, `locatie`, `type`, `deelnemers`, `talkRoomUrl`, `verslag`, `conclusie`, `aanwezigen`, `datumAfgerond`. Full schema in `design.md`.

#### Scenario: Schedule a physical hearing

- **GIVEN** complaint `KL-2026-0042` has status `in_behandeling`
- **WHEN** the handler schedules a hearing with type `fysiek`, date, location, and participants
- **THEN** a `hearing` object MUST be created linked to the complaint
- **AND** calendar invitations MUST be sent to all `deelnemers` via `OCP\Calendar\IManager`
- **AND** the complaint status MUST change to `hoorgesprek_gepland`

#### Scenario: Video hearing creates a Talk room

- **GIVEN** the hearing type is `videogesprek`
- **WHEN** the hearing is scheduled
- **THEN** `HearingService` MUST call `OCP\Talk\IBroker` to create a conversation
- **AND** the resulting URL MUST be stored in `hearing.talkRoomUrl`
- **AND** the Talk URL MUST be included in the calendar invitation

#### Scenario: Hearing outcome is recorded

- **GIVEN** hearing `hoorgesprek-2026-0001` has taken place
- **WHEN** the handler records `verslag`, `conclusie`, `aanwezigen`, and `datumAfgerond`
- **THEN** the `hearing` object MUST be updated with those fields
- **AND** the complaint status MUST change to `hoorgesprek_afgerond`

#### Scenario: Complainant waives the right to be heard

- **GIVEN** complaint `KL-2026-0042` is `in_behandeling`
- **WHEN** the handler records a waiver (date, method, confirmation text)
- **THEN** the waiver MUST be stored as a document attached to the complaint
- **AND** the complaint MUST skip the hearing statuses and proceed directly to disposition

---

### REQ-CM-004: The system SHALL support bidirectional escalation between complaints and formal cases

`ComplaintService` MUST link complaints to procest `case` objects using the `geescaleerdeZaak` field on `complaint` and a `bronKlacht` relation reference on the `case`. The link is maintained as an OpenRegister relation — no custom foreign-key table.

#### Scenario: Escalate a complaint to a formal case

- **GIVEN** complaint `KL-2026-0042` reveals a systemic service failure
- **WHEN** the handler clicks "Escaleren naar zaak" and selects a zaaktype
- **THEN** a new `case` object MUST be created with the selected caseType
- **AND** `complaint.geescaleerdeZaak` MUST be set to the new case UUID
- **AND** the case MUST carry a relation back to the originating complaint UUID
- **AND** the complaint MUST remain independently trackable (not closed by escalation)

#### Scenario: Escalated case is shown on the complaint detail

- **GIVEN** complaint `KL-2026-0042` has `geescaleerdeZaak` set
- **WHEN** the handler views the complaint detail
- **THEN** a "Gerelateerde zaak" `CnDetailCard` section MUST show the linked case number, status, and a deep-link to the case detail

#### Scenario: Multiple complaints point to the same case

- **GIVEN** 3 complaints reference the same case UUID via `geescaleerdeZaak`
- **WHEN** viewing the case detail
- **THEN** a "Gerelateerde klachten" section MUST list all 3 complaints with their klachtnummers and statuses

---

### REQ-CM-005: The system SHALL record a formal disposition (oordeel) when closing a complaint

A `complaintDisposition` schema MUST be declared in `procest_register.json`. `DispositionService` handles submission and an optional coordinator approval gate (tenant-configurable). On closing, `DispositionService` calls Docudesk to render the response letter using the tenant's `afsluitbrief` template.

**Schema.org annotation:** `schema:AssessAction`

Fields: `complaint`, `oordeel`, `toelichting`, `maatregelen`, `afsluitdatum`, `afsluitbrief`, `goedgekeurdDoor`. Full schema in `design.md`.

#### Scenario: Close a complaint with disposition `gegrond`

- **GIVEN** complaint `KL-2026-0042` has status `hoorgesprek_afgerond`
- **WHEN** the handler submits a disposition with `oordeel: "gegrond"`, `toelichting`, and `maatregelen`
- **THEN** a `complaintDisposition` object MUST be created linked to the complaint
- **AND** the complaint status MUST change to `afgehandeld`

#### Scenario: Coordinator approval gate delays closure

- **GIVEN** the tenant has `klachten_goedkeuring_vereist: true` in settings
- **WHEN** the handler submits any disposition
- **THEN** the complaint status MUST change to `wacht_op_goedkeuring`
- **AND** the `klachten-coordinator` group MUST receive a task to approve or reject
- **AND** on approval the status transitions to `afgehandeld`

#### Scenario: Generate the formal response letter

- **GIVEN** disposition with `oordeel: "deels_gegrond"` is submitted for complaint `KL-2026-0042`
- **WHEN** the handler clicks "Afsluitbrief genereren"
- **THEN** `DispositionService` MUST call Docudesk with the `afsluitbrief` template
- **AND** the resulting document UUID MUST be stored in `complaintDisposition.afsluitbrief`
- **AND** the document MUST be linked to the complaint in OpenRegister's files

---

### REQ-CM-006: The system SHALL detect frequency patterns and alert on systemic issues

`ComplaintAnalyticsService` MUST provide frequency aggregations by category, department, and intake channel. Employee-threshold alerts MUST fire when the same `betrokkenMedewerker` appears in ≥3 complaints within 6 months. Systemic-issue detection fires when a category shows >50% QoQ increase.

#### Scenario: Frequency dashboard shows complaint distribution

- **GIVEN** the manager opens the `ComplaintAnalyticsDashboard.vue`
- **WHEN** the dashboard loads
- **THEN** bar charts MUST show complaint counts by category, by department, and by intake channel for the selected period
- **AND** a trend line chart MUST show monthly complaint totals
- **AND** KPI cards MUST show: total complaints, average resolution time, Awb compliance rate, and gegrond rate

#### Scenario: Employee threshold alert fires and is anonymized

- **GIVEN** 3 complaints in the last 6 months reference the same `betrokkenMedewerker` UID
- **WHEN** `ComplaintAnalyticsService` evaluates the threshold
- **THEN** a Nextcloud notification MUST be sent to the `hr-coordinator` role with: complaint count, categories, and time period — but NOT the employee's name or UID in the notification text
- **AND** the alert MUST NOT be visible to regular complaint handlers

#### Scenario: Systemic issue detection creates a systeemmelding

- **GIVEN** category "Wachttijd" has 35 complaints in Q2 2026 versus 17 in Q1 2026 (>50% increase)
- **WHEN** the quarterly analysis runs in `ComplaintAnalyticsService`
- **THEN** a "Systeemmelding" banner MUST appear on the analytics dashboard with: affected category, Q1 vs Q2 counts, trend direction, and a link to the filtered complaint list

---

### REQ-CM-007: Complaint categories SHALL be configurable per tenant

A `complaintCategory` schema MUST be declared in `procest_register.json`. The tenant-admin UI MUST provide CRUD access under `Settings > Klachtcategorieen`. On complaint creation, if the selected category has a `defaultHandler`, the complaint MUST be automatically routed to that user or group.

**Schema.org annotation:** `schema:DefinedTerm`

Fields: `name`, `description`, `defaultHandler`, `slaOverride`, `actief`. Full schema in `design.md`.

#### Scenario: Create a custom complaint category

- **GIVEN** a tenant admin opens `Settings > Klachtcategorieen`
- **WHEN** they create a category "Bereikbaarheid" with `defaultHandler: "kcc-klachten"` and `slaOverride: 20`
- **THEN** the `complaintCategory` object MUST be created via the OR API
- **AND** "Bereikbaarheid" MUST appear in the complaint intake form's category dropdown

#### Scenario: Category routing assigns the default handler

- **GIVEN** category "Bejegening" has `defaultHandler: "hr-klachten"`
- **WHEN** a new complaint is created with `categorie` pointing to "Bejegening"
- **THEN** `complaint.behandelaar` MUST be set to `"hr-klachten"` automatically

#### Scenario: Deactivating a category preserves historical data

- **GIVEN** category "Legacy categorie" has 15 historical complaints
- **WHEN** the admin sets `actief: false`
- **THEN** new complaints MUST NOT be able to select this category
- **AND** existing complaints retain their `categorie` value unchanged
- **AND** the category still appears in historical analytics reports

---

### REQ-CM-008: Complaint views SHALL integrate with the Procest navigation and dashboard

`ComplaintList.vue` using `CnIndexPage` + `useListView` MUST be accessible via the "Klachten" sidebar entry. `ComplaintDetail.vue` using `CnDetailPage` MUST provide full complaint management. `ComplaintDashboardWidget.vue` MUST show on the Procest dashboard for handlers. `ComplaintAnalyticsDashboard.vue` MUST be accessible to coordinators and managers.

#### Scenario: Complaint list shows handler inbox sorted by urgency

- **GIVEN** a handler navigates to "Klachten" in the sidebar
- **WHEN** the list loads
- **THEN** complaints assigned to them MUST be shown with columns: klachtnummer, onderwerp, categorie, status, ontvangstdatum, afhandelDeadline, days-remaining
- **AND** overdue complaints MUST be sorted to the top and marked with a red `CnStatusBadge`
- **AND** filters MUST support: status, categorie, behandelaar, date range, prioriteit

#### Scenario: Complaint detail enables all management actions

- **GIVEN** handler opens complaint `KL-2026-0042` from the list
- **WHEN** the detail view loads
- **THEN** all tabs MUST be present: Klacht, Deadlines (DeadlinePanel.vue), Hoorgesprek, Afsluiting, Escalatie, Communicatie, Bijlagen
- **AND** status change, hearing scheduling, disposition submission, and escalation MUST all be operable from this view

#### Scenario: Dashboard widget shows the handler's complaint summary

- **GIVEN** a handler views the Procest dashboard
- **WHEN** the "Mijn klachten" widget loads
- **THEN** it MUST show: open complaints count, overdue count, and the next 5 deadlines (working-day-sorted)
- **AND** clicking the widget MUST navigate to the complaint list filtered to the handler's open items

---

### REQ-CM-009: All complainant communication SHALL be tracked in the complaint record

The n8n attachment-matcher workflow MUST link inbound emails whose subject line contains a known `klachtnummer` back to the complaint. Phone calls recorded by the handler MUST be stored as activity entries. The acknowledgment letter generated via Docudesk MUST be linked as a document on the complaint.

#### Scenario: Send and record the acknowledgment letter

- **GIVEN** complaint `KL-2026-0042` has status `ontvangen`
- **WHEN** the handler clicks "Ontvangstbevestiging verzenden"
- **THEN** `DispositionService` (or `ComplaintService`) MUST call Docudesk with the `ontvangstbevestiging` template
- **AND** the letter MUST be sent via the configured channel (email or print queue)
- **AND** the complaint status MUST change to `ontvangst_bevestigd`
- **AND** the document MUST be linked to the complaint via OpenRegister's files relation

#### Scenario: Inbound attachment is matched to the correct complaint

- **GIVEN** a follow-up email arrives at klachten@gemeente.nl with subject "Re: KL-2026-0042 — aanvullende informatie"
- **WHEN** the n8n attachment-matcher workflow processes it
- **THEN** the attachments MUST be linked to complaint `KL-2026-0042`
- **AND** the `behandelaar` MUST receive a notification: "Nieuwe bijlage ontvangen voor KL-2026-0042"

#### Scenario: Phone call is logged as a communication record

- **GIVEN** the handler makes a phone call to the complainant
- **WHEN** they record the call via the complaint detail's Communicatie tab
- **THEN** a communication entry MUST be created with: date, duration, summary, and follow-up actions
- **AND** the entry MUST appear in the activity timeline

---

### REQ-CM-010: All complaint UI strings SHALL be available in Dutch and English

All user-visible strings in complaint Vue components, notification templates, and n8n workflow webhook bodies MUST use `t(appName, 'key')` for the English source and have Dutch translations in `l10n/nl.json` per ADR-007.

#### Scenario: Complaint list renders in Dutch for a nl-NL user

- **GIVEN** a user has locale set to `nl_NL`
- **WHEN** they open the complaint list
- **THEN** all column headers, button labels, status badges, and filter options MUST be in Dutch

#### Scenario: No hardcoded strings in Vue templates

- **GIVEN** the complaint Vue components
- **WHEN** scanned for string literals not wrapped in `t()`
- **THEN** no user-visible hardcoded strings SHALL be found in `<template>` or JS logic

---

## Non-Requirements

- This spec does NOT cover bezwaarschriften (formal objections to decisions).
- This spec does NOT cover ombudsman case management or external oversight reporting.
- This spec does NOT cover automated complaint classification via AI/NLP.
- This spec does NOT cover a citizen-facing complaint submission portal.
- This spec does NOT cover Belgian or non-Dutch complaint variants.

## Dependencies

| Dependency | Purpose |
|---|---|
| OpenRegister | `complaint`, `hearing`, `complaintDisposition`, `complaintCategory` object storage |
| `case` / `caseType` infrastructure | Escalation target, status infrastructure reuse |
| `DeadlinePanel.vue` | Awb deadline visualization in ComplaintDetail |
| `ActivityTimeline.vue` | Communication trail in ComplaintDetail |
| n8n | email-intake, deadline-monitor, attachment-matcher workflows |
| Docudesk | Acknowledgment letter + response letter generation |
| `OCP\Calendar\IManager` | Hearing calendar invitations |
| `OCP\Talk\IBroker` | Video hearing Talk room creation |
