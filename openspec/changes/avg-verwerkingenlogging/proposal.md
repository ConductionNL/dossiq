# Proposal: avg-verwerkingenlogging

## Why

Dutch municipalities are accountable under the AVG (GDPR art. 5 lid 2, art. 30) for *every* processing of personal data, and the VNG **Logging Verwerkingen** standard (verwerkingenlogging API-standaard) is the sector norm for proving it: each processing action must be logged with *which* processing activity it belonged to, *which* purpose and legal basis that activity has, *whose* data was touched (e.g. by BSN), *who* performed it, and *when*. This is fundamentally different from Procest's existing object audit trail, which records *what changed on an object* — verwerkingenlogging records *that personal data was processed and under which justification*, including pure **reads**, which the audit trail does not cover at all.

Procest today has zero verwerkingenlogging: no processing-activities register, no per-access log, no FG (functionaris gegevensbescherming) inquiry surface, and no VNG Logging Verwerkingen API. A zaaksysteem handling BSN-bearing cases (BRP/KvK register sets are in flight, sociaal-domein zaaktypen are planned) cannot pass a municipal privacy audit without it. This slots naturally next to `zgw-autorisaties-api` in the compliance layer.

## What Changes

1. New OpenRegister schemas: `processingActivity` (verwerkingsactiviteit: naam, doel, AVG-rechtsgrond, categorieën betrokkenen, ontvangers, bewaartermijn) and `processingLogEntry` (per-access record per the VNG Logging Verwerkingen model: actie, handeling, verwerkingsactiviteit, uitgevoerdDoor, tijdstip, verwerkte objecten met soortObjectId/objectId, vertrouwelijkheid).
2. `ProcessingLogService` — non-blocking (queued) emission of log entries on every read/create/update/delete of person-bearing case data, attributed to a processing activity.
3. Attribution configuration — each case type maps to a default verwerkingsactiviteit; ZGW API clients map per client; unmapped processing is logged against a flagged fallback activity so nothing is silently unlogged.
4. Append-only guarantees and retention — log entries are immutable through the app, retained per configured bewaartermijn, with `vertrouwelijk`-marked entries visible only to the FG role.
5. FG inquiry & export UI — query by betrokkene (BSN), period, activity, or user; export to support an art. 15 inzageverzoek ("who accessed my data and why").
6. VNG Logging Verwerkingen API endpoints (bearer-gated, mirroring the `zgw-autorisaties-api` posture) so external audit/privacy tooling can consume the log.

## Impact

- New schemas in `lib/Settings/procest_register.json` + config keys in `SettingsService`.
- New `ProcessingLogService`, `ProcessingActivityService`, `VerwerkingenController` (+ API routes); a queued background job for asynchronous log writes.
- Read-path instrumentation in the case/ZGW controllers (emit, never block).
- Admin UI: processing-activities register tab + per-caseType activity mapping; FG inquiry view.
- Distinct from and additional to the OR object audit trail — no changes to existing audit behaviour.

## Out of Scope

- Logging of processing in *other* apps (pipelinq, openregister core) — this change covers Procest's case-domain processing; a fleet-level standard can later adopt the schema shape.
- Automatic DPIA generation or the full verwerkingsregister document (art. 30 register) — the `processingActivity` schema carries the fields, but document generation is not included.
- Anonymization/pseudonymization of logged identifiers (the standard logs the identifier; access to the log itself is restricted instead).
- Citizen-facing self-service inzage portal (zaakportaal-mijngemeente territory; the export here serves the FG handling such a request).
