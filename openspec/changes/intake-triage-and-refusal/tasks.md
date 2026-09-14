# Tasks: intake-triage-and-refusal

Tier: V1. Kind: code. Size M. Round 4 discovery cluster 35, candidates
C-intake-8, C-intake-42, C-case-core-10, C-intake-5, C-intake-22 and
C-intake-33. C-intake-30 is hermiq's. Statutory: Awb 2:3. Decision D6
admits all three dossiq `must` candidates on relevance. The fan-out's
relation waits on openregister `relation-types-with-inverses` and its form
on buildiq `forms-per-case-type`; both are named and neither exists yet.

- [ ] 1.1 `caseType`: two declared lists, required before the case exists
  and required before the case is complete, with the channel and the
  confidentiality on the first by default (D-1, D-2).
  - `tests/unit/Service/IntakeRequirementsTest.php`
  - `@spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md`
- [ ] 1.2 Refuse creation for a missing field on the first list, naming it,
  with `{message, error}` per ADR-050 (D-2).
- [ ] 1.3 The create-case form asks for what the case type declares (D-1).
  - `tests/vitest/createCaseRequiredFields.spec.js`
- [ ] 2.1 `caseType`: the classification, the sensitivity, the action
  facet and the insight level, with the classification markable as an
  access rule (D-2).
  - `tests/unit/Service/CaseClassificationTest.php`
- [ ] 2.2 Fail closed when the classification scheme does not resolve, per
  ADR-102 (D-2).
- [ ] 3.1 `caseType`: declare the allowed groups and people; the picker
  reads it and the write enforces it, naming the declaration on a refusal
  (D-3).
  - `tests/unit/Service/AssigneeNarrowingTest.php`
  - `tests/vitest/assigneePickerNarrowing.spec.js`
- [ ] 4.1 `lib/Service/Routing/`: refusal as a sixth outcome, with a
  declared department and role, the reason and the refuser recorded, and
  the case left findable (D-4).
  - `tests/unit/Service/Routing/RefusalOutcomeTest.php`
- [ ] 4.2 Refuse the refusal when no destination is configured, saying so
  (D-4).
- [ ] 5.1 The triage sleep: a date, a reason, out of the queue, back to
  the queue unassigned, and refused on an accepted case with a running
  term (D-5).
  - `tests/unit/Service/TriageSleepTest.php`
- [ ] 6.1 The intake form's declared destinations, one case per
  destination, each in its department (D-6).
  - `tests/unit/Service/IntakeFanOutTest.php`
- [ ] 6.2 Relate the created cases to the submission and to each other
  over the existing related-cases link, recording that the named relation
  type waits on openregister (D-6).
- [ ] 6.3 A department reads its own case and not the others' content
  (D-6).
- [ ] 6.4 Report a destination that could not be created, without stopping
  the others (D-6).
- [ ] 7.1 Hand openregister `relation-types-with-inverses` and buildiq
  `forms-per-case-type` their halves, with candidate id C-intake-33.
- [ ] 7.2 Dutch and English strings.
- [ ] 7.3 `tests/e2e/intake-triage-and-refusal.spec.ts`: a creation
  refused for a missing channel, an unclassified case refused, a narrowed
  picker with the API refusing the third group, a refused case that lands
  and stays findable, an item slept and woken unassigned, and one melding
  opening two cases that know about each other;
  `openspec validate intake-triage-and-refusal --strict`.
