# Tasks — Delegate procest contract/besluit decisions to decidesk

## Phase 0: Deduplication Check (ADR-012)

- [x] Confirm decidesk owns decision *making*: Decision supertype (`decisionType`), decision
      routes/stages, decision methods incl. signature/eIDAS-as-method, chair-register, advice,
      Minutes signers (verified in SHARED-BRIEF fleet facts).
- [x] Inventory procest's duplicated decision surface (verified against live code):
  - `lib/Controller/ContractController.php` — supplier contract list/detail + `requestRenewal`
    (the renewal-approval node is the duplicate; list/detail are genuine ZGW portal).
  - `lib/Service/ContractRenewalService.php` — `requestRenewal()` opens a
    `leverancier-contractverlenging-verzoek` case (KEEP the case-open; the approval *decision* is
    the duplicate). `scanAndFlagExpiring()` / `daysUntilExpiry()` / `isWithinRenewalWindow()` are
    genuine ZGW expiry tracking (KEEP).
  - `lib/BackgroundJob/ScanExpiringContractsJob.php` — nightly expiry sweep (KEEP — ZGW).
  - `lib/Controller/BesluitvormingController.php` (`activateTemplate`), `AgendaController`
    (`addToAgenda`/`updateAgendaItem`), `PublicationController` (`publish`),
    `MandaatController` (`mandaatCheck`) + `besluitvorming#*` routes — procest's parallel
    decision-making engine (DELEGATE the deciding; narrow to ZGW-record).
  - `src/views/settings/tabs/DecisionTypesTab.vue` — decision-type config (DELEGATE — decidesk
    decisionType authority).
  - `src/views/cases/components/bezwaar/BezwaarDecisionForm.vue` — besluit-authoring UI
    (DELEGATE the deciding; KEEP the Awb domain rules + materialise ZGW Besluit).
  - `lib/Settings/templates/bvw-mandaatbesluit.json`, `bvw-college-besluit.json`,
    `bvw-raadsbesluit.json` — besluit decision-type templates (DEPRECATE — decidesk decisionTypes).
- [x] Confirm what procest KEEPS (genuine ZGW case management, NOT duplicated): the contract is a
      zaak; the nightly expiry scan; the supplier portal list/detail with IDOR fail-closed scope;
      the ZGW `Besluit` artifact recorded on the case file (Besluiten API).
- [x] Confirm dependency: `decidesk-contract-decision-hub` exposes the contract-decision
      integration surface (raise a Decision for a contract approval/renewal/sign-off, return
      outcome). No procest code authors the decision after this change.
- [x] Confirm no overlap with sibling changes: `migrate-parafering-to-or-approval-workflow`
      (generic approval chains → OR), `migrate-status-engine-to-or-lifecycle` (status engine);
      this change is specifically the *contract/besluit decision* node → decidesk.

## Phase 1: Delegation service (raise a decidesk Decision via ADR-019)

- [ ] Add `lib/Service/ContractDecisionDelegationService.php`:
  - `raiseContractDecision(string $caseRef, string $contractRef, string $decisionType, array $subject, array $mandateContext): string` — resolves the `decidesk` integration leaf from the OR
    integration registry (ADR-019) and creates a decidesk `Decision`; returns `decisionRef`.
  - `consumeOutcome(string $decisionRef): array` — reads the decided Decision (result, decidedAt,
    motivering, signer/mandaathouder, method).
  - Fail **closed** when the decidesk leaf is unavailable: surface a clear error, never auto-approve
    (mirror `hydra-gate-unsafe-auth-resolver` — no `catch { return null }` fall-open).
- [ ] Register the service in `lib/AppInfo/Application.php` DI.
- [ ] Unit tests: leaf-available → Decision raised + ref returned; leaf-unavailable → fails closed,
      no local approval state set.

## Phase 2: Rewire contract renewal to delegate the decision

- [ ] Modify `lib/Service/ContractRenewalService.php::requestRenewal()`: still open the
      `leverancier-contractverlenging-verzoek` case (ZGW), then call
      `ContractDecisionDelegationService::raiseContractDecision()` for the renewal approval instead
      of relying on a procest-local approval state. Persist the returned `decisionRef` on the case.
- [ ] Modify `lib/Controller/ContractController.php::requestRenewal()`: keep IDOR fail-closed scope,
      role gate, and 90-day window guard; return the `decisionRef`/`caseRef`. Do NOT advance any
      local approval state machine.
- [ ] Leave `ContractController::index`/`show` and `daysUntilExpiry`/`isWithinRenewalWindow`/
      `scanExpiringContracts` UNCHANGED (genuine ZGW portal + expiry tracking).
- [ ] Update `@spec` tags on the touched methods to point at this change's spec.

## Phase 3: Materialise the ZGW Besluit from the decidesk outcome

- [ ] Add `lib/Service/BesluitMaterialisationService.php` (or fold into the narrowed
      `PublicationService`): given a decidesk Decision outcome, write/update the ZGW `Besluit` on
      the case — map result→Besluit result, decidedAt→`datum`, motivering→`toelichting`,
      signer/method→audit fields. Preserve the Besluiten-API shape exactly.
- [ ] Wire the decidesk outcome (webhook/poll via the integration registry) →
      `consumeOutcome()` → `BesluitMaterialisationService`.
- [ ] Contract test: the materialised `Besluit` matches the prior ZGW schema shape (no compliance
      regression).

## Phase 4: Narrow the besluitvorming engine to ZGW-record

- [ ] `lib/Controller/PublicationController.php` / `PublicationService` — narrow `publish` to
      "publish the recorded ZGW `Besluit`" fed by the decidesk outcome; stop authoring the besluit
      locally.
- [ ] `lib/Controller/MandaatController.php::mandaatCheck` — delegate to the decidesk decision
      route/stage assignee model; reduce to a thin read-through (route stays routable for
      deep-links/back-compat) or remove once callers migrate.
- [ ] `lib/Controller/BesluitvormingController.php::activateTemplate` — deprecate; decision *types*
      come from decidesk decisionTypes.
- [ ] `lib/Controller/AgendaController.php` — keep agenda endpoints (ZGW case orchestration); ensure
      they no longer drive decision authoring.
- [ ] Deprecate `lib/Settings/templates/bvw-mandaatbesluit.json`, `bvw-college-besluit.json`,
      `bvw-raadsbesluit.json` (keep readable until sunset).

## Phase 5: Decision UI → decidesk-backed

- [ ] `src/views/cases/components/bezwaar/BezwaarDecisionForm.vue` — raise a decidesk Decision on
      submit (disposition, follows-advice, motivation); on outcome materialise the ZGW Besluit
      (incl. rechtsmiddelenclausule). KEEP the Awb domain rules (art. 7:12 motivering required,
      ex-nunc heroverweging, reformatio-in-peius guard) as procest validation.
- [ ] `src/views/settings/tabs/DecisionTypesTab.vue` — read decidesk decisionTypes instead of
      persisting procest-local decision types (or retire the tab).
- [ ] Update the relevant Pinia store/service calls to target the delegation endpoints.

## Phase 6: Migration of in-flight cases

- [ ] Add `lib/Repair/LinkInFlightContractDecisionsRepair.php`: for each open contract /
      besluitvorming case, link it to a decidesk Decision so its outcome can complete there; if a
      `Besluit` is already recorded, keep it as the authoritative historical record. No besluit
      data is dropped.
- [ ] Register the repair step in `appinfo/info.xml` `<repair-steps>`.
- [ ] Migration note in `proposal.md` Out-of-Scope confirmed: historical Besluiten stay
      authoritative; only in-flight cases are linked forward.

## Phase 7: Validation & gates

- [ ] `openspec validate --strict procest-delegate-contract-decision` exits 0.
- [ ] Hydra gates green (spec-coverage `@spec` on touched methods; unsafe-auth-resolver clean —
      delegation fails closed; route-reachability/route-auth on narrowed endpoints).
- [ ] e2e: requesting a renewal raises a decidesk Decision and materialises a ZGW Besluit on
      outcome; nightly scan still flags `renewalWarning`; supplier portal still lists contracts.
