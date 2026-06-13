# Mijn gemeente Design

## Architecture

Zaakportaal is a read-mostly citizen-facing app (separate Nextcloud instance or deployment) that queries Procest and OpenRegister via authenticated REST APIs. It holds no persistent data of its own — all case, document, and user information is retrieved on-demand from source systems. Session state is stored in the browser (JWT tokens, bound to IP + user-agent), and all access is logged to OpenRegister audit trails.

The app is strictly consumer, not producer: it reads cases, documents, statuses, and messages; it writes only messages, complaint objects, and bezwaar/klacht submissions back to Procest via restricted webhook endpoints.

## Data Model

### PortaalGebruiker (Session State)
A transient session object, not persisted in portal database:
```json
{
  "sessieId": "sess-2026-77234abc",
  "ingelogdSinds": "2026-04-15T10:22:00+02:00",
  "authenticatieMethode": "digid | eherkenning",
  "betrouwbaarheidsniveau": "substantieel",
  "ingelogdAls": {
    "type": "burger | bedrijf | gemachtigde",
    "bsn": "123456789",  // for burger or gemachtigde
    "kvkNummer": "12345678",  // for bedrijf
    "naam": "M.A. Janssen-de Vries"
  },
  "machtiging": {
    "voorBsn": "123456789",
    "machtigingsType": "wettelijk-vertegenwoordiger | professionele-bewindvoerder | mantelzorger",
    "geldig_tot": "2027-04-15"
  },
  "sessieVerloopt": "2026-04-15T10:37:00+02:00"
}
```

### ZaakOverzichtItem (List View)
Retrieved from Procest API; filters by BSN or KvK match:
```json
{
  "zaakId": "zaak-2026-vth-09128",
  "zaakKenmerk": "Z/2026/09128",
  "zaaktype": "omgevingsvergunning-aanvraag",
  "onderwerp": "Bouw uitbouw achterzijde woning Plataanlaan 14",
  "status": "vergunning-verleend",
  "ingediendOp": "2026-01-12",
  "actie": null,  // "bezwaar-mogelijk", "klacht-mogelijk", "betaling-vereist"
  "termijnen": {
    "afhandelTermijnEinde": "2026-04-15",
    "termijnOverschreden": false,
    "dagenResterend": 0
  },
  "documentAantal": 6,
  "ongelezenBerichten": 0
}
```

### ZaakDetail (Detail View)
Full case data with timeline, messages, and action states:
```json
{
  "zaakId": "zaak-2026-vth-09128",
  "zaakKenmerk": "Z/2026/09128",
  "zaaktype": {
    "code": "omgevingsvergunning-aanvraag",
    "naam": "Omgevingsvergunning aanvraag",
    "wetgevingsbasis": "Omgevingswet artikel 5.1"
  },
  "onderwerp": "Bouw uitbouw achterzijde woning Plataanlaan 14",
  "huidigeStatus": "vergunning-verleend",
  "tijdlijn": [
    {"datum": "2026-01-12", "status": "ingediend", "toelichting": "Aanvraag ontvangen", "actor": "burger"},
    {"datum": "2026-01-15", "status": "ontvankelijkheid-getoetst", "toelichting": "Aanvraag is volledig", "actor": "gemeente"},
    {"datum": "2026-02-08", "status": "inhoudelijk-getoetst", "toelichting": "Toets ruimtelijke kwaliteit positief", "actor": "gemeente"},
    {"datum": "2026-04-02", "status": "vergunning-verleend", "toelichting": "Besluit genomen", "actor": "gemeente"}
  ],
  "termijnen": {
    "afhandelTermijnWettelijk": "8 weken",
    "afhandelTermijnEinde": "2026-04-15",
    "termijnOverschreden": false
  },
  "behandelaar": {
    "naam": "K. Bakker",
    "afdeling": "VTH",
    "bereikbaar": "ma-do 9-17"
  },
  "documenten": [
    {"id": "doc-9128-01", "naam": "Aanvraagformulier.pdf", "soort": "aanvraag", "datum": "2026-01-12", "downloadbaarVoor": ["aanvrager"]}
  ],
  "berichten": [],
  "mogelijkeActies": ["bericht-sturen", "bezwaar-indienen", "klacht-indienen"]
}
```

### PortaalBericht
Message from citizen to case handler, stored in Procest:
```json
{
  "id": "msg-2026-77123",
  "zaakId": "zaak-2026-vth-09128",
  "verzender": {"type": "burger", "bsn": "123456789", "naam": "M.A. Janssen-de Vries"},
  "ontvanger": {"type": "medewerker", "medewerkerId": "behandelaar-vth-44"},
  "onderwerp": "Vraag over voorwaarde 3 in beschikking",
  "inhoud": "Geachte mevrouw Bakker, ...",
  "bijlagen": [],
  "verzondenOp": "2026-04-10T14:33:00+02:00"
}
```

### PortaalVerzoek (Bezwaar / Klacht / Subsidie)
Intake form submission, creates new case in Procest:
```json
{
  "id": "verz-2026-09128-bezw-01",
  "soort": "bezwaarschrift | klachtschrift | subsidie-aanvraag",
  "tegenZaakId": "zaak-2026-vth-09128",
  "indiener": {"type": "burger", "bsn": "123456789"},
  "onderwerp": "Bezwaar tegen omgevingsvergunning Z/2026/09128",
  "motivering": "Ik ben het niet eens met voorwaarde 3 omdat ...",
  "bijlagen": ["doc-id-1"],
  "ingediendOp": "2026-04-12T10:15:00+02:00",
  "binnenTermijn": true,
  "nieuweZaakId": "zaak-2026-bezw-04711"
}
```

### PortaalNotificatieVoorkeur
Persistent record (in Procest) of citizen's notification preferences:
```json
{
  "id": "pref-bsn-123456789",
  "bsn": "123456789",
  "kanalen": {
    "email": {"actief": true, "adres": "marja@example.nl", "geverifieerd": true},
    "berichtenbox": {"actief": true},
    "sms": {"actief": false}
  },
  "gebeurtenissen": {
    "statuswijziging": true,
    "documentToegevoegd": true,
    "berichtVanBehandelaar": true,
    "termijnHerinnering": true
  }
}
```

## Components

### Frontend (Vue)

1. **AuthLayout.vue** — Wraps all pages; manages session state (JWT + refresh), detects IP/user-agent mismatch, redirects to login on expiry.
2. **LoginPage.vue** — DigiD/eHerkenning selection; SSO redirect to OpenConnector; handles post-auth callback and session creation.
3. **CaseOverview.vue** — Lists all citizen's cases with filters (status, zaaktype, datum); uses virtual scrolling for large lists; integrates accessibility (table with sortable headers).
4. **CaseDetail.vue** — Full case page with tabs: Status (timeline), Documenten (download list), Berichten (messaging thread), Acties (bezwaar/klacht buttons).
5. **StatusTimeline.vue** — SVG + div timeline visualization with status circles, dates, descriptions; includes progress bar showing deadline progression; accessible via table fallback.
6. **DocumentList.vue** — Sortable document table; download button for each; tracks viewing in audit log.
7. **MessagingWidget.vue** — Message thread with history; compose area with file upload; displays sender name and timestamp.
8. **BezwaarForm.vue** — Intake form for objections; checks deadline validity; auto-populates tegenZaakId; submit validation.
9. **KlachtForm.vue** — Intake form for complaints; category dropdown; includes phoned/in-person intake option link.
10. **SubsidieForm.vue** — Simple list of available subsidies (from opencatalogi); link to external application system or embedded form.
11. **NotificationSettings.vue** — Manages PortaalNotificatieVoorkeur; toggle kanalen and gebeurtenissen; email verification flow.
12. **LoadingSpinner.vue** & **ErrorBoundary.vue** — Standard UX patterns.

### Backend (OpenConnector + Procest API Layer)

1. **AuthService** — Handles DigiD/eHerkenning OIDC/SAML flow; manages session tokens; enforces IP+user-agent binding.
2. **CaseService** — Queries Procest for cases filtered by BSN/KvK; retrieves statuses, deadlines, decisions.
3. **DocumentService** — Retrieves citizen-accessible documents from OpenRegister; enforces downloadbaarVoor ACL; logs each access.
4. **MessageService** — Reads/writes PortaalBericht to Procest; creates notifications in n8n queue.
5. **ObjectionService** — Validates bezwaar deadline; creates PortaalVerzoek in Procest; triggers new bezwaarzaak workflow.
6. **ComplaintService** — Creates klachtschrift; routes to Procest klachtafhandeling module.
7. **NotificationPreferenceService** — Reads/writes PortaalNotificatieVoorkeur; queues preference changes to n8n.
8. **AuditLogger** — Logs all citizen actions (login, document download, message send, form submit) to OpenRegister.

### Integration Points

- **Procest:** Case data, documents metadata, deadlines, treatment handler info, messaging endpoints.
- **OpenConnector:** DigiD/eHerkenning authentication, DigiD Machtigen/eHerkenning Ketenmachtiging delegation.
- **OpenRegister:** Document access rights, audit trail logging.
- **Docudesk:** Email templates for notifications, intake letter generation.
- **Berichtenbox (MijnOverheid):** Official delivery channel for legal notifications.
- **n8n:** Message queueing, notification templating and fan-out to email/Berichtenbox.

## Seed Data

### Example Citizens & Cases

**Citizen 1: Marja Janssen-de Vries (BSN 123456789)**
- Case 1: Omgevingsvergunning Z/2026/09128 (Bouw uitbouw Plataanlaan 14)
  - Status: Vergunning verleend (2026-04-02)
  - Documents: Aanvraagformulier, Bouwtekening, Beschikking
  - Possible actions: Bezwaar indienen (termijn tot 2026-05-02)

**Citizen 2: Pieter de Jong (BSN 234567890)**
- Case 1: Subsidie Woningverbetering 2026 (Dakisolatie)
  - Status: Ingediend (2026-03-15)
  - Documents: Aanvraagformulier, Offerte elektricien
  - Possible actions: Geen

**Business: Janssen & Partners B.V. (KvK 12345678)**
- Via eHerkenning login as "J. Janssen" (bestuurder)
- Case 1: Horeca-vergunning (Café Plein) (Z/2026/04499)
  - Status: In behandeling (issued 2026-02-01, 8-week deadline 2026-03-29)
  - Documents: Aanvraag, Veiligheidsplan
  - Possible actions: Bericht sturen

## Design Principles

1. **No funnel:** UI is task-focused, sober. No marketing, cross-sell, or targeting — citizens come to act, not to browse.
2. **Privacy by design:** Session data held minimally; no shadow administration; all writes logged.
3. **Read-only source of truth:** Citizens cannot edit cases, only query them and submit bounded requests.
4. **Accessibility first:** WCAG 2.2 AA throughout; keyboard navigation, screen-reader support, sufficient color contrast.
5. **Short session TTL:** 15 minutes inactivity; tokens re-issued on refresh; old tokens invalidated.

## Standards & Compliance

- **Wet Digitale Overheid (Wdo):** DigiD/eHerkenning authentication at "substantieel" level.
- **WCAG 2.2 AA:** Web accessibility.
- **NL Design System:** Visual consistency, component reuse.
- **OWASP ASVS 4.0 Level 2:** Baseline security controls.
- **AVG/GDPR:** Data minimization, audit trails, privacy notices.
- **Awb:** Timely access to case info, right to know decision-making status.

## Risks & Mitigations

- **Session hijacking:** Bind tokens to IP + user-agent; short TTL (15 min); enforce HTTPS + Secure + HttpOnly cookies.
- **Document access escalation:** Enforce downloadbaarVoor ACL from Procest on every download; log all attempts.
- **Stale data:** No caching of case/document metadata; always fetch fresh from source systems.
- **Privacy leaks:** Anonymize treatment handler info where possible (show only first name + department); audit all data reads.
- **Bezwaar deadline miscalculation:** Use centralized Awb deadline helper from Procest; test edge cases (weekends, holidays).
