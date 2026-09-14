# Tasks: markers-and-assessments-on-the-case

Tier: V1. Kind: code. Size M. Rows 2.36, 2.40 and 2.44.

- [ ] 1.1 `lib/Settings/dossiq_register.json`: the attention flag as a row
  per raising, with the reason, the person and the moment, and the same on
  clearing (D-1, D-2).
  - `@spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md`
- [ ] 1.2 Refuse a raising or a clearing with no reason, naming what is
  missing.
  - `tests/unit/Service/CaseAttentionFlagTest.php`
- [ ] 1.3 The flag as a filter and a count on the work list, and the flag
  history on the case page.
  - `tests/vitest/caseAttentionFlag.spec.js`
- [ ] 2.1 `lib/Settings/dossiq_register.json`: `riskAssessment` on the case
  with the level, the ground, the assessor, the date and the review date
  (D-3).
  - `tests/unit/Service/CaseRiskAssessmentTest.php`
- [ ] 2.2 Declare the assessment behind its own group in the
  `row-field-level-security` vocabulary, as `sensitive-fields-declared`
  declares the BSN (D-4).
- [ ] 2.3 Feed the assessed level into the impact axis of
  `case-priority-impact-urgency`, adding no new priority vocabulary (D-5).
  - `tests/unit/Service/RiskFeedsImpactTest.php`
- [ ] 2.4 Filter the work list on the level for the people allowed to read
  it, and leave the column out entirely for the people who are not.
- [ ] 2.5 Show a stale assessment as stale once its review date passes.
- [ ] 3.1 Declare system markers on the schema as a raise condition and a
  clear condition per marker, each naming the tab it points at (D-6, D-7).
  - `tests/unit/Service/CaseAttentionMarkerTest.php`
- [ ] 3.2 Raise and clear markers from the object events that already reach
  dossiq, placed as post-event work under ADR-078 (D-8).
- [ ] 3.3 Render the markers on the case tabs beside, and distinct from, the
  per-user unread badge of `unread-state-on-the-case`.
  - `tests/vitest/caseTabMarkers.spec.js`
- [ ] 4.1 Dutch and English strings for the flag dialog, the refusal, the
  assessment fields and each shipped marker.
- [ ] 4.2 `tests/e2e/markers-and-assessments-on-the-case.spec.ts`: raise and
  clear a flag with reasons, read and fail to read an assessment as two
  users, a marker that survives opening the tab and clears when the work is
  done;
  `openspec validate markers-and-assessments-on-the-case --type change --strict`.
