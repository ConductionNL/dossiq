# Design: voorstel-management

## Context

Voorstellen are the central unit of work in Procest's parafering domain. Every collegeadvies, raadsvoorstel and DT-advies that goes through a Dutch municipality's B&W (College van Burgemeester en Wethouders) signing route is, in our model, one `voorstel` record bound to one parent `case`. The capability was implemented incrementally across two changes — `parafeerroute-engine` (routes, snapshotting, step advancement) and `parafering-actions` (actor decisions, delegation, e-signature) — but never received its own capability spec. This change formalizes what already exists.

## Entity: `voorstel`

Defined in `lib/Settings/procest_register.json` as a Schema.org `CreativeWork`. Required properties: `case`, `type`, `onderwerp`, `steller`, `status`. Selected non-required properties drive the workflow:

| Property | Type | Role |
|----------|------|------|
| `case` | UUID (`$ref: case`, `onDelete: CASCADE`) | Parent case |
| `type` | enum: `dt_advies`, `collegeadvies`, `raadsvoorstel` | Determines default parafeerroute |
| `onderwerp` | string ≤ 255 | Subject; typically inherited from case title |
| `steller` | string (NC UID) | Creator |
| `afdeling` | string | Department of the steller |
| `portefeuillehouder` | string (NC UID) | Wethouder (portfolio holder) |
| `status` | enum, 8 values | Lifecycle state (see below) |
| `parafeerroute` | UUID | Linked parafeerroute |
| `routeSnapshot` | JSON-encoded array (hidden) | Frozen steps captured at submission |
| `currentStep` | integer ≥ 0 | 1-based active step, 0 = not yet submitted |
| `returnedFromStep` | integer (hidden) | Resume marker after teruggestuurd |
| `document` | string (NC file ID) | Primary voorstel document |
| `bijlagen` | array<string> | Additional NC file IDs |
| `behandeling` | enum: `hamerstuk`, `bespreekstuk` | Bestuurlijk treatment hint |

## Lifecycle (status state machine)

```
concept ──(submit, requires document+route)──► in_parafering
in_parafering ──(non-final step complete)──► in_parafering (currentStep++)
in_parafering ──(actor "terugsturen", reason required)──► teruggestuurd
teruggestuurd ──(steller resubmits)──► in_parafering (resume at returnedFromStep)
in_parafering ──(final accordering step complete)──► geaccordeerd
geaccordeerd ──(secretariaat plaatst op agenda)──► aangeboden
aangeboden ──(RIS records besluit)──► besloten
besloten ──(retention period reached)──► gearchiveerd
```

`STATUS_IN_PARAFERING`, `STATUS_GEACCORDEERD`, `STATUS_TERUGGESTUURD`, and `STATUS_TER_ACCORDERING` are surfaced as PHP constants on `ParafeerRouteService` / `ParafeerActieService` — the spec MUST stay aligned with those.

## Relationship map

- **voorstel ↔ case** — `voorstel.case` is required; deleting a case cascades; a case can hold many voorstellen
- **voorstel ↔ parafeerroute** — at submission, the route's `steps[]` is JSON-frozen onto `voorstel.routeSnapshot` so later route edits do not retroactively change in-flight voorstellen
- **voorstel ↔ parafeeractie** — each actor decision (advised / parafered / accorded / returned / skipped) is a separate immutable `parafeeractie` referencing the voorstel; advances are driven by `ParafeerRouteService::completeStep()`
- **voorstel ↔ document / bijlagen** — Nextcloud file IDs; the primary document is the surface for the e-signature annotation when accordering completes
- **voorstel ↔ besluit (external)** — once `besloten`, the RIS-derived besluit ID is linked back through the `besluitvorming-workflow` capability (out of scope here)

## Security & audit considerations

- **Identity is server-derived**: every status-changing operation derives the user from `IUserSession`; frontend-supplied UIDs are ignored. Re-using the rule already enforced by `ParafeerActieService::recordAction`.
- **Per-step authorization**: only the actor of `routeSnapshot[currentStep]` (or a valid delegate) may advance the voorstel. Returns are also limited to the current step's actor.
- **Steller-only edits**: while `status = concept` or `status = teruggestuurd`, only the steller may edit `onderwerp`, `document`, `bijlagen`; once `in_parafering`, voorstel content is locked except for status transitions and route overrides.
- **Override audit**: route skip / ad-hoc step additions append to `voorstel.auditTrail` (see `ParafeerRouteService::appendAuditTrail`); OpenRegister's automatic per-save audit log captures every change irrespective of caller.
- **Read scope**: voorstel detail readable by anyone with access to the parent case; this includes the steller, all assigned actors (past and current), and case-shared participants per `case-sharing-collaboration`.
- **Archiefwet compliance**: `parafeeractie` records and OpenRegister's append-only audit trail together satisfy the legal accountability requirements for municipal proposals.

## Why a GENERATE-style change?

The implementation predates the spec. Rather than retrofitting tasks that "create code that already works", this change records the contract that the existing code already honors. Tasks are scoped to **verification**: schema match, lifecycle match, route coverage, UI presence. If verification reveals a gap, that gap becomes a follow-up change (filed as an issue per the "always file issues for deferred work" rule).
