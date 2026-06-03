# Proposal: Parafering Audit Trail

## Summary

Introduce a dedicated, immutable, legally-sufficient audit trail for the Procest parafeerroute lifecycle. Every transition on a voorstel — `started`, `paraferd`, `terugsturen`, `advised`, `route-changed`, `completed` — SHALL be persisted as an append-only `paraferingAuditEntry` object that records actor, actor role, timestamp, reason, an immutable content snapshot of the voorstel at the moment of the action, and the originating IP address. The trail is queryable for archive export per Archiefwet retention rules (20 years for B&W decisions) and integrates with OpenRegister's `audit-trail-immutable` capability so that no procest-specific tampering surface is introduced. The sister specs `parafeerroute-engine`, `parafering-actions`, and `voorstel-management` already record `parafeeractie` rows — this spec layers a separate, regulator-grade audit stream on top so that legal accountability does not depend on the operational `parafeeractie` table.

## Problem

The current `parafeeractie` records (introduced by `parafering-actions`) are operational: they exist to drive the routing engine, populate the timeline, and trigger notifications. They are immutable by convention, not by validator. They also do not capture the voorstel content at the moment of action (only references), do not record IP / actor role, and have no archive-export format aligned with the Archiefwet metadata schema (TMLO / MDTO). The retired `parafering-audit-trail` capability points to OR's `audit-trail-immutable` for raw object mutation history, but that is per-object change diffing — not a per-transition legal record with reason, role, and content snapshot. Without this change, municipalities cannot demonstrate Awb-compliant decision provenance during bezwaar/beroep procedures, and archive exports to the gemeentelijk e-Depot lack the mandated 20-year decision dossier shape.

## Affected Projects

- [ ] Project: `procest` — Add `paraferingAuditEntry` schema, append-only validator, audit listener on parafeerroute events, archive-export endpoint, and a manifest-driven index page for auditor browsing.

## Scope

### In Scope (V1)

- **paraferingAuditEntry schema** (REQ-PAT-1): A new OpenRegister schema with `voorstel`, `step`, `action`, `actor`, `actorRole`, `timestamp`, `reason`, `contentSnapshot`, `ipAddress`, and a `transitionType` enum (`started`, `paraferd`, `advised`, `terugsturen`, `route-changed`, `completed`).
- **Audit listener** (REQ-PAT-2): A single event listener subscribes to all parafeerroute transitions emitted by `ParafeerRouteService` and `ParafeerActieService` and writes one audit entry per transition. The application code does NOT write audit entries directly.
- **Append-only enforcement** (REQ-PAT-3): A validator rejects any `UPDATE` or `DELETE` operation on `paraferingAuditEntry` objects, including via the generic ObjectService write path. Only INSERT is permitted.
- **OR audit-trail integration** (REQ-PAT-4): The new entries reuse OR's `audit-trail-immutable` capability for the underlying immutability primitive; the procest spec only adds the transition semantics on top — no custom hashing or chain logic.
- **Content snapshot** (REQ-PAT-5): At the moment of each transition the listener captures a JSON snapshot of the voorstel's content fields (`onderwerp`, `document`, `bijlagen`, `routeSnapshot`, `currentStep`, `status`) so that the legal record reflects the voorstel state at decision time, not its current state.
- **Archive export** (REQ-PAT-6): A `GET /api/parafering-audit-trail/export?voorstel={uuid}` endpoint returns an Archiefwet-aligned export (JSON + TMLO/MDTO metadata block) suitable for handover to the gemeentelijk e-Depot.
- **Manifest index page** (REQ-PAT-7): A manifest page of `type: 'index'` declared in `procest_register.json` so that auditors and beheerders can browse audit entries from the admin UI without bespoke views.
- **Retention policy** (REQ-PAT-8): Retention SHALL be 20 years from the `completed` transition for voorstellen that became `besluiten`, aligned with the Selectielijst Gemeenten 2020 category "Bestuurlijke besluitvorming". Earlier deletion SHALL be blocked by the append-only validator.

### Out of Scope

- Bespoke cryptographic chain / Merkle-tree audit log — V2 if required by sector-specific audits
- e-Depot submission automation (push to municipal archive) — separate change
- Cross-app audit aggregation (combining procest + docudesk + zaakafhandelapp into one regulator dossier) — Hydra-level concern
- Real-time SIEM streaming — handled by Nextcloud platform `Activity` integration
- PII redaction in exports for AVG/GDPR DSARs — separate change

## Approach

1. **Schema**: Add `paraferingAuditEntry` to `lib/Settings/procest_register.json` with the 9 properties listed above plus an `auditEntryHash` (SHA-256 of the canonical entry payload) for tamper detection.
2. **Listener**: Subscribe to `\OCA\Procest\Event\ParafeerTransitionEvent` (a new domain event raised by `ParafeerRouteService` and `ParafeerActieService` after each successful save) and write one audit entry per event. Application services do NOT call the audit service directly — the event bus is the only entry point.
3. **Validator**: Hook into OR's pre-save validation pipeline and reject any non-INSERT mutation on the `paraferingAuditEntry` schema with `OCSForbiddenException`.
4. **Manifest page**: Declare `type: 'index'` for `paraferingAuditEntry` under `components.x-pages[]` in `procest_register.json` per the manifest pattern adopted by `parafeerroute` and `legesberekening`.
5. **Export endpoint**: A new lightweight controller wraps `ObjectService::findObjects` with a TMLO/MDTO metadata wrapper. Output is JSON for V1; XML profiles deferred.

## Cross-Project Dependencies

- **parafeerroute-engine** (required): Emits `ParafeerTransitionEvent` for each `started`, `route-changed`, and `completed` transition.
- **parafering-actions** (required): Emits the same event for `paraferd`, `advised`, and `terugsturen` transitions.
- **voorstel-management** (required): The content snapshot reads `onderwerp`, `document`, `bijlagen`, `routeSnapshot`, `currentStep`, `status` — these MUST remain the canonical content fields.
- **OpenRegister `audit-trail-immutable`**: Provides the immutability primitive (no custom hashing or chain logic introduced here).
- **ADR-022**: Established that procest delegates audit immutability to OR; this change layers transition semantics, NOT a parallel audit store.
