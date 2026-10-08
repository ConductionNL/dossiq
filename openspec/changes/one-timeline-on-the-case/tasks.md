# Tasks: one-timeline-on-the-case

- [x] 1 `CaseTimeline`, the one seam that writes a timeline entry, with
  the lazy resolve, the soft failure and the related-cases write
- [x] 2 `DeclareTimelineKinds` repair step, the seven kinds and the
  seeded text blocks, registered in both blocks of `info.xml`
- [x] 3 `ContactMomentService` writes a `contactmoment` entry carrying
  its own record id, on the case and its related cases
- [x] 4 `IntakeLog` writes a `mail-inkomend` entry when a message
  reached a case
- [x] 5 `AcknowledgementService` writes a public `ontvangstbevestiging`
  entry
- [x] 6 `CaseEmailService` writes a public `mail-uitgaand` entry
- [x] 7 `BerichtenboxService` writes a public `portaalbericht` entry
  without the BSN
- [x] 8 `CaseTimelineTab`, registered as the `case-timeline-pane` widget
  type and placed as the Timeline tab of the case panels
- [x] 9 Unit tests for the seam, the repair step and each writer,
  mutation-checked
- [x] 10 `tests/e2e/one-timeline-on-the-case.spec.ts`, tagged against the
  scenarios, not run locally
- [x] 11 The status-change and term-event writers, now that
  `what-a-status-declares` and `phase-terms-and-the-internal-target`
  have landed. `CaseStatusStore` records the four moves that write a
  status record, a post-persist listener records the derived move, and
  `TermijnService` records every term event and the start of a term that
  writes none. Both kinds gained the fields their writers carry
- [ ] 12 The applicant-facing visibility toggle, in
  `timeline-entries-default-internal`
