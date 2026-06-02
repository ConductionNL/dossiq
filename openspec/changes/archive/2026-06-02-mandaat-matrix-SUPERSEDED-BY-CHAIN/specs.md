# Specs: Mandaat-matrix voor zaak-gestuurde besluitvorming

## Overview

Detailed requirements for mandate-matrix implementation, covering mandate registration, real-time authorization checks, escalation, effective dating, delegation, and audit compliance.

---

## REQ-MANDAAT-001: Mandateringsbesluit Import from Decidesk

**Purpose**: Import legislative mandate decisions from decidesk and create atomic mandate records for authorization checks.

### REQ-MANDAAT-001-A: Decidesk Integration
GIVEN a collegebesluit "Algemene mandaatregeling gemeente 2026" in decidesk with besluit-id "e1c2d3e4" and attached Excel table
WHEN a juridisch medewerker navigates to Settings > Mandate Matrix > Import and selects this besluit
THEN:
- Procest retrieves the besluit details and attachment from decidesk REST API
- System displays a preview of the mandate table (first 10 rows) with columns: mandaatNummer, omschrijving, gemandateerdeRol, plafond_bedrag
- Import button is enabled
- User can confirm import to proceed

### REQ-MANDAAT-001-B: Mandate Record Creation
GIVEN the user confirms import
WHEN Procest parses the Excel/CSV table
THEN:
- System creates one MandateringsBesluit record with:
  - `besluitNummer` = "CR 2026-001" (extracted or auto-generated)
  - `besluitNaam` = "Algemene mandaatregeling gemeente 2026"
  - `besluitOrgaan` = "college" (from decidesk besluitorgaan)
  - `besluitDatum` = decision date from decidesk
  - `inwerkingtreding` = date from decidesk or today
  - `vastgesteldDoor` = "e1c2d3e4" (decidesk UUID)
  - `status` = "concept" (until review)
  - `bijlageDocumentId` = Nextcloud file ID of Excel
- For each table row, system creates a Mandaat record with:
  - `besluitId` → MandateringsBesluit reference
  - `mandaatNummer` = column value (e.g., "M.3.1.2")
  - `omschrijving` = column value
  - `gemandateerdeRol` = column "Rol" → resolved to OrganisatieRol UUID (or error if role not found)
  - `bevoegdheidType` = derived from omschrijving or explicit column
  - `voorwaarden` = parsed from column "Plafond", "Subdelegatie", etc.
  - `status` = "concept"

### REQ-MANDAAT-001-C: Validation and Diff View
GIVEN mandate records are created with status "concept"
WHEN the user views the import result
THEN:
- System validates that all referenced OrganisatieRol records exist; if missing, shows error "Role 'Wethouder RO' not found in registry"
- System compares against prior mandaatregeling (if any) and displays a diff:
  - NEW: rows not in prior version (shown in green)
  - CHANGED: rows with different plafond or conditions (shown in yellow)
  - REMOVED: rows in prior version but not in new (shown in red)
  - UNCHANGED: no change
- User reviews diff and can cancel or approve import
- On approval: MandateringsBesluit status → "vastgesteld"; all Mandaat records status → "active"

### REQ-MANDAAT-001-D: Effective Dating
GIVEN a mandaatregeling imports with `inwerkingtreding` = 2026-07-01 (future date)
WHEN the import is approved
THEN:
- MandateringsBesluit is created with `inwerkingtreding` = 2026-07-01
- All Mandaat records are created with `geldigVanaf` = 2026-07-01
- Prior mandateringsbesluit (if any) is updated with `vervalDatum` = 2026-06-30
- System maintains both versions in registry
- Authorization checks on or after 2026-07-01 use new mandaten; before use prior

---

## REQ-MANDAAT-002: Real-Time Bevoegdheidscheck on Decision

**Purpose**: Enforce authorization on every case decision without requiring manual mandate lookups.

### REQ-MANDAAT-002-A: Authorization Check on Decision Action
GIVEN a zaak with zaaktype "Omgevingsvergunning", bouwsom €75.000
WHEN a user with role "Vergunningverlener" attempts the action "Vergunning verlenen" (decision type M.3.1.2 required)
THEN:
- Procest invokes `MandaatCheckService.isAuthorized(userId, "vergunning_verlenen", zaakId)`
- Service loads applicable Mandaat records for this zaaktype/decisionType
- Service resolves user's current OrganisatieRol(en) as of today
- Service validates: role "Vergunningverlener" holds mandate M.3.1.2? YES
- Service validates conditions: bouwsom (€75K) ≤ plafond (€100K)? YES
- Service evaluates subdelegatie: M.3.1.2 has subdelegatie_toegestaan = true? YES (permitted but not required here)
- Return: {authorized: true, mandaatId: "M.3.1.2", reden: null}
- Decision action proceeds
- MandaatGebruik record is created with snapshot of role, mandate, conditions, and timestamp

### REQ-MANDAAT-002-B: Authorization Denied — Role Does Not Hold Mandate
GIVEN a zaak with decisionType requiring M.3.1.2
WHEN a user with role "Medewerker Vergunningen" (does NOT have M.3.1.2) attempts the decision
THEN:
- Authorization check returns: {authorized: false, mandaatId: null, reden: "niet_bevoegd"}
- Decision action is blocked with message: "You do not have mandate M.3.1.2 required for this decision"
- System auto-creates MandaatEscalatie with:
  - `escalatieReden` = "niet_bevoegd"
  - `escalatiePadEindigtBij` = role ID of Senior Vergunningverlener (next level)
  - `status` = "open"
  - Notification sent to Senior Vergunningverlener: "Decision escalation required: Omgevingsvergunning €75K — role Medewerker does not have mandate"
- Case status transitions to "Wacht op besluit hoger mandaat" (or similar)
- User sees escalation link and can cancel or follow escalation

### REQ-MANDAAT-002-C: Waarnemer (Substitute) Authority
GIVEN Hoofd VTH is on vacation 2026-06-15 to 2026-06-30
AND a MedewerkerRolToewijzing exists: Hoofd Stadsbeheer (waarnemer) for "Hoofd VTH" role, 2026-06-15 – 2026-06-30
WHEN on 2026-06-22, Hoofd Stadsbeheer attempts a decision requiring mandate held by "Hoofd VTH"
THEN:
- Authorization check resolves user's roles and finds waarnemer assignment (active on this date for this role)
- Service validates: is waarnemer assignment active? YES
- Authorization succeeds with note: "Authorized via waarnemer"
- MandaatGebruik is created with:
  - `bevoegdheidsCheckResult` = "bevoegd_via_waarnemer"
  - `rolOpMomentVanBesluit` includes: {rolNaam: "Hoofd VTH (waargenomen door Hoofd Stadsbeheer)", toewijzingType: "waarnemer"}
  - Audit trail explicitly records waarnemer relationship for compliance
- Decision proceeds

---

## REQ-MANDAAT-003: Automatic Escalation on Plafond Overschrijding

**Purpose**: Detect authority limits and auto-escalate to appropriate mandaathouder.

### REQ-MANDAAT-003-A: Plafond Validation and Escalation
GIVEN a zaak with bouwsom €250.000
WHEN a user with role "Vergunningverlener" (holds M.3.1.1 with plafond €50K) attempts "Vergunning verlenen"
THEN:
- Authorization check evaluates conditions: €250K > €50K plafond? YES, exceeded
- Return: {authorized: false, mandaatId: null, reden: "plafond_overschreden"}
- System queries applicable higher mandaten for this decisionType: M.3.1.2 (€100K), M.3.1.3 (€500K)
- System finds M.3.1.3 is appropriate (€250K < €500K) held by role "Hoofd Vergunningverlening"
- System creates MandaatEscalatie with:
  - `escalatiePadEindigtBij` = "Hoofd Vergunningverlening" role ID
  - `escalatieReden` = "plafond_overschreden"
  - Notification: "Escalation: Omgevingsvergunning €250K exceeds your mandate. Forwarded to Hoofd VV."
- Case status updated to "Wacht op besluit hoger mandaat"
- User sees escalation pending and can cancel/follow-up

---

## REQ-MANDAAT-004: Subdelegatie Enforcement

**Purpose**: Block re-delegation when mandate does not permit it.

### REQ-MANDAAT-004-A: Subdelegatie Not Permitted
GIVEN a mandaat M.4.2.1 "Vaststellen bestemmingsplan" with `subdelegatieToegestaan: false`, held by role "Wethouder RO"
AND an HR assignment: Beleidsmedewerker RO (waarnemer) for Wethouder RO, valid today
WHEN the Beleidsmedewerker RO attempts a decision requiring M.4.2.1
THEN:
- Authorization check finds: role Beleidsmedewerker RO does NOT directly hold M.4.2.1
- Waarnemer check: is Beleidsmedewerker RO a waarnemer for a role that holds M.4.2.1? YES (Wethouder RO)
- Subdelegatie check: does M.4.2.1 permit subdelegatie? NO (subdelegatieToegestaan: false)
- Return: {authorized: false, reden: "subdelegatie_niet_toegestaan"}
- Error message: "Mandate M.4.2.1 does not permit subdelegation. Only Wethouder RO can execute this decision."
- Decision is blocked; escalation created to Wethouder RO

---

## REQ-MANDAAT-005: Effective Dating (Retroactive and Future-Dated)

**Purpose**: Support mandates with retroactive or future entry dates for regulatory changes.

### REQ-MANDAAT-005-A: Future-Dated Mandate Activation
GIVEN two mandateringsbesluit versions:
- v1 (2025): M.3.1.1 plafond €50K, effective until 2026-06-30
- v2 (2026): M.3.1.1 plafond €100K, effective from 2026-07-01
WHEN on 2026-06-25, a user attempts a decision with bouwsom €75K
THEN:
- Authorization check determines decision date = 2026-06-25
- Applicable mandaat version = v1 (active on 2026-06-25)
- Plafond check: €75K > €50K? YES, overschreden
- Escalation triggered (using v1 rules)
- System offers option: "Schedule decision for 2026-07-01 or later to use new mandate (plafond €100K)"

### REQ-MANDAAT-005-B: Retroactive Mandate Audit
GIVEN a zaak decision made 2026-03-15, at that time using mandate version v1
WHEN an auditor opens the zaak in 2026-07-01 (after v2 activated)
THEN:
- Audit trail shows: "Decision 2026-03-15 — Mandate M.3.1.1 plafond €50K (v1, effective through 2026-06-30)"
- System does NOT re-evaluate against current mandate (v2)
- Snapshot of mandaat conditions at decision time is immutable

---

## REQ-MANDAAT-006: Bevoegdheden Dashboard per Zaaktype

**Purpose**: Allow users to self-serve and view their authority without escalation.

### REQ-MANDAAT-006-A: User-Facing Bevoegdheden View
GIVEN a zaakbehandelaar opens a zaak of type "Omgevingsvergunning"
WHEN they click "Toon bevoegdheden" or open the mandate matrix panel
THEN:
- System displays the mandate matrix for this zaaktype, filtered by their current role(s)
- Table columns: Mandaat | Omschrijving | Plafond | Subdelegatie | Hudig Geldig | Geldend van-tot
- Rows show only mandaten the user's role(s) hold
- Example for Vergunningverlener:
  - M.3.1.1 | Verlenen omgevingsvergunning < €50K | €50.000 | Nee | Ja | 2026-01-01 – 2026-12-31
- User can click "Details" to see full conditions and references to mandateringsbesluit
- Filter option "What can I do?" → shows only decision types they can unilaterally execute

### REQ-MANDAAT-006-B: Role Holder Registry
GIVEN the bevoegdheden view is open
WHEN user clicks on a mandate row
THEN:
- Panel expands to show:
  - Mandate details (omschrijving, wettelijke grondslag, voorwaarden)
  - Current role holders: [list of people in this role as of today]
  - If user is a waarnemer: note that they are acting as substitute for [primary role holder]
  - Mandateringsbesluit link: "Source: CR 2026-001, effective 2026-01-01"

---

## REQ-MANDAAT-007: Audit Trail — Decision-Level Mandate Snapshot

**Purpose**: Provide immutable proof of authority for each decision (compliance with Awb art. 3:4, BIO, audit).

### REQ-MANDAAT-007-A: MandaatGebruik Immutable Log
GIVEN a decision "Vergunning verlenen" is executed on 2026-03-15 by Alice van Bergen, role Senior Vergunningverlener
WHEN an auditor reviews this zaak later
THEN:
- Audit trail shows MandaatGebruik entry:
  - `zaakId` = zaak UUID
  - `beslissingId` = decision UUID
  - `mandaatId` = "M.3.1.2" UUID
  - `gemandateerdeId` = "alice.vandenberg"
  - `rolOpMomentVanBesluit` = {rolNaam: "Senior Vergunningverlener", rolType: "senior_behandelaar", parentRol: "Hoofd VV", toewijzingVanaf: "2026-01-01", toewijzingTotEnMet: null, toewijzingType: "primair"}
  - `beslissingTimestamp` = 2026-03-15 13:45:22 UTC
  - `bevoegdheidsCheckResult` = "bevoegd"
  - `gebruikteVoorwaarden` = {plafond_bedrag: 100000, bedrag_zaak: 75000, passed: true, subdelegatie_toegestaan: true, check_result: true}
  - `beslissingType` = "vergunning_verlenen"
- Entry is immutable; cannot be edited or deleted (system prevents via audit trail lock)
- If mandate or role is later changed/deleted, this snapshot proves what was in effect at decision time

### REQ-MANDAAT-007-B: Compliance Export
GIVEN an accountant is performing year-end audit
WHEN they request "Mandate Audit Report" for zaaktype "Omgevingsvergunning", period 2026-01-01 to 2026-12-31
THEN:
- System exports:
  - Count of decisions by mandate (e.g., M.3.1.1: 234, M.3.1.2: 89, M.3.1.3: 12)
  - Count of escalations (e.g., plafond_overschreden: 23, niet_bevoegd: 5)
  - Count of decisions by waarnemer (e.g., "Decision 5 times via waarnemer as substitute")
  - List of all decisions with {zaakId, beslissingId, mandate, user, date, result}
  - Any overrides or unusual patterns flagged for manual review
- Export is in CSV/Excel format with formulas for compliance checking

---

## REQ-MANDAAT-008: Escalation Workflow

**Purpose**: Route escalated decisions to appropriate authority with notification and approval tracking.

### REQ-MANDAAT-008-A: Escalation Creation and Routing
GIVEN an escalation is triggered (user lacks mandate or plafond exceeded)
WHEN system creates MandaatEscalatie
THEN:
- System determines `escalatiePadEindigtBij` by:
  1. Finding the next-higher mandaat that covers the decision + conditions
  2. Looking up current role holders for that mandaat
  3. Setting escalatiePadEindigtBij = first active role holder's user ID (or role ID if no primary holder)
- System sends notification to escalation recipient:
  - Subject: "Escallation: Omgevingsvergunning [zaak title]"
  - Body: "Reason: [plafond_overschreden]. Bouwsom €250K exceeds your limit (€100K). Forwarded to Hoofd VV. [Link to zaak and escalation]"
- Escalation enters inbox of mandaathouder

### REQ-MANDAAT-008-B: Escalation Approval
GIVEN an escalation is open in the mandaathouder's inbox (e.g., Hoofd VV for a €250K vergunning)
WHEN the mandaathouder reviews and approves the decision
THEN:
- System invokes the underlying decision action (Vergunning verlenen) with escalation-approval flag
- Authorization re-check: does Hoofd VV (role) hold mandate for this decision? YES
- Decision executes; case proceeds
- MandaatEscalatie status → "goedgekeurd"
- MandaatGebruik entry created with:
  - `gemandateerdeId` = mandaathouder user ID
  - `rolOpMomentVanBesluit` = Hoofd VV role snapshot
  - `bevoegdheidsCheckResult` = "bevoegd"
- Notification sent to original user: "Your escalated decision has been approved by [mandaathouder]"

### REQ-MANDAAT-008-C: Escalation Rejection
GIVEN a mandaathouder reviews an escalation and rejects it
WHEN they click "Reject" and provide reason (e.g., "Insufficient documentation")
THEN:
- MandaatEscalatie status → "afgewezen"
- `toelichting` = reason
- Decision is NOT executed; case remains in prior status
- Notification sent to originating user: "Your decision has been rejected: [reason]. Please revise and resubmit."
- Escalation can be re-opened after user provides additional documentation

### REQ-MANDAAT-008-D: Escalation Rerouting on Personnel Change
GIVEN an escalation is open with `escalatiePadEindigtBij` = Hoofd VV user "carol.dewit"
WHEN HR ends Carol's assignment and assigns a new Hoofd VV "frank.kerkhof"
THEN:
- System detects open escalaties addressed to Carol in role "Hoofd VV"
- System auto-rerouts escalaties: `escalatiePadEindigtBij` = frank.kerkhof
- Notification sent to Frank: "You are now the recipient of [N] escalated decisions (previously assigned to Carol)"
- Carol receives notification: "You are no longer responsible for [N] escalated decisions (rerouted to Frank)"

---

## REQ-MANDAAT-009: Personnel Mutations Without Mandate-Regeling Changes

**Purpose**: Support HR role changes without requiring legal mandaatregeling updates.

### REQ-MANDAAT-009-A: Role Transfer on Departure
GIVEN Bob (bob.jansen) is assigned to role "Vergunningverlener" and holds all mandaten for that role
WHEN HR ends Bob's assignment (`toewijzingTotEnMet` = today) and assigns Carol to the same role (starting today)
THEN:
- System performs no changes to Mandaat records (they remain "Vergunningverlener role" → no specificity to person)
- System updates all active escalaties that were addressed to Bob → reroute to Carol
- Authorization checks going forward resolve "Vergunningverlener" role → Carol (current holder)
- MedewerkerRolToewijzing for Bob is closed; new assignment for Carol is created
- No legal mandaatregeling change is needed

### REQ-MANDAAT-009-B: Temporary Coverage (Waarnemer)
GIVEN Bob is on leave 2026-06-15 to 2026-06-30
WHEN HR assigns Carol as waarnemer for "Vergunningverlener" role during this period
THEN:
- New MedewerkerRolToewijzing: Carol → Vergunningverlener, toewijzingType: "waarnemer", 2026-06-15 – 2026-06-30
- Bob's primary assignment remains active but "on leave" flag noted
- Authorization checks for dates 2026-06-15 – 2026-06-30 resolve role → Carol (waarnemer has authority)
- Authorization checks outside this period resolve → Bob (primary)
- Escalaties during the period are addressed to Carol; after period, to Bob
- Audit trail explicitly shows "waarnemer" relationship for each decision during period

---

## REQ-MANDAAT-010: Belangenconflict (Conflict of Interest) Detection

**Purpose**: Prevent decision-makers with interest in the outcome from executing decisions.

### REQ-MANDAAT-010-A: Automatic Conflict Detection
GIVEN a zaak for "Omgevingsvergunning" with aanvrager (applicant) BSN "123456789"
WHEN user Bob (BSN "987654321", but spouse BSN "123456789") attempts a decision on this zaak
THEN:
- System checks BRP relationship: is Bob related to applicant? (via spouse lookup)
- Conflict is detected
- Authorization check returns: {authorized: false, reden: "belangenconflict"}
- Decision is blocked with message: "Conflict of interest detected. You or a family member is involved in this case."
- System auto-escalates with `escalatieReden` = "belangenconflict" to a different role holder
- Audit trail records conflict detection event

### REQ-MANDAAT-010-B: Manual Conflict Registration
GIVEN a user has no automatic conflict but a colleague alerts them
WHEN they click "Register interest conflict" and provide reason (e.g., "Personal acquaintance with applicant")
THEN:
- System records conflict in case: `potentiaalConflict` flag = true with reason
- User is prevented from executing decision on this case
- Escalation triggered to alternative mandaathouder
- Audit trail records manual conflict flag

---

## REQ-MANDAAT-011: Configuration and Settings

**Purpose**: Admin interface for managing mandate matrix and organizational roles.

### REQ-MANDAAT-011-A: OrganisatieRol Management
GIVEN an admin opens Settings > Organization > Roles
WHEN they view the role registry
THEN:
- Table shows all OrganisatieRol entries with columns: Naam | Type | Afdeling | Team | ParentRol | MandaatNiveau
- Admin can:
  - Add new role: "Hoofd Milieuzaken" (type: afdelingshoofd, parent: Director Operations)
  - Edit role: change parent, add description, set mandaatNiveau
  - Delete role: only if no active MedewerkerRolToewijzing or Mandaat references (else error)
  - Assign role to person: click role → "Add assignment" → select person, startdate, type (primair/waarnemer/interim)

### REQ-MANDAAT-011-B: Mandate Matrix Editor
GIVEN an admin opens Settings > Mandates > Matrix
WHEN they view an active mandaatregeling (e.g., CR 2026-001)
THEN:
- Table shows all Mandaat records grouped by gemandateerdeRol
- Columns: # | Omschrijving | Type | Wettelijke Grondslag | Voorwaarden | Valid Period | Edit | Delete
- Admin can:
  - Edit any Mandaat: update omschrijving, voorwaarden (plafond, subdelegatie), validity dates
  - Clone mandate: duplicate for new decision type
  - Delete mandate (soft-delete; marked as invalid retroactively if changes)
  - Export as Excel for audit trail

---

## REQ-MANDAAT-012: Integration with Zaak Decision Points

**Purpose**: Embed authorization checks seamlessly into case workflows without disrupting UX.

### REQ-MANDAAT-012-A: Decision-Action Authorization Guard
GIVEN a zaak workflow has a decision step "Vergunning verlenen"
WHEN the step is configured in workflowTemplate
THEN:
- Step definition includes: `mandaat_required: "M.3.1.*"` (wildcard or specific)
- At runtime, before user can execute action, system invokes MandaatCheckService
- If authorized: action button is enabled, user proceeds
- If not authorized: action button is disabled with tooltip "You don't have mandate M.3.1.2 required for this action"
- Escalation option is provided inline

### REQ-MANDAAT-012-B: Case Status Transitions Blocked by Authorization
GIVEN a case is in status "Beschikking opgesteld" and next step is "Verzenden"
WHEN the user attempts to transition
THEN:
- System checks if user has mandate to "Verzenden" (decision type)
- If not: transition is blocked; escalation offered; case remains in "Beschikking opgesteld"
- If yes: transition proceeds; MandaatGebruik log entry created

---

## Standards and Regulations

- **Awb artikel 10:1 – 10:12** — Mandate, delegation, and authority framework
- **Awb artikel 3:4** — Motivation requirement (linked to mandate in decision)
- **Gemeentewet artikelen** — College and burgemeester authority distributions
- **VNG Model Mandaatbesluiten** — Best practices and templates
- **AVG (GDPR)** — Personal data in audit trails (anonymization for non-audit use)
- **NEN 7510** — Logging of decision-makers for compliance
- **ISO 27001 A.9** — Access control and authorization
