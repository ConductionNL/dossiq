# Design — procest-delegate-remaining-decisions-to-decidesk

## Context

The merged `procest-delegate-contract-decision` established the pattern: decidesk owns the *making*
of a decision (ADR-005 Decision supertype), procest keeps ZGW case management and records the ZGW
`Besluit` as a **projection** of the decidesk outcome, and the binding goes through the ADR-019
integration registry (fail CLOSED). That change covered the **contract-renewal / besluitvorming
engine** slice (contract approval, agenda/publication/mandaat, the `BezwaarDecisionForm.vue` UI,
`DecisionTypesTab.vue`, `bvw-*` templates).

It did **not** touch the remaining procest-local decision/advice **backends**. This change extends
the same pattern to those (cross-app contract #1, ADR-005, ADR-019, ADR-012, ADR-022).

## Prior change coverage vs. what remains (Phase 0 result)

| Surface | File(s) | Prior change? | This change |
| --- | --- | --- | --- |
| Contract renewal approval | `ContractController` / `ContractRenewalService::requestRenewal` | **DELEGATED** | untouched |
| Besluitvorming engine | `BesluitvormingController` / `AgendaController` / `PublicationController` / `MandaatController`, `bvw-*` templates | **DELEGATED / narrowed** | untouched |
| Beslissing-op-bezwaar **UI** | `src/views/cases/components/bezwaar/BezwaarDecisionForm.vue` | **DELEGATED (UI raises Decision)** | untouched |
| Decision-type config | `src/views/settings/tabs/DecisionTypesTab.vue` | **DELEGATED** | untouched |
| Beslissing-op-bezwaar **BACKEND** | `lib/Service/Bezwaar/DecisionService.php` (`draft`/`publish`/`applyToBezwaar`) | **NO** | **DELEGATE** (decisionType `bezwaar-decision`) |
| BAC-adviezen | `lib/Service/Bezwaar/AdvisoryCommitteeService.php`, `AdvisoryReportPanel.vue` | **NO** | **DELEGATE** (decisionType `advice`) |
| Advies (adviesAanvraag) | `lib/Controller/AdviceController.php`, `lib/Service/AdviceService.php`, `Advice*.vue` | **NO** | **DELEGATE** (decisionType `advice`) |
| Consultatie advice | `lib/Controller/ConsultationController.php`, `lib/Service/ConsultationService.php` | **NO** | **DELEGATE** (decisionType `advice`) |
| Voorstel → besluit | `src/views/voorstellen/VoorstelDetail.vue`, `components/BesluitRegistration.vue` | **NO** | **DELEGATE** (decisionType `report-adoption`) |
| Parafeerroute / parafering | `ParafeerRouteController` etc. | n/a | **untouched** (owned by `migrate-parafering-to-or-approval-workflow`) |
| Nav grouping | `src/menu-layout.json` / manifest | n/a | **untouched** (owned by `procest-objections-appeals-group`) |

Key evidence: `Bezwaar/DecisionService::draft()` writes a `bezwaarDecision` object and `publish()`
runs the Awb validity matrix and **authors** the besluit independently — this is a procest-local
decision-making authority, exactly the node cross-app contract #1 says belongs in decidesk. The
prior change rewired only the Vue form, not this backend service.

## Key decisions

### D1 — Reuse the merged delegation plumbing

The merged change shipped `ContractDecisionDelegationService` (resolve the `decidesk` integration
leaf via the OR registry, raise a `Decision`, consume the outcome, fail CLOSED) and
`BesluitMaterialisationService` (write the ZGW `Besluit` from the outcome). This change reuses both.
Where a flow needs different provenance/decisionType it adds a thin sibling
(`BezwaarDecisionDelegationService`, `AdviceDelegationService`) that delegates to the same
registry-resolution + materialisation core — no second integration mechanism.

### D2 — Map each remaining flow to the right decisionType (ADR-005)

| procest flow | decidesk `decisionType` | Provenance (sourceApp=procest) |
| --- | --- | --- |
| Beslissing op bezwaar | `bezwaar-decision` | subjectSchema=`bezwaarDecision`, subjectId=bezwaarId, subjectLabel=zaaknummer |
| BAC-advies | `advice` | subjectSchema=`bacAdviceRequest`, subjectId=requestId |
| Advies (adviesAanvraag) | `advice` | subjectSchema=`adviesAanvraag`, subjectId=adviceId |
| Consultatie | `advice` | subjectSchema=`consultation`, subjectId=consultationId |
| Voorstel → besluit | `report-adoption` | subjectSchema=`voorstel`, subjectId=voorstelId |

Each `POST /api/v1/decisions` carries `sourceApp`/`subjectRegister`/`subjectSchema`/`subjectId`/
`subjectLabel`/`outcomeCallbackUrl`/`externalReference` per the hub contract; the outcome is read
via `GET /api/v1/decisions/{id}/outcome` (or a subscription).

### D3 — Domain rules stay in procest; deciding moves to decidesk

The legal/domain **validation** is genuine procest responsibility and is retained:

- Awb 7:11 disposition set + 7:12 motivering required + proceskosten + replacement-decision guard
  (`Bezwaar/DecisionService` validation) → kept as procest pre-flight validation before raising the
  Decision.
- BAC panel-independence check (REQ-BAC-2) → kept as a procest guard before raising the advice Decision.
- Advice IDOR fail-closed (`assertAdviceCallerIsAuthorized` — only the assigned adviseur/admin may
  submit) → kept.
- Rechtsmiddelenclausule (appeal clause) completeness → kept, recorded on the materialised `Besluit`.

decidesk decides *who/whether/which method*; procest validates *the legal shape* and records the
`Besluit`.

### D4 — The ZGW Besluit / advice record becomes a projection

On a decided outcome, `BesluitMaterialisationService` writes the ZGW `Besluit` (result → Besluit
result, decidedAt → `datum`, motivering/advies → `toelichting`, signer/method → audit fields,
rechtsmiddelenclausule preserved) onto the case. Advice outcomes update the advice record's
`status`/`adviesText` from the decidesk advice Decision. The Besluiten-API shape is preserved
exactly — only the *origin* of the values changes (decidesk outcome instead of local authoring).

### D5 — Fail CLOSED everywhere

Every delegation resolves the `decidesk` leaf and, when it is unavailable, surfaces a clear
"decision service unavailable" error and sets **no** local decided state (mirrors
`hydra-gate-unsafe-auth-resolver`: an unavailable decision service is not "decision skipped"). No
`catch { return null }` fall-open; no procest-local decision/advice state machine advances as a
fallback.

### D6 — Do NOT touch nav or parafering

The sibling `procest-objections-appeals-group` re-groups the objections/appeals nav; this change
makes no nav edit. The parafeerroute (parafering) is delegated separately by
`migrate-parafering-to-or-approval-workflow`; the voorstel parafering steps are untouched here —
only the besluit-registration node on the voorstel is delegated.

## Alternatives considered

- **Keep procest's bezwaar/advice engines, only share data.** Rejected — violates ADR-012/ADR-022:
  multiple decision/advice engines drift and cross-app decision queries stay impossible.
- **Move the Awb domain rules into decidesk too.** Rejected — the legal validity matrix is
  jurisdiction-/case-specific procest domain logic; decidesk owns the generic decision lifecycle,
  procest owns the Awb shape. Keep the split.
- **One mega delegation service.** Rejected in favour of reusing the merged plumbing with thin
  per-flow provenance siblings, so each flow's subject/decisionType is explicit and testable.
- **Hard-coded HTTP to decidesk.** Rejected — ADR-019 mandates the integration registry (configurable,
  discoverable, fails closed cleanly).

## Migration / rollout

1. Reuse `ContractDecisionDelegationService` + `BesluitMaterialisationService`; add thin per-flow
   delegation siblings; wire `Bezwaar/DecisionService`, `AdvisoryCommitteeService`, `AdviceService`,
   `ConsultationService`, and the voorstel besluit-registration to raise decidesk Decisions.
2. Add `lib/Repair/LinkInFlightRemainingDecisionsRepair`: for each open bezwaar-decision / advice /
   consultation / voorstel case, link it to a decidesk Decision so its outcome can complete there;
   if a `Besluit`/advice is already recorded, keep it as the authoritative historical record. **No
   decision/advice data is dropped.**
3. Keep the legacy authoring code paths readable until in-flight cases are linked, then sunset.

## Risks

- **ZGW compliance regression.** Mitigated by D4: Besluiten-API shape preserved; contract test
  asserts the materialised `Besluit` matches the prior schema.
- **Fail-open on decidesk unavailability.** Mitigated by D5: every delegation fails closed.
- **In-flight cases stranded.** Mitigated by the Repair step; historical records stay authoritative.
- **Awb rule drift.** Mitigated by D3: the legal validity matrix stays in procest and runs before
  the Decision is raised, so the decision can never be raised on an Awb-invalid payload.
- **Scope bleed into the sibling nav change.** Mitigated by D6: zero nav/parafering edits here.

## Touched surfaces (exact)

| Kind | Identifier | Action |
| --- | --- | --- |
| Service | `lib/Service/Bezwaar/DecisionService.php` | delegate decision; keep Awb validation |
| Service | `lib/Service/Bezwaar/AdvisoryCommitteeService.php` | delegate BAC advice; keep panel-independence |
| Controller+Service | `lib/Controller/AdviceController.php`, `lib/Service/AdviceService.php` | delegate advice; keep IDOR gate |
| Controller+Service | `lib/Controller/ConsultationController.php`, `lib/Service/ConsultationService.php` | delegate consultation advice |
| Vue | `src/views/voorstellen/components/BesluitRegistration.vue`, `VoorstelDetail.vue` | raise Decision on besluit-registration |
| Vue | `src/views/cases/components/bezwaar/AdvisoryReportPanel.vue`, `Advice*.vue`, `AdviesAanvraagDialog.vue` | raise/read decidesk advice Decision |
| Service | `lib/Service/*DelegationService.php`, `BesluitMaterialisationService.php` | reuse/extend (merged change) |
| Repair | `lib/Repair/LinkInFlightRemainingDecisionsRepair.php` | new idempotent forward-link step |
| Nav | `src/menu-layout.json` / manifest | **NOT touched** (sibling `procest-objections-appeals-group`) |
