# Design: parafering-audit-trail

## Architecture Overview

The parafering audit trail is an append-only, event-sourced ledger of every state transition that occurs on a voorstel during its parafeerroute lifecycle. It is distinct from the operational `parafeeractie` records (which drive routing) and distinct from the per-object OR audit trail (which records raw field diffs). Its purpose is exclusively legal accountability: producing a regulator-grade dossier that demonstrates *who* approved *what content* at *what moment* for *which reason*, sufficient for Awb bezwaar/beroep procedures and Archiefwet handover to the gemeentelijk e-Depot.

```
ParafeerRouteService.startRoute()       \
ParafeerRouteService.completeStep()       \
ParafeerRouteService.skipStep()            ┐
ParafeerRouteService.addAdHocStep()        │  emits  ParafeerTransitionEvent
ParafeerActieService.recordAction(parafered)│  ──────────────────────────────►
ParafeerActieService.recordAction(advised)  │                                  ParaferingAuditListener
ParafeerActieService.recordAction(returned) ┘                                  ├─ build contentSnapshot
                                                                                ├─ derive actorRole
                                                                                ├─ compute auditEntryHash
                                                                                └─ ObjectService::saveObject(paraferingAuditEntry)
                                                                                                  │
                                                                                                  ▼
                                                                                OR audit-trail-immutable (storage)
                                                                                                  │
                            ┌─────────────────────────────────────────────────────────────────────┘
                            ▼
              ParaferingAuditExportController  →  GET /api/parafering-audit-trail/export?voorstel={uuid}
                            ▼
              Manifest index page (type: 'index')  →  /apps/procest/parafering-audit
```

## File Map

### New Files

| File | Purpose |
|------|---------|
| `lib/Event/ParafeerTransitionEvent.php` | Domain event raised after every successful parafeerroute transition; carries `voorstelId`, `transitionType`, `step`, `actor`, `reason`, `actorRole` |
| `lib/Listener/ParaferingAuditListener.php` | Subscribes to `ParafeerTransitionEvent`; builds and persists one `paraferingAuditEntry` per event |
| `lib/Validator/ParaferingAuditAppendOnlyValidator.php` | Hooks into OR's pre-save pipeline; rejects UPDATE/DELETE on `paraferingAuditEntry` schema with `OCSForbiddenException` carrying the static message `Audit entries are append-only` |
| `lib/Controller/ParaferingAuditExportController.php` | `GET /api/parafering-audit-trail/export?voorstel={uuid}` — returns JSON export with TMLO/MDTO metadata wrapper |

### Modified Files

| File | Changes |
|------|---------|
| `lib/Settings/procest_register.json` | Add `paraferingAuditEntry` schema, 3 example seed objects, and a manifest index page (`type: 'index'`) under `components.x-pages[]` |
| `lib/Service/ParafeerRouteService.php` | Dispatch `ParafeerTransitionEvent` from `startRoute`, `completeStep`, `skipStep`, `addAdHocStep` — REUSE existing methods, do NOT write audit entries directly |
| `lib/Service/ParafeerActieService.php` | Dispatch `ParafeerTransitionEvent` from `recordAction` after the parafeeractie is saved |
| `appinfo/routes.php` | Add `parafering_audit_export#index` route — BEFORE any wildcard `{slug}` route |
| `appinfo/info.xml` | Register `ParaferingAuditListener` against `ParafeerTransitionEvent` via the `<events>` element |

## Data Model

### paraferingAuditEntry (new — Schema.org type: `schema:Action`)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| voorstel | string (uuid) | Yes | Reference to the voorstel that this transition occurred on |
| step | integer | No | Step number in the `routeSnapshot` (omitted for `started`, `route-changed`, `completed` if not step-bound) |
| transitionType | enum | Yes | One of `started`, `paraferd`, `advised`, `terugsturen`, `route-changed`, `completed` |
| actor | string | Yes | Nextcloud user UID who triggered the transition (always the session user — never request body) |
| actorRole | string | Yes | Role at the moment of action — `steller`, `adviseur`, `parafeerder`, `accorderend`, `beheerder`, `secretariaat` — derived from the routeSnapshot step or admin override context |
| timestamp | string (ISO 8601, UTC) | Yes | Server-side timestamp at write moment — never accepted from client |
| reason | string | Conditional | Mandatory for `terugsturen` and `route-changed`; optional for others |
| contentSnapshot | object | Yes | Immutable JSON copy of `voorstel.{onderwerp, document, bijlagen, routeSnapshot, currentStep, status}` at transition moment |
| ipAddress | string | Yes | `IRequest::getRemoteAddress()` at write moment; redacted to /24 (IPv4) or /48 (IPv6) per AVG minimisation |
| auditEntryHash | string (SHA-256 hex) | Yes | Hash of canonical JSON of the entry (excluding the hash field itself) — for tamper detection |

**Append-only enforcement**: The `ParaferingAuditAppendOnlyValidator` rejects every non-INSERT mutation. Even system administrators cannot UPDATE or DELETE these entries via the API. Storage-level deletion (e.g. SQL `DELETE`) is technically possible — but is detectable via the OR `audit-trail-immutable` capability that records every raw mutation.

### Why a separate schema (vs. extending `parafeeractie`)

`parafeeractie` exists to drive the routing engine — it MUST be updateable by the routing service (e.g. to flag a step as superseded by an ad-hoc insert). The audit trail MUST be append-only and MUST also capture transitions that are NOT actor actions (e.g. `started`, `route-changed`, `completed`). Conflating them into one schema would either weaken append-only guarantees on `parafeeractie` or force every audit-only transition to invent a synthetic `parafeeractie`. Two schemas is the safer model.

## Transition → Audit Entry Mapping

| Origin | Trigger | transitionType | actorRole | reason required |
|--------|---------|----------------|-----------|-----------------|
| `ParafeerRouteService::startRoute` | Voorstel submitted | `started` | `steller` | No |
| `ParafeerActieService::recordAction(parafered)` | Parafeerstap completed | `paraferd` | `parafeerder` | No |
| `ParafeerActieService::recordAction(advised)` | Advies submitted | `advised` | `adviseur` | No |
| `ParafeerActieService::recordAction(returned)` | Terugsturen | `terugsturen` | step actor's role | YES |
| `ParafeerRouteService::skipStep` / `::addAdHocStep` | Route override | `route-changed` | `beheerder` or `secretariaat` | YES |
| `ParafeerRouteService::completeStep` (final accordering) | Voorstel geaccordeerd | `completed` | `accorderend` | No |

## Archive Export Format

`GET /api/parafering-audit-trail/export?voorstel={uuid}` returns:

```json
{
  "metadata": {
    "schema": "MDTO 1.0",
    "exportedAt": "2026-05-11T14:23:00Z",
    "voorstel": "uuid-of-voorstel",
    "voorstelOnderwerp": "Uitbreiding parkeerterrein Raadhuis",
    "retentionUntil": "2046-05-11",
    "selectielijstCategory": "Bestuurlijke besluitvorming — bewaartermijn 20 jaar",
    "exportedBy": "auditor.uid",
    "entryCount": 6
  },
  "entries": [
    { /* paraferingAuditEntry 1 — chronological order */ },
    { /* ... */ }
  ]
}
```

## Retention Policy

- **Decisions** (voorstellen that completed and produced a `Besluit`): retain 20 years from the `completed` transition timestamp, per Selectielijst Gemeenten 2020 category 1.1 "Bestuurlijke besluitvorming".
- **Non-decisions** (voorstellen that ended in `teruggestuurd` without resubmission, or were withdrawn): retain 7 years per the general administrative-correspondence retention.
- **Enforcement**: The append-only validator blocks DELETE outright. A future scheduled retention sweeper (out of scope here, filed as separate issue) MAY hard-delete entries whose `timestamp` is older than the retention window AND whose parent voorstel is flagged `gearchiveerd`.

## Reuse Analysis

| Capability | Platform Component | Reuse |
|------------|--------------------|-------|
| Persistence | `ObjectService::saveObject()` (3-arg) | Listener delegates all storage to OR — no custom mapper |
| Immutability primitive | OR `audit-trail-immutable` capability | OR records every raw mutation; the procest validator adds the API-level append-only block on top |
| Event bus | Nextcloud `OCP\EventDispatcher\IEventDispatcher` | Standard NC event dispatch — no bespoke pub/sub |
| Manifest index page | OR manifest pattern (`type: 'index'`) | Reused exactly as `parafeerroute` and `legesberekening` did in their manifest refactors |
| TMLO/MDTO metadata | Static JSON wrapper in the export controller | No external library — the export is JSON-only for V1; XML profiles deferred |
| IP redaction | Existing `\OCA\Procest\Util\IpRedactor` (if present) OR a 3-line inline helper | TBC during deduplication check D01 |
| RBAC for export endpoint | `IUserSession` + group check via `IGroupManager` | Standard NC RBAC — no custom role system |

No overlap found with existing procest services for transition-aware audit logging. The custom listener + validator + export controller are justified by the legal-accountability remit, which is not covered by the operational `parafeeractie` flow or by OR's raw-mutation audit-trail-immutable capability.
