---
depends_on:
  - openregister/processing-activity-register
---

# Proposal: avg-verwerkingenlogging

> **BLOCKED_EXTERNAL** — this change depends on the OpenRegister change
> `openregister/openspec/changes/processing-activity-register/` (requirements
> OR-PA-1..9) and cannot be applied until that platform capability lands. The
> catalogue content and UI designs can be authored ahead; nothing here ships
> against an OR release that lacks the `x-openregister-processing` dialect.

## Why

Dutch municipalities are accountable under the AVG (GDPR art. 5 lid 2, art. 30) for *every* processing of personal data, and the VNG **Logging Verwerkingen** standard is the sector norm for proving it. A zaaksysteem handling BSN-bearing cases (BRP/KvK register sets are in flight, sociaal-domein zaaktypen are planned) cannot pass a municipal privacy audit without a verwerkingsregister and per-access logging.

**Product decision (2026-06-11):** the AVG processing-register/verwerkingenlogging is a **platform abstraction owned by OpenRegister**, not an app concern. Three apps (procest, docudesk, scholiq) authored near-identical changes on the same day, which per ADR-022 (apps-consume-OR-abstractions) is the proof that the requirement is abstract. The original version of this change specified procest-local storage, logging, retention, export, and API layers; **all of those layers are superseded by `openregister/processing-activity-register`** (see the supersession table below and in that change's `design.md`). Procest contributes **catalogue + UI only**.

What remains genuinely procest's: only procest knows *which* verwerkingsactiviteiten a zaakgericht-werkend municipality performs through it — the doel and rechtsgrond of behandelen omgevingsvergunning, afhandelen bezwaar, handhaving — which case-data schemas carry persoonsgegevens and must log reads, how a case type maps to an activity, and how procest's FG-facing screens surface the procest slice of the platform register and log.

## What Changes

1. **Domain catalogue of verwerkingsactiviteiten** — procest declares its zaakgericht-werken processing activities (doel, rechtsgrond with statutory reference, betrokkenen, ontvangers, bewaartermijn — per zaaktype context: vergunningverlening, bezwaar & beroep, handhaving, klantcontact) in the `x-openregister-processing.activities` catalogue block on the procest register (OR-PA-2). OpenRegister seeds them as `draft` on register import; the municipality's privacy officer activates them.
2. **Attribution + read-logging opt-in annotations** — person-bearing case-data schemas (`case`, `role`, `customerContact`, `bezwaar`, …) get `attribution` defaults and `logReads: true` in the same dialect block (OR-PA-2/OR-PA-3); reference-data schemas (`caseType`, `statusType`, …) opt out. Case-type-specific attribution (a case of type "Omgevingsvergunning" attributes to that activity, not the schema default) uses the platform's imperative override (`ObjectEntity::setProcessingActivityId()`, the dynamic-attribution fallback OR-PA-2 keeps available), driven by an optional `processingActivityId` on the `caseType` schema.
3. **FG view surfacing procest's slice** — an app-side FG inquiry view that consumes the platform log/activity surfaces with the procest register filter (OR-PA-8), including the platform's flagged-fallback "niet-geclassificeerde verwerking" counter (OR-PA-4) so case-type mapping gaps are visible where the zaaksysteem admin works.
4. **Per-betrokkene / inzageverzoek entry point** — a procest UI entry point (from the FG view and the case/betrokkene context) that **delegates** to the platform's per-subject extract (OR-PA-7) for an art. 15 inzageverzoek; procest renders and links, it does not query or export log data itself.

## Superseded by OpenRegister (2026-06-11 abstraction decision)

The **storage, logging, API, and export layers** of the original change are superseded by `openregister/processing-activity-register`. Per its supersession table:

| # | Original procest requirement (removed here) | Absorbed by OR requirement |
|---|---|---|
| P1 | Processing activities MUST be maintained in a register | **OR-PA-1** (platform register, Art. 30(1) fields, lifecycle, validation) |
| P2 | Every processing of personal data MUST produce a log entry (incl. reads) | **OR-PA-3** + existing `audit-trail-immutable` attribution (writes) + **OR-PA-5** (never-blocking emission) |
| P3 | Processing MUST be attributable to an activity, with a visible fallback | **OR-PA-2** (declarative attribution) + **OR-PA-4** (flagged fallback) |
| P4 | The processing log MUST be append-only with enforced retention | **OR-PA-6** (append-only, retention, confidential FG-only) |
| P5 | The FG MUST be able to query and export the log per betrokkene | **OR-PA-7** (per-subject extract) + **OR-PA-8** (FG delegation, scoping) |
| P6 | A VNG Logging Verwerkingen-shaped API MUST be exposed | **OR-PA-9** |

Consequently this change ships **no** `processingActivity`/`processingLogEntry` schemas, no `ProcessingLogService`/`ProcessingActivityService`, no flush/retention background jobs, no read-path instrumentation, no `VerwerkingenController`, and no VNG API routes. Procest's reads are logged because its schemas opt in declaratively; OpenRegister does the recording, retention, export, and external API.

## Cross-Project Dependencies

**This change is BLOCKED_EXTERNAL on `openregister/processing-activity-register`** (status: draft, authored 2026-06-11). Everything procest-side consumes that change's deliverables:

- the `x-openregister-processing` dialect with `activities` / `attribution` / `logReads` blocks and save-time 422 validation (OR-PA-2),
- read logging on opted-in schemas with batched, never-blocking emission (OR-PA-3/OR-PA-5),
- the seeded flagged fallback activity + compliance gap surfacing (OR-PA-4),
- the per-subject extract and Art. 30 export (OR-PA-7),
- register-filtered, FG-delegated inquiry surfaces (OR-PA-8).

Catalogue authoring and UI design work can proceed ahead of the OR release, but no task here merges before it: a register import carrying the dialect against an older OR would at best be ignored and at worst rejected.

## Impact

- `lib/Settings/procest_register.json` — `x-openregister-processing` annotation on the register (catalogue) and on person-bearing schemas (attribution + `logReads`); optional `processingActivityId` property on the `caseType` schema. **No new schemas, no new tables, no migrations.**
- Case-type configuration UI (existing settings tab) gains the activity-mapping selector; saving a mapping is config-only — attribution stamping happens platform-side.
- New `src/views/admin/VerwerkingenlogInquiry.vue` (FG view, procest register filter) + an inzageverzoek entry point delegating to the platform extract.
- No changes to existing audit behaviour, no new backend services, controllers, routes, or background jobs.

## Out of Scope

- Everything in the supersession table above — owned and specced by `openregister/processing-activity-register` (storage, log mechanics, append-only/retention/prune, exports, VNG Logging Verwerkingen API, attribution fallback machinery, FG access delegation).
- Logging of processing in *other* apps — each app thins/authors its own catalogue change against the same platform capability.
- Automatic DPIA generation; the platform Art. 30 export covers the register document itself (OR-PA-7).
- Anonymization/pseudonymization of logged identifiers (platform posture: access to the log is restricted instead).
- Citizen-facing self-service inzage portal (zaakportaal-mijngemeente territory; the entry point here serves the FG handling such a request).
