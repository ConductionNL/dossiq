# Proposal: procest-delegate-remaining-decisions-to-decidesk

kind: delegation / dedup refactor — cites **ADR-019** (integration-registry: cross-app calls go
through the shared OpenRegister integration registry, never hard-coded HTTP), **ADR-005**
(decidesk Decision supertype: a single `Decision` entity with `decisionType` is the fleet's
canonical decision authority — routes/stages, methods incl. advice & signature/eIDAS-as-method,
chair-register), **ADR-012** (deduplication: a change must prove it isn't re-implementing an
existing capability), **ADR-022** (apps-consume-or-abstractions) and **CROSS-APP INTERFACE
CONTRACT #1** (Decisions / contracts / approvals → decidesk; a consuming app raises a Decision via
the integration registry, consumes the OUTCOME, fails CLOSED, and keeps its own domain record as a
PROJECTION of the outcome).

## Summary

The earlier change **`procest-delegate-contract-decision`** (merged) delegated the **contract /
besluitvorming-engine** decision node to decidesk: the contract-renewal approval path, the
agenda/publication/mandaat besluitvorming engine, the `BezwaarDecisionForm.vue` **UI**, the
`DecisionTypesTab.vue` config, and the `bvw-*` besluit templates. It did **NOT** touch the
remaining decision/advice **backend** surfaces that procest still owns and that, per cross-app
contract #1, belong in decidesk.

This change extends that delegation to those **REMAINING** decision/advice flows. Each raises a
decidesk `Decision` of the appropriate `decisionType` (ADR-005) via the ADR-019 integration
registry and consumes the outcome (fail CLOSED). procest **keeps** the ZGW case record and the
ZGW `Besluit` artifact as a **PROJECTION** of the decidesk outcome — no ZGW regression.

The remaining duplicated surfaces (verified against live code in Phase 0) are:

- **Beslissing op bezwaar (decision on objection) — BACKEND.** `lib/Service/Bezwaar/DecisionService.php`
  is a procest-local besluit-**authoring** engine: `draft()` writes a `bezwaarDecision` object
  (disposition, reasoning, legalBasis, replacementDecision) and `publish()` runs the Awb validity
  matrix and **authors** the besluit independently, then `applyToBezwaar()` hands it to the status
  engine. The prior change rewired only the `BezwaarDecisionForm.vue` UI; this backend decision
  authority remains procest-local and must delegate (decisionType `bezwaar-decision`).
- **BAC-adviezen (objection advisory committee advice).** `lib/Service/Bezwaar/AdvisoryCommitteeService.php`
  (`assignToCommittee`, `transitionAdviceStatus`, `recordCouncilDeviation`) is a procest-local
  **advice** authority for the bezwaarschriftencommissie. Advice is a decidesk decisionType
  (`advice`) under ADR-005; the BAC advice + council-deviation should be raised/consumed as a
  decidesk advice Decision. `src/views/cases/components/bezwaar/AdvisoryReportPanel.vue` is its UI.
- **Advice (adviesAanvraag) — general.** `lib/Controller/AdviceController.php` + `lib/Service/AdviceService.php`
  (`requestAdvice`, `submitAdvice`, `transitionStatus`, `cancelAdvice`) run a procest-local advice
  request/response lifecycle. The advice **request → advice outcome** is a decidesk `advice`
  Decision. UI: `src/views/cases/components/Advice*.vue`, `AdviesAanvraagDialog.vue`.
- **Consultation advice.** `lib/Controller/ConsultationController.php` + `lib/Service/ConsultationService.php`
  (`createConsultation`, `submitResponse`, advisory-body consultation) is a second procest-local
  advice surface (consultatie/zienswijze advice from advisory bodies) — same decidesk `advice`
  decisionType.
- **Voorstellen (proposals) → besluit.** `src/views/voorstellen/VoorstelDetail.vue` +
  `components/BesluitRegistration.vue` let a user **register a besluit** directly on a voorstel
  (proposal). A proposal that is decided into a besluit is a decidesk Decision (decisionType
  `report-adoption` / `voorstel-besluit`); the besluit becomes a PROJECTION on the case, not a
  procest-authored artifact.

**What changes:** each remaining flow raises a decidesk `Decision` (correct decisionType) through
the ADR-019 integration registry and consumes the outcome; the ZGW `Besluit` / advice record on
the case is **materialised from the decidesk outcome** (fail CLOSED — never auto-decide locally).

**What stays:** procest keeps ZGW **case management** — the bezwaar/voorstel/advies is still a
zaak, the Awb **domain rules** (7:11 disposition set, 7:12 motivering, proceskosten, rechtsmiddelen
clause, BAC panel-independence check) stay as procest validation, and the ZGW `Besluit` is still
recorded on the case dossier (Besluiten API). The `Besluit`/advice record is a **PROJECTION** of
the decidesk outcome; only the *making* of the decision/advice moves to decidesk.

**Dedup rationale (ADR-012):** decidesk's Decision supertype (ADR-005) already models decisionType
(incl. `advice`, `bezwaar-decision`, `report-adoption`), routes/stages and methods. procest's
`Bezwaar/DecisionService`, `AdvisoryCommitteeService`, `AdviceService`, `ConsultationService` and
voorstel-besluit registration each re-implement a slice of that authority and drift independently.
The prior change closed the contract/besluitvorming slice; this change closes the **remaining**
bezwaar-decision / advice / consultation / voorstel-besluit slices. Phase 0 documents exactly what
was already delegated (do NOT duplicate) vs. what remains. This change does **NOT** touch the nav
grouping — the sibling change `procest-objections-appeals-group` owns that.

**Depends on:** `decidesk-contract-decision-hub` (decidesk side, merged — exposes the decision hub:
`POST /api/v1/decisions` with provenance fields + decisionType, `GET /api/v1/decisions/{id}/outcome`,
`POST /api/v1/decisions/{id}/subscriptions`). Builds on the merged `procest-delegate-contract-decision`
(reuses its `ContractDecisionDelegationService` integration-registry pattern + `BesluitMaterialisationService`).
Relates to (does not block) `pluggable-integration-registry` / `integration-maps` (ADR-019 registry,
OpenRegister side) and the sibling `procest-objections-appeals-group` (nav only).

## Why

procest is a ZGW case-management app. A bezwaar, a voorstel, or an advice request is legitimately a
*zaak*. But the **decision / advice** on that zaak — the beslissing op bezwaar, the BAC-advies, the
adviesuitkomst, the voorstel→besluit — is a governance concern that decidesk owns fleet-wide
(cross-app contract #1, ADR-005). procest keeping `Bezwaar/DecisionService`, `AdvisoryCommitteeService`,
`AdviceService`, `ConsultationService`, and a direct besluit-registration on voorstellen means:

- **Duplicate decision/advice authority.** decidesk's Decision supertype already models `advice`
  and decision dispositions; procest's services re-implement subsets that drift independently.
- **No cross-app decision queries.** A concerncontroller cannot ask "all pending advice / bezwaar
  decisions across apps" while procest keeps them in its own case objects.
- **ZGW compliance is NOT the duplication.** Recording a ZGW `Besluit` (and an advice record) on a
  zaak is genuine procest responsibility; procest keeps that — but materialises it *from* the
  decidesk outcome rather than authoring it.

## What

1. **Delegate beslissing-op-bezwaar (backend) to decidesk (ADR-019).** `Bezwaar/DecisionService::draft()`/
   `publish()` raise a decidesk `Decision` (decisionType `bezwaar-decision`) carrying disposition,
   reasoning, legalBasis, replacementDecision; on outcome, materialise the ZGW `Besluit` + the
   rechtsmiddelenclausule on the case. KEEP the Awb domain rules (7:11 disposition set, 7:12
   motivering, proceskosten, replacement guard) as procest validation; STOP authoring the besluit
   locally. `applyToBezwaar()` consumes the decidesk outcome rather than a locally-authored decision.
2. **Delegate BAC-adviezen to decidesk.** `Bezwaar/AdvisoryCommitteeService` raises a decidesk
   `advice` Decision for the bezwaarschriftencommissie advice (and `recordCouncilDeviation` records
   the council deviation against that advice Decision's outcome). KEEP the panel-independence guard
   (REQ-BAC-2) as a procest domain rule.
3. **Delegate Advice (adviesAanvraag) + Consultation advice to decidesk.** `AdviceService` and
   `ConsultationService` raise a decidesk `advice` Decision for an advice/consultation request and
   consume the advice outcome; the ZGW advice record on the case is a projection. KEEP the IDOR
   fail-closed authorisation (the adviseur/assigned-body gate) as procest validation.
4. **Delegate Voorstel→besluit to decidesk.** `BesluitRegistration.vue` / `VoorstelDetail`
   `canRegisterBesluit` raise a decidesk `Decision` (decisionType `report-adoption`/`voorstel-besluit`)
   for the proposal; on outcome the ZGW `Besluit` is materialised on the case. KEEP the parafeerroute
   (parafering) as procest case orchestration (it is delegated separately by
   `migrate-parafering-to-or-approval-workflow`).
5. **Reuse the delegation plumbing.** Extend the merged `ContractDecisionDelegationService` (or add
   thin `BezwaarDecisionDelegationService` / `AdviceDelegationService` siblings) and reuse
   `BesluitMaterialisationService`; all resolve the `decidesk` integration leaf via the OR
   integration registry and **fail CLOSED** when unavailable (mirrors `hydra-gate-unsafe-auth-resolver`).
6. **Keep ZGW case management untouched.** The bezwaar/voorstel/advies remains a zaak; the ZGW
   `Besluit` is still recorded (now as a projection); IDOR fail-closed scoping is preserved.
7. **Migration for in-flight cases.** A `lib/Repair/*` step links existing open bezwaar-decision /
   advice / consultation / voorstel cases to a decidesk Decision (or keeps an already-recorded
   `Besluit`/advice as the authoritative historical record), so no decision/advice data is dropped.

## Capabilities

### New Capabilities

- `remaining-decision-delegation`: procest raises a decidesk `Decision` (decisionType
  `bezwaar-decision` / `advice` / `report-adoption`) for the remaining decision/advice surfaces
  (beslissing-op-bezwaar backend, BAC-adviezen, advies/consultatie, voorstel→besluit) via the
  ADR-019 integration registry and consumes the outcome, materialising the ZGW `Besluit`/advice
  record on the case as a projection. Fails CLOSED when decidesk is unavailable.

### Modified Capabilities

- The bezwaar-decision surface (archived `bezwaar-decision`) — `Bezwaar/DecisionService` stops
  authoring the besluit; the decision is a decidesk Decision; the Awb domain rules are retained.
- The BAC advice surface (archived `bezwaar-advisory-committee`) — advice is a decidesk `advice`
  Decision; panel-independence stays a procest rule.
- The advice + consultation surfaces (archived `advice-management` / `consultation-management`) —
  advice requests/outcomes are decidesk `advice` Decisions; IDOR fail-closed stays.
- The voorstellen surface (archived `voorstel-management`) — besluit registration on a voorstel is
  a decidesk Decision; the parafeerroute is untouched here.

## Affected Projects

- [x] Project: `procest` — all implementation tasks are in this repo.
- [x] Project: `decidesk` — consumes the merged `decidesk-contract-decision-hub` surface
      (no decidesk code in this change).
- [x] Project: `openregister` — no code change; uses the existing ADR-019 integration registry.

## Out of Scope

- The contract-renewal / besluitvorming-engine / `BezwaarDecisionForm.vue` UI / `DecisionTypesTab.vue` /
  `bvw-*` templates — already delegated by the merged `procest-delegate-contract-decision`
  (do NOT re-do).
- The **nav grouping** of objections/appeals — owned by the sibling change
  `procest-objections-appeals-group`.
- The **parafeerroute / parafering** approval chain — owned by
  `migrate-parafering-to-or-approval-workflow` (status/route engine), not re-built here.
- IFRS/contract *accounting* (shillinq) and document *e-signature* (docudesk) — separate contracts.
- Backfilling historical besluit/advice records into decidesk Decisions (historical records stay
  authoritative; only in-flight cases are linked forward).

## Success Criteria

- `openspec validate --strict procest-delegate-remaining-decisions-to-decidesk` exits 0.
- A beslissing-op-bezwaar raises a decidesk `bezwaar-decision` Decision (via the integration
  registry); `Bezwaar/DecisionService` no longer authors the besluit locally; the ZGW `Besluit` +
  rechtsmiddelenclausule are materialised from the decidesk outcome.
- A BAC-advies and an advies/consultatie raise a decidesk `advice` Decision and consume the advice
  outcome; panel-independence and the IDOR fail-closed gate remain procest rules.
- Registering a besluit on a voorstel raises a decidesk Decision and materialises the ZGW `Besluit`
  as a projection.
- When decidesk is unavailable, every delegation **fails CLOSED** — no procest-local decision/advice
  state machine decides as a fallback.
- ZGW case management is unchanged; the ZGW `Besluit` shape is preserved (no compliance regression).
- A `lib/Repair/*` step links in-flight cases forward without dropping any recorded besluit/advice.
- This change does NOT modify the nav (left to `procest-objections-appeals-group`).
