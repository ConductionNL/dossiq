# Specs: kcc-werkplek-zaaksysteem-bridge

## Overview

Detailed requirements for KCC-werkplek zaaksysteem integration, covering burger identification, case-voorblad auto-display, quick-actions, sentiment monitoring, belplan-routering, warm doorverbinding, and geconsolideerde klantreis.

---

## REQ-KCC-001: Automatisch zaak-voorblad bij inkomende telefoon

**Purpose**: Display case context instantly when a burger calls.

### REQ-KCC-001-A: Case-voorblad Auto-Open
GIVEN a KCC-medewerker with the KCC-werkplek open
AND a configured belplan that links inbound calls to Procest
WHEN a burger calls from a telefoonnummer in the Burger register (via `bekendeIdentificaties`)
THEN system opens within 2 seconds a zaak-voorblad in the werkplek with:
- Burger NAW (naam, adres, telefoonnummer, email)
- Active zaken (max 10, with `title`, `status`, `lastActionDate`)
- Recente contactmomenten (max 5, with `kanaal`, `duur`, `samenvatting`, `datum`)
- Openstaande facturen (from leges-heffingen API, if any)
- Suggested dialogue topic (e.g., "Heeft 3 dagen geleden omgevingsvergunning ingediend — waarschijnlijk statusvraag")

### REQ-KCC-001-B: Phone Number Lookup Fallback
GIVEN a burger calls from an unregistered phone number
WHEN the system cannot match the phone to a known Burger
THEN the case-voorblad opens in "unidentified" mode:
- Shows only openbare zaaksinformatie (no personal contact details)
- Displays "Burger niet herkend — start identificatie?" prompt
- KCC-medewerker can initiate identificatievragen flow manually

---

## REQ-KCC-002: DigiD-authenticatie voor portaal/chat-contacten

**Purpose**: Link portaal/chat contacts to Burger via DigiD, capturing the authentication for compliance.

### REQ-KCC-002-A: DigiD Auth Flow
GIVEN a burger who opens chat or authenticates via webformulier on the gemeentelijke portaal
WHEN DigiD authentication succeeds via openconnector
THEN system:
- Validates DigiD response (signature, nonce)
- Extracts BSN from authenticated credential
- Looks up or creates Burger record with `bsn` (encrypted), `naam`, `adres` from DigiD assertion
- Tags Contactmoment with `identificatieMethode: digid`
- Logs DigiD authentication event for AVG compliance (which PII was processed under DigiD delegation)
- Returns case-voorblad with full zaak access (not restricted to openbare zaaksinfo)

### REQ-KCC-002-B: Failed DigiD Auth Fallback
GIVEN DigiD authentication fails or is cancelled by user
WHEN the contact continues (e.g., chat without auth)
THEN contactmoment is tagged `identificatieMethode: niet_geidentificeerd` and case-voorblad shows only openbare zaaksinfo

---

## REQ-KCC-003: Identificatie-vragen bij telefonisch contact zonder DigiD

**Purpose**: Establish identity when phone caller is unknown; support high-assurance identification for sensitive zaken.

### REQ-KCC-003-A: Guided Identification Flow
GIVEN a telefonisch contact where:
- The phone number is not in `bekendeIdentificaties`, OR
- High assurance of identity is required (e.g., requesting status of omgevingsvergunning detail)
WHEN the KCC-medewerker starts the identificatie-flow via quick-action or button
THEN system displays a guided questionnaire:
1. Naam (open text)
2. Geboortedatum (DD-MM-YYYY picker)
3. Huisadres (open text with postcode autocomplete)
4. BSN bevestiging (masked: "Ter bevestiging, uw BSN eindigt op: __?")
5. Out-of-wallet question if configured (e.g., "Wat was uw laatste aanvraag?")

### REQ-KCC-003-B: Identification Scoring
GIVEN all questions are answered
WHEN the system compares answers to Burger records or BRP lookup
THEN:
- Match score is calculated (0.0–1.0) based on field matches (naam, geboortedatum, adres, BSN, out-of-wallet)
- Score >= configured threshold (default 0.8) → Burger linked; full zaaksinfo available
- Score < threshold → No link; "Identificatie onzeker (score {{score}}) — toon alleen openbare zaaksinfo"
- Score very low (< 0.5) → Create placeholder Burger record as "unverified"; log event for manual review

### REQ-KCC-003-C: Verification Logging
GIVEN identification succeeds or fails
WHEN the KCC-medewerker confirms or rejects the identification
THEN contactmoment records:
- `identificatieMethode: identificatievragen`
- `identificatieScore: {{score}}`
- `geidentificeerdeBurgerId: {{burgerId}}` (or null if unverified)
- Audit log: "User [name] verified burger [burgerId] with score [score]; method: identificatievragen"

---

## REQ-KCC-004: Quick-action "Status terugkoppelen" in één klik

**Purpose**: Deliver case status to burger in seconds without system switching.

### REQ-KCC-004-A: Quick-Action Invocation
GIVEN an identified burger with one or more open zaken
AND quick-action "Status terugkoppelen" is configured
WHEN KCC-medewerker selects a zaak and clicks "Status terugkoppelen"
THEN system generates status text using `emailTemplate` for the case type:
- Example for omgevingsvergunning: "Uw aanvraag omgevingsvergunning Z2026-00547 is op 12 mei door de vergunningverlener ontvangen en wordt nu beoordeeld. Verwachte beschikkingsdatum: 15 juni 2026."
- Template pulls from case `status`, `deadline`, `lastActionDate`, `assignee`, etc.
- If deadline has passed: "Uw zaak is in behandeling; vervolgstap volgt spoedig."
- KCC-medewerker reviews text and can edit before sending

### REQ-KCC-004-B: Confirmation & Logging
GIVEN the medewerker confirms the status text
WHEN button "Bevestig — status is gegeven" is clicked
THEN system:
- Records outgoing `contactmoment` with `kanaal: telefoon`, `richting: uitgaand`, `samenvatting: {{status_text}}`
- Appends activity entry to case: `{type: "status_given", timestamp, medewerker, text: {{status_text}}}`
- Sets `firstTimeFix: true` if this resolves the caller's query (medewerker checkbox)

---

## REQ-KCC-005: Nieuwe zaak openen vanuit KCC-werkplek

**Purpose**: Create new case during call without interrupting call flow.

### REQ-KCC-005-A: Intake Form Auto-Prefill
GIVEN a telefonisch contact where burger reports new issue (e.g., "kapotte lantaarnpaal melden")
WHEN KCC-medewerker clicks quick-action "Nieuwe zaak" with `targetZaaktype: melding_openbare_ruimte`
THEN system opens minimalist intake form with:
- Burger NAW prefilled from identified Burger record (naam, adres, telefoonnummer, email)
- Zaaktype field pre-selected to "Melding openbare ruimte"
- Location picker (address → BAG autocomplete or manual pin on map)
- Defect type dropdown (kuil, lantaarnpaal, boom, ander)
- Urgency selector (laag, normaal, hoog)
- Description textbox (required; medewerker types during call)

### REQ-KCC-005-B: Case Creation & Notification
GIVEN all required fields are filled
WHEN KCC-medewerker clicks "Zaak aanmaken"
THEN system:
- Creates new case with:
  - `zaaktype: melding_openbare_ruimte`
  - `initiator: {{geidentificeerdeBurgerId}}`
  - `startDate: now()`
  - `deadline: calculated per zaaktype (e.g., P7D for openbare-ruimte meldingen)`
  - `sourceChannel: kcc_telefoon`
  - `assignee: team_openbare_ruimte` (via routing rules)
- Returns new case ID (e.g., "MLD-2026-001234") to medewerker
- Medewerker reads ID aloud to burger: "Uw meldingnummer is MLD-2026-001234"
- Creates linkage to contactmoment: `nieuweZaakIds: ["MLD-2026-001234"]`

### REQ-KCC-005-C: Audit Trail
GIVEN the case is created
THEN contactmoment records:
- `aard: nieuwe_aanvraag`
- `nieuweZaakIds: ["MLD-2026-001234"]`
- `firstTimeFix: true` (the issue was resolved immediately in one contact)

---

## REQ-KCC-006: Klacht registreren als apart zaaktype

**Purpose**: Auto-escalate complaints with sentiment flag; ensure compliant klachtenregeling processing.

### REQ-KCC-006-A: Complaint Registration Trigger
GIVEN an unsatisfied caller
AND `klantSentiment.sentimentLabel == "negatief"` or `"boos"`
WHEN KCC-medewerker clicks quick-action "Klacht registreren"
THEN system opens minimal form:
- Related case ID(s) (if complaint is about existing zaak; auto-filled if selected case triggered sentiment)
- Complaint reason (text, required)
- Severity selector (minor, serious, critical)
- Medewerker can add internal notes

### REQ-KCC-006-B: Klacht Case Creation & Routing
GIVEN complaint form is submitted
WHEN system creates new case
THEN:
- New case created with `zaaktype: klacht_ex_artikel_9_1_awb`
- `title: "Klacht op {{relatedCaseId}} — {{reason}}"`
- `deadline: P42D` (6 weeks per klachtenregeling; Awb art. 6:7)
- Linked to referenced case via `relatedCases` array
- Assigned role: `klachtenfunctionaris` (via mandaat-matrix)
- Status: "Klacht ontvangen"
- Activity entry: `{type: "complaint_created", contactmoment: {{id}}, sentiment: {{sentiment_label}}}`

### REQ-KCC-006-C: Notification & SLA Tracking
GIVEN klacht case is created
THEN system:
- Sends notification to klachtenfunctionaris: "Nieuwe klacht ontvangen op zaak {{relatedCaseId}}: {{reason}}"
- Triggers automatic ontvangstbevestiging brief via docudesk (printed or emailed)
- Sets SLA tracker: 6-week deadline with warnings at 4 weeks, 2 weeks, 1 week remaining
- Logs to mydash KCC dashboard: "Klacht registratie (+1 complaint; average satisfaction trending down)"

---

## REQ-KCC-007: Warm doorverbinden met context-overdracht naar specialist

**Purpose**: Transfer complex cases to back-office specialist with full context visible to both parties.

### REQ-KCC-007-A: Specialist Availability Check
GIVEN a KCC-medewerker handling a complex question about omgevingsvergunning
WHEN medewerker clicks quick-action "Doorverbinden"
THEN system displays:
- Vaardigheid selector (dropdown: "Omgevingsvergunning", "Bouwtoezicht", etc., or "Generalist")
- Available specialists list showing:
  - Medewerker naam
  - Current status (beschikbaar/in_gesprek/wrap_up/afwezig)
  - Wachtrij lengte (number of queued calls)
  - Gemiddelde behandeltijd (e.g., "avg 12 min")
  - Estimated wait time for caller

### REQ-KCC-007-B: Transfer Initiation & Context Snapshot
GIVEN medewerker selects a specialist
WHEN medewerker clicks "Doorverbinden naar {{specialist_naam}}"
THEN system:
- Creates `doorverbinding` record with immutable context snapshot:
  - Burger NAW
  - Gerelateerde zaak(en) with status + last action
  - Contact summary so far ("Burger vraagt naar omgevingsvergunning Z2026-00547; gaat de beschikking volgende maand af?")
  - `klantSentiment` (score + label; escalatie-flag)
  - Quick-action history from this contact
- Initiates warm transfer in pipelinq (phone-level transfer with screen-pop)
- Sets `doorverbinding.status: "pending_acceptance"`

### REQ-KCC-007-C: Specialist Context Display & Acceptance
GIVEN the transfer is initiated
WHEN specialist's phone rings with screen-pop
THEN specialist's KCC-werkplek displays:
- Pop-up with context snapshot:
  - Burger: {{naam}}, {{telefoonnummer}}
  - Zaak(en): {{title}}, {{status}}, {{deadline}}
  - Summary: "Statusvraag omgevingsvergunning; negatief sentiment gedetecteerd"
  - Quick-action suggestions for this caller/zaak combo
- Specialist can:
  - Click "Accept" → `doorverbinding.geaccepteerd: true`, call transfers, context stays on specialist's screen
  - Click "Decline with reason" (bv. "Ik ben in gesprek; queue me") → call goes to voicemail/wachtrij; callback scheduled

### REQ-KCC-007-D: Context Trail Preservation
GIVEN specialist accepts and converses with caller
WHEN call ends
THEN:
- Specialist can append final notes to `doorverbinding.contextOverdracht` (append-only)
- New `contactmoment` is created for specialist with `aard: doorverbinding_inkomend`, linked to the doorverbinding record
- Both medewerker's and specialist's contactmomenten are linked to the same Burger + zaak(en)
- Case activity timeline shows full contact flow: "KCC medewerker [name] → Specialist [name]"

---

## REQ-KCC-008: Datagedreven belplan-routering

**Purpose**: Automatically route callers to the right specialist based on case type, skill, and availability.

### REQ-KCC-008-A: Belplan Configuration
GIVEN an administrator configures Belplan for "Algemeen gemeentenummer +31-12-3456789"
WHEN belplan is saved
THEN belplan specifies:
- `routeringStappen: [{type: "keuzemenu", options: ["Omgevingsvergunningen", "Bouwtoezicht", "Infocentrum", "Overig"]}]`
- For each menu option, a `zaaktype_to_vaardigheid` mapping:
  - "Omgevingsvergunningen" → vaardigheid "omgevingsvergunning"
  - "Bouwtoezicht" → vaardigheid "bouwtoezicht"
- Overflow rules: "If wachttijd > 3 min for specialized queue, route to generalist with escalatie-flag"

### REQ-KCC-008-B: Outbound Routing at Call Arrival
GIVEN a burger calls the algemeen nummer
AND belplan is active
WHEN call arrives at PBX
THEN system (via openconnector):
1. Plays keuzemenu: "Druk 1 voor omgevingsvergunningen, 2 voor bouwtoezicht, 3 voor infocentrum"
2. Caller presses "1" (omgevingsvergunning)
3. System looks up `specialistBeschikbaarheid` for vaardigheid "omgevingsvergunning"
4. Queries real-time beschikbaarheid (via API poll every 30s or webhook push):
   - Specialist A (omgevingsvergunning): beschikbaar, wachtrij=0
   - Specialist B (omgevingsvergunning): in_gesprek, wachtrij=2
   - Generalist C: beschikbaar, wachtrij=5
5. Routes call to Specialist A (beschikbaar, shortest queue)
6. If all specialists busy AND wachttijd > 3 min: routes to Generalist C with escalatie-flag + context (caller selected omgevingsvergunning vaardigheid)

### REQ-KCC-008-C: Escalation Flag in KCC-Werkplek
GIVEN a generalist receives a call with escalatie-flag from overflow
WHEN the call arrives at generalist's station
THEN generalist's screen shows:
- Badge: "Escalatie vanuit menu-routering (Omgevingsvergunning aanvraagd)"
- Suggestion: "Specialist is nog bezet, maar beschikbaar over ~5 min — warm doorverbinden in aanbouw?"
- Generalist can provide basic info or warm transfer to specialist when available

---

## REQ-KCC-009: Contactmoment-historie als geconsolideerde klantreis

**Purpose**: Display all contact across channels in one unified timeline for KCC staff and case handlers.

### REQ-KCC-009-A: Klantreis Timeline View
GIVEN a burger has contacted the gemeente multiple times:
- 3 phone calls (statusvragen, melding indiening)
- 2 emails (aanvullende info, vraag vervolgstap)
- 1 portal login (DigiD-authenticated chat)
WHEN KCC-medewerker or case handler opens the burger's profiel
THEN system displays "Klantreis" timeline (chronological, oldest first):
- **2026-05-15 10:30 — Telefoon (KCC: Jan Jansen)**
  - Aard: statusverzoek
  - Duration: 8 min
  - Summary: "Vraag naar omgevingsvergunning Z2026-00547; status gegeven"
  - Zaak: Z2026-00547 (status: In behandeling)
- **2026-05-16 14:00 — Email (Inkomend)**
  - Aard: informatieverzoek
  - Subject: "Vervolgstap omgevingsvergunning?"
  - Zaak: Z2026-00547 (handled by: Sarah de Vries)
- **2026-05-17 11:15 — Telefoon (KCC: Eva Müller)**
  - Aard: melding
  - Duration: 5 min
  - Summary: "Melding kapotte lantaarnpaal aangegeven"
  - Zaak: MLD-2026-001234 (created during this contact)
- **2026-05-18 16:45 — Portaal Chat (DigiD)**
  - Aard: statusverzoek
  - Duration: 3 min
  - Summary: "Vraag naar melding MLD-2026-001234; geautomatiseerd antwoord gegeven"

### REQ-KCC-009-B: Aggregation & Drill-Down
GIVEN the timeline is displayed
WHEN user hovers over "2026-05-15 Telefoon" entry
THEN system shows:
- **Aggregatie-kaarten** (insights):
  - "3 statusverzoeken in 4 dagen → Verbeterpunt: proactief status communiceren?"
  - "Gemiddelde contactduur: 5 minuten"
  - "Meest gestelde vraag: Wanneer beschikking omgevingsvergunning?"
  - "Sentiment trend: Neutraal → Neutraal → Positief (melding opgelost snel)"

WHEN user clicks "Details weergeven" on a specific contact
THEN drill-down shows:
- Full contact transcript (if available)
- Linked zaak(en) at time of contact (state snapshot)
- Medewerker notes
- Sentiment details (trigger words, score progression)
- Any quick-actions executed in that contact

---

## REQ-KCC-010: Realtime sentiment-detectie en escalatie-aanbeveling

**Purpose**: Flag negative emotions in real-time; suggest de-escalation quick-actions.

### REQ-KCC-010-A: Trigger-Word Detection
GIVEN an active telefoongesprek or chat conversation
WHEN system analyzes text (from transcription or manual chat)
THEN system detects Dutch trigger words and phrases:
- **Strong negative**: "ongelooflijk", "klacht", "zwak", "onprofessioneel"
- **Authority/escalation**: "wethouder", "advocaat", "media", "rechtszaak"
- **Emotional intensity**: prolonged use of exclamation marks, ALL CAPS words, rapid question marks
- System updates `klantSentiment.triggerWoorden: ["klacht", "advocaat"]` in real-time

### REQ-KCC-010-B: Sentiment Scoring & Escalation Display
GIVEN trigger words are detected
WHEN system calculates sentiment score and label
THEN:
- Score algorithm: baseline 0.0 (neutral) + word weights (e.g., "klacht": -0.3, "advocaat": -0.2, "ongelooflijk": ±0.2 context-dependent)
- Final score: -0.5 (negative)
- Label: "negatief"
- Escalatie-aanbevolen: **true** (if score <= -0.5 OR trigger contains "klacht", "advocaat", "media", "rechtszaak")

WHEN escalatie-aanbevolen is **true**
THEN KCC-medewerker's screen shows:
- **Unobtrusive notification**: Low-key banner: "⚠️ Sentiment: negatief — overweeg klacht registreren of warm doorverbinden naar manager?"
- **Suggested quick-actions popup**: 
  - "Klacht registreren" button (red)
  - "Doorverbinden naar manager dienstverlening" button (orange)
  - "Dismiss this suggestion" (if false positive)

### REQ-KCC-010-C: Sentiment Logging & Coaching
GIVEN the call ends
WHEN contactmoment is closed
THEN system:
- Records `klantSentiment` with full details: `sentimentScore`, `sentimentLabel`, `triggerWoorden`, `escalatieAanbevolen`, `transcriptieSnippet` (quote with highlighted words)
- Posts sentiment event to case activity: `{type: "sentiment_detected", label: "negatief", triggers: ["klacht", "advocaat"], recommendation: "escalatie"}`
- Stores in analytics for:
  - Manager coaching dashboard (sentiment trends per medewerker, per zaaktype)
  - KCC performance metrics: "Escalations caught by sentiment flag: 24 this month (+12% vs last month)"
  - Trend alerts: "Sentiment for omgevingsvergunning zaken trending negative (3-week avg: -0.3) — investigate"

### REQ-KCC-010-D: False Positive Handling
GIVEN a false positive trigger (e.g., "Wat een ongelooflijk snelle service! Excellent!")
WHEN medewerker clicks "Dismiss — sentiment was incorrect; feedback noted"
THEN:
- Trigger word is not removed from logs
- Feedback is recorded: `{medewerker: [name], feedback: "false_positive", reason: "positive context"}`
- Over time, system learns multi-word context to reduce false positives

---

## REQ-KCC-011: Standards & Compliance

### REQ-KCC-011-A: GEMMA KCC Architecture Alignment
GIVEN the KCC integration is deployed
THEN system aligns with:
- GEMMA KCC reference architecture (multikanaal, unified contact center best practices)
- NORA-principles for omnichannel service (klantreis, channels are interchangeable)

### REQ-KCC-011-B: Legal Compliance
GIVEN personal data processing (PII capture, sentiment logging)
THEN system implements:
- **AVG/GDPR**: Personal data minimization; retention per zaak archival rules; subject access on request; encryption at-rest + TLS in-transit
- **Klachtbehandeling**: Klacht zaaktype enforces Awb art. 9:1 procedures (6-week SLA, advisory committee hearing if requested, rechtsmiddelenclausule in decision)
- **NEN 7510** (healthcare) or **ISO 18295** (customer contact center): audit logs, quality monitoring, accessibility

### REQ-KCC-011-C: Integration Standards
GIVEN cross-system data exchange
THEN system uses:
- **OpenRegister API** for all zaak/burger/document CRUD (not direct DB access)
- **OpenConnector** for DigiD/BRP integration (ZGW-compatible, mTLS)
- **RFC 2822** email headers for threading (Message-ID, In-Reply-To)
- **SIP/SIPS** for telefonie transfer (via pipelinq integration)

---
