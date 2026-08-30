# Tasks — Delegate procest's remaining decision/advice flows to decidesk

## Phase 0: Deduplication Check (ADR-012)

- [x] Confirm decidesk owns decision/advice *making*: Decision supertype with `decisionType` incl.
      `advice`, `bezwaar-decision`, `report-adoption`; routes/stages; methods (vote/chair-register/
      signature/advice); hub merged (`decidesk-contract-decision-hub`: `POST /api/v1/decisions`,
      `GET /api/v1/decisions/{id}/outcome`, `POST /api/v1/decisions/{id}/subscriptions`).
- [x] Confirm what the **prior merged** `procest-delegate-contract-decision` already delegated (DO
      NOT duplicate): contract-renewal approval (`ContractController`/`ContractRenewalService::requestRenewal`);
      the besluitvorming engine (`BesluitvormingController`/`AgendaController`/`PublicationController`/
      `MandaatController` + `bvw-*` templates); the `BezwaarDecisionForm.vue` UI; `DecisionTypesTab.vue`;
      and the `ContractDecisionDelegationService` + `BesluitMaterialisationService` plumbing.
- [x] Inventory the **REMAINING** procest-local decision/advice surface (verified against live code):
  - `lib/Service/Bezwaar/DecisionService.php` — `draft()` writes a `bezwaarDecision` object,
    `publish()` runs the Awb validity matrix and **authors** the besluit, `applyToBezwaar()` hands
    it to the status engine (DELEGATE the deciding; KEEP the Awb rules; materialise the Besluit).
  - `lib/Service/Bezwaar/AdvisoryCommitteeService.php` — `assignToCommittee`/`transitionAdviceStatus`/
    `recordCouncilDeviation` = procest-local BAC advice authority (DELEGATE as decidesk `advice`
    Decision; KEEP panel-independence guard REQ-BAC-2).
  - `lib/Controller/AdviceController.php` + `lib/Service/AdviceService.php` — `requestAdvice`/
    `submitAdvice`/`transitionStatus`/`cancelAdvice` = procest-local advies lifecycle (DELEGATE as
    decidesk `advice` Decision; KEEP `assertAdviceCallerIsAuthorized` IDOR gate).
  - `lib/Controller/ConsultationController.php` + `lib/Service/ConsultationService.php` —
    `createConsultation`/`submitResponse` advisory-body consultation (DELEGATE as decidesk `advice`).
  - `src/views/voorstellen/components/BesluitRegistration.vue` + `VoorstelDetail.vue`
    (`canRegisterBesluit`) — register a besluit on a voorstel (DELEGATE as decidesk `report-adoption`
    Decision; KEEP parafeerroute untouched).
- [x] Confirm what procest KEEPS (genuine ZGW case management / domain rules, NOT duplicated): the
      bezwaar/voorstel/advies remains a zaak; the ZGW `Besluit` artifact recorded on the case file
      (Besluiten API, now a projection); the Awb domain rules (7:11/7:12/proceskosten/replacement
      guard); the BAC panel-independence check; the advice IDOR fail-closed gate; the rechtsmiddelen
      clause completeness check.
- [x] Confirm dependency: `decidesk-contract-decision-hub` (merged) exposes the decision hub
      reused here. No procest code authors the decision/advice after this change.
- [x] Confirm NO overlap with sibling changes: `procest-objections-appeals-group` (nav grouping —
      this change touches NO nav); `migrate-parafering-to-or-approval-workflow` (parafeerroute —
      untouched here); `migrate-status-engine-to-or-lifecycle` (status engine). This change is
      specifically the *remaining decision/advice* node → decidesk.

## Phase 1: Delegation services (raise decidesk Decisions via ADR-019)

- [x] Add `lib/Service/BezwaarDecisionDelegationService.php` (or extend `ContractDecisionDelegationService`):
      `raiseBezwaarDecision(string $bezwaarId, array $payload): string` — resolve the `decidesk`
      integration leaf from the OR registry (ADR-019), `POST /api/v1/decisions` with decisionType
      `bezwaar-decision` + provenance (sourceApp=procest, subjectSchema=`bezwaarDecision`,
      subjectId=$bezwaarId), return `decisionRef`. Fail **closed** when the leaf is unavailable.
- [x] Add `lib/Service/AdviceDelegationService.php`: `raiseAdviceDecision(string $subjectSchema,
      string $subjectId, array $payload): string` — decisionType `advice`; reused by BAC,
      adviesAanvraag and consultation. Fail **closed** when unavailable.
- [x] Add a `raiseVoorstelBesluit(string $voorstelId, array $payload): string` path — decisionType
      `report-adoption`; subjectSchema=`voorstel`. Fail **closed** when unavailable.
- [x] Reuse `BesluitMaterialisationService` (from the merged change) for outcome → ZGW `Besluit`.
- [x] Register the new services in `lib/AppInfo/Application.php` DI.
- [x] Unit tests: leaf-available → Decision raised + ref returned per decisionType; leaf-unavailable
      → fails closed, no local decision/advice state set.

## Phase 2: Delegate beslissing-op-bezwaar (backend)

- [x] `lib/Service/Bezwaar/DecisionService.php` — run the Awb validity matrix (7:11 disposition set,
      7:12 reasoning+legalBasis required, proceskosten rules, replacementDecision guard) as
      pre-flight validation, then `raiseBezwaarDecision(...)` instead of authoring the besluit.
      `applyToBezwaar()` consumes the decidesk outcome and materialises the ZGW `Besluit` +
      rechtsmiddelenclausule on the case. STOP setting `status:'draft'`/`publishedAt` as a local
      decision state.
- [x] Keep `BezwaarDecisionListener`/`BezwaarLifecycleListener` wiring; point them at the outcome
      consumption path rather than local authoring.
- [x] Update `@spec` tags on the touched methods to point at this change's spec.

## Phase 3: Delegate BAC-adviezen + advies + consultatie

- [x] `lib/Service/Bezwaar/AdvisoryCommitteeService.php` — keep `assignToCommittee` (panel set) +
      the panel-independence guard; raise a decidesk `advice` Decision for the committee advice;
      `recordCouncilDeviation` records the council deviation against that advice Decision outcome.
- [x] `lib/Service/AdviceService.php` / `lib/Controller/AdviceController.php` — keep the IDOR gate
      (`assertAdviceCallerIsAuthorized`); `requestAdvice` raises a decidesk `advice` Decision;
      `submitAdvice`/`transitionStatus` consume/reflect the advice outcome rather than authoring it.
- [x] `lib/Service/ConsultationService.php` / `lib/Controller/ConsultationController.php` —
      `createConsultation` raises a decidesk `advice` Decision; `submitResponse` consumes the outcome.
- [x] Update `@spec` tags on the touched methods.

## Phase 4: Delegate Voorstel → besluit (UI)

- [x] `src/views/voorstellen/components/BesluitRegistration.vue` + `VoorstelDetail.vue` — on
      "Besluit registreren", raise a decidesk `report-adoption` Decision for the voorstel; on
      outcome materialise the ZGW `Besluit` on the case. Do NOT author a procest-local besluit.
- [x] Leave the parafeerroute components (`ParafeerActieDialog`/`ParafeerInbox`/`ProgressTimeline`)
      UNTOUCHED (parafering is owned by `migrate-parafering-to-or-approval-workflow`).

## Phase 5: Decision/advice UI → decidesk-backed reads

- [x] `src/views/cases/components/bezwaar/AdvisoryReportPanel.vue` — read the decidesk advice
      Decision outcome (advice type / summary / grounds / recommendation / deviation) instead of a
      procest-local advice record where the field is decided in decidesk; keep the create-form Awb
      fields as procest input that feeds the raised Decision.
- [x] `src/views/cases/components/Advice*.vue`, `AdviesAanvraagDialog.vue` — raise/read the decidesk
      advice Decision via the delegation endpoints; update the relevant Pinia store/service calls.

## Phase 6: Migration of in-flight cases

- [x] Add `lib/Repair/LinkInFlightRemainingDecisionsRepair.php`: for each open bezwaar-decision /
      advies / consultatie / voorstel case, link it to a decidesk Decision so its outcome can
      complete there; if a `Besluit`/advice is already recorded, keep it as the authoritative
      historical record. Idempotent + fail-safe; no decision/advice data dropped. Use the
      `setRegister(slug)->setSchema(Name)->findAll([])` read pattern + POSITIONAL args for OCP calls.
- [x] Register the repair step in `appinfo/info.xml` `<repair-steps>`.
- [x] Confirm historical records stay authoritative (Out-of-Scope) — only in-flight cases linked forward.

## Phase 7: Validation & gates

- [x] `openspec validate --strict procest-delegate-remaining-decisions-to-decidesk` exits 0.
- [x] Hydra gates green: spec-coverage `@spec` on touched methods; unsafe-auth-resolver clean (every
      delegation fails closed — no `catch { return null }` fall-open); route-reachability/route-auth
      on touched endpoints; redundant-controller clean.
- [x] e2e: a beslissing-op-bezwaar / BAC-advies / advies / voorstel-besluit each raises a decidesk
      Decision and materialises the ZGW `Besluit`/advice record on outcome; decidesk-unavailable
      fails closed; ZGW `Besluit` shape unchanged.
- [x] Confirm NO nav/menu-layout edit was made (sibling `procest-objections-appeals-group` owns nav).
