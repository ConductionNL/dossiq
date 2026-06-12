# Proposal: avg-verwerkingenlogging

> **Reworked 2026-06-11 (abstraction decision):** AVG verwerkingenlogging surfaced near-identically in procest, docudesk, and scholiq on the same day — proof the requirement is abstract. The storage, append-only log, retention, attribution-fallback machinery, Art. 30/inzageverzoek export, and the VNG Logging Verwerkingen API are now owned by OpenRegister (`openregister/openspec/changes/processing-activity-register`, requirements OR-PA-1..9). This change is procest's THIN CONSUMER: it contributes the zaakgericht-werken activity catalogue, read-logging opt-ins, attribution mappings, and FG UI surfacing — nothing more.
>
> **Depends on:** `openregister/processing-activity-register` — BLOCKED_EXTERNAL until that change lands.

## Why

Dutch municipalities are accountable under the AVG (art. 5 lid 2, art. 30) for *every* processing of personal data, and the VNG **Logging Verwerkingen** standard is the sector norm for proving it — including pure **reads**, which the object audit trail does not cover. A zaaksysteem handling BSN-bearing cases (BRP/KvK register sets in flight, sociaal-domein zaaktypen planned) cannot pass a municipal privacy audit without it.

The platform mechanics now live in OpenRegister; what remains procest-specific is the *domain knowledge*: which verwerkingsactiviteiten a municipality performs through case handling, which case types map to which activity, which schemas carry person data and need read logging, and where the FG finds all of this in procest's UI.

## What Changes

1. **Activity catalogue (consumes OR-PA-2):** procest's verwerkingsactiviteiten (e.g. "Behandelen omgevingsvergunning" with doel, AVG-rechtsgrond, statutory reference, betrokkene categories, recipients, retention) declared via the `x-openregister-processing` dialect in `lib/Settings/procest_register.json`, seeded as drafts for FG review.
2. **Read-logging opt-in (consumes OR-PA-2/3):** `logReads` enabled on procest's person-bearing schemas (case, betrokkene/rol, klantcontact); schemas without person data stay out of the high-volume read log.
3. **Attribution mapping (consumes OR-PA-2/4):** case types map to a default activity via the catalogue annotations; ZGW API client identity flows to OR's log context so `channel`/`performedBy` attribution works for machine access; unmapped processing lands in OR's flagged fallback (OR-PA-4) and procest's FG view surfaces the unclassified count.
4. **FG surfacing (consumes OR-PA-7/8):** an FG/admin view in procest scoped to procest's register slice — activity catalogue status, unclassified counter, and the per-betrokkene inzageverzoek export entry point delegating to OR's export.

## Superseded by OpenRegister (removed from this change)

`processingActivity`/`processingLogEntry` app schemas, ProcessingLogService + flush/spool jobs, append-only + retention enforcement, fallback-activity machinery, the FG query/export engine, and the VNG Logging Verwerkingen API endpoints — see the supersession table in the OR change's `design.md` (procest P1–P6 → OR-PA-1..9).

## Out of Scope

- Anything the OR change owns (see above).
- Citizen-facing self-service inzage portal (zaakportaal-mijngemeente territory).
- DPIA generation.
