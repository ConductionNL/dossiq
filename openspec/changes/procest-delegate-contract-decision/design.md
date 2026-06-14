# Design — procest-delegate-contract-decision

## Context

procest is a thin ZGW case-management client over OpenRegister. Over time it accreted a parallel
decision/approval engine: a contract-renewal approval path, a besluitvorming (decision-making)
engine (agenda → publish → mandaat), decision-type configuration, and a besluit-authoring UI.
The fleet decided (CROSS-APP INTERFACE CONTRACT #3) that **decidesk owns the decision / approval
/ signing flow**, while **the originating finance/case app keeps its domain artifacts**. For
procest that means: keep ZGW case management + record the ZGW `Besluit`; delegate the *making* of
the decision to decidesk.

The hard constraint is **ZGW compliance**: `Besluit` is a first-class ZGW concept (Besluiten API).
procest must still attach a `Besluit` to the zaak. The redesign must therefore separate
*deciding* (decidesk) from *recording the Besluit artifact* (procest).

## Key decisions

### D1 — Split "deciding" from "recording the Besluit"

The single most important boundary. We decompose the existing flow into two responsibilities:

| Responsibility | Owner | Mechanism |
| --- | --- | --- |
| Decide (approve/reject/sign-off, decisionType, route/stage, method incl. eIDAS, mandate, advice) | **decidesk** | decidesk `Decision` raised + outcome consumed |
| Record the ZGW `Besluit` artifact on the zaak (Besluiten-API shape, datum, result, motivering, mandaathouder) | **procest** | `Besluit` materialised on the case from the decidesk outcome |
| Manage the case/zaak lifecycle (the contract IS a zaak; renewal IS a case) | **procest** | unchanged ZGW case engine |
| Scan contracts for expiry + flag `renewalWarning` | **procest** | unchanged `ScanExpiringContractsJob` |
| Serve the supplier portal contract list/detail | **procest** | unchanged `ContractController::index`/`show` |

decidesk is the *system of decision*; procest is the *system of record for the case file*. The
Besluit on the zaak is a **projection** of the decidesk outcome, not an independently-authored
artifact.

### D2 — Integration via the ADR-019 registry, not hard-coded HTTP

procest raises the Decision through the OpenRegister **integration registry** (ADR-019), the same
mechanism procest already uses for other cross-app calls. A new `ContractDecisionDelegationService`:

- `raiseContractDecision(caseRef, contractRef, decisionType, subject, mandateContext)` → resolves
  the `decidesk` integration leaf from the registry and creates a decidesk `Decision`, returning a
  `decisionRef`.
- `consumeOutcome(decisionRef)` / outcome webhook → reads the decided Decision (result, datum,
  motivering, signer/mandaathouder, method) and hands it to `BesluitMaterialisationService`.

When the decidesk leaf is unavailable the service **fails closed** (no silent fall-open to a
local approval) and surfaces a clear "decision service unavailable" error — never auto-approves.
This mirrors the `hydra-gate-unsafe-auth-resolver` rule: a null/unavailable decision service must
not be treated as "decision skipped".

### D3 — Materialise the ZGW `Besluit` from the outcome

`BesluitMaterialisationService` (or the reduced `PublicationService`) writes the `Besluit` onto the
zaak from the decidesk outcome, mapping:

- decidesk Decision `result` (verleend / geweigerd / aangehouden) → ZGW `Besluit` result.
- decidesk `decidedAt` → ZGW `Besluit.datum`.
- decidesk motivering / advice → ZGW `Besluit.toelichting`.
- decidesk signer / mandaathouder + method (eIDAS/chair-register/vote) → recorded on the Besluit
  for the audit/ZGW dossier.

The Besluiten-API field shape is preserved exactly; only the *origin* of the values changes
(decidesk outcome instead of the `BezwaarDecisionForm` / `PublicationService` local authoring).

### D4 — Reduce, don't blindly delete, the besluitvorming endpoints

- `besluitvorming#activateTemplate` — deprecated; decision *types* come from decidesk decisionTypes.
- `agenda#addToAgenda` / `agenda#updateAgendaItem` — kept as **agenda** (a case's agenda is ZGW
  case orchestration) but no longer drive decision authoring.
- `publication#publish` — narrowed to "publish the recorded ZGW `Besluit`" (a publication/openbaar
  concern on a zaak), fed by the decidesk outcome rather than authoring the besluit.
- `mandaat#mandaatCheck` — delegated: mandate is the decidesk decision route/stage assignee model;
  the procest endpoint becomes a thin read-through or is removed once callers migrate.

Routes that remain routable for deep-links after their decision role is removed are documented
(established pattern). We do not break the router; we narrow semantics.

### D5 — DecisionTypes + Bezwaar decision UI become decidesk-backed

- `DecisionTypesTab.vue` — stops persisting procest-local decision *types*; reads decidesk
  decisionTypes (or is retired as a settings tab) so there is one decisionType authority.
- `BezwaarDecisionForm.vue` — a "beslissing op bezwaar" is a decision: the form raises a decidesk
  Decision (disposition, follows-advice, motivation, reformatio-in-peius guard kept as a
  **domain rule** procest still owns) and, on outcome, the ZGW Besluit (incl. the
  rechtsmiddelenclausule / appeal clause) is materialised on the case. The *legal text rules*
  (art. 7:12 Awb motivering, ex-nunc heroverweging) stay in procest as domain validation; the
  *deciding* moves to decidesk.

## Alternatives considered

- **Keep procest's besluitvorming engine, only share data.** Rejected — violates ADR-012/ADR-022:
  two decision engines drift, and cross-app decision queries remain impossible.
- **Move the ZGW `Besluit` into decidesk too.** Rejected — `Besluit` is part of the ZGW zaak
  dossier (Besluiten API) and must live with the case for compliance/archiving. decidesk decides;
  procest records.
- **Hard-coded HTTP call to decidesk.** Rejected — ADR-019 mandates the integration registry so
  the binding is configurable and discoverable, and so a missing leaf fails closed cleanly.

## Migration / rollout

1. Ship `ContractDecisionDelegationService` + `BesluitMaterialisationService` behind the integration
   registry; wire `requestRenewal` and `BezwaarDecisionForm` to raise decidesk Decisions.
2. Add a `lib/Repair/LinkInFlightContractDecisionsRepair` step: for each open contract /
   besluitvorming case, either (a) link it to a decidesk Decision so its outcome can complete
   there, or (b) if a `Besluit` is already recorded, keep that as the authoritative historical
   record (no forward link needed). **No besluit data is dropped.**
3. Deprecate the `bvw-*` besluit templates and the procest-local decision-type persistence; keep
   them readable until sunset.
4. Sunset the procest-local approval/besluit-authoring code paths once in-flight cases are linked.

## Risks

- **ZGW compliance regression.** Mitigated by D3: the Besluiten-API shape is preserved; a contract
  test asserts the materialised `Besluit` matches the prior schema.
- **Fail-open on decidesk unavailability.** Mitigated by D2: the delegation service fails closed,
  never auto-approves (mirrors `hydra-gate-unsafe-auth-resolver`).
- **In-flight cases stranded.** Mitigated by the Repair step (D-migration); historical Besluiten
  stay authoritative.
- **Mandate semantics drift during transition.** During the window where `mandaatCheck` still
  exists as a read-through, both procest and decidesk could answer "is X mandated?" — resolved by
  making decidesk's route/stage assignee model authoritative and reducing the procest endpoint.
