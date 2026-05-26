# Design: Mandaat-matrix voor zaak-gestuurde besluitvorming

## Architecture

The mandate-matrix is a policy-enforcement layer that sits between case decision-making and the ABAC policy engine. On every decision action, Procest checks authorization by:
1. Loading the decision-required mandate(s)
2. Checking user's current role(s) and whether they hold the mandate
3. Validating conditions (plafond, subdelegatie, belangenconflict)
4. Either approving the decision or escalating

```
Case Decision Action
├─ MandaatCheckService.isAuthorized(user, decisionType, caseProperties)
│  ├─ Load applicable Mandaat records (by decisionType, caseType)
│  ├─ Resolve user's OrganisatieRol (current, including waarnemer)
│  ├─ Query ABAC policy engine: does role have mandate + conditions satisfied?
│  └─ Return {authorized: bool, mandaatId?: UUID, escalatieId?: UUID}
├─ If authorized: Decision proceeds → MandaatGebruik log created
└─ If not: MandaatEscalatie created → escalation workflow starts
```

## Data Model

### Core Entities (OpenRegister Schemas)

**MandateringsBesluit** — Legislative decision establishing mandate(s)
- `besluitNummer` (string, required) — Formal decision number (e.g., "CR 2026-001")
- `besluitNaam` (string, required) — Title (e.g., "Algemene mandaatregeling gemeente 2026")
- `besluitOrgaan` (enum: raad | college | burgemeester | secretaris, required) — Issuing body
- `besluitDatum` (date, required) — Decision date
- `inwerkingtreding` (date, required) — Effective date (may be retroactive or future)
- `vervalDatum` (date, nullable) — Expiration date (null = indefinite)
- `vastgesteldDoor` (string, nullable) — Decidesk decision UUID (for lineage)
- `gepubliceerdIn` (string, nullable) — Publication reference (e.g., Gemeenteblad)
- `status` (enum: concept | vastgesteld | vervallen | ingetrokken, required) — Lifecycle
- `bijlageDocumentId` (string, nullable) — Nextcloud file ID of mandate table (PDF/Excel)
- Standard fields: id, uuid, uri, version, createdAt, updatedAt, owner, organization, notes, files, auditTrail, status, locked

**Mandaat** — Individual mandate within a decision
- `besluitId` (string, required) — Reference to parent MandateringsBesluit
- `mandaatNummer` (string, required) — Hierarchical ID (e.g., "M.3.1.2")
- `omschrijving` (string, required) — Description (e.g., "Verlenen omgevingsvergunning bouwactiviteit < €100.000")
- `bevoegdheidType` (enum: besluit_nemen | besluit_ondertekenen | dwangsom_opleggen | boete_opleggen | subsidie_verlenen | contract_aangaan | aanstelling_doen, required)
- `wettelijkeGrondslag` (string, required) — Legal reference (e.g., "Awb art. 10:1")
- `geldigVanaf` (date, required) — Valid from (may be retroactive)
- `geldigTotEnMet` (date, nullable) — Valid until (null = indefinite)
- `voorwaarden` (JSON, nullable) — Conditions: `{plafond_bedrag, plafond_omvang, subdelegatie_toegestaan, voorwaarde_omschrijving}`
- `subdelegatieToegestaan` (boolean, required) — Whether delegate can re-delegate
- `gemandateerdeRol` (string, required) — Reference to OrganisatieRol (who holds this mandate)
- `mandantOrgaan` (enum, required) — Which body delegates (college, burgemeester, etc.)
- Standard fields

**OrganisatieRol** — Role within the organization
- `rolNaam` (string, required) — Display name (e.g., "Hoofd Vergunningverlening")
- `rolType` (enum: bestuurder | directielid | afdelingshoofd | teamleider | senior_behandelaar | behandelaar | medewerker, required)
- `parentRolId` (string, nullable) — Hierarchical parent (e.g., "Teamleider VTH" → "Hoofd VTH")
- `afdeling` (string, nullable) — Department name
- `team` (string, nullable) — Team name
- `mandaatNiveau` (enum: hoog | middel | laag, nullable) — Quick filter for searches
- Standard fields

**MedewerkerRolToewijzing** — Person-to-role mapping with validity period
- `medewerkerId` (string, required) — Nextcloud user UID
- `rolId` (string, required) — Reference to OrganisatieRol
- `toewijzingVanaf` (date, required) — Assignment start
- `toewijzingTotEnMet` (date, nullable) — Assignment end (null = indefinite)
- `toewijzingType` (enum: primair | waarnemer | tijdelijk | interim, required)
- `toegewezenDoor` (string, nullable) — HR person who assigned role
- `toewijzingsBesluitId` (string, nullable) — Reference to HR decision (e.g., appointment document)
- Standard fields

**MandaatGebruik** — Immutable log of each decision using a mandate
- `zaakId` (string, required) — Reference to case
- `beslissingId` (string, required) — Reference to decision
- `mandaatId` (string, required) — Reference to Mandaat used
- `gemandateerdeId` (string, required) — Nextcloud user ID who decided
- `rolOpMomentVanBesluit` (JSON snapshot, required) — OrganisatieRol at time of decision (for audit)
- `beslissingType` (string, required) — Decision type (e.g., "Vergunning verlenen")
- `beslissingTimestamp` (timestamp, required) — When decision was made
- `bevoegdheidsCheckResult` (enum: bevoegd | niet_bevoegd_geescaleerd | bevoegd_via_waarnemer, required)
- `gebruikteVoorwaarden` (JSON snapshot, required) — Conditions checked (e.g., plafond_bedrag: €75K, passed: true)
- `geescaleerdNaar` (string, nullable) — Escalation recipient (user or role ID)
- Standard fields

**MandaatEscalatie** — Escalation route when authorization is insufficient
- `zaakId` (string, required) — Reference to case
- `beslissingType` (string, required) — Type of decision being escalated
- `initiatorId` (string, required) — User who triggered escalation
- `escalatieReden` (enum: niet_bevoegd | plafond_overschreden | subdelegatie_niet_toegestaan | belangenconflict, required)
- `escalatiePadEindigtBij` (string, required) — Role or user ID of authorized mandaathouder
- `status` (enum: open | goedgekeurd | afgewezen | terugverwezen, required)
- `besluitTijd` (timestamp, nullable) — When escalation was resolved
- `toelichting` (string, nullable) — Reason for approval/rejection
- Standard fields

## Seed Data

### OrganisatieRol Seed (3 hierarchies)

1. **Hoofd Vergunningverlening** (afdelingshoofd, level: hoog)
2. **Senior Vergunningverlener** (senior_behandelaar, parent: Hoofd VV, level: middel)
3. **Vergunningverlener** (behandelaar, parent: Senior VV, level: laag)

4. **Hoofd Handhaving** (afdelingshoofd, level: hoog)
5. **Handhaver** (behandelaar, parent: Hoofd Handhaving, level: middel)

6. **HR Medewerker** (medewerker, afdeling: HR)
7. **Juridisch Medewerker** (medewerker, afdeling: Juridische Zaken)

### MedewerkerRolToewijzing Seed (sample assignments)

1. Alice van Bergen (uid: alice.vandenberg) → Senior Vergunningverlener, primair, 2026-01-01 –
2. Bob Jansen (uid: bob.jansen) → Vergunningverlener, primair, 2026-01-01 –
3. Carol de Wit (uid: carol.dewit) → Hoofd Vergunningverlening, primair, 2026-01-01 –
4. Dave Peeters (uid: dave.peeters) → Handhaver, primair, 2026-01-01 – 2026-06-30
5. Eve Müller (uid: eve.mueller) → Handhaver, waarnemer (voor Dave), 2026-06-15 – 2026-06-30

### MandateringsBesluit Seed (sample legislation)

1. **CR 2026-001** — "Algemene mandaatregeling gemeente 2026"
   - Status: vastgesteld
   - Ingangsdatum: 2026-01-01
   - Vervaldatum: 2026-12-31

2. **CR 2025-099** — "Mandaatregeling 2025" (predecessor)
   - Status: vervallen
   - Vervaldatum: 2025-12-31

### Mandaat Seed (under CR 2026-001)

1. **M.3.1.1** — Verlenen omgevingsvergunning bouwactiviteit < €50.000 bouwsom
   - bevoegdheidType: besluit_nemen
   - gemandateerdeRol: Vergunningverlener
   - voorwaarden: {plafond_bedrag: 50000, subdelegatie_toegestaan: false}
   - geldigVanaf: 2026-01-01, geldigTotEnMet: 2026-12-31

2. **M.3.1.2** — Verlenen omgevingsvergunning bouwactiviteit < €100.000 bouwsom
   - bevoegdheidType: besluit_nemen
   - gemandateerdeRol: Senior Vergunningverlener
   - voorwaarden: {plafond_bedrag: 100000, subdelegatie_toegestaan: true}
   - geldigVanaf: 2026-01-01, geldigTotEnMet: 2026-12-31

3. **M.3.1.3** — Verlenen omgevingsvergunning bouwactiviteit < €500.000 bouwsom
   - bevoegdheidType: besluit_nemen
   - gemandateerdeRol: Hoofd Vergunningverlening
   - voorwaarden: {plafond_bedrag: 500000, subdelegatie_toegestaan: true}
   - geldigVanaf: 2026-01-01, geldigTotEnMet: 2026-12-31

4. **M.4.1.1** — Opleggen dwangsom (handhaving)
   - bevoegdheidType: dwangsom_opleggen
   - gemandateerdeRol: Handhaver
   - voorwaarden: {plafond_bedrag: 5000, subdelegatie_toegestaan: false}
   - geldigVanaf: 2026-01-01, geldigTotEnMet: 2026-12-31

## Service Layer

### MandaatCheckService
- `isAuthorized(userId, decisionType, caseId)` → {authorized, mandaatId?, escalatieId?, reden?}
- `resolveCurrentRole(userId)` → OrganisatieRol (including waarnemer override if active)
- `getApplicableMandaten(decisionType, caseType)` → [Mandaat]
- `evaluateConditions(mandaat, caseProperties)` → {passed, failedConditions}

### MandaatEscalatieService
- `createEscalatie(zaakId, decisionType, initiatorId, reden)` → MandaatEscalatie
- `resolveEscalatiePath(mandaatId, reden)` → {escalatiePadEindigtBij, notificationRecipients}
- `autoRerouteOnPersonnelChange(oldUserId, newUserId)` → updated escalaties

### MandaatImportService
- `importFromDecidesk(decidesk_uuid)` → creates MandateringsBesluit + Mandaat records
- `parseTable(tableBytes)` → [{mandaatNummer, omschrijving, rolNaam, plafond, ...}]

### RolToewijzingService
- `assignRole(medewerkerId, rolId, toewijzingVanaf, toewijzingType)` → MedewerkerRolToewijzing
- `endAssignment(toewijzingId, toewijzingTotEnMet)` → updated assignment
- `findWaarnemers(rolId, date)` → [MedewerkerRolToewijzing] (active substitutes)

## API Design

### Authorization Endpoints
- `POST /api/mandate/check` — Payload: {userId, decisionType, caseId, caseProperties} → {authorized, mandaatId?, escalatieId?}
- `GET /api/mandate/user/{userId}/roles` — User's current roles (including waarnemers)
- `GET /api/mandate/zaaktype/{zaaktypeId}/matrix` — Mandate matrix for a case type

### Import & Admin
- `POST /api/mandate/import-from-decidesk` — Payload: {decidesk_uuid, status?: review | vastgesteld}
- `GET /api/mandate/besluiten` — List MandateringsBesluit with versions
- `PUT /api/mandate/mandaat/{mandaatId}` — Update mandate (visibility, conditions, roles)

### Escalation
- `GET /api/mandate/escalaties` — List open escalaties (for mandaathouder inbox)
- `POST /api/mandate/escalaties/{escalatieId}/approve` — Approve escalalated decision
- `POST /api/mandate/escalaties/{escalatieId}/reject` — Reject with reason

### Audit & Analytics
- `GET /api/mandate/zaak/{zaakId}/decisions` — MandaatGebruik history (audit trail)
- `GET /api/mandate/analytics/usage` — Mandate usage statistics (frequency, by role, by decisionType)

## Integration Boundaries

- **Procest ↔ OpenRegister ABAC Policy Engine** — Mandate conditions (plafond, subdelegatie) evaluated by policy engine; Procest provides fact set
- **Procest ↔ Decidesk** — MandateringsBesluit sourced from decidesk; import via REST + document attachment
- **Procest ↔ OpenConnector** — HR sync (role assignments) via webhook on AFAS/ADP changes
- **Procest ↔ MyDash** — Mandate analytics exposed via REST for dashboards
- **Case Decision Point** — Every zaak decision action checks authorization before proceeding

## Standards Alignment

- **Awb artikel 10:1 t/m 10:12** — Mandate, delegation, authority rules
- **Gemeentewet** — College and burgemeester decision authority
- **VNG Model Mandaatbesluiten** — Templates and best practices
- **GEMMA Procesarchitectuur** — Case and decision workflows
- **ISO 27001 A.9** — Access control and authorization audit
- **NEN 7510** — Healthcare/sensitive data logging (if applicable)
