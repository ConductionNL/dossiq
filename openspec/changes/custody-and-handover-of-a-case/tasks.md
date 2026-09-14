# Tasks: custody-and-handover-of-a-case

Tier: V1. Kind: code. Size M. Rows 2.37, 2.38 and 13.28.

- [ ] 1.1 `lib/Settings/dossiq_register.json`: the `caseCustody` schema with
  the organisation unit, the handler, from, until, the reason and who moved
  it (D-1).
  - `@spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md`
- [ ] 1.2 `lib/Service/CaseTransferService.php`: close the open holding and
  open the next in one write; refuse a state with no open holding (D-2).
  - `tests/unit/Service/CaseCustodyChainTest.php`
- [ ] 1.3 Backfill a holding for every existing case from its current owner,
  dated from the case start, so the chain has no hole at the beginning.
  - `tests/unit/Migration/CaseCustodyBackfillTest.php`
- [ ] 1.4 The custody reader: who held a case on a date, and which cases a
  unit held between two dates.
  - `tests/unit/Service/CaseCustodyQueryTest.php`
- [ ] 2.1 The takeover request: an engine task to the holder with the asker,
  the reason and the case (D-3).
  - `tests/unit/Service/CaseTakeoverRequestTest.php`
- [ ] 2.2 Accept moves the case through the same transfer path and writes the
  next holding; refuse records the reason and leaves the case where it is.
  - `tests/unit/Service/CaseTakeoverAnswerTest.php`
- [ ] 2.3 Escalate an unanswered request to the holding unit after the period
  the case type declares (D-4).
- [ ] 2.4 Declare who may accept or refuse on behalf of a unit as an action
  mapping under ADR-023, not a group check in the service.
- [ ] 3.1 `lib/Settings/register.d/50-sociaal-domein.json`: read the declared
  `toestemming`, with the sharing scope and the period it covers (D-5, D-6).
  - `@spec openspec/changes/custody-and-handover-of-a-case/specs/dossiq-sociaal-domein-avg-consent/spec.md`
- [ ] 3.2 `CaseTransferService::initiateTransfer`: refuse a hand-off that
  crosses an organisation boundary without a covering consent, naming what is
  missing (D-5, D-7).
  - `tests/unit/Service/CaseTransferConsentGateTest.php`
- [ ] 3.3 `createPartnerShare`: write the recorded scope onto the share;
  name the openregister slug that enforces it once that lane opens it (D-6).
  - `tests/unit/Service/PartnerShareScopeTest.php`
- [ ] 3.4 Declare per case type whether consent is needed inside the
  organisation as well (D-7).
- [ ] 4.1 Dutch and English strings for the custody tab, the takeover
  request and answer, and the consent refusal.
- [ ] 4.2 `tests/e2e/custody-and-handover-of-a-case.spec.ts`: a transfer
  chain read back by date, a refused takeover with a reason, a hand-off
  blocked for missing consent and allowed once it is recorded;
  `openspec validate custody-and-handover-of-a-case --type change --strict`.
