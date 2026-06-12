# Design: avg-verwerkingenlogging (thin consumer)

## Division of labour

| Concern | Owner |
|---|---|
| ProcessingActivity entity, versioning, lifecycle | OpenRegister (OR-PA-1) |
| Declarative catalogue dialect (`x-openregister-processing`), seed-as-draft, attribution, `logReads` | OpenRegister (OR-PA-2) — procest supplies the content |
| Read/export processing log, batched non-blocking emission, spool | OpenRegister (OR-PA-3/5) |
| Flagged fallback "Niet-geclassificeerde verwerking" | OpenRegister (OR-PA-4) — procest surfaces the count |
| Append-only + retention + confidential-FG-only | OpenRegister (OR-PA-6) |
| Art. 30 export + per-betrokkene extract | OpenRegister (OR-PA-7) — procest provides the UI entry point |
| RBAC, FG delegation, register-slice scoping | OpenRegister (OR-PA-8) |
| VNG Logging Verwerkingen API | OpenRegister (OR-PA-9) — procest documents the scope |

## D1 — Catalogue as annotations, not objects

Procest's verwerkingsactiviteiten are authored as `x-openregister-processing` blocks in `procest_register.json` (upsert-by-code, seed-as-draft per OR-PA-2). The FG activates them after review. Case-type attribution is part of the same annotation (per-schema/per-operation overrides), so no parallel mapping store exists in procest.

## D2 — ZGW client attribution

ZGW bearer access already authenticates per client (`zgw-autorisaties-api`). The client identifier reaches OR's log context through the request actor — OR-PA-2's attribution records it as `performedBy` with channel context. If the OR emission path cannot see the resolved ZGW client identity, that is an OR-change gap to raise there, NOT something procest re-implements (task DC02 verifies this explicitly).

## D3 — FG view is a scoped window, not an engine

The procest FG view renders OR's inquiry/export surface filtered to procest's registers (OR-PA-8 register-slice scoping): catalogue review status, unclassified-processing counter, per-betrokkene export trigger. No procest controller stores or queries log entries directly (ADR-022 — no pass-through duplication).
