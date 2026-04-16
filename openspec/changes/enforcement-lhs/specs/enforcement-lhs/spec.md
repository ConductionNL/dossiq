---
status: proposed
---
# enforcement-lhs Specification

## Purpose

Implement Landelijke Handhavingsstrategie (LHS) matrix support in Procest's VTH (Vergunning, Toezicht, Handhaving) case flows. The system guides inspectors through the two-axis LHS classification (ernst × gedrag), automatically suggests the appropriate intervention, enforces documentation of any deviation, and provides management tooling for search, overview, export, and deadline notifications on all enforcement actions.

## Context

Dutch municipalities conducting toezicht en handhaving are legally required to apply the Landelijke Handhavingsstrategie (VNG/Ministerie van JenV, 2022 revision). The strategy defines a 4×4 matrix mapping violation severity (ernst) and violator behaviour (gedrag) to a standardised intervention category. Without system support, inspectors look up the matrix manually, record the outcome in free text, and there is no automated check that deviations are explained. This change implements the `handhavingsactie` entity already defined in the Procest data model (ADR-000) and builds the services, API, and UI needed to use it.

## Stakeholders

| Role | Responsibility |
|------|---------------|
| Toezichthouder (inspector) | Creates enforcement actions; classifies ernst and gedrag in the field |
| Juridisch medewerker | Reviews enforcement dossiers; authors dwangsombeschikkingen; tracks deadlines |
| Teamleider handhaving | Monitors team workload; reviews LHS deviations; approves escalations |
| Bevoegd gezag (bestuur) | Final authority on enforcement policy; reviews override audit trail |

## Requirements

---

### REQ-ENF-001: Enforcement Action Creation with LHS Matrix

Inspectors MUST be able to create a `handhavingsactie` linked to a case by selecting ernst and gedrag; the system MUST suggest the LHS-appropriate intervention and pre-fill it.

#### Scenario 1.1: Create enforcement action from case detail
- GIVEN case `zaak-BT-2026-0041` is a toezichtzaak (VTH handhaving case type)
- AND the toezichthouder has completed an inspectieRapport showing a violation
- WHEN the toezichthouder clicks "Actie toevoegen" in the `HandhavingsactieSection`
- THEN `LhsMatrixDialog` MUST open showing the ernst selector (gering / matig / ernstig / zeer ernstig) and the gedrag selector (goedwillend / onachtzaam / calculerend / opzettelijk)
- AND selecting ernst `matig` and gedrag `calculerend` MUST display: "Aanbevolen interventie: **Last onder dwangsom**"
- AND the suggestion MUST update immediately when ernst or gedrag changes (client-side lookup, no API call)

#### Scenario 1.2: Complete enforcement action form
- GIVEN the inspector has selected ernst `matig` and gedrag `calculerend` in `LhsMatrixDialog`
- AND the suggested intervention is `last_onder_dwangsom`
- WHEN the inspector proceeds to step 2 and fills in: begunstigingstermijn `28` days, dwangsomBedrag `€ 2.500`, dwangsomMaximaal `€ 25.000`, effectueringsDatum `2026-05-15`
- AND clicks "Opslaan"
- THEN a `handhavingsactie` object MUST be created in OpenRegister with all submitted fields
- AND `interventie` MUST be set to `last_onder_dwangsom`
- AND `overrideReason` MUST be null
- AND the new action MUST appear in the `HandhavingsactieSection` with status badge "actief"

#### Scenario 1.3: Multiple enforcement actions per case
- GIVEN case `zaak-BT-2026-0041` already has one active enforcement action
- WHEN the inspector adds a second action for a different violation on the same case
- THEN both enforcement actions MUST be listed in the `HandhavingsactieSection`
- AND each action MUST have its own independent lifecycle and deadline tracking

#### Scenario 1.4: Enforcement action linked to inspection report
- GIVEN inspectieRapport `rapport-2026-0041-1` is linked to case `zaak-BT-2026-0041`
- WHEN the inspector creates a handhavingsactie from the case detail
- THEN the `HandhavingsactieFormDialog` MUST offer an optional "Gekoppeld aan inspectieRapport" field that the inspector can populate with the rapport reference
- AND the field MUST appear in the handhavingsactie detail view

---

### REQ-ENF-002: LHS Intervention Suggestion and Override Documentation

When an inspector overrides the LHS-suggested intervention, the system MUST require a documented reason and MUST record the override in the audit trail.

#### Scenario 2.1: Accept LHS suggestion (no override)
- GIVEN the LHS matrix suggests `last_onder_dwangsom` for ernst `matig` + gedrag `calculerend`
- WHEN the inspector submits the form without changing the `interventie` field
- THEN the `handhavingsactie` MUST be saved with `overrideReason` null
- AND the OpenRegister audit trail MUST record the creation with actor and timestamp

#### Scenario 2.2: Override LHS suggestion — reason required
- GIVEN the LHS matrix suggests `last_onder_dwangsom` for ernst `matig` + gedrag `calculerend`
- WHEN the inspector changes `interventie` to `waarschuwing` (a lesser intervention)
- THEN the form MUST reveal a mandatory `overrideReason` textarea with placeholder "Motiveer de afwijking van de LHS-aanbeveling"
- AND the "Opslaan" button MUST be disabled until `overrideReason` has at least 20 characters
- AND upon save, `overrideReason` MUST be stored on the object

#### Scenario 2.3: Override rejected by service layer
- GIVEN the inspector submits `interventie: waarschuwing` with `overrideReason: null` via the API directly
- WHEN `HandhavingsactieController::create()` receives the request
- THEN `HandhavingsactieService` MUST detect the deviation and return HTTP 422 with message: "overrideReason is verplicht wanneer de interventie afwijkt van de LHS-aanbeveling"
- AND NO object MUST be created in OpenRegister

#### Scenario 2.4: Suggest endpoint — stateless preview
- GIVEN a GET or POST to `/api/handhavingsacties/suggest` with body `{"ernst": "ernstig", "gedrag": "goedwillend"}`
- WHEN the request is processed
- THEN the response MUST be: `{"interventie": "last_onder_dwangsom"}`
- AND no `handhavingsactie` object MUST be created or modified
- AND the endpoint MUST respond within 100ms (pure in-memory matrix lookup)

#### Scenario 2.5: Override visible in management view
- GIVEN a handhavingsactie with a non-null `overrideReason`
- WHEN a teamleider views it in `HandhavingView.vue`
- THEN a "⚠ LHS-afwijking" badge MUST be displayed on the row
- AND clicking the badge MUST expand the `overrideReason` text inline

---

### REQ-ENF-003: Enforcement Overview View

Teamleiders and juridisch medewerkers MUST be able to see all enforcement actions across all cases in a filterable, sortable list.

#### Scenario 3.1: View enforcement overview
- GIVEN the user navigates to `/handhaving`
- THEN `HandhavingView.vue` MUST load and display all `handhavingsactie` objects the user has access to
- AND the list MUST show columns: Zaaknummer, Overtreder, Type, Ernst, Gedrag, Interventie, Status, Effectueringsdatum, LHS-afwijking indicator
- AND the list MUST be paginated (default 25 rows)

#### Scenario 3.2: Filter by ernst
- GIVEN the enforcement overview is displayed
- WHEN the user selects "Ernstig" in the ernst filter in `CnFilterBar`
- THEN the list MUST refresh to show only handhavingsacties with `ernst: ernstig`
- AND the filter chip MUST show "Ernst: Ernstig" above the table

#### Scenario 3.3: Filter by status
- GIVEN the enforcement overview is displayed
- WHEN the user selects status "actief" in the filter
- THEN only active enforcement actions MUST be shown
- AND the result count MUST update in the `CnActionsBar`

#### Scenario 3.4: Filter by deadline (this week)
- GIVEN today is 2026-04-16
- WHEN the user selects "Effectuering deze week" in the deadline filter
- THEN only handhavingsacties with `effectueringsDatum` between 2026-04-16 and 2026-04-23 MUST be shown
- AND the list MUST be sorted ascending by `effectueringsDatum` by default

#### Scenario 3.5: Sort by column
- GIVEN the enforcement overview is displayed
- WHEN the user clicks the "Effectueringsdatum" column header
- THEN the list MUST sort ascending by effectueringsDatum
- AND clicking again MUST sort descending

---

### REQ-ENF-004: Search within Enforcement Requests

Users MUST be able to search across all enforcement action fields using free-text search.

#### Scenario 4.1: Search by case identifier
- GIVEN enforcement actions exist for case "BT-2026-0041"
- WHEN the user types "BT-2026-0041" in the search box in `HandhavingView.vue`
- THEN the list MUST filter to show only enforcement actions linked to that case
- AND the search MUST execute within 500ms

#### Scenario 4.2: Search by override reason text
- GIVEN a handhavingsactie has overrideReason "Proportionaliteitsbeoordeling leidt tot lichtere maatregel"
- WHEN the user searches for "proportionaliteit"
- THEN that enforcement action MUST appear in the results
- AND the matching term MUST be highlighted in the result (if `CnDataTable` supports highlighting)

#### Scenario 4.3: Search by intervention type
- GIVEN multiple enforcement actions with different interventions exist
- WHEN the user searches for "dwangsom"
- THEN only actions with interventie containing "dwangsom" (i.e., `last_onder_dwangsom`) MUST be returned
- AND the result count MUST update

#### Scenario 4.4: Empty search returns all results
- GIVEN the user has entered a search term and then clears the search box
- WHEN the search box is empty
- THEN the full unfiltered list MUST be restored

---

### REQ-ENF-005: Export Enforcement Request Data

Users MUST be able to export enforcement actions to CSV or Excel for reporting and external oversight.

#### Scenario 5.1: Export filtered results to CSV
- GIVEN the user has filtered the enforcement overview to status "actief" (42 results)
- WHEN the user clicks "Exporteren" and selects "CSV"
- THEN a CSV file MUST be downloaded containing the 42 filtered enforcement actions
- AND the file MUST include columns: Zaaknummer, Type, Ernst, Gedrag, Interventie, Status, BegunstigingstermijnDagen, DwangsomBedrag, DwangsomMaximaal, EffueringsDatum, OverrideReason
- AND the export MUST be handled by `CnMassExportDialog` + `ExportService` (no custom controller)

#### Scenario 5.2: Export all enforcement actions to Excel
- GIVEN no filter is applied
- WHEN the user clicks "Exporteren" and selects "Excel"
- THEN an `.xlsx` file MUST be downloaded with all accessible enforcement actions
- AND column headers MUST be in Dutch

#### Scenario 5.3: Export respects RBAC
- GIVEN user `jansen` does not have access to enforcement actions in organisation B
- WHEN `jansen` exports
- THEN the export MUST contain ONLY enforcement actions `jansen` has access to
- AND no 403 error MUST occur — the export simply omits inaccessible records

---

### REQ-ENF-006: Notifications for Enforcement Deadlines

The system MUST automatically notify responsible inspectors and case handlers when an enforcement action's begunstigingstermijn is about to expire or has expired.

#### Scenario 6.1: Deadline warning notification
- GIVEN handhavingsactie `lod-2026-0041-1` has `effectueringsDatum: 2026-04-23` and status `actief`
- AND today is 2026-04-16 (7 days before effectuering)
- AND `enforcement_deadline_warning_days` is configured as 7
- WHEN `HandhavingsactieDeadlineJob` runs at midnight
- THEN a Nextcloud notification MUST be sent to the case's `assignee` with message: "Handhavingsactie voor zaak BT-2026-0041 wordt effectief op 23 april 2026. Controleer de status van de overtreding."
- AND the notification MUST link to the case detail page

#### Scenario 6.2: Notification not re-sent daily
- GIVEN the deadline notification for `lod-2026-0041-1` was already sent on 2026-04-16
- WHEN the job runs on 2026-04-17
- THEN NO duplicate notification MUST be sent
- AND the handhavingsactie object MUST have a `notificationSentAt` field set to prevent re-delivery

#### Scenario 6.3: No notification for closed actions
- GIVEN handhavingsactie `lod-2026-0007-3` has status `afgerond` and an effectueringsDatum in the past
- WHEN `HandhavingsactieDeadlineJob` runs
- THEN NO notification MUST be sent for this action

#### Scenario 6.4: Warning window configurable
- GIVEN the admin sets `enforcement_deadline_warning_days` to 14 in Procest settings
- WHEN the job runs
- THEN notifications MUST be sent for enforcement actions with effectueringsDatum within 14 days
- AND actions more than 14 days away MUST be skipped

---

### REQ-ENF-007: Workflow-Embedded Compliance Checks

Case workflows MUST support transition guards that require an active `handhavingsactie` before a case can advance past the toezicht phase.

#### Scenario 7.1: Workflow guard blocks advancement without enforcement action
- GIVEN case `zaak-BT-2026-0041` is at status "Toezicht afgerond"
- AND the `workflowTemplate` transition from "Toezicht afgerond" to "Handhaving" has a guard of type `requiredObject: handhavingsactie`
- AND no `handhavingsactie` is linked to the case
- WHEN the case worker attempts to advance the case status
- THEN the workflow engine MUST block the transition
- AND display: "Er moet minimaal één handhavingsactie aanwezig zijn voordat de zaak kan worden doorgezet naar Handhaving."

#### Scenario 7.2: Workflow guard passes when enforcement action exists
- GIVEN case `zaak-BT-2026-0041` has a linked `handhavingsactie` with status `actief`
- WHEN the case worker advances the case status to "Handhaving"
- THEN the transition MUST succeed
- AND the case status MUST update to "Handhaving"

#### Scenario 7.3: Policy check — override audit visible in workflow log
- GIVEN case `zaak-BT-2026-0041` has a handhavingsactie with a non-null `overrideReason`
- WHEN the teamleider reviews the case workflow log
- THEN a workflow activity entry MUST appear: "LHS-afwijking gedocumenteerd: [overrideReason tekst]"
- AND the entry MUST include the actor (toezichthouder) and timestamp

#### Scenario 7.4: Configuring enforcement guard in workflow template
- GIVEN the admin is editing a workflow template in the workflow editor
- WHEN they add a transition guard
- THEN "Vereist handhavingsactie" MUST be available as a guard type option
- AND the admin MUST be able to specify minimum required count (default 1)

---

### REQ-ENF-008: Automated Policy Enforcement Audit

The system MUST automatically flag and audit every deviation from the LHS-recommended intervention, enabling the bevoegd gezag to review compliance across all enforcement actions.

#### Scenario 8.1: Override audit trail entry
- GIVEN a toezichthouder creates a handhavingsactie with interventie `waarschuwing` where the LHS suggests `last_onder_dwangsom`
- WHEN the object is saved
- THEN the OpenRegister audit trail for that object MUST contain an entry with:
  - field: `interventie`
  - suggestedValue: `last_onder_dwangsom`
  - actualValue: `waarschuwing`
  - overrideReason: [the documented reason]
  - actor: [toezichthouder user ID]
  - timestamp: [ISO 8601 creation time]

#### Scenario 8.2: LHS compliance overview for management
- GIVEN the teamleider navigates to the enforcement overview
- AND applies the filter "LHS-afwijkingen"
- THEN the list MUST show ONLY handhavingsacties where the stored `interventie` differs from `LhsMatrixService::suggest(ernst, gedrag)`
- AND the total deviation count MUST be displayed in the filter chip

#### Scenario 8.3: Bulk export of overrides for external audit
- GIVEN the teamleider needs to submit an LHS compliance report to the province
- WHEN the teamleider exports the "LHS-afwijkingen" filtered list to CSV
- THEN the export MUST include columns: Zaaknummer, Datum, Toezichthouder, Ernst, Gedrag, LHS-aanbeveling, GekozenInterventie, Motivatie
- AND the CSV MUST comply with the column format of the standard VTH-rapportageformat

## Dependencies

- OpenRegister (`ObjectService`, `IndexService`, `ExportService`) — all handhavingsactie persistence and search
- `workflowTemplate` workflow engine — guard integration for REQ-ENF-007
- `inspectieRapport` entity — optional linkage from inspection report to enforcement action
- `case` entity — handhavingsactie.case reference (mandatory)
- `OCP\Notification\IManager` — deadline notifications (REQ-ENF-006)
- `@conduction/nextcloud-vue` — `CnFilterBar`, `CnDataTable`, `CnMassExportDialog`, `CnFormDialog` (no custom UI primitives)

---

### Current Implementation Status

**Not yet implemented.** The `handhavingsactie` schema is defined in ADR-000 and included in the Procest data model, but no service, controller, background job, or frontend component for LHS-based enforcement action management exists. No `suggest` endpoint exists. No deadline notification job exists.

**Foundation available:**
- `handhavingsactie` schema in `procest_register.json` (entity defined in ADR-000)
- OpenRegister `ObjectService` for CRUD
- `inspectieRapport` and `inspectieChecklist` schemas provide the upstream inspection data
- `workflowTemplate` engine provides guard extension points
- `CnFilterBar`, `CnDataTable`, `CnMassExportDialog` from `@conduction/nextcloud-vue` provide list/export UI

**Partial implementations:** None.

### Standards and References

- **Landelijke Handhavingsstrategie (LHS 2022)**: Ministerie van Justitie en Veiligheid / VNG. Published matrix defining intervention categories by ernst × gedrag.
- **VTH-beleidscyclus (IPPC / Wabo / Omgevingswet)**: Legal framework requiring municipalities to document and justify enforcement interventions.
- **Omgevingswet art. 18.1+**: Enforcement obligation and documentation requirements for VTH authorities.
- **Awb afd. 5.3**: Bestuursdwang and afd. 5.4: Dwangsom framework for enforcement instruments.
- **GEMMA VTH referentiearchitectuur**: VNG reference for Vergunning, Toezicht, and Handhaving processes in Dutch municipalities.
- **VTH-rapportageformat**: Province reporting format for LHS compliance (used in Scenario 8.3).
