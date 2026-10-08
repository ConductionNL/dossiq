# Tasks: parties-and-contact-moments-consume-pipelinq

Tier: MVP. Kind: feature. Size L. The consumer half of seven pipelinq changes
merged 2026-09-18: pipelinq#1970, #1972, #1973, #1975, #1976, #1977 and #1978.
Part of `competitor-parity-2026-09`: it closes the dossiq side of ledger rows
5.17, 6.2, 6.16, 6.21 and the parties and programme clusters.

## 1. The seam

- [ ] 1.1 `lib/Service/Pipelinq/PipelinqGateway.php`: resolve a pipelinq class
  through the server container, guarded by `class_exists` and by the methods the
  caller is about to use. The only file in `lib/` that names `OCA\Pipelinq`.
  - **spec_ref**: `specs/pipelinq-consumption/spec.md#requirement-one-seam-names-pipelinq-and-an-absent-pipelinq-is-a-different-answer-from-an-empty-one-req-plq-01`
- [ ] 1.2 Answer availability separately from data, so a surface can tell an
  absent pipelinq from an empty one.

## 2. Contact moments

- [ ] 2.1 `lib/Service/Pipelinq/ContactMomentBridge.php`: append a logged
  contact moment through pipelinq's leaf with its direction, the case as host
  and the party when known. Best effort, never blocks the dossiq write.
  - **spec_ref**: `specs/pipelinq-consumption/spec.md#requirement-a-contact-moment-logged-on-a-case-is-appended-to-pipelinqs-record-req-plq-02`
- [ ] 2.2 Read by MEMBERSHIP and carry the shared marker, counting a case the
  reader may not see rather than naming it.
  - **spec_ref**: `specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03`
- [ ] 2.3 File and unfile through pipelinq's two acts; write no reference set.
- [ ] 2.4 Wire the bridge into `Service\ContactMomentService::createContactMoment()`.

## 3. Party kinds

- [ ] 3.1 `lib/Service/Pipelinq/PartyKindConsumer.php`: read pipelinq's kinds,
  falling back to `PartyVocabulary`'s three, saying which answered.
  - **spec_ref**: `specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04`
- [ ] 3.2 Declare `dossiq:case:<caseType>` acceptances, in the declared order.

## 4. Indicators

- [ ] 4.1 `lib/Service/Pipelinq/PartyRefusalReader.php`: join pipelinq's blocking
  answer with `PartyIndicatorReader`'s; refuse when either refuses.
  - **spec_ref**: `specs/pipelinq-consumption/spec.md#requirement-dossiq-asks-before-it-sends-and-before-it-publishes-and-either-refusal-stops-it-req-plq-05`
- [ ] 4.2 Leave `PartyIndicatorReader` pointed at OpenRegister. Do not repoint it.

## 5. Correspondence language

- [ ] 5.1 `lib/Service/Pipelinq/CorrespondenceLanguageConsumer.php`: the tag and
  its reason, unset rendered as unset.
  - **spec_ref**: `specs/pipelinq-consumption/spec.md#requirement-the-language-to-write-to-a-party-in-comes-from-the-resolver-with-its-reason-req-plq-06`

## 6. Satisfaction

- [ ] 6.1 `lib/Service/Pipelinq/SatisfactionHandoff.php`: tell pipelinq a case
  completed. No survey, invitation, token, cooldown or opt-out in dossiq.
  - **spec_ref**: `specs/pipelinq-consumption/spec.md#requirement-a-closing-case-asks-pipelinq-for-the-satisfaction-survey-and-holds-no-survey-engine-req-plq-07`

## 7. Programme

- [ ] 7.1 `lib/Service/Pipelinq/ProgrammeConsumer.php`: link a case as
  `dossiq:case`, report the refusal with the holder named, render the progress
  with its mode and an uncomputable figure as uncomputable.
  - **spec_ref**: `specs/pipelinq-consumption/spec.md#requirement-a-case-hangs-under-a-programme-by-reference-and-the-progress-figure-names-its-mode-req-plq-08`

## 8. Declarations

- [ ] 8.1 `lib/Settings/register.d/68-pipelinq-leaves.json`: the two leaf ids on
  `case.configuration.linkedTypes`, and the note saying what is deliberately not
  declared here.
  - **spec_ref**: `specs/pipelinq-consumption/spec.md#requirement-the-case-declares-pipelinqs-leaves-and-none-of-pipelinqs-data-req-plq-09`

## 9. Verification

- [ ] 9.1 PHPUnit over the gateway, the bridge, the kinds, the joined refusal,
  the language, the hand-off and the programme.
- [ ] 9.2 `tests/e2e/parties-and-contact-moments-consume-pipelinq.spec.ts`, with
  a reason-bearing exclusion on every scenario it does not cover, per gate 19.
- [ ] 9.3 `openspec validate --strict` exits 0.

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
