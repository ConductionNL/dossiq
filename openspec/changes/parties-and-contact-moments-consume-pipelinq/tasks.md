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
