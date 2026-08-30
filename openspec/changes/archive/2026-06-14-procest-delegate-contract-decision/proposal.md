# Proposal: procest-delegate-contract-decision

kind: delegation / dedup refactor — cites **ADR-019** (integration-registry: cross-app calls go
through the shared OpenRegister integration registry, not hard-coded HTTP), **ADR-022**
(apps-consume-or-abstractions: no redundant per-app engine duplicating a fleet capability),
**ADR-012** (deduplication: a change must prove it isn't re-implementing an existing capability)
and **ADR-031** (schema-declarative-business-logic).

## Summary

procest today reimplements a contract-DECISION / approval / sign-off engine locally that should
live in **decidesk**, the fleet's canonical decision/approval authority (Decision supertype with
`decisionType`, decision routes/stages, decision methods including signature/eIDAS-as-a-method,
chair-register, advice, Minutes signers). The duplicated surface in procest is:

- `lib/Controller/ContractController.php` — supplier contract list/detail + `requestRenewal`
  (opens a renewal *case*; the *decision to approve the renewal* is the duplicated node).
- `lib/Service/ContractRenewalService.php` — renewal orchestration; `requestRenewal()` opens a
  `leverancier-contractverlenging-verzoek` case and is the entry to procest's own approval path.
- `lib/BackgroundJob/ScanExpiringContractsJob.php` + `ContractRenewalService::scanAndFlagExpiring()`
  — nightly contract-expiry sweep that flags `renewalWarning`.
- `besluitvorming#*` routes (`agenda#addToAgenda`, `agenda#updateAgendaItem`,
  `publication#publish`, `mandaat#mandaatCheck`, `besluitvorming#activateTemplate`) +
  `AgendaController` / `PublicationController` / `MandaatController` / `BesluitvormingController`
  + the `bvw-*` besluit templates — procest's parallel besluitvorming (decision-making) engine.
- `src/views/settings/tabs/DecisionTypesTab.vue` — operator config of decision *types*
  (a decidesk concern: `decisionType`).
- `src/views/cases/components/bezwaar/BezwaarDecisionForm.vue` — the besluit *making* form
  (disposition, follows-advice, motivation, appeal clause) — the duplicated decision-authoring UI.

**What changes:** the contract-DECISION / approval / sign-off flow (approve a renewal, decide
besluitvorming, authorise-to-sign) DELEGATES to decidesk via the ADR-019 integration registry:
procest raises a decidesk `Decision` and consumes the outcome. **What stays:** procest keeps ZGW
**case management** — the contract is still a zaak, the nightly expiry scan still runs, the
supplier portal still lists contracts — and procest still records the ZGW **`Besluit`** artifact
on the case file. The `Besluit` is **materialised from the decidesk Decision outcome**: decidesk
owns the *making* of the decision; procest records the ZGW `Besluit` for the case dossier. This
keeps ZGW (Besluiten API) compliance intact while removing the parallel approval state machine.

**Dedup rationale (ADR-012):** decidesk already owns decision *making* (`decidesk-contract-decision-hub`
exposes the contract-decision integration surface). procest's besluitvorming engine,
DecisionTypes config, and renewal-approval path duplicate that authority. Per ADR-012 we do not
keep two decision engines; per ADR-022 a leaf app consumes the fleet decision capability rather
than re-shipping it. Phase 0 documents exactly what is duplicated vs. what is genuine ZGW case
management that procest must keep.

**Depends on:** `decidesk-contract-decision-hub` (decidesk side — exposes the contract-decision
integration surface: raise a Decision for a contract approval/renewal/sign-off, return the
outcome). Also relates to (does not block) `pluggable-integration-registry` and `integration-maps`
(ADR-019 registry, OpenRegister side).

## Why

procest is a ZGW case-management app (zaakgericht werken). A contract renewal or a besluit on a
case is legitimately a *zaak*. But the **decision** on that zaak — approve / reject / sign-off,
who is mandated, which decision method (vote, chair-register, signature/eIDAS) — is a governance
concern that decidesk owns fleet-wide. procest having its own `AgendaController`,
`PublicationController`, besluit templates, `DecisionTypesTab`, and `BezwaarDecisionForm` means:

- **Duplicate decision authority.** decidesk's Decision supertype already models decisionType,
  routes/stages, methods (incl. signature/eIDAS-as-method), advice and chair-register. procest's
  besluitvorming engine re-implements a subset and drifts independently.
- **No cross-app decision queries.** A board / concerncontroller cannot ask "all pending
  contract decisions across apps" while procest keeps decisions in its own case objects.
- **Mandate logic re-implemented.** `MandaatController::mandaatCheck` + `MandaatValidationService`
  re-do "is the signing user mandated?" that decidesk's decision route/stage assignee model
  already enforces.
- **ZGW compliance is NOT the duplication.** Recording a ZGW `Besluit` on a zaak is a genuine
  procest responsibility (the case file must carry the Besluit artifact for the Besluiten API).
  procest keeps that — but materialises it *from* the decidesk outcome rather than authoring it.

## What

1. **Delegate the contract-decision node to decidesk (ADR-019).** A new
   `ContractDecisionDelegationService` raises a decidesk `Decision` through the OR integration
   registry when a contract needs approval/renewal/sign-off, and consumes the returned outcome.
   `ContractController::requestRenewal` and `ContractRenewalService::requestRenewal` are rewired:
   the renewal *case* is still opened (ZGW), but the approval *decision* is a decidesk Decision,
   not a procest-local approval state.
2. **Materialise the ZGW `Besluit` from the decidesk outcome.** `PublicationService` /
   `BesluitMaterialisationService` writes the ZGW `Besluit` onto the case from the decidesk
   Decision outcome (verleend/geweigerd → besluit result, datum, motivering, mandaat-houder).
   The Besluiten-API shape is preserved; only the *origin* of the decision changes.
3. **Retire procest's parallel besluitvorming engine.** `BezwaarDecisionForm.vue` and
   `DecisionTypesTab.vue` become decidesk-backed (raise/read a Decision) rather than authoring a
   procest-local besluit; `AgendaController` / `PublicationController` besluit-*making* endpoints
   are reduced to ZGW-record endpoints; the `bvw-*` besluit decision-type templates are
   deprecated in favour of decidesk decisionTypes. Mandate checking delegates to the decidesk
   decision route/stage assignee model.
4. **Keep ZGW case management untouched.** The contract is still a zaak; `ScanExpiringContractsJob`
   + `scanAndFlagExpiring()` still run nightly and flag `renewalWarning`; the supplier portal
   (`ContractController::index`/`show`) still lists/serves contracts; IDOR fail-closed scoping is
   preserved.
5. **Migration for in-flight contract cases.** A `lib/Repair/*` step links existing open contract
   /besluitvorming cases to a decidesk Decision (or leaves their already-recorded `Besluit` as the
   authoritative historical record), so no decision data is dropped.

## Capabilities

### New Capabilities

- `contract-decision-delegation`: procest raises a decidesk `Decision` for any contract
  approval / renewal / sign-off and consumes the outcome via the ADR-019 integration registry,
  then materialises the ZGW `Besluit` on the case from that outcome.

### Modified Capabilities

- The supplier-portal contract surface (`leverancier-zaakportaal-09-contract-backend`) — list/detail
  and the nightly expiry scan are **unchanged**; only `requestRenewal` is rewired so the approval
  decision is a decidesk Decision.
- The besluitvorming surface (archived `besluitvorming-workflow`) — agenda/publication endpoints
  are reduced to ZGW-record endpoints; decision *making* moves to decidesk.

## Affected Projects

- [x] Project: `procest` — all implementation tasks are in this repo.
- [x] Project: `decidesk` — counterpart `decidesk-contract-decision-hub` exposes the
  contract-decision integration surface (separate change; no procest code).
- [x] Project: `openregister` — no code change; uses the existing ADR-019 integration registry.

## Out of Scope

- IFRS15/16 contract *accounting* schemas (those live in **shillinq**, untouched here).
- docudesk document e-signature (`signingRequest`/`signingSession`) — a contract *document*
  signature is requested from docudesk; that is a separate concern and not re-built here.
- Removing procest's generic case/zaak engine, status engine, or parafering (covered by other
  changes: `migrate-status-engine-to-or-lifecycle`, `migrate-parafering-to-or-approval-workflow`).
- Backfilling historical besluit records into decidesk Decisions (historical `Besluit` rows stay
  as the authoritative record; only in-flight cases are linked forward).

## Success Criteria

- `openspec validate --strict procest-delegate-contract-decision` exits 0.
- Requesting a contract renewal raises a decidesk `Decision` (via the integration registry) and
  no procest-local approval state machine advances the decision.
- A ZGW `Besluit` is materialised on the case from the decidesk Decision outcome; the Besluiten-API
  shape is preserved (ZGW compliance unbroken).
- The nightly `ScanExpiringContractsJob` still runs and flags `renewalWarning`; the supplier
  portal still lists/serves contracts with IDOR fail-closed scoping intact.
- `DecisionTypesTab.vue` / `BezwaarDecisionForm.vue` no longer author a procest-local besluit;
  the `bvw-*` decision-type templates are deprecated.
- A `lib/Repair/*` step links in-flight contract/besluitvorming cases forward without dropping
  any recorded `Besluit`.
