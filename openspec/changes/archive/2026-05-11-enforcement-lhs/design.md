# Design: enforcement-lhs

## Context

The Landelijke Handhavingsstrategie Omgevingsrecht (LHS) is a Dutch national reference framework, published by the joint Omgevingsdiensten and codified by IPO/VNG, that prescribes a *proportional* enforcement response. Inspectors do not freely choose sanctions; they pick along two axes — **ernst** (severity of the offence) and **gedrag** (intentionality of the offender) — and the matrix gives the prescribed intervention. In practice a third axis matters: a `burger` who builds an illegal shed should not be treated identically to a `bedrijf` violating an environmental permit, and a `recidivist` should not be treated identically to a first-time offender. Operational omgevingsdiensten express this as four distinct matrices (one per actor type) — which is what this design captures.

## Data structure: 3-D LHS matrix

The `lhsMatrix` schema models the matrix as a versioned object holding a dense cell array of length `len(ernst) * len(gedrag) * len(actorType)`. For the national reference: 3 × 4 × 4 = 48 cells.

| Property | Type | Role |
|----------|------|------|
| `name` | string | e.g. "Landelijke Handhavingsstrategie 2024" |
| `version` | integer | Monotonic; new edits create a new version (immutable history) |
| `active` | boolean | Exactly one matrix is `active = true` per tenant |
| `ernstAxis` | array<string> | Ordered: `[gering, aanzienlijk, ernstig]` |
| `gedragAxis` | array<string> | Ordered: `[goedwillend, onverschillig, calculerend, crimineel]` |
| `actorTypeAxis` | array<string> | Ordered: `[burger, bedrijf, overheid, recidivist]` |
| `cells` | array<cell> | Dense; one entry per (ernst, gedrag, actorType) triple |
| `auditTrail` | array<entry> | Edit log: who, when, which cells changed |

Each `cell` has: `ernst`, `gedrag`, `actorType`, `interventie` (enum: `waarschuwing`, `herstelactie`, `last_onder_dwangsom`, `last_plus_pv`, `bestuursdwang`, `pv_plus_bestuursdwang`), `note` (optional rationale).

## Sanction recommendation service

`SanctionRecommendationService::recommend(ernst, gedrag, actorType, caseId)` is the single entry point used by the wizard, mobile inspection, and complaint intake. It:

1. Loads the `active = true` `lhsMatrix` for the tenant
2. Looks up the cell matching the input triple (O(1) via a Map keyed `ernst:gedrag:actorType`)
3. Returns a `sanctionRecommendation` object: input triple, matrix version, recommended interventie, lookup timestamp, server-derived user UID
4. Persists the recommendation as a `sanctionRecommendation` OpenRegister record linked to `caseId`

When the inspector confirms, a `handhavingsactie` is created from the recommendation. When the inspector overrides, the recommendation captures: `applied` (the chosen interventie), `override = true`, `overrideJustification` (mandatory, free text ≥ 20 chars), and the resulting `handhavingsactie` references the recommendation by ID — preserving the original recommendation even when the case advances on a different sanction.

## Inspector decision UI

The matrix UI extends step 1 of the existing enforcement wizard:

- **Axis selectors**: three radio groups (ernst, gedrag, actorType), with the actor-type pre-filled from the case subject's role classification
- **Matrix preview**: a 3-D visualisation rendered as a stack of three 3×4 panels (one per actor type), with the cell matching the current selectors highlighted
- **Recommendation card**: shows the prescribed `interventie`, the matrix name+version it came from, and the per-cell `note`
- **Override toggle**: when enabled, exposes a sanction dropdown limited to interventions of equal-or-lesser severity (override-up requires manager role), plus a mandatory justification text field

The matrix is read-only from the wizard; configuration lives in **Admin → VTH Instellingen → Handhavingsstrategie**.

## Audit trail integration

Every recommendation and every override appends to two trails:

1. The `sanctionRecommendation` record's own audit trail (immutable per OpenRegister policy)
2. The parent case timeline (via the existing `vth-module` enforcement workflow), as a new event type `lhs_recommendation` with payload `{ recommendationId, ernst, gedrag, actorType, recommended, applied, override }`

This makes the matrix outcome — and any deviation from it — visible on the same chronological timeline as the constatering, the vooraankondiging and the eventual bezwaar, which is the evidence package a bezwaar committee actually consumes.

## Override authority and security

- Override-down (lighter sanction than recommended): allowed for any inspector with justification
- Override-up (harsher sanction than recommended): requires manager role; UI hides the higher-severity options for non-managers
- Identity is server-derived from `IUserSession` on every recommendation and override call — never trusted from request body

## Schema versioning

When an admin edits the matrix, a new `lhsMatrix` version is created (rather than mutating cells in place). In-flight `sanctionRecommendation` records keep referencing the matrix version they used. This is the same pattern as `voorstel.routeSnapshot` — frozen at the moment of decision, immune to retroactive changes.
