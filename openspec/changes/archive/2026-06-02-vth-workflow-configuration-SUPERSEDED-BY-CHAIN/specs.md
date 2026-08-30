# Specs: vth-workflow-configuration

## Overview

Detailed requirements for VTH workflow configuration, covering zaaktype templates, process workflows, leges calculation, mobile inspection, DSO integration, and LHSO classification.

---

## REQ-VTH-001: Omgevingsvergunning Case Type and Workflow

**Purpose**: Define the Omgevingsvergunning (environmental permit) case type with complete workflow from intake to decision publication.

### REQ-VTH-001-A: Case Type Definition
GIVEN an administrator accesses the case type configuration
WHEN they load the Omgevingsvergunning template
THEN the case type is created with properties:
- `title` = "Omgevingsvergunning"
- `processingDeadline` = "P30D" (reguliere procedure) or "P180D" (uitgebreide)
- `suspensionAllowed` = true
- `extensionAllowed` = true (max 1 extension per OW rules)
- `publicationRequired` = true
- Status types: Aanvraag ontvangen, In behandeling, Advies aangevraagd, Beschikking opgesteld, Verzonden, Verleend, Geweigerd, Ingetrokken, Afgehandeld
- Roles: Vergunningverlener, Juridisch adviseur, Ondersteunend medewerker
- Document types: Aanvraagformulier, Tekeningen, Milieueffectrapport, Beschikking, Wijzigingsbesluit

### REQ-VTH-001-B: Workflow Status Transition Rules
GIVEN a case in status "Aanvraag ontvangen"
WHEN the case is moved to "In behandeling"
THEN:
- The system records the transition timestamp
- A task "Beoordelen aanvraag" is automatically created and assigned to Vergunningverlener
- Applicant receives notification of receipt

GIVEN a case in "In behandeling"
WHEN the Vergunningverlener completes initial assessment
AND "Advies extern" is required
THEN transition to "Advies aangevraagd" is blocked until:
- At least one external advice request is created
- The deadline for each advice request is set

GIVEN a case with external advice received
WHEN all required advice is marked "ontvangen"
THEN transition to "Beschikking opgesteld" is available to Vergunningverlener

GIVEN a case in "Beschikking opgesteld"
WHEN transition to "Verzonden" is initiated
THEN the system validates:
- Beschikking document is attached (from `bijlagen`)
- Decision is digitally signed by authorized role
- If blocking errors exist, transition is rejected with specific error message

### REQ-VTH-001-C: Processing Deadline Calculation
GIVEN a case created with `startDate` = 2026-03-15
WHEN the case type has `processingDeadline` = "P30D" (reguliere procedure)
THEN:
- `deadline` = 2026-04-14 (30 calendar days, excluding weekends and Dutch national holidays)
- System warns at 6 weeks remaining (not applicable for 30-day deadline; warning shows "approaching deadline")
- System warns at 2 weeks remaining
- If `endDate` is not set after deadline, case is flagged "Overdue"

GIVEN an extension is granted
WHEN extension period = "P14D"
THEN:
- New deadline = prior deadline + 14 days
- `extensionCount` increments
- Applicant is notified of extension grant

### REQ-VTH-001-D: Advies Management Integration
GIVEN a case requires external advice on "Milieueffecten"
WHEN an advice request is created
THEN:
- `adviesAanvraag` object is created with:
  - `case` reference to this zaak
  - `adviseur` = selected organization (e.g., "Milieudienst Noord-Holland")
  - `deadline` = case deadline - 7 days
  - `status` = "open"
  - `onderwerp` = "Milieueffecten beoordeling"
- External advisor receives notification and secure response link
- Timeline entry appears on case

GIVEN the advice deadline passes
WHEN the nightly job runs
THEN:
- `adviesAanvraag.status` is updated to "overdue"
- Case handler receives notification "Advies van Milieudienst Noord-Holland is verlopen"
- Case status remains "Advies aangevraagd" but is flagged with warning

GIVEN external advisor submits advice via secure link
WHEN advice is received
THEN:
- `advicesRequest.status` = "received"
- Advice document is attached to `adviesAanvraag`
- Case handler receives notification "Advies ontvangen van Milieudienst Noord-Holland"
- Case is eligible to transition to "Beschikking opgesteld"

---

## REQ-VTH-002: Toezichtzaak (Inspection) Case Type and Mobile Workflow

**Purpose**: Define the inspection case type with mobile-optimized workflow for field inspectors.

### REQ-VTH-002-A: Case Type Definition
GIVEN an administrator loads the Toezichtzaak template
WHEN the case type is created
THEN:
- `title` = "Toezichtzaak"
- Status types: Geplande inspectie, In voorbereiding, Inspectie in voortgang, Rapport opstellen, Afgerond
- Roles: Inspector, Coördinator toezicht, Rapporteur
- Property: `inspectionType` (enum: "Bouwtoezicht", "Milieutoezicht", "Veiligheid", "Arbeid")
- Property: `location` (geolocation reference to BAG object)
- Property: `checklist` (reference to inspectionChecklist)

### REQ-VTH-002-B: Mobile Inspection Workflow
GIVEN an inspector opens a Toezichtzaak on a mobile device
WHEN the case status is "Geplande inspectie" or "Inspectie in voortgang"
THEN the mobile view displays:
- Checklist items in responsive, single-column layout
- Each item: question, type-specific input (checkbox, text, photo button, GPS button)
- Progress bar showing "3/12 items completed"
- Navigation: "Previous", "Next" buttons and "Submit" when all required items complete
- Offline mode: Can answer items offline; sync when connectivity returns

GIVEN inspector completes checklist items
WHEN inspector captures photo
THEN:
- Photo is uploaded to Nextcloud (compressed for mobile)
- Nextcloud file ID is recorded in the item's `photos` array
- GPS coordinates are automatically captured with timestamp
- If GPS is unavailable, inspector can manually enter coordinates

GIVEN inspector finishes all required checklist items
WHEN inspector clicks "Submit"
THEN:
- System validates all required items are answered and required photos are attached
- Validation error if any required photo is missing (e.g., "Question 5 requires photo evidence")
- On success: `inspectionResult` object is created with status "completed"
- Case transitions to "Rapport opstellen"
- Inspector receives confirmation message

### REQ-VTH-002-C: Checklist Template Configuration
GIVEN an administrator creates a checklist for "Bouwtoezicht fase fundering"
WHEN the checklist is saved
THEN the template is stored as `inspectionChecklist` with:
- `name` = "Bouwtoezicht fase fundering"
- `caseType` reference to Toezichtzaak
- `items` array with objects: `{question, type, required, helpText, photos, gpsCoordinates}`
- `version` = 1
- `status` = "active"
- `validFrom` = current date

GIVEN a checklist is updated (new questions added)
WHEN the template is saved
THEN:
- `version` increments to 2
- Prior completed inspections remain linked to version 1
- New inspections use version 2
- Checklist is idempotent: re-importing same template does not create duplicates

### REQ-VTH-002-D: Inspection Report Generation
GIVEN an inspector completes a checklist with 11/12 items
WHEN inspection is submitted
THEN `inspectionResult` is created with:
- `case` reference to the toezichtzaak
- `checklist` reference to the template
- `inspector` = user ID of inspector
- `inspectionDate` = submission timestamp
- `location` = {lat, lng, accuracy} from last GPS capture or manual entry
- `result` = auto-calculated from answers (e.g., "Compliant", "Non-compliant", "Partial")
- `failedItems` = count of "non-compliant" answers
- `items` array with completed item details
- `photos` array with all Nextcloud file IDs uploaded during inspection
- `followUpRequired` = true if any item answered "non-compliant"

GIVEN the inspection has `followUpRequired` = true
WHEN the case handler reviews the report
THEN:
- Case is eligible to transition to "Afgerond" with follow-up note
- A new sub-case or task can be created for follow-up inspection

---

## REQ-VTH-003: Handhavingszaak (Enforcement) Case Type with LHSO Classification

**Purpose**: Define enforcement case type with LHSO-based workflow suggestions.

### REQ-VTH-003-A: Case Type Definition
GIVEN an administrator loads the Handhavingszaak template
WHEN the case type is created
THEN:
- `title` = "Handhavingszaak"
- Status types: Onderzoek, Geclassificeerd, Waarschuwing verstuurd, Aanzegging verstuurd, Dwangsom opgelegd, Afgerond, Ingetrokken
- Roles: Handhaver, Manager handhaving, Juridisch adviseur
- Properties: `violation` (text), `gedrag` (LHSO gedrag axis), `gevolgen` (LHSO gevolgen axis), `lhsoSuggestion` (interventie step), `interventieActual` (chosen intervention), `overrideReason` (if different from suggestion)

### REQ-VTH-003-B: LHSO Classification and Intervention Lookup
GIVEN a case handler is in status "Onderzoek"
WHEN they classify the violation as:
- `gedrag` = "C" (Serious non-compliance; knowledge of regulation assumed)
- `gevolgen` = "3" (Substantial environmental/health damage; recovery difficult)
THEN:
- System queries `GET /api/vth/lhso/lookup?gedrag=C&gevolgen=3`
- Returns `lhsoMatrixCell` with:
  - `interventieStep` = "Aanzegging dwanggeld (boete ≥ €2500)"
  - `description` = "Formal written notice with penalty amount; must be complied within 30 days"
- Case transitions to "Geclassificeerd"
- Case handler sees suggestion in UI: "Based on LHSO classification, suggested intervention: Aanzegging dwanggeld"

### REQ-VTH-003-C: Intervention Execution and Override
GIVEN a classification with LHSO suggestion "Aanzegging dwanggeld"
WHEN case handler chooses to execute "Bestuurlijke waarschuwing" (different from suggestion)
THEN:
- `interventieActual` = "Bestuurlijke waarschuwing"
- `overrideReason` field becomes required
- Handler enters: "Overridedreason" = "Violation is first offense; lenient approach negotiated with facility operator"
- On save: audit trail records the suggestion and override reason
- Case transitions to "Waarschuwing verstuurd"
- Audit log entry shows: "User [name] overrode LHSO suggestion [suggestion] with [actual]; Reason: [override reason]"

### REQ-VTH-003-D: Dwangsom (Penalty) Tracking
GIVEN an enforcement case reaches status "Dwangsom opgelegd"
WHEN the dwangsom is recorded
THEN:
- `handhavingsactie` object is created with:
  - `type` = "Dwangsom"
  - `dwangsomBedrag` = amount per violation (e.g., €500)
  - `dwangsomMaximaal` = maximum total (e.g., €5000)
  - `effectueringsDatum` = date when penalty is due (e.g., 30 days from notice)
  - `status` = "opgelegd"
- Case handler creates task "Controleer betaling dwangsomheffing"
- On payment, `status` updates to "betaald" and task is marked complete
- If not paid by `effectueringsDatum`, case is flagged "Dwangsom oninbaar"

---

## REQ-VTH-004: Leges Calculation Engine

**Purpose**: Automated fee calculation based on zaaktype, activity, and case properties.

### REQ-VTH-004-A: Fee Calculation Request
GIVEN a case is created with zaaktype = "Omgevingsvergunning", activiteit = "Verbouwing kantoor", location = "Amsterdam", size = "250 m²"
WHEN the case handler requests fee calculation via `POST /api/vth/leges/calculate`
THEN:
- System retrieves `legesRuleSet` for (zaaktype, activiteit)
- Base fee = €500
- Size modifier: +€100 * 2 (for 250/100) = €200
- Total calculated fee = €700
- Response: `{zaaktype, activiteit, baseFee: 500, modifiers: [{type: "size", amount: 200}], totalFee: 700}`

GIVEN another case with zaaktype = "Omgevingsvergunning", activiteit = "Bouw", property `publicHousing` = true
WHEN fee calculation is requested
THEN:
- Base fee = €750
- Exemption applied: 50% waive for public housing
- Calculated fee = €375
- Response includes: `{exemptions: [{reason: "publicHousing", reduction: "50%"}], totalFee: 375}`

### REQ-VTH-004-B: Verrekening (Offsetting Prior Fees)
GIVEN a case has prior leges recorded: €500 for prior "Omgevingsvergunning" application at same location
WHEN new fee calculation is requested with `verrekening: true`
THEN:
- New fee calculated = €700
- System applies verrekening rule: "Offset prior omgevingsvergunning leges at same location"
- Amount offset = min(700, 500) = €500
- **Final fee = 700 - 500 = €200**
- Response: `{calculatedFee: 700, priorFees: 500, verrekening: 500, finalFee: 200}`
- On save: audit log records the verrekening transaction

### REQ-VTH-004-C: Teruggaaf (Refund)
GIVEN a case is withdrawn after fees are paid: amount_paid = €600
WHEN refund is requested with reason = "aanvraag ingetrokken"
THEN:
- System calculates refund per rule: "100% refund if withdrawn before beschikking"
- Refund amount = €600
- Case property `betalingTeruggaaf` = €600 (recorded for accounting)
- Response: `{refundReason: "aanvraag ingetrokken", refundAmount: 600}`
- Notification sent to applicant: "Leges terugbetaald: €600"

### REQ-VTH-004-D: Navordering (Additional Billing)
GIVEN a case pays €700 but new fees are discovered later (e.g., scope change): additional_amount = €250
WHEN navordering is triggered
THEN:
- System records additional fee via `navordering(caseId, amount, reason)`
- Case property `beleggingExtra` = €250
- Notification sent to applicant: "Aanvullende leges verschuldigd: €250"
- Payment reminder sent after 14 days

### REQ-VTH-004-E: Rule Configuration and Activation
GIVEN an administrator edits leges rules for "Omgevingsvergunning-verbouwing"
WHEN the admin changes:
- Base fee: €500 → €600
- Size modifier: +€100 per 100 m² → +€125 per 100 m²
THEN:
- New `legesRuleSet` is created with `version` = 2
- Prior version is marked `validUntil` = today
- New version is marked `validFrom` = tomorrow (future-dated)
- Existing cases calculated with v1 rules remain unchanged (historical accuracy)
- New cases use v2 rules by default

---

## REQ-VTH-005: Beschikking (Permit Decision) Generation

**Purpose**: Automated generation of permit and decision documents from templates with case data merge.

### REQ-VTH-005-A: Template-Based Generation
GIVEN a case in status "Beschikking opgesteld" with zaaktype = "Omgevingsvergunning", decision = "Verleend"
WHEN beschikking generation is triggered via `POST /api/vth/cases/{id}/beschikking/generate`
THEN:
- System retrieves `beschikkingTemplate` for (zaaktype, decisionType="Verleend")
- Template content: "Hierbij vergunnen wij u {{applicantName}} de volgende activiteiten op {{location}}: {{activities}}. Deze vergunning is onderworpen aan: {{conditions}}."
- Case data merged:
  - `{{applicantName}}` → Case initiator name
  - `{{location}}` → Case address from location reference
  - `{{activities}}` → Comma-separated list of activities from case properties
  - `{{conditions}}` → Formatted list of permit conditions (numbered, indented)
- Generated document is stored as Nextcloud file
- Reference added to case `bijlagen`
- Response: `{documentId, fileName: "Beschikking_Omgevingsvergunning_2026-ENV-001.pdf", mimeType: "application/pdf"}`

### REQ-VTH-005-B: Required Field Validation
GIVEN a case missing required field `voorwaarden` (conditions)
WHEN beschikking generation is triggered
THEN:
- System validation fails with error: "Cannot generate beschikking: required field 'voorwaarden' is empty"
- Case property `voorwaarden` is highlighted in case detail
- Beschikking generation is blocked until field is populated

GIVEN all required fields are populated
WHEN beschikking is generated
THEN document is successfully created and case transitions to "Beschikking opgesteld" → "Verzonden" workflow

### REQ-VTH-005-C: Multi-Language and Format Support
GIVEN a municipality has bilingual requirements (Dutch + English)
WHEN beschikking template is configured
THEN:
- Template system supports language enum: `language: "nl" | "en" | "both"`
- If `language: "both"`, generated document includes both language versions (side-by-side or dual pages)
- Merge fields support language-specific values: `{{applicantName_nl}}` vs `{{applicantName_en}}`

GIVEN output format preference = "PDF"
WHEN beschikking is generated
THEN:
- PDF is generated (via Docudesk if configured; fallback: HTML-to-PDF)
- Digital signature is applied (if signing certificate is configured)
- Signature timestamp is recorded in case audit trail

### REQ-VTH-005-D: Template Versioning and Validity
GIVEN a beschikking template is updated:
- Old template: content mentions "Wet Ruimtelijke Ordening"
- New template: content mentions "Omgevingswet (OW)"
WHEN the change is saved
THEN:
- Old template is marked `validUntil: today`
- New template is marked `validFrom: tomorrow`
- Cases generated before today use old template (historical accuracy)
- Cases generated after tomorrow use new template
- Audit trail shows which template version was used for each beschikking

---

## REQ-VTH-006: DSO Integration Hooks

**Purpose**: Receive DSO verzoeken, auto-create cases, and push status updates back to DSO-LV.

### REQ-VTH-006-A: Verzoek Reception and Case Creation
GIVEN OpenConnector receives a DSO verzoek (vergunningaanvraag) from Omgevingswet digital system
WHEN the verzoek is written to OpenRegister as `vergunningaanvraag` object
THEN:
- Procest listens to `ObjectCreatedEvent` on `vergunningaanvraag` schema
- Service `DsoCaseService` is invoked with the verzoek payload
- New case is created with:
  - `caseType` = "Omgevingsvergunning"
  - `title` = "Vergunning {{location}} — {{activities}}"
  - `startDate` = verzoek `indieningsDatum`
  - `deadline` calculated per procedure type (8 weeks reguliere, 26 weeks uitgebreide)
  - `sourceProcedure` = "DSO-LV verzoek {{verzoekId}}"
  - Case property `dsoVerzoekRef` → OpenRegister object reference
- Case transitions to status "Aanvraag ontvangen"
- Notification sent to case coordinator: "Nieuwe DSO verzoek ontvangen: {{location}} — {{activities}}"

### REQ-VTH-006-B: Data Mapping from DSO Payload
GIVEN a DSO verzoek with STAM 2.0 payload:
```json
{
  "verzoekId": "DSO-2026-001234",
  "indieningsDatum": "2026-03-15",
  "activiteiten": ["Verbouwing kantoor", "Uitbreiding functie"],
  "locatie": {"objectId": "0599200000000000", "adres": "Amsterdam, Oudezijds Voorburgwal 104"},
  "initiatiefnemer": {"bsn": "123456789", "naam": "Jan de Vries"},
  "procedureType": "reguliere",
  "bijlagen": ["DSO-2026-001234-01.pdf", "DSO-2026-001234-02.dwg"]
}
```
WHEN the case is created
THEN:
- Case properties are mapped:
  - `title` = "Verbouwing kantoor, Uitbreiding functie — Amsterdam, Oudezijds Voorburgwal 104"
  - `geometry` = GeoJSON from location BAG object (if available)
  - `caseProperty[activiteiten]` = ["Verbouwing kantoor", "Uitbreiding functie"]
  - `caseProperty[locatie]` = BAG object reference
  - `caseProperty[initiatiefnemer]` = BRP person reference (resolved via BSN or manual link)
- Bijlagen are downloaded and attached to case via Nextcloud FileService
- BRP person reference fails if BSN is invalid → case is flagged "Awaiting manual initiator linking"
- All DSO references are preserved for audit and status pushback

### REQ-VTH-006-C: Status Pushback to DSO-LV
GIVEN a case transitions from status "In behandeling" to "Beschikking opgesteld"
WHEN the transition is executed
THEN:
- Event `VergunningStatusChangedEvent` is dispatched with:
  - `vergunningaanvraagRef` = DSO object reference
  - `oldStatus` = "In behandeling"
  - `newStatus` = "Beschikking opgesteld"
  - `timestamp` = transition timestamp
  - `userId` = user executing transition
- OpenConnector listens to this event and converts to DSO status update
- DSO-LV receives status update via OpenConnector's mTLS connection
- Applicant sees status update in Omgevingsloket portal within minutes

GIVEN a case reaches status "Verleend" or "Geweigerd"
WHEN transition is executed AND beschikking document is attached
THEN:
- Status pushback includes: `{status: "Verleend", beschikkingUrl: "..."}`
- Applicant can download beschikking via Omgevingsloket portal
- Case marked as "DSO pushback completed"

### REQ-VTH-006-D: DSO Deadline Tracking and Warnings
GIVEN a case created from DSO verzoek with `procedureType` = "reguliere" (8 weeks)
WHEN `deadline` = indieningsDatum + 8 weeks (40 working days per OW rules)
THEN:
- Daily job evaluates deadline:
  - At deadline - 6 weeks: Send notification "6 weeks remaining for beschikking"
  - At deadline - 2 weeks: Send notification "2 weeks remaining — escalate if not in beschikking stage"
  - At deadline date: Flag case "Deadline reached"; block transitions until escalation review

GIVEN deadline is reached and case is still "In behandeling"
WHEN escalation workflow is triggered
THEN:
- Case is flagged "Urgent — overdue; escalate to manager"
- Manager receives task: "Approve deadline extension or prepare rejection"
- Manager can grant extension (per OW rules, max 1 extension) OR trigger expedited beschikking generation

---

## REQ-VTH-007: Activiteit-Object-Subject Linking and Searchability

**Purpose**: Register permits as structured data linked to activity, location, and applicant for searchability and reporting.

### REQ-VTH-007-A: Structured Data Registration
GIVEN a case reaches status "Verleend" (permit granted)
WHEN the case is marked as completed
THEN:
- Procest creates links in OpenRegister:
  - `caseObject` linking case to BAG object (location)
  - `caseProperty[activiteiten]` storing activity classifications
  - `caseProperty[initiatiefnemer]` storing applicant reference (BRP or organization)
- These links are NOT embedded in the case; instead, they are navigable relations
- Audit trail records the linking action

### REQ-VTH-007-B: Full-Text Search
GIVEN a user searches for "Verbouwing kantoor Amsterdam"
WHEN full-text search is executed via `IndexService`
THEN:
- Search index includes case title, activities, location, applicant name
- Results show all matching cases (granted permits, in-progress, etc.)
- Each result shows: title, zaaktype, status, location, decision date (if applicable)

### REQ-VTH-007-C: Faceted Search by Activity, Location, Status
GIVEN a user opens the VTH dashboard
WHEN they filter by:
- Activiteit = "Verbouwing kantoor"
- Status = "Verleend"
- Location = "Amsterdam"
THEN:
- System executes faceted search via `FacetBuilder`
- Returns all granted permits matching all three criteria
- Facet counts show: "25 Verbouwing kantoor | 18 Verleend | 42 Amsterdam"
- User can click facets to refine results

### REQ-VTH-007-D: Reporting and Analytics
GIVEN a municipality manager requests: "Number of omgevingsvergunningen verleend per activiteit in 2026"
WHEN the report is generated
THEN:
- System queries cases with:
  - zaaktype = "Omgevingsvergunning"
  - status = "Verleend"
  - decisionDate between 2026-01-01 and 2026-12-31
- Groups by `caseProperty[activiteiten]`
- Returns table: | Activiteit | Count | Avg Processing Days |
  - Verbouwing kantoor | 45 | 28 days
  - Bouw | 32 | 35 days
  - Energieopwekking | 8 | 42 days

---

## REQ-VTH-008: Configuration and Admin Interface

**Purpose**: Admin interface for managing VTH workflow templates, rules, and configurations.

### REQ-VTH-008-A: Workflow Template Management
GIVEN an administrator accesses Settings → VTH Configuration
WHEN they navigate to "Workflows"
THEN:
- Admin can view all VTH workflow templates (Omgevingsvergunning, Toezichtzaak, Handhavingszaak)
- For each template:
  - View current version and active status
  - Download template as JSON for backup
  - Activate/deactivate versions
  - View audit trail of changes
  - Preview workflow diagram (visual editor showing statuses and transitions)

### REQ-VTH-008-B: Leges Rules Configuration
GIVEN an administrator navigates to Settings → VTH Configuration → Leges Rules
WHEN they edit rules for "Omgevingsvergunning-verbouwing"
THEN:
- Admin can edit:
  - Base fee (EUR)
  - Modifiers (list of property × amount pairs)
  - Exemptions (property conditions that waive or reduce fee)
  - Verrekening rules (prior fee offset logic)
  - Teruggaaf rules (refund conditions)
- On save: new rule version is created; prior version marked `validUntil: today`
- UI confirms: "Leges rules updated; effective for new cases from tomorrow"

### REQ-VTH-008-C: Beschikking Template Management
GIVEN an administrator navigates to Settings → VTH Configuration → Beschikking Templates
WHEN they manage templates for "Omgevingsvergunning"
THEN:
- Admin can:
  - Create new template for decision type (Verleend, Geweigerd, etc.)
  - Edit template content with merge field picker (drag-drop or autocomplete `{{fieldName}}`)
  - View list of available merge fields for the zaaktype
  - Test template by generating sample beschikking from test case data
  - Set validity dates (validFrom, validUntil)
- On save: template is versioned; old version archived

### REQ-VTH-008-D: Checklist Template Configuration
GIVEN an administrator navigates to Settings → VTH Configuration → Inspection Checklists
WHEN they create checklist for "Bouwtoezicht fase fundering"
THEN:
- Admin can:
  - Create new checklist with name and description
  - Add checklist items: question, type (checkbox/text/photo/GPS), required flag, help text
  - Reorder items via drag-drop
  - Enable nesting (parent-child item relationships for hierarchical checklists)
  - Set checklist as active or draft
  - Preview mobile view of checklist
- On save: checklist is versioned; can be linked to cases

---

## REQ-VTH-009: Audit, Logging, and Compliance

**Purpose**: Full audit trail of workflow execution, configuration changes, and decision documentation.

### REQ-VTH-009-A: Case Workflow Audit Trail
GIVEN any workflow transition (e.g., "In behandeling" → "Beschikking opgesteld")
THEN:
- Audit trail entry is created with:
  - Timestamp
  - Initiating user
  - Status from → to
  - Any field changes (e.g., fees, deadline extension)
  - Document attachments added/removed
- Entries are immutable (cannot be edited or deleted)
- Audit trail is accessible to authorized roles via case detail view

### REQ-VTH-009-B: Fee Calculation Audit
GIVEN leges are calculated or verrekening is applied
THEN:
- Audit trail entry records:
  - Original fee amount
  - Modifiers applied (size, exemptions, etc.)
  - Verrekening offset (if any)
  - Final fee amount
  - Effective date of rule version used
  - User who triggered calculation

### REQ-VTH-009-C: Configuration Change Tracking
GIVEN an administrator modifies:
- Leges rules
- Beschikking templates
- Workflow definitions
- Checklist templates
THEN:
- Change is logged with:
  - Timestamp and user
  - Before/after snapshot of configuration
  - Versioning information
  - Impact analysis (e.g., "Affects 23 existing cases in progress")
- Change is reversible: admin can view prior version and revert if needed

### REQ-VTH-009-D: GDPR/BIO Compliance
GIVEN personal data is processed (applicant name, BSN, contact details)
THEN:
- Procest implements:
  - Personal data encryption at rest (DBand at-transit (TLS)
  - Purpose limitation: personal data used only for case processing and notifications
  - Data retention: personal data is retained per case archival rules; deleted after archival period
  - Subject access rights: applicant can request export of their personal data
  - Anonymization: case data can be anonymized for reporting/analytics (names → [REDACTED])
- Audit trail records all personal data access for BIO compliance
