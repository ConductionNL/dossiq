# Specs: termijnbewaking-dwangsom-engine

## Overview

Detailed requirements for deadline monitoring, pause/extension management, ingebrekestelling validation, dwangsom calculation, and financial system integration per Dutch administrative law (AWB 4:1.3–4:1.3a, Wet dwangsom bij niet-tijdig-beslissen).

---

## REQ-TERM-001: Termijn-Binding per Zaaktype

**Purpose**: Every zaak must have a matching TermijnDefinitie; explicit configuration prevents silent deadline-handling failures.

### REQ-TERM-001-A: TermijnDefinitie Requirement on Zaak-Creation
GIVEN a gemeente has configured TermijnDefinities for "Omgevingsvergunning-regulier" (56 days) and "Wmo-aanvraag" (42 days) but NOT for "Horeca-exploitatievergunning"
WHEN a new "Horeca-exploitatievergunning" zaak is created
THEN the system MUST block zaak-creation with error message: "Termijnbewaking is not configured for zaaktype Horeca-exploitatievergunning. Contact your administrator to configure a TermijnDefinitie before creating cases of this type."

### REQ-TERM-001-B: Auto-Create TermijnInstance on Zaak-Creation
GIVEN a zaak of type "Omgevingsvergunning-regulier" is registered on 2026-06-01
WHEN the TermijnInstance is auto-created
THEN:
- einddatumBerekend MUST be 2026-07-27 (56 days: June has 30 days, so 29 days into July = July 27, excluding weekends per Dutch calendar)
- status MUST be "lopend"
- A TermijnGebeurtenis of type "start" MUST be recorded with type=start, tijdstip=zaak-creation-time, grondslag="AWB 4:13"

### REQ-TERM-001-C: TermijnDefinitie Validation on Change
GIVEN a TermijnDefinitie is updated (e.g., duration from 56 to 70 days)
WHEN the change takes effect
THEN:
- New zaaktypen created AFTER the change MUST use the new duration
- Existing TermijnInstances created BEFORE the change MUST retain their original einddatumBerekend (no retroactive changes)
- If the TermijnDefinitie is marked validUntil=today, new zaaktypen MUST NOT be created; existing ones continue

---

## REQ-TERM-002: Pauze wegens Onvolledige Aanvraag (AWB 4:5/4:15)

**Purpose**: Pause mechanism for hersteltermijn (request-for-completion period) while preserving the original deadline window.

### REQ-TERM-002-A: Pauze Registration with Automatic Resumption
GIVEN a TermijnInstance for "Omgevingsvergunning" is running since 2026-06-01 with einddatumActueel=2026-07-27
AND on 2026-06-10 the handler registers a hersteltermijn-verzoek with duurDagen=14
WHEN the pauze is registered
THEN:
- status MUST be "gepauzeerd"
- einddatumActueel MUST extend to 2026-07-27 + 14 days = 2026-08-10 (assuming no issues)
- A TermijnGebeurtenis of type "pauze" MUST be recorded with dagenImpact=+14

GIVEN the burger responds with aanvulling on 2026-06-18 (8 days into the 14-day window)
WHEN the handler registers the aanvulling-ontvangst
THEN:
- A TermijnGebeurtenis of type "hervat" MUST be recorded
- status MUST revert to "lopend"
- einddatumActueel MUST be recalculated: original 2026-07-27 + (14 - 8) days pause consumed = 2026-08-04
- No additional pause days are added; only the unconsumed pause days extend the deadline

### REQ-TERM-002-B: Pause-Expiry Alert on Non-Response
GIVEN a pauze is registered with duurDagen=14 expiring on 2026-06-24
WHEN the daily termijn-scan runs on 2026-06-25 (no aanvulling received)
THEN:
- A TermijnGebeurtenis of type "pauze-verlopen" MUST be recorded
- Handler MUST receive pro-active notification: "Hersteltermijn voor case 2026-001 is verlopen zonder antwoord. Advies: Neem aanvraag buiten behandeling (AWB 4:5) of zet zaak in status 'Afgehandeld' met uitkomst 'Ongegrond'."
- Case status MUST be flagged for manual review; automatic termijn-continuation is blocked until handler makes decision

---

## REQ-TERM-003: Verlenging according to AWB 4:14

**Purpose**: One-time extension with mandatory motivation; blocking of second extension unless exceptional grounds apply.

### REQ-TERM-003-A: First Extension with Validation
GIVEN a TermijnInstance is running and the handler initiates an extension
WHEN the handler provides motivering="Complex dossier, advies derden nodig" and newEinddatum=6 weeks later
THEN:
- Extension MUST be validated: motivering present (not empty), newEinddatum is after current einddatumActueel, aantalVerlengingen < maxVerlengingen (1)
- A TermijnGebeurtenis of type "verleng" MUST be recorded with motivering and dagenImpact=+42
- einddatumActueel MUST be updated to newEinddatum
- A notification MUST be emitted (for later processing into a verlengingsbrief to the applicant)

### REQ-TERM-003-B: Second Extension Blocking
GIVEN a TermijnInstance already has 1 extension (aantalVerlengingen=1)
WHEN the handler attempts a second extension
THEN the system MUST reject with error: "Tweede verlenging is niet toegestaan per AWB 4:14 lid 3, tenzij er een uitzonderlijke regelingsgrondslag wordt aangevoerd (bijv. wettelijke plicht tot advies derden die nog niet is ingewacht)."
- Manual override is possible ONLY if the handler provides exceptionalGroundslug and supervisor approval

---

## REQ-TERM-004: Pro-Active Notificaties bij Naderende Deadlines

**Purpose**: Escalating alerts at key thresholds (14d, 7d, 2d) to ensure human attention before overschrijding.

### REQ-TERM-004-A: 14-Day Alert
GIVEN a TermijnInstance has einddatumActueel over 13 days away
WHEN the daily termijn-scan runs
THEN the handler MUST receive notification via Nextcloud + e-mail: "Case 2026-001 deadline approaching in 13 days (2026-07-27). Review status: besluit gereed?"

### REQ-TERM-004-B: 7-Day Alert
GIVEN a TermijnInstance has einddatumActueel over 6 days away
WHEN the daily scan runs
THEN the handler AND teamleader MUST receive notification with elevated priority: "Case 2026-001 deadline in 6 days. Escalation required if besluit not ready."

### REQ-TERM-004-C: 2-Day Terminal Warning
GIVEN a TermijnInstance has einddatumActueel in 1 day
WHEN the daily scan runs
THEN handler, teamleader, AND afdeling-manager MUST receive RED-FLAG notification: "CRITICAL: Case 2026-001 deadline TOMORROW (2026-07-27). Immediate action required or overschrijding will trigger dwangsom sequence."

### REQ-TERM-004-D: Overschrijding Detection and Status Update
GIVEN a TermijnInstance's einddatumActueel has passed
WHEN the scan runs on the next day
THEN:
- status MUST be set to "overschreden"
- A TermijnGebeurtenis of type "overschreden" MUST be recorded
- Case-detail-UI MUST display red warning: "⚠ Verwerking termijn overschreden op [date]. Ingebrekestelling kan volgen."
- All users with access to the case MUST receive "OVERSCHRIJDING" notification

---

## REQ-TERM-005: Ingebrekestelling-Registratie en Validatie

**Purpose**: Formal notification (required by AWB 4:17) with validation to prevent premature dwangsom-triggering.

### REQ-TERM-005-A: Valid Ingebrekestelling (Termijn Actually Overschreden)
GIVEN a TermijnInstance is overschreden since 5 days (einddatumActueel was 5 days ago)
AND on 2026-07-31 a burger files an ingebrekestelling
WHEN the handler registers it with ontvangstDatum=2026-07-31
THEN:
- System MUST validate: TermijnInstance.status=overschreden AND einddatumActueel < ontvangstDatum
- Validation MUST pass; Ingebrekestelling.gevalideerd=true, geldigheidStatus="geldig"
- A DwangsomBerekening MUST be auto-created with startDatum=2026-07-31 + 14 days = 2026-08-14
- Burger MUST receive notification: "Uw ingebrekestelling op [date] is ontvangen. Indien uw aanvraag niet voor [2026-08-11] is beslist, begint de dwangsom-berekening."

### REQ-TERM-005-B: Premature Ingebrekestelling (Termijn Not Yet Overschreden)
GIVEN a burger files an ingebrekestelling on 2026-07-20 but the termijn doesn't expire until 2026-07-27
WHEN the handler registers it with ontvangstDatum=2026-07-20
THEN:
- System MUST validate: einddatumActueel (2026-07-27) > ontvangstDatum (2026-07-20)
- Validation MUST FAIL; Ingebrekestelling.gevalideerd=false, geldigheidStatus="premaat"
- No DwangsomBerekening is created
- System MUST advise handler: "Ingebrekestelling is prematuur. Termijn loopt nog tot [2026-07-27]. Instrueer burger om ingebrekestelling opnieuw in te dienen na overschrijding."

---

## REQ-TERM-006: Dwangsom-Staffel Berekening according to AWB 4:17

**Purpose**: Precise daily tariff application per statutory schedule: €23/day (days 1–14), €35/day (days 15–28), €45/day (days 29+), max €1.442 per case.

### REQ-TERM-006-A: Grace Period and First Tier (€23/day)
GIVEN an Ingebrekestelling is registered on 2026-06-15
AND the 14-day grace period ends on 2026-06-29
WHEN the daily DwangsomBerekening runs on 2026-07-10 (11 days after grace ends)
THEN:
- Grace period (2026-06-15 to 2026-06-29) accrues NO dwangsom
- Days 1–10 (2026-06-30 to 2026-07-09) accrue €23/day = €230
- huidigeDag=10, cumulatievBedrag=€230, dagtarief=€23

### REQ-TERM-006-B: Transition to Second Tier (€35/day)
GIVEN the dwangsom accrual is on day 14 (2026-07-12)
WHEN the daily calculation runs on day 15 (2026-07-13, after 14 × €23 = €322)
THEN:
- dagtarief MUST switch to €35
- cumulatievBedrag MUST remain €322 (no retroactive recalculation of prior days)
- From day 15 onward, each new day adds €35
- By day 28 (2026-07-26), cumulatievBedrag = €322 + (14 × €35) = €322 + €490 = €812

### REQ-TERM-006-C: Transition to Third Tier (€45/day) and Plafond Enforcement
GIVEN the dwangsom accrual is at day 28 (cumulatievBedrag = €812)
WHEN the daily calculation runs on day 29 (2026-07-27, after grace ends 2026-06-29, day 29 = 2026-07-27)
THEN:
- dagtarief MUST switch to €45
- By day 42 (2026-08-09), cumulatievBedrag = €812 + (14 × €45) = €812 + €630 = €1.442 (plafond reached)
- plafondBereikt=true
- On day 43+, no additional dwangsom accrues; cumulatievBedrag remains €1.442

### REQ-TERM-006-D: Beschikking Stops Accrual
GIVEN a DwangsomBerekening has accrued €532 (14 days × €23 + 6 days × €35) over 20 days
WHEN the handler registers a beschikking on day 20 (2026-07-08, 20 days post-grace-end 2026-06-19)
THEN:
- DwangsomBerekening.status MUST be set to "gestopt-wegens-beschikking"
- definitievBedrag MUST be locked at €532
- No further daily accrual occurs
- A DwangsomUitbetaling MUST be prepared for payment signaling

---

## REQ-TERM-007: Uitbetaling-Signaal aan Financieel Systeem

**Purpose**: ERP-ready payment signal with all required metadata for automated processing via openconnector.

### REQ-TERM-007-A: Payment Signal Generation
GIVEN a DwangsomBerekening closes with definitievBedrag=€532
AND the burger's IBAN is known from the case record (NL91ABNA0417164300)
AND the burger's name is "Piet Jansen"
WHEN the signal is generated
THEN a DwangsomUitbetaling MUST be created with:
- bedrag=€532
- rekeninghouderNaam="Piet Jansen"
- iban="NL91ABNA0417164300"
- referentie="2026-001-20260715" (zaak-ID-ingebrekestelling-date)
- wettelijkeGrondslag="AWB 4:17 lid 2"
- betaaldatumUiterlijk=2026-08-12 (ingebrekestelling-date + 28 days per AWB)
- status="voorbereid"

### REQ-TERM-007-B: ERP Integration via OpenConnector
GIVEN a DwangsomUitbetaling is prepared
WHEN the signal is emitted to the ERP via openconnector
THEN the ERP system MUST receive a structured event containing: {zaakId, dwangsomBedrag, rekeninghouderNaam, iban, referentie, wettelijkeGrondslag, betaaldeadline, caseLink}
- Status MUST be tracked: voorbereid → in-betaling → betaald

### REQ-TERM-007-C: Payment Confirmation and Status Update
GIVEN the ERP processes the payment and sends a callback via openconnector
WHEN the system receives the callback with {referentie, status="betaald", werkelijkeBetaaldatum, betalingsreferentie}
THEN DwangsomUitbetaling MUST be updated:
- status="betaald"
- werkelijkeBetaaldatum=callback-date
- betalingsreferentie=callback-reference
- A notification MUST be sent to the burger: "Dwangsom van €532 is op [date] naar uw rekening overgemaakt. Referentie: [betalingsreferentie]"

---

## REQ-TERM-008: Burger-Notificatie van Termijn-Events

**Purpose**: Proactive, transparent communication at key lifecycle moments (receipt, extension, ingebrekestelling, payment).

### REQ-TERM-008-A: Receipt Confirmation with Deadline Toezegging
GIVEN an aanvraag is registered in procest
WHEN the TermijnInstance is created
THEN the burger MUST receive an ontvangstbevestiging containing:
- Case reference (zaak-ID)
- Wettelijke termijn (e.g., "8 weken volgens Wabo artikel 4")
- Berekende einddatum (e.g., "27 juli 2026")
- Expected besluit-timing (e.g., "U ontvangt uiterlijk op deze datum bericht over uw aanvraag")
- Link to portal for status tracking
- Contact info for questions
- Language: Dutch, accessible to citizens

### REQ-TERM-008-B: Ingebrekestelling Receipt Confirmation
GIVEN an ingebrekestelling is registered
WHEN the Ingebrekestelling.status=geldig
THEN burger MUST receive notification:
- Confirmation that ingebrekestelling was received on [date]
- Statement: "De bestuurlijke behandeling van uw aanvraag is nu verlopen. Twee weken na deze brief (op of voor [grace-end-date]) begint een dagelijkse dwangsom te lopen."
- Dwangsom-tariff transparency: "Vanaf [grace-end-date] is het bestuursorgaan wettelijk verplicht u dagelijks €23 te betalen (maximaal €1.442 per aanvraag)."
- Statement about beschikking termijn (e.g., "Zodra uw aanvraag is beslist, stopt de dwangsom.")

### REQ-TERM-008-C: Payment Confirmation
GIVEN a dwangsom is paid via openconnector
WHEN DwangsomUitbetaling.status="betaald"
THEN burger MUST receive notification with:
- Bedrag (€532)
- Payment date
- Payment reference
- Confirmation: "Dit bedrag is op uw rekening gestort als automatische vergoeding wegens niet-tijdig-besluiten."

---

## REQ-TERM-009: Reporting voor Management en Accountant

**Purpose**: Evidence-based KPI tracking and audit-compliant dwangsom accounting.

### REQ-TERM-009-A: Quarterly Termijn-KPI Report (per Afdeling)
GIVEN an afdelingshoofd requests the kwartaalrapport for Q2 2026
WHEN the report is generated
THEN the response MUST include per zaaktype:
- **Totaal-zaken**: 87 (e.g., "Omgevingsvergunning")
- **Percentage-binnen-termijn**: 94% (81 zaaken decided on-time)
- **Gemiddelde-doorlooptijd**: 38 dagen (from start to beschikking)
- **Aantal-verlengingen**: 12 cases extended once
- **Aantal-overschrijdingen**: 6 cases exceeded deadline
- **Aantal-ingebrekestellingen**: 4 formal notifications issued
- **Totaal-dwangsom-uitgekeerd**: €1.892 (sum of all DwangsomUitbetaling.bedrag in period)

### REQ-TERM-009-B: Annual Dwangsom Audit Report (for Jaarrekening)
GIVEN an accountant requests the jaaroverzicht for 2026
WHEN the report is generated
THEN a CSV/JSON MUST be produced listing ALL dwangsommen:
- Zaak-referentie (2026-001)
- Zaaktype (Omgevingsvergunning)
- Ingebrekestelling-datum (2026-07-15)
- Beschikking-datum (2026-07-20)
- Dwangsom-bedrag (€230)
- Betaal-datum (2026-08-12)
- Betalings-referentie (ERP-generated)
- Status (betaald, in-processing, etc.)

---

## REQ-TERM-010: Bezwaar-Handling tegen Dwangsom-Beschikking

**Purpose**: AWB 4:18 bezwaar pathway; freeze accrual and payment pending resolution.

### REQ-TERM-010-A: Bezwaar Registration and Accrual Freeze
GIVEN a DwangsomBerekening is closed (definitievBedrag=€532, status=gestopt-wegens-beschikking)
AND a DwangsomUitbetaling is prepared
AND the burger files a bezwaarschrift on 2026-07-25 against the dwangsom-hoogte
WHEN the handler registers the bezwaar
THEN:
- DwangsomBerekening MUST remain status=gestopt-wegens-beschikking (frozen, no change)
- DwangsomUitbetaling.status MUST change to "on-hold-bezwaar"
- A TermijnGebeurtenis of type "bezwaar-ingediend" MUST be recorded on DwangsomBerekening
- Payment MUST be suspended pending bezwaar resolution
- Burger MUST receive: "Uw bezwaarschrift tegen de dwangsom is ontvangen. Betaling is opgeschort tot de bezwaaruitspraak."

### REQ-TERM-010-B: Bezwaar Resolution and Amount Adjustment
GIVEN a bezwaar is upheld (gegrond) with a revised dwangsom-bedrag
WHEN the handler registers the beslissing-op-bezwaar with revised newBedrag=€400
THEN:
- DwangsomBerekening.definitievBedrag MUST be updated to €400
- DwangsomUitbetaling.bedrag MUST be updated to €400
- DwangsomUitbetaling.status MUST change back to "voorbereid"
- A new payment signal MUST be emitted via openconnector with the revised amount
- Burger MUST receive: "Uw bezwaarschrift is gegrond verklaard. De dwangsom is herzien naar €400. U ontvangt dit bedrag."

---

## Edge Cases & System Guarantees

### Case: Zaak Intrekking (Case Withdrawal)
GIVEN a zaak is withdrawn by the applicant before beschikking
WHEN the handler marks the case status as "Ingetrokken"
THEN:
- TermijnInstance.status MUST be set to "ingetrokken"
- DwangsomBerekening (if running) MUST be stopped with status="gestopt-wegens-intrekking"
- No further accrual or payment occurs
- Burger is notified of withdrawal acknowledgment but NO dwangsom is paid (case closed without besluiten)

### Case: Multiple Ingebrekestellingen on Same Termijn
GIVEN a termijn is overschreden and the burger submits ingebrekestelling #1 on date X
AND the burger submits ingebrekestelling #2 on date Y (Y > X)
WHEN both are registered
THEN:
- Ingebrekestelling #1 is marked as "the relevant" (TermijnInstance.relevantIngbrekes=ingebrekestelling-#1)
- Ingebrekestelling #2 is recorded but DOES NOT spawn a second DwangsomBerekening
- Only ingebrekestelling #1's date is used for grace-period calculation
- System logic: "Only one ingebrekestelling matters for dwangsom purposes; subsequent ones are procedural

