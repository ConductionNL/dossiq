# Design: consultation-management

## Architecture

A consultation is a first-class OpenRegister object linked to a parent zaak via a typed relation. It has an independent status lifecycle, its own deadline, and its own document attachments scoped to the consultation (not the entire parent case). Consultations can be parallel or sequential; mandatory consultations participate in milestone gates so that case progression is blocked until all required advice has been received. External advisory bodies that have no Nextcloud account interact via a per-consultation secure response link.

## Data Model

Three new schemas are added to `procest_register.json`. All use OpenRegister's built-in fields (id, uuid, uri, version, createdAt, updatedAt, owner, organization, register, schema, relations, files, auditTrail, notes, tasks, tags, status, locked) — these are NOT redefined.

### consultation

| Property | Type | Required | Description |
|---|---|---|---|
| consultationNumber | string | Yes | Auto-generated `ADV-{year}-{seq}` identifier |
| parentZaak | string | Yes | OpenRegister relation reference to the parent case |
| adviesInstantie | string | Yes | OpenRegister relation reference to the advisoryBody |
| onderwerp | string | Yes | Subject of the consultation (pre-filled from case title) |
| vraagstelling | string | Yes | Specific questions being asked (rich text) |
| uiterlijkeReactiedatum | string | Yes | Deadline for the advice response (ISO 8601 date) |
| prioriteit | string | No | Priority level: `normaal` or `spoed` |
| assignee | string | No | Nextcloud user UID of the individual handler within the consulted department |
| mandatory | boolean | No | Whether this consultation blocks case progression |
| dependsOn | string | No | JSON-encoded array of consultation IDs that must complete first |
| secureToken | string | No | 256-bit secure token for external advisory-body access (single-purpose, expires on closure) |
| extensionRequested | boolean | No | Whether a deadline extension has been requested |
| extensionJustification | string | No | Reason provided for the extension request |
| extensionApprovedBy | string | No | User UID who approved the extension |

**Status lifecycle:** `open` → `ontvangen` → `in_behandeling` → `advies_uitgebracht` → `afgesloten`, with `ingetrokken` as a coordinator-only side branch. Backward transitions are coordinator-only.

### adviceResponse

| Property | Type | Required | Description |
|---|---|---|---|
| consultation | string | Yes | OpenRegister relation reference to the parent consultation |
| advies | string | Yes | Formal advice enum: `positief`, `positief_met_voorwaarden`, `negatief`, `niet_van_toepassing` |
| toelichting | string | No | Explanation (mandatory for all values except `niet_van_toepassing`) |
| voorwaarden | string | No | JSON-encoded array of condition objects `{description, priority}` |
| datum | string | Yes | Date the advice was formally issued (ISO 8601) |

### advisoryBody

| Property | Type | Required | Description |
|---|---|---|---|
| name | string | Yes | Display name of the advisory body |
| type | string | Yes | `internal` (department within the municipality) or `external` (outside organization) |
| defaultGroup | string | No | Nextcloud group ID used for consultation assignment |
| email | string | No | Contact email address (required for external bodies) |
| specializations | string | No | JSON-encoded array of specialization tags (e.g. `["brandveiligheid","bouwconstructies"]`) |

## Components

1. **ConsultationCreateDialog.vue** — invoked from `CaseDetail.vue`; selects advisory body via specialization-weighted search, copies in case documents by reference, sets deadline default to 4 weeks.
2. **ConsultationPanel.vue** — "Adviezen" tab on case detail; renders consultation cards with progress, deadline, and advice outcomes; surfaces "2/4 adviezen ontvangen" summary. Uses `CnDetailCard` pattern.
3. **ConsultationDashboard.vue** — department-scoped inbox; filters by status, requester, deadline; supports `Oppakken` (claim) and reassignment. Uses `CnIndexPage` + `useListView`.
4. **ConsultationResponseForm.vue** — used by consulted parties; structured response with conditional `voorwaarden` editor. Uses `CnTabbedFormDialog`.
5. **ExternalConsultationResponsePage.vue** — public page accessed by secure token for external bodies; same fields minus authenticated navigation.

## Backend

- `ConsultationService` — CRUD, status machine, dependency check, mandatory-gate evaluator (queried by milestone-tracking via `getBlockingConsultations(zaakId)`).
- `ConsultationNotificationService` — emits events for create, acknowledge, deadline warnings (T-5 days), overdue (T+0), extension requests, response submitted.
- `ConsultationController` — REST under `/apps/procest/api/consultations`, plus `/apps/procest/api/public/consultations/{token}` for external bodies (annotated `#[PublicPage]` + `#[NoCSRFRequired]`).
- `AdvisoryBodyService` — registry CRUD with specialization-weighted search.
- Document linkage uses OpenRegister's `relationsPlugin`; consulted-party access is scoped to consultation-linked documents only (enforced at controller level by verifying attachment UUIDs against the consultation's `relations` list, not the parent case's).

## Integration

- **Milestone-tracking** — `ConsultationService::getBlockingConsultations(zaakId)` returns consultations with `mandatory = true` and `status != advies_uitgebracht`; milestone-tracking calls this at the decision-milestone guard.
- **Activity timeline** — `ActivityTimeline.vue` consumes consultation events from the shared Nextcloud event bus (`\OCP\EventDispatcher\IEventDispatcher`) so create, acknowledge, response, and overdue events surface on the parent case.
- **n8n** — three workflows: (1) daily deadline-monitor emitting overdue and T-5 warnings; (2) email-fanout for external advisory bodies on consultation creation; (3) bottleneck-detection generating coordinator alerts when a body's overdue rate exceeds 20%.

## Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Document-scope leakage | Consulted parties must NOT see unrelated case documents. Enforced at `ConsultationController` level: every document access request checks the attachment UUID against the consultation's `relations` list, not the parent case's files. Unit test covers the negative path. |
| Token security for external bodies | Tokens are 256-bit (random bytes via `\OCP\Security\ISecureRandom`), single-purpose, expire on consultation closure, and all access is logged (`$this->logger->info()`) for BIO compliance. Tokens are stored hashed; plaintext is emailed once. |
| Dependency cycles | `dependsOn` is validated at write time using topological sort; the service throws a `\InvalidArgumentException` on cycle detection. UI surfaces the dependency graph via a compact DAG visualization. |
| Overlap with existing `adviesAanvraag` schema | Addressed in Reuse Analysis below — the existing schema is retained for backward compatibility but is not extended to avoid breaking changes. |

## Standards

Awb 3:5-3:9, ZGW Zaken API, GEMMA adviesverzoek/adviesreactie, CMMN 1.1 CaseTask/Sentry, Common Ground "verwerken"/"notificeren", BIO access logging.

---

## Reuse Analysis

Per ADR-012, the following OpenRegister services and capabilities are reused (not reimplemented):

| Capability | OpenRegister abstraction used | What this change builds on top |
|---|---|---|
| Object CRUD | `ObjectService::saveObject()` / `deleteObject()` | `ConsultationService` delegates persistence to OR |
| Audit trail | Automatic via OR's `auditTrail` field | No custom audit table; `ConsultationController` never logs manually |
| File attachments | `filesPlugin` + `FileService` | Document linkage stored as OR relations, not custom join table |
| Relations between objects | `relationsPlugin` | `parentZaak` and `adviesInstantie` stored as OR typed relations |
| Notifications | `NotificationService` via `OCP\Notification\IManager` | `ConsultationNotificationService` wraps OR's notification primitives |
| RBAC / authorization | `AuthorizationService` + `PropertyRbacHandler` | No custom permission middleware; per-object IDOR check calls OR's permission handler |
| Search / filter | `IndexService` + `useListView` composable | `ConsultationDashboard.vue` uses `useListView` without custom query builder |
| Dashboard widgets | `CnDashboardPage` + `CnStatsBlock` + `CnTableWidget` | "Openstaande adviesaanvragen" widget uses existing widget primitives |

**Why new schemas instead of extending `adviesAanvraag`:** The existing `adviesAanvraag` schema (ADR-000) is a minimal advice-request shape (9 properties, no status machine, no advisory-body registry, no structured response). Extending it would be a breaking schema change (new required properties) per ADR-001 schema standards. Three new schemas provide a clean surface without breaking existing data.

---

## Seed Data

Per ADR-001 seed data requirements, `procest_register.json` MUST include 3–5 realistic objects per new schema. Dutch values, fictional but distinguishable.

### advisoryBody — 5 objects

```json
[
  {
    "@self": { "register": "procest", "schema": "advisoryBody", "slug": "advisory-body-brandweer-utrecht" },
    "name": "Brandweer Utrecht",
    "type": "internal",
    "defaultGroup": "brandweer",
    "email": "advies@brandweer-utrecht.nl",
    "specializations": ["brandveiligheid", "blusinstallaties", "vluchtwegen"]
  },
  {
    "@self": { "register": "procest", "schema": "advisoryBody", "slug": "advisory-body-welstand-utrecht" },
    "name": "Welstandscommissie gemeente Utrecht",
    "type": "internal",
    "defaultGroup": "welstand",
    "email": "welstand@utrecht.nl",
    "specializations": ["welstandstoets", "beeldkwaliteit", "historisch-stadsgezicht"]
  },
  {
    "@self": { "register": "procest", "schema": "advisoryBody", "slug": "advisory-body-ggd-regio-utrecht" },
    "name": "GGD Regio Utrecht",
    "type": "external",
    "defaultGroup": null,
    "email": "advies@ggdregioutrecht.nl",
    "specializations": ["gezondheidszorg", "milieugezondheidskunde", "risicobeoordeling"]
  },
  {
    "@self": { "register": "procest", "schema": "advisoryBody", "slug": "advisory-body-milieudienst-mwu" },
    "name": "Milieudienst Midden-West Utrecht",
    "type": "external",
    "defaultGroup": null,
    "email": "advies@milieudienst-mwu.nl",
    "specializations": ["milieuadvies", "bodemkwaliteit", "luchtkwaliteit", "geluidshinder"]
  },
  {
    "@self": { "register": "procest", "schema": "advisoryBody", "slug": "advisory-body-rce" },
    "name": "Rijksdienst voor het Cultureel Erfgoed",
    "type": "external",
    "defaultGroup": null,
    "email": "vergunningen@cultureelerfgoed.nl",
    "specializations": ["monumentenzorg", "cultureel-erfgoed", "rijksmonumenten"]
  }
]
```

### consultation — 4 objects

```json
[
  {
    "@self": { "register": "procest", "schema": "consultation", "slug": "consultation-adv-2026-0001" },
    "consultationNumber": "ADV-2026-0001",
    "onderwerp": "Brandveiligheidsadvies nieuwbouwwoning Vondellaan 14",
    "vraagstelling": "Voldoet het ontwerp aan de brandveiligheidseisen uit het Bouwbesluit 2012? Zijn de vluchtwegen en brandscheiding conform NEN 6068?",
    "uiterlijkeReactiedatum": "2026-06-17",
    "prioriteit": "normaal",
    "mandatory": true,
    "extensionRequested": false
  },
  {
    "@self": { "register": "procest", "schema": "consultation", "slug": "consultation-adv-2026-0002" },
    "consultationNumber": "ADV-2026-0002",
    "onderwerp": "Welstandstoets gevelwijziging Oudegracht 88",
    "vraagstelling": "Is de voorgestelde gevelwijziging (kunststof kozijnen) in overeenstemming met de welstandsnota voor het historisch stadsgezicht?",
    "uiterlijkeReactiedatum": "2026-06-03",
    "prioriteit": "normaal",
    "mandatory": true,
    "extensionRequested": false
  },
  {
    "@self": { "register": "procest", "schema": "consultation", "slug": "consultation-adv-2026-0003" },
    "consultationNumber": "ADV-2026-0003",
    "onderwerp": "Milieuadvies uitbreiding bedrijfsgebouw Leidsche Rijn",
    "vraagstelling": "Wat zijn de milieugevolgen van de uitbreiding (50% meer vloeroppervlak) op bodem, lucht en geluid? Is er een nader bodemonderzoek noodzakelijk?",
    "uiterlijkeReactiedatum": "2026-06-24",
    "prioriteit": "spoed",
    "mandatory": true,
    "extensionRequested": true,
    "extensionJustification": "Externe bodemonderzoeker heeft 2 weken extra nodig voor laboratoriumanalyse."
  },
  {
    "@self": { "register": "procest", "schema": "consultation", "slug": "consultation-adv-2026-0004" },
    "consultationNumber": "ADV-2026-0004",
    "onderwerp": "Monumentenadvies restauratie Domtoren ketelhuis",
    "vraagstelling": "Voldoet het restauratieplan aan de instandhoudingseisen voor rijksmonumenten? Zijn de gebruikte materialen passend bij de historische constructie?",
    "uiterlijkeReactiedatum": "2026-07-01",
    "prioriteit": "normaal",
    "mandatory": false,
    "extensionRequested": false
  }
]
```

### adviceResponse — 3 objects

```json
[
  {
    "@self": { "register": "procest", "schema": "adviceResponse", "slug": "advice-response-adv-2026-0001" },
    "advies": "positief_met_voorwaarden",
    "toelichting": "Het ontwerp voldoet aan de basisvereisten. De vluchtweg via de garage heeft onvoldoende vrije breedte (80 cm i.p.v. vereiste 90 cm). Bij gebruik van dubbele brandmeldinstallatie conform NEN 2535 kan dit worden gecompenseerd.",
    "voorwaarden": "[{\"description\": \"Vluchtweg via garage verbreden naar minimaal 90 cm of NEN 2535 brandmeldinstallatie installeren\", \"priority\": \"hoog\"}, {\"description\": \"Brandwerendheid scheiding woning-garage verhogen naar 60 minuten (EI 60)\", \"priority\": \"normaal\"}]",
    "datum": "2026-06-10"
  },
  {
    "@self": { "register": "procest", "schema": "adviceResponse", "slug": "advice-response-adv-2026-0002" },
    "advies": "positief",
    "toelichting": "De voorgestelde houten kozijnen in traditionele profielmaten zijn passend bij het historisch stadsgezicht en in overeenstemming met de welstandsnota sectie 4.3 (beschermd stadsgezicht). Het aanvankelijk ingediende ontwerp met kunststof kozijnen is vervangen door hout conform ons advies van 28 mei 2026.",
    "voorwaarden": "[]",
    "datum": "2026-05-30"
  },
  {
    "@self": { "register": "procest", "schema": "adviceResponse", "slug": "advice-response-adv-2026-0003" },
    "advies": "negatief",
    "toelichting": "Uit het verkennend bodemonderzoek blijkt ernstige verontreiniging (klasse 4) ter plaatse van de uitbreiding. Saneringsplicht op grond van de Wet bodembescherming. De aangevraagde omgevingsvergunning kan niet worden verleend totdat een saneringsplan is goedgekeurd door de provincie Utrecht.",
    "voorwaarden": "[]",
    "datum": "2026-06-18"
  }
]
```
