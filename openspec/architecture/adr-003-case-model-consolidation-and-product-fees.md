# ADR-003: Case-Model Consolidation — Artifacts Are Case Types, Transfers Are Actions, Fees Are Products

## Status

Accepted (2026-07-09)

## Context

Procest grew a set of top-level entities that are, on inspection, **not
independent objects** — each one already references a `case` and only exists in
the context of one:

- `voorstel` (proposal), `adviesAanvraag` / `adviceRequest` / `adviceResponse`
  (advice), `beroep` (appeal), `bezwaar` + `bezwaarDecision` +
  `bacAdviceRequest` + `bezwaaradviescommissie` (the objection subsystem) — all
  hang off a case. They were surfaced as their own index + detail pages and
  backed by their own schemas, services and controllers.
- `casetransfer` modelled a hand-off from one handler to the next as a stored
  object with its own detail page — but a transfer is an **action**, not a
  thing that has a lifecycle of its own.
- `legesverordening` / `legesartikel` / `legesberekening` (and the parallel
  `legesTariefTabel` / `legesTarief` / `legesVariant` / `legesKorting` /
  `legesRestitutie` set) implemented a bespoke municipal-fee engine — rate
  tables, per-case calculations, refunds, Shillinq hand-off — entirely inside
  procest.

This produced a wide surface (9 leges schemas, ~19 leges backend files, a dozen
detail/index pages) modelling concepts that belong to a smaller, sharper core.

## Decision

1. **Case-type artifacts are case types, not entities.** A proposal, an advice
   request, an appeal and an objection are *cases of a particular type*
   (`caseType`), differentiated by `case.caseType` and driven by the case's
   status/workflow — not standalone schemas with their own nav. Their
   type-specific behaviour (parafering/approval, advice consultation, appeal
   handling, objection processing) lives as **case-type workflow**, not as a
   separate object graph. (Implemented in later waves; this ADR fixes the
   direction.)

2. **Handler hand-offs are actions, not objects.** Reassigning a case from one
   handler to the next is a stateless operation (`CaseReassignmentService`,
   invoked from a case action) — it already persists no object and needs none.
   (Distinct and out of scope: `casetransfer` models **cross-organisation**
   case ownership handover with an accept/reject handshake between partner
   orgs; that persisted record is legitimate and **stays**. The two were
   conflated in the initial review — only the stateless handler hand-off is an
   "action", and it already is one.)

3. **Fees are products, owned by Pipelinq.** Procest does **not** implement a
   fee engine. A municipal fee is a **product** in Pipelinq's product register
   (catalogue, pricing, financial hand-off all live there). A `caseType`
   declares which products/fees apply to it via its `productsOrServices`
   field, which references Pipelinq `product` objects. The charge that lands on
   a concrete case is a Pipelinq financial transaction, not a procest
   `legesberekening`.

4. **Cross-register references address Pipelinq objects by UUID.** OpenRegister
   objects are globally addressable, so `caseType.productsOrServices` stores
   Pipelinq `product` UUIDs. The property is declared as a relation
   (`items.$ref: "product"`) annotated with the owning register
   (`x-external-register: "pipelinq"`) so a picker can resolve options against
   Pipelinq's register rather than procest's own.

## Consequences

- **Wave 1 (this change):** the entire leges subsystem is removed from procest —
  9 schemas, ~19 backend classes (services, controllers, listeners, repair,
  seed), the leges routes, settings keys, frontend views/dialogs/API, and the
  four leges OpenSpec specs. `caseType.productsOrServices` becomes a
  Pipelinq-product relation. **Existing leges data is dropped, not migrated**
  (deliberate — the fee model is replaced, not ported). `beschikking.legesbedrag`
  remains as a stored amount on a decision and is out of scope here.
- **Later waves:** `voorstel` / advice / `beroep` (Wave 2) and the `bezwaar`
  objection subsystem (Wave 3) fold into the `case` model as case types. Each is
  its own change. (Handler reassignment is already an action and `casetransfer`
  cross-org handover stays — there is no transfer-deletion wave.)
- Procest gains a soft dependency on Pipelinq's product register for the fee
  relation. This is a reference, not a code dependency; procest degrades to an
  empty picker if Pipelinq is absent.

### Decisions governing the later waves (2026-07-09)

- **No data migration in any wave.** Existing voorstel / advies / beroep /
  bezwaar / leges objects are example/seed data and are **dropped, not
  migrated** — the same treatment leges gets here. Every wave is a forward-only
  model change.
- **The whole decision-making / signing subsystem moves to Decidesk.**
  `voorstel` → `parafeerroute`/`parafeeractie` (signing) → `decision` is one
  chain: procest's Besluitvorming subsystem, which produces municipal
  college/raad besluiten, WOO decisions and contract decisions, delegating
  approval to OpenRegister. Signing belongs to Decidesk (motions, amendments,
  decisions), and the decision apparatus goes with it — not just the parafering
  layer. Procest cases invoke Decidesk for signing/decisions (a cross-app
  action, analogous to the Pipelinq-product fee link). **This is a cross-app
  migration, not a procest-internal deletion, and warrants its own ADR** (the
  procest↔Decidesk decision boundary): the `decision` schema is a dependency
  hub (`bezwaarDecision`, contract decisions, WOO decisions all reference it),
  so it cannot be removed from procest until those dependents are resolved and
  Decidesk owns the capability. Sequencing is therefore: (a) design the
  boundary + confirm/build Decidesk's decision capability, (b) migrate
  consumers, (c) retire procest's Besluitvorming last.
- **The bezwaar dependents are removed, not retained.** `DwangsomBezwaarService`
  (penalty payments), `IngebrekestellingController` (notice of default) and the
  bezwaar-coupled deadline logic go with the objection subsystem in Wave 4.
  (Scope caveat: general, non-bezwaar termijnbewaking, if any, is preserved —
  only the objection-coupled deadline behaviour is removed.)

## Alternatives considered

- **Keep a thin leges engine and sync to Pipelinq** — rejected: two sources of
  truth for pricing, and the bespoke engine is exactly the surface we are
  removing.
- **A shared product register both apps own** — deferred: Pipelinq already owns
  the product catalogue; a UUID reference is sufficient and avoids a new
  register-ownership question. Can be revisited if a third consumer appears.
