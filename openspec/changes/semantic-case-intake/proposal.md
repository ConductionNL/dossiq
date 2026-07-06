# Proposal: semantic-case-intake

kind: cross-app consumption — procest's consume-side of the **semantic object handoff** contract.
Cites **ADR-048** (cross-app-semantic-references), **ADR-022**, and the new company ADR
**ADR-051 "semantic-object-handoff"** + hydra cross-app change **`semantic-object-handoff`**
(both being authored in parallel in `../hydra`, 2026-07-05 — referenced by name; numbering
resolved, see Open Questions).

> **This change finally BACKS the README's "Pipelinq Bridge" claim.** README line 68 advertises
> "**Pipelinq Bridge** — Receive requests handed off from Pipelinq CRM as new cases", but a
> 2026-07-05 audit confirmed no code implements that bridge. Until this change lands, the claim is
> re-pointed at this change as roadmap by `align-claims-and-licence`.

## Why

Pipelinq (CRM) captures requests that must become cases in whatever case system the customer runs.
Per the handoff contract, pipelinq does NOT couple to procest: it hands the request off to the
semantic kind `https://openregister.app/ns#Case`, resolved at runtime via OpenRegister's
`SemanticTypeResolver` (shipped on OR `origin/development`,
`lib/Service/SemanticTypeResolver.php`) and the `x-openregister-handoff` dialect (arriving with
the hydra `semantic-object-handoff` change; not yet on OR origin/development as of 2026-07-05).
Procest becomes a provider of `ns#Case` by declaring `implements` on its zaak schema — the same
degrade-gracefully pattern ADR-048 defines for references.

Without a consume-side, the handoff resolves to nothing: pipelinq requests cannot land in
procest's Werkvoorraad, and the advertised bridge stays fictional.

## What Changes

1. **Declare the semantic kind.** Procest's `case` schema in `lib/Settings/procest_register.json`
   declares `implements: ["https://openregister.app/ns#Case"]`, making procest discoverable by
   OR's `SemanticTypeResolver` as a `ns#Case` provider. (Verified: the schema has no `implements`
   member today.)
2. **Accept handoff-created cases.** Cases created through the handoff dialect map from the hydra
   contract onto existing case fields (all verified present on the schema):
   - contract `title` → `case.title`; contract `summary` → `case.description`
   - contract `requester` → ADR-048 **semantic reference** on the case (coordination note: the
     `brp-kvk-register-sets` change proposes `initiatorType`/`initiatorSourceId`/
     `initiatorDisplayName` on the same schema; this change stores the requester as the semantic
     reference and, where those initiator fields exist, fills their display projection — one
     write path, no second requester field)
   - contract `channel` → `case.intakeChannel`; contract `priority` → `case.priority`
   - contract provenance → a provenance link on the case back to the originating pipelinq request
     (semantic reference to the source object), so the chain request→case is navigable both ways.
3. **Surface in Werkvoorraad/intake.** Handoff-created cases appear in the existing intake surface
   (`src/views/Werkvoorraad.vue`) like any other new case, with **provenance visible**: origin app,
   source request link, and handoff timestamp on the case detail/intake card.
4. **Notify declaratively.** Case-created notification for handoff intake is declared via the
   canonical `x-openregister-notifications` dialect (ADR-031) on the case schema — which the schema
   already carries — extended with the handoff-intake event; no imperative dispatch in procest.

## Overlap with `procest-delegation-via-events` (ADR-041, 0/13 — ACTIVE)

Checked 2026-07-05. That change fixes the procest→decidesk **decision delegation** transport
(dispatching `OCA\Decidesk\Event\DecisionRequestedEvent` over `IEventDispatcher`, plus a
`DecisionConcludedListener`). Different direction (procest as *requester* of decisions vs procest
as *receiver* of cases) and different mechanism (typed NC events vs OR handoff dialect +
SemanticTypeResolver). **No duplication:** this change adds no event transport of its own; the
handoff arrives through OpenRegister. If the hydra contract specifies a post-creation notification
event, its listener registration follows the conventions established in
`procest-delegation-via-events` (fail-closed `class_exists` guard, listener in
`lib/AppInfo/Application.php`) — referenced, not re-specified.

## Impact

- `lib/Settings/procest_register.json`: `implements` on `case` + requester/provenance semantic
  reference properties + `x-openregister-notifications` extension.
- `src/views/Werkvoorraad.vue` + case detail: provenance display (origin badge + source link).
- No new procest controller/route: creation flows through OR's handoff machinery (per
  `hydra-gate-redundant-controller` / ADR-022, procest wraps nothing).
- Dependencies: OR release carrying `SemanticTypeResolver` (shipped) **and** the
  `x-openregister-handoff` dialect (in-flight, hydra `semantic-object-handoff`); pipelinq's
  produce-side (owned by pipelinq's own change under the same hydra umbrella).

## Out of Scope

- The handoff dialect/resolver mechanics themselves (OR-owned, hydra `semantic-object-handoff`).
- Pipelinq's produce-side and its UI.
- Two-way status sync back to pipelinq (candidate follow-up once the hydra contract defines it).
- Request-to-case *conversion rules* beyond the contract's field mapping (zaaktype inference etc.
  stays a procest intake decision made by the behandelaar).

## Open Questions

- ~~ADR number collision~~ RESOLVED 2026-07-05 (owner decision): ADR-049 =
  `adr-049-declarative-widget-vocabulary`, ADR-050 = reserved for the Spectr re-platform ADR,
  and the semantic-object-handoff ADR is **ADR-051**
  (`hydra/openspec/architecture/adr-051-semantic-object-handoff.md`); this change cites the ADR
  by name and number.
- Exact contract field names (`title`/`summary`/`requester`/`channel`/`priority`/provenance) are
  taken from the 2026-07-05 product-owner brief; final names bind to the hydra change's published
  contract at apply time (DC02).
