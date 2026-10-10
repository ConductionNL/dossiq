# Tasks: parties-and-contact-moments-consume-pipelinq

Tier: MVP. Kind: feature. Size L. The consumer half of seven pipelinq changes
merged 2026-09-18: pipelinq#1970, #1972, #1973, #1975, #1976, #1977 and #1978.
Part of `competitor-parity-2026-09`: it closes the dossiq side of ledger rows
5.17, 6.2, 6.16, 6.21 and the parties and programme clusters.

## 1. The seam

- [x] 1.1 (`lib/Service/Pipelinq/PipelinqGateway.php`, `tests/Unit/Service/Pipelinq/PipelinqGatewayTest.php`) `lib/Service/Pipelinq/PipelinqGateway.php`: resolve a pipelinq class
  through the server container, guarded by `class_exists` and by the methods the
  caller is about to use. The only file in `lib/` that names `OCA\Pipelinq`.
  - **spec_ref**: `specs/pipelinq-consumption/spec.md#requirement-one-seam-names-pipelinq-and-an-absent-pipelinq-is-a-different-answer-from-an-empty-one-req-plq-01`
- [x] 1.2 (`PipelinqGateway::isAvailable()` and `ask()` answering `answered`/`reason`; `ContactMomentBridgeTest::testAnAbsentPipelinqIsNotAnEmptyCase`) Answer availability separately from data, so a surface can tell an
  absent pipelinq from an empty one.

## 2. Contact moments

- [x] 2.1 (`lib/Service/Pipelinq/ContactMomentBridge.php::append()`, `ContactMomentBridgeTest`) `lib/Service/Pipelinq/ContactMomentBridge.php`: append a logged
  contact moment through pipelinq's leaf with its direction, the case as host
  and the party when known. Best effort, never blocks the dossiq write.
  - **spec_ref**: `specs/pipelinq-consumption/spec.md#requirement-a-contact-moment-logged-on-a-case-is-appended-to-pipelinqs-record-req-plq-02`
- [x] 2.2 (2026-10-10, board DqZaakContactmomenten: `PipelinqCaseController::contactMoments()` over `ContactMomentBridge::onCase()`, rendered by `src/components/case/CasePipelinqContactMoments.vue` as the Customer record section of the Communication tab; `PipelinqCaseControllerTest::testContactMomentsSayWhenPipelinqIsAbsent`, `tests/vitest/pipelinqCaseSurfaces.spec.js`) Read by MEMBERSHIP and carry the shared marker, counting a case the
  reader may not see rather than naming it.
  - **spec_ref**: `specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03`
- [x] 2.3 (2026-10-10: `PipelinqCaseController::fileContactMoment()` (mutation access on BOTH cases) and `::unfileContactMoment()`, `src/dialogs/FileContactMomentDialog.vue` and the row menu; `PipelinqCaseControllerTest::testFilingNeedsBothCasesAndGoesThroughPipelinq`, `::testUnfilingTakesItOffThisCase`) File and unfile through pipelinq's two acts; write no reference set.
- [x] 2.4 (`ContactMomentService` takes the bridge (constructor, `pipelinqBridge`)) Wire the bridge into `Service\ContactMomentService::createContactMoment()`.
- [x] 2.5 (2026-10-10, decision 155, board DqZaakContactmomenten: the case page's Log contact action opens `src/dialogs/LogContactDialog.vue`, which posts to `PipelinqCaseController::logContactMoment()` so ContactMomentService runs and appends to pipelinq; the answer carries `pipelinqRefusal` and `pipelinqIndicators` and the dialog shows the refusal with the indicator named while the dossiq record is kept; `PipelinqCaseControllerTest::testALoggedMomentCarriesPipelinqsRefusal`, vitest `the Log contact dialog`) Show pipelinq's refusal of an outbound append to the handler who logged it.
  - **spec_ref**: `specs/pipelinq-consumption/spec.md#requirement-a-contact-moment-logged-on-a-case-is-appended-to-pipelinqs-record-req-plq-02`

## 3. Party kinds

- [x] 3.1 (2026-10-10, board DqZaakPartijen: `PipelinqCaseController::partyKinds()` asks `PartyKindConsumer::kindsFor()` for the case's own type (read server-side); the Roles section (`CasePartiesWidget`) lists the accepted kinds in order with their source, labels a party by pipelinq's label and marks a party of a kind the type does not accept; `PipelinqCaseControllerTest::testPartyKindsAreAskedForTheCasesType`. The spec's "picker" is amended to the board's Roles section) `lib/Service/Pipelinq/PartyKindConsumer.php`: read pipelinq's kinds,
  falling back to `PartyVocabulary`'s three, saying which answered.
  - **spec_ref**: `specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04`
- [x] 3.2 (2026-10-10, board DqZaaktype (Partijsoorten): `src/components/caseType/CaseTypePartyKindsWidget.vue` on CaseTypeDetail, `PipelinqCaseController::caseTypePartyKinds()` / `::declarePartyKinds()` (admin setting) over `PartyKindConsumer::vocabulary()`, `::acceptanceOf()` and `::declareAcceptance()`; `PipelinqSurfaceReadsTest`, vitest `the case type party kinds`) Declare `dossiq:case:<caseType>` acceptances, in the declared order.

## 4. Indicators

- [x] 4.1 (`lib/Service/Pipelinq/PartyRefusalReader.php`, wired into `FileRequestService`) `lib/Service/Pipelinq/PartyRefusalReader.php`: join pipelinq's blocking
  answer with `PartyIndicatorReader`'s; refuse when either refuses.
  - **spec_ref**: `specs/pipelinq-consumption/spec.md#requirement-dossiq-asks-before-it-sends-and-before-it-publishes-and-either-refusal-stops-it-req-plq-05`
- [x] 4.2 (`PartyIndicatorReader` still reads OpenRegister) Leave `PartyIndicatorReader` pointed at OpenRegister. Do not repoint it.

## 5. Correspondence language

- [x] 5.1 (2026-10-10, board DqZaakPartijen: `PipelinqCaseController::partyLanguage()` over `CorrespondenceLanguageConsumer::forParty()`; the Roles section shows the writing language per party with its reason, an unset preference said as unset; `PipelinqCaseControllerTest::testTheLanguageWithoutPipelinqIsNotAChoice`, vitest `the sentences`) `lib/Service/Pipelinq/CorrespondenceLanguageConsumer.php`: the tag and
  its reason, unset rendered as unset.
  - **spec_ref**: `specs/pipelinq-consumption/spec.md#requirement-the-language-to-write-to-a-party-in-comes-from-the-resolver-with-its-reason-req-plq-06`

## 6. Satisfaction

- [x] 6.1 (2026-10-09: `ProgrammeConsumer::caseCompleted()` is called by `lib/Listener/CaseCompletedSatisfactionListener.php` on ObjectUpdatedEvent when a case moves onto a final status, handing `status: closed` with the uuid as `statusType`; `tests/Unit/Listener/CaseCompletedSatisfactionListenerTest.php` (real gateway, consumer and reader; a recorder with pipelinq's real `onInteractionCompleted` signature)) `lib/Service/Pipelinq/SatisfactionHandoff.php`: tell pipelinq a case
  completed. No survey, invitation, token, cooldown or opt-out in dossiq.
  - **spec_ref**: `specs/pipelinq-consumption/spec.md#requirement-a-closing-case-asks-pipelinq-for-the-satisfaction-survey-and-holds-no-survey-engine-req-plq-07`

## 7. Programme

- [x] 7.1 (2026-10-10, board DqZaakPartijen (Programma): `ProgrammeConsumer::programmeOf()` / `::programmes()` / `::linkCase()` through `PipelinqCaseController::programme()`, `::linkProgramme()` (409 naming the holder) and `::programmeOptions()`; `src/components/case/CaseProgrammeSection.vue` on the Related tab and `src/dialogs/LinkProgrammeDialog.vue`; `PipelinqSurfaceReadsTest`, `PipelinqCaseControllerTest::testARefusedLinkNamesTheHolder`) `lib/Service/Pipelinq/ProgrammeConsumer.php`: link a case as
  `dossiq:case`, report the refusal with the holder named, render the progress
  with its mode and an uncomputable figure as uncomputable.
  - **spec_ref**: `specs/pipelinq-consumption/spec.md#requirement-a-case-hangs-under-a-programme-by-reference-and-the-progress-figure-names-its-mode-req-plq-08`

## 8. Declarations

- [x] 8.1 (`lib/Settings/register.d/68-pipelinq-leaves.json`, `PipelinqLeafDeclarationTest`) `lib/Settings/register.d/68-pipelinq-leaves.json`: the two leaf ids on
  `case.configuration.linkedTypes`, and the note saying what is deliberately not
  declared here.
  - **spec_ref**: `specs/pipelinq-consumption/spec.md#requirement-the-case-declares-pipelinqs-leaves-and-none-of-pipelinqs-data-req-plq-09`

## 9. Verification

- [x] 9.1 (`tests/Unit/Service/Pipelinq/` (29 tests) plus `CaseCompletedSatisfactionListenerTest`) PHPUnit over the gateway, the bridge, the kinds, the joined refusal,
  the language, the hand-off and the programme.
- [x] 9.2 (2026-10-10: written, four scenarios, each skipping with a reason on an instance without pipelinq) `tests/e2e/parties-and-contact-moments-consume-pipelinq.spec.ts`, with
  a reason-bearing exclusion on every scenario it does not cover, per gate 19.
- [ ] 9.4 (live pass, decision 139) Run `tests/e2e/parties-and-contact-moments-consume-pipelinq.spec.ts` and `tests/e2e/case-communication.spec.ts` on an instance with dossiq and pipelinq installed (pipelinq with at least two party kinds and two programmes).
- [x] 9.3 (2026-10-10: `openspec validate parties-and-contact-moments-consume-pipelinq --strict` reports valid) `openspec validate --strict` exits 0.

## Rescue, 2026-09-18: the six guards, one at a time

`feat/parties-and-contact-moments-consume-pipelinq` had no pull request of any
state. The merge conflict is resolved and each of the six guards it failed is
answered.

**1. The data loss, first, whatever else happened to the branch.**
`PartyKindConsumer::declareAcceptance()` called `saveObject()` with a uuid and
a two-field payload. That REPLACES the stored object, so re-declaring a case
type would have deleted every other field pipelinq holds on that acceptance
row — silently, on another app's data. A first declaration is a create and
still saves; a re-declaration now patches.

**2. The pipelinq name behind the gateway.** `SeedContributedCaseTypes`'s
docblock spelled `OCA\Pipelinq\Dossiq\CaseTypeContributionProvider`. The
lookup it describes is duck-typed, so the day that name moves nothing errors
and the contribution simply stops arriving. It is now
`PipelinqGateway::CASE_TYPE_CONTRIBUTIONS`, and the comment points at the
constant.

**3 and 4. The leaf declarations.** The fragment declared
`case.configuration.linkedTypes` as the two pipelinq ids ALONE, and the
declaration is read last-one-wins — so it did not add two leaves, it removed
`talk`, `deck`, `mail`, `calendar`, `forms`, `photos`, `maps`, `shares` and
`decidesk-decisions` from the case page. It now carries the full list. The two
pipelinq ids are declared in the guard's own `CROSS_APP_LEAVES`, beside
`decidesk-decisions`, because they are registered by pipelinq rather than by
the library.

**5. The digest.** `68-pipelinq-leaves.json::case` shipped ungated;
`tools/schema-version-digests.php` recorded it.

**6. The four uncalled classes — one wired, three declared blocked.**
`PartyRefusalReader` is wired into `FileRequestService`, which already asked
the question it answers: either refusal stops a file request, and pipelinq's
label wins the sentence when both refuse. With pipelinq absent the reader
answers on OpenRegister alone, which is exactly what that call site did before.

The other three are blocked on surfaces this change never specified, and each
is recorded in `DARK_TODAY` with what would unblock it rather than given an
invented caller: `PartyKindConsumer` wants a per-case-type party picker
(dossiq decides kinds once, schema-wide, in `CaseRoleVocabulary::sync()`, which
has no case type to ask about); `CorrespondenceLanguageConsumer` wants a
correspondence surface, since nothing in dossiq chooses a language today;
`ProgrammeConsumer` wants a programme surface, of which dossiq has none — no
tab, no route, no field.

**Verified:** the full unit suite on the merge and on `parity/round2` fail the
same 42 names, so this adds no red and fixes none. Every inherited failure is
parity's own.
