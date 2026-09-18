# Tasks: intake-says-when-the-term-starts

Tier: V1. Kind: code. Row Q8.21. Reads openregister
`working-calendar-admin` (to be specified) through dossiq
`terms-on-the-engine-calendar` (open); until that lands,
`WorkingDayCalculator` answers.

- [x] 1.1 `lib/Settings/dossiq_register.json`: `case.receivedAt`,
  `case.termStartsAt`, `case.receivedOutsideWorkingHours`, all three
  read-only, both moments as `date-time` (D-1, D-2). A `date` format would
  render a Sunday evening filing and a Monday morning one identical, which
  is the whole distinction. Register 0.20.4, above every number the
  branches ahead of this one claim.
  - `tests/Unit/Settings/IntakeFieldsShippedTest.php`
- [x] 1.2 `lib/Service/Terms/IntakeTermStart.php` and
  `lib/Listener/IntakeTermStartListener.php`: stamped at creation, from the
  SAME `WorkingDayCalculator` the term is counted on (D-3), and never
  recomputed on read. An intake channel that declares `receivedAt` wins
  over the moment the listener ran: a form posted at 23:58 and processed at
  00:02 arrived on the earlier day. The working window is administered
  (`working_hours_start` / `working_hours_end`), and a window with no
  working moment in it falls back rather than pushing every request to the
  next day for ever.
  - `tests/Unit/Service/IntakeTermStartTest.php`
  - `@spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md`
- [x] 2.1 The case page's terms panel names the arrival, the start, the
  flag and the deadline together, which is where a handler answering the
  phone call reads them.
  - `src/manifest.json`, `tests/vitest/intakeConfirmation.spec.js`

  **Amended while building.** The proposal put the four facts in the
  confirmation shown immediately after submitting. A citizen submits in the
  PORTAL, which is portaliq's surface, and dossiq's own success message is
  `successMessage`, a pre-translated toast with no token interpolation in
  the manifest schema: putting dates in it is not possible without
  inventing a grammar nextcloud-vue does not have. dossiq's half is
  therefore the stamp, the mail, and the case surface; the portal
  confirmation reads the same two fields off the case and belongs with
  portaliq's intake form.
- [x] 2.2 The ontvangstbevestiging names the reference, the arrival, the
  start and the deadline, and adds the one sentence about the first working
  day ONLY when the two moments differ (D-4). It reads the stamp off the
  case and computes nothing: a second computation in the renderer would
  drift the day a holiday is administered.
  - `lib/Service/TermijnNotificationService.php`,
    `lib/Service/AcknowledgementService.php`
  - `tests/Unit/Service/IntakeConfirmationSaysTheStartTest.php`
- [x] 2.3 Dutch and English strings for both surfaces (ADR-025), rebuilt
  into the browser catalogues.
- [x] 3.1 `tests/e2e/intake-says-when-the-term-starts.spec.ts`, filing
  inside and outside the working window, a stamp that does not move on a
  later write, and a confirmation without the sentence where none is
  needed; `openspec validate intake-says-when-the-term-starts --strict`.
