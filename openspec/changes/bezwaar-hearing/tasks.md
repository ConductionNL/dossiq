# Tasks: bezwaar-hearing

This is a **GENERATE-style** change: spec-only, no code. Tasks below cover authoring the requirements, verifying delta format, and confirming `openspec validate --strict` passes. Implementation lands in a follow-up apply change.

## Spec authoring

- [ ] **T01**: Author `specs/bezwaar-hearing/spec.md` with eight `### Requirement:` blocks (REQ-BH-1..8) in `## ADDED Requirements` delta format. Each requirement carries at least one `#### Scenario:` block with Given/When/Then.
- [ ] **T02**: Confirm REQ-BH-1 (Hearing Session Entity) declares all fourteen `hearingSession` properties from `design.md` and the six-state status enum.
- [ ] **T03**: Confirm REQ-BH-2 (Scheduling + Waiver) captures both the `gepland` happy path and the art. 7:3 waiver path with audit-trail entries.
- [ ] **T04**: Confirm REQ-BH-3 (Invitation Flow + Accessibility) covers channel selection (Berichtenbox / email / post / in_person) and the four `accessibilityNeeds` variants (`low_literacy`, `interpreter`, `sign_language`, `physical_access`).
- [ ] **T05**: Confirm REQ-BH-4 (Inspection of File) encodes the 7-day floor (Awb art. 7:4 lid 2) as a hard block, plus the art. 7:6 confidential-document exclusion with placeholder.
- [ ] **T06**: Confirm REQ-BH-5 (Attendee List + Attendance) captures invitee structure, presence tracking, and arrival timestamps.
- [ ] **T07**: Confirm REQ-BH-6 (Minutes Capture) requires at least one of `minutesSummary` or `minutesDocument` to mark the hearing `uitgevoerd`, and that `audioRecording` is gated by `recordingConsent = granted`.
- [ ] **T08**: Confirm REQ-BH-7 (Follow-Up Questions) covers post-hearing question entries with deadlines and a dashboard surfacing requirement.
- [ ] **T09**: Confirm REQ-BH-8 (Legal Compliance Hooks) catalogues the audit-trail entries written for status transitions, waivers, invitation timestamps, inspection access, and recording consent/denial.

## Pre-commit verification

- [ ] **V01**: `openspec validate bezwaar-hearing --type change --strict` → exit code 0
- [ ] **V02**: `openspec change show bezwaar-hearing --json --deltas-only | jq '.deltaCount'` → ≥ 8 (one delta per REQ-BH-1..8)
- [ ] **V03**: Every REQ-BH-* in `specs/bezwaar-hearing/spec.md` contains at least one `#### Scenario:` subsection
- [ ] **V04**: No code files modified — `git diff --name-only origin/development...HEAD` returns only paths under `openspec/changes/bezwaar-hearing/`
