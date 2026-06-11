# Design: avg-verwerkingenlogging

## Architecture

Three concerns, deliberately separated:

1. **Register of processing activities** (`processingActivity`) — slow-moving admin-managed reference data: what the municipality processes, why (doel), on which legal basis (AVG-rechtsgrond), who receives it, how long it is kept. Maps to the VNG "verwerkingsactiviteiten" notion.
2. **Per-access log** (`processingLogEntry`) — high-volume append-only event stream. One entry per processing action (including reads), referencing a processing activity. Modeled on the VNG Logging Verwerkingen API resource shape (verwerking/handeling/actie hierarchy flattened pragmatically to one entry with `actie` + `handelingNaam` + `verwerkingsactiviteitId`).
3. **Consumption** — FG inquiry UI + export, and the VNG-shaped REST API for external tooling.

The OR object audit trail is untouched and remains the "what changed" record; verwerkingenlogging is the "personal data was processed under justification X" record. They intersect (a case update produces both) but neither replaces the other — notably, **reads produce only a verwerkingslog entry**.

## Data Model

**processingActivity** (verwerkingsactiviteit)
| field | type | notes |
|---|---|---|
| `name` | string | e.g. "Behandelen omgevingsvergunning" |
| `purpose` | string | doel van de verwerking |
| `legalBasis` | enum | AVG art. 6 grondslag: `consent`, `contract`, `legalObligation`, `vitalInterest`, `publicTask`, `legitimateInterest` |
| `legalBasisReference` | string | wettelijke grondslag (e.g. "Omgevingswet art. 5.1") |
| `dataSubjectCategories` | array | categorieën betrokkenen |
| `recipients` | array | (categorieën) ontvangers |
| `retentionPeriod` | string | ISO 8601 duration for the *processed data* |
| `confidential` | bool | activity whose log entries are FG-only (e.g. fraud investigation) |
| `active` | bool | |

**processingLogEntry**
| field | type | notes |
|---|---|---|
| `activityId` | UUID ref | the processingActivity |
| `action` | enum | `read`, `create`, `update`, `delete`, `export` |
| `actionName` | string | handelingNaam, e.g. "Zaak raadplegen" |
| `performedBy` | string | NC user id, or system/client identifier for API access |
| `channel` | enum | `ui`, `zgw-api`, `background` |
| `timestamp` | datetime | |
| `processedObjects` | array | `[{objectType, idType (e.g. BSN/KVK/UUID), idValue}]` per VNG verwerkteObjecten |
| `caseRef` | UUID | the procest case context, when applicable |
| `confidential` | bool | inherited from activity; FG-only visibility |

## Emission Path (non-blocking)

Log emission MUST never block or fail the primary action. `ProcessingLogService::log(...)` enqueues an in-memory buffer flushed post-response via a queued NC background job (`ProcessingLogFlushJob`); on flush failure the batch is retried and a flagged admin warning is raised — entries are spooled, not dropped silently. Read-path instrumentation lives at the controller/service boundary of person-bearing resources (case detail, ZGW zaak/rol endpoints, betrokkene lookups); list views log one entry per *case actually returned containing person identifiers*, not per row scanned.

## Attribution

- Each `caseType` gets an optional `processingActivityId`; processing in the context of a case attributes to its case type's activity.
- ZGW API bearer clients get a per-client activity mapping (next to `zgw-autorisaties-api` client config).
- Anything unmapped attributes to a seeded fallback activity `"Niet-geclassificeerde verwerking"` with `flagged = true` semantics, surfaced on the FG dashboard so the gap is visible and fixable — never silently unlogged.

## Immutability & Retention

- No app endpoint updates or deletes a `processingLogEntry`; the controller exposes create (internal only) and read. OR RBAC on the log schema is locked to the service + FG read role.
- Retention default 3 years (VNG Logging Verwerkingen norm), configurable; a background job hard-deletes entries past retention. The retention job's own run is logged.
- `confidential` entries (e.g. fraud-investigation activities) are excluded from all non-FG query results, including the betrokkene export — per the standard's vertrouwelijkheid provision.

## API Surface

Bearer-gated endpoints mirroring the `zgw-autorisaties-api` posture, shaped after the VNG Logging Verwerkingen API: list/filter verwerkingsacties (by betrokkene idValue, period, activity, performer) + processing-activities listing. Procest-internal FG UI consumes the same controller.

## Performance Notes

Verwerkingenlogging is write-heavy. Entries go to a dedicated OR register/schema (own magic table), are never joined into case queries, and the flush job batches writes. If volume becomes problematic the storage backend can be swapped behind `ProcessingLogService` without spec change.
