# Tasks: custody-and-handover-of-a-case

Tier: V1. Kind: code. Size M. Rows 2.37, 2.38 and 13.28.

- [x] 1.1 `lib/Settings/dossiq_register.json`: the `caseCustody` schema with
  the organisation unit, the handler, from, until, the reason and who moved
  it (D-1).
  - `@spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md`
  - Also `caseTakeover`, and two keys on `caseType`:
    `consentRequiredInsideOrganisation` (D-7) and `takeoverAnswerPeriodDays`
    (D-4). Both new slugs are mapped in `SchemaSlugMap::SLUG_TO_CONFIG_KEY`
    and `ConfigKeys::ALL`, because an unmapped slug is a schema no service
    can resolve, which is exactly how `toestemming` came to be declared and
    read by nothing.
  - `open` is STORED rather than derived from an empty `until`. A reader can
    then ask the register for the open holding instead of sorting the chain
    client-side, and the two-open window on `move()` becomes something a
    reader outside this app can see rather than something only the sort
    order hides.
- [x] 1.2 `lib/Service/CaseTransferService.php`: close the open holding and
  open the next in one write; refuse a state with no open holding (D-2).
  - `tests/Unit/Service/CaseCustodyChainTest.php` (NOT `tests/unit/`, which
    is not a suite `phpunit.xml` runs)
  - The act lives in `lib/Service/Custody/CaseCustodyChain.php` and the
    transfer service calls it from its two doors: `completeTransfer()` on an
    accept, and `handToTeam()` on the internal handover. One chain, two
    boundaries, for the same reason `handToTeam` enters through the transfer
    service rather than beside it.
  - THE NEXT HOLDING IS WRITTEN BEFORE THE PREVIOUS IS CLOSED, and the
    method says why: OpenRegister has no transaction across two objects, so
    one of the two windows is unavoidable. Closing first leaves a moment
    where the case is held by NOBODY, which reads as an answer. Opening
    first leaves two open holdings, which every reader here resolves by the
    highest sequence and a reader outside can see is wrong.
  - Custody is written BEFORE the status save on an accept, the same order
    `InternalHandover` already used: a chain written afterwards leaves a hole
    whenever the chain write fails, and this way the visible failure is that
    the transfer stays pending.
- [x] 1.3 Backfill a holding for every existing case from its current owner,
  dated from the case start, so the chain has no hole at the beginning.
  - `tests/Unit/Migration/CaseCustodyBackfillTest.php`
  - `lib/Repair/BackfillCaseCustody.php`, registered post-migration and in
    `RepairStepRegistrationTest::INSTALL_EXEMPT` with its reason. Idempotent:
    `begin()` is a no-op on a case that already has an open holding.
  - A SECOND HOLE WOULD HAVE OPENED WITHOUT 1.3b. The backfill repairs the
    cases that existed on the day this shipped, and nothing was opening a
    holding for the ones created after it, so every new case would have had a
    chain starting at its first MOVE. That is 1.3b.
- [x] 1.3b `lib/Listener/CustodyCaseCreatedListener.php`: a new case opens
  its first holding when it is registered, dated from the case rather than
  from the moment the listener ran.
- [x] 1.4 The custody reader: who held a case on a date, and which cases a
  unit held between two dates.
  - `tests/Unit/Service/CaseCustodyQueryTest.php`
  - `lib/Service/Custody/CaseCustodyQuery.php`. The boundary is half-open, so
    the day of a transfer belongs to the unit that TOOK the case and never to
    both. The test file says plainly that a mutation does not cover that
    condition, because `holderOn()` keeps the last matching holding and the
    newer one wins either way; claiming a mutation there would have been a
    test that cannot fail.
- [x] 2.1 The takeover request: an engine task to the holder with the asker,
  the reason and the case (D-3).
  - `tests/Unit/Service/CaseTakeoverRequestTest.php`
  - `lib/Service/Custody/CaseTakeoverRequest.php`. A request the engine will
    not carry is still recorded, with an empty `taskId` saying so: a delivery
    failure must not lose the question.
- [x] 2.2 Accept moves the case through the same transfer path and writes the
  next holding; refuse records the reason and leaves the case where it is.
  - `tests/Unit/Service/CaseTakeoverAnswerTest.php`
- [x] 2.3 Escalate an unanswered request to the holding unit after the period
  the case type declares (D-4).
  - `tests/Unit/Service/CaseTakeoverEscalationTest.php`
  - `escalated` is still OPEN and the test says so: a request that quietly
    expired would be indexed the same as one somebody refused, and the two
    are not the same fact.
- [ ] 2.4 [partial] Declare who may accept or refuse on behalf of a unit as an
  action mapping under ADR-023, not a group check in the service.
  - WHAT SHIPPED, so nobody has to read the code to find out: there is NO
    group check in the service, which is the half of this task that was a
    defect. `CaseTakeoverController` answers on `CaseAccessGuard`, per case
    and failing closed, and the guards differ per verb on purpose: asking
    needs READ access, because the asker does not hold the case and requiring
    mutation access would refuse exactly the people the request exists for,
    while answering needs MUTATION access, because an accept moves the case.
  - WHAT DID NOT: the answer is not yet a declared action in OpenRegister's
    action vocabulary, so an administrator cannot rebind who answers for a
    unit without a code change. That is the ADR-023 half and it stays open.
- [x] 3.1 `lib/Settings/register.d/50-sociaal-domein.json`: read the declared
  `toestemming`, with the sharing scope and the period it covers (D-5, D-6).
  - `@spec openspec/changes/custody-and-handover-of-a-case/specs/dossiq-sociaal-domein-avg-consent/spec.md`
  - NO SCHEMA CHANGE WAS NEEDED, which is worth saying because the task reads
    as though one was. `recipientParties`, `grantedDate`, `validTo`,
    `withdrawn`, `tegegevens` and `scope` were all already declared. What was
    missing was the MAPPING: the slug had no config key, so no service could
    resolve the schema and the record was unreadable by construction. That is
    the whole of why "toestemming is declared and nothing reads it" was true.
- [x] 3.2 `CaseTransferService::initiateTransfer`: refuse a hand-off that
  crosses an organisation boundary without a covering consent, naming what is
  missing (D-5, D-7).
  - `tests/Unit/Service/CaseTransferConsentGateTest.php`
  - The gate runs BEFORE the idempotency lookup: a refused hand-off must not
    leave a pending transfer behind that a later call would return as
    "already initiated".
  - A hand-off that cannot name its receiver is treated as crossing. It is
    the one case where guessing "internal" would skip the gate entirely.
- [x] 3.3 `createPartnerShare`: write the recorded scope onto the share;
  name the openregister slug that enforces it once that lane opens it (D-6).
  - `tests/Unit/Service/PartnerShareScopeTest.php`
  - The slug is openregister `share-scope-on-an-object`, still to be
    specified there. Until it lands, dossiq refuses the hand-off without
    consent and writes `consentId`, `consentScope` and `consentUntil` onto
    the share, which is the half that is dossiq's either way.
  - `CaseSharingController` answers a refusal with 409 and the rule slug, not
    502. A missing consent is this instance deciding, correctly; 502 says
    OpenRegister broke, and the caller could not tell the two apart.
- [x] 3.4 Declare per case type whether consent is needed inside the
  organisation as well (D-7).
  - `caseType.consentRequiredInsideOrganisation`, read by the gate rather
    than decided in it, so an administrator can read which case types may not
    move without one.
- [x] 4.1 Dutch and English strings for the custody tab, the takeover
  request and answer, and the consent refusal.
  - `src/components/case/CaseCustodyPanel.vue` and the Custody tab on
    CaseDetail, with `src/services/custodyApi.js` behind it and
    `tests/vitest/caseCustodyPanel.spec.js` over it. 21 new keys in
    `l10n/en.json` and `l10n/nl.json`, both catalogues rebuilt into their
    `.js` siblings.
  - INHERITED, reported rather than hidden: `node tests/l10n/check-l10n.js`
    was already failing on `parity/round2` over seven strings from
    `PropertyDefinitionFields.vue` (dossiq#2913) that shipped untranslated,
    including a Dutch placeholder `wijken` that rendered Dutch to an English
    reader. They are in the same two catalogue files this change edits and
    took under a minute, so they are fixed here and named here.
- [x] 4.2 `tests/e2e/custody-and-handover-of-a-case.spec.ts`: a transfer
  chain read back by date, a refused takeover with a reason, a hand-off
  blocked for missing consent and allowed once it is recorded;
  `openspec validate custody-and-handover-of-a-case --type change --strict`.
  - Written, tagged and NOT RUN: the integration branch defers Playwright to
    the nightly. The same scenarios are watched against a real in-memory
    register by the unit suites named above, which is where a regression is
    caught before this file next runs.
