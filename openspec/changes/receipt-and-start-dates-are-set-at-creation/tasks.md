# Tasks: receipt-and-start-dates-are-set-at-creation

- [x] 1.1 `IntakeTermStart::arrivalOf()` reads the case's `receiptDate`
  answer after `receivedAt`; a day-only receipt compares the flag by day
  (D-2, D-5). `tests/Unit/Service/IntakeTermStartTest.php`.
- [x] 1.2 `CaseReceiptDates` computes `startDate` and the `receiptDate`
  answer, each only when empty (D-3).
- [x] 1.3 `IntakeTermStartListener` writes the stamp and the dates in one
  write; dates are written when the calendar does not answer (D-1, D-4).
  `tests/Unit/Listener/IntakeTermStartListenerTest.php`, through the real
  listener.
- [x] 1.4 `DeadlineCaseCreatedListener` starts the term from an imported
  `receiptDate` (through `arrivalOf()`).
- [x] 2.1 `ChannelIntake` and `DsoIntakeCasePayload` write `receivedAt`
  (D-6, REQ-TERM-043).
- [x] 3.1 Woo `receiptDate` definition: `isRequired` false in
  `register.d/81-woo-verzoek.json` and `templates/woo-verzoek.json`.
- [x] 3.2 Frontend lane: drop `startDate` from the five create dialogs'
  `includeFields` in `src/manifest.json`, from `src/utils/caseCreateForm.js`,
  and from `tests/e2e/case-create-form.spec.ts`'s expected fields. Done: the
  create form (Cases `includeFields`) and `caseCreateForm.js` carry no
  `startDate`, and the e2e spec asserts it is absent. The case page's EDIT
  form keeps it, so a start date can still be corrected.
- [x] 3.3 Design system: drop Startdatum and Ontvangen op from the
  DqNieuweZaak board. Done in ConductionNL/design-system#209.
- [x] 4.1 `IntakeTermStartListener` sets the stamp and the dates on
  `ObjectCreatingEvent`, registered above OpenRegister's calculations
  (D-7). `tests/Unit/Listener/IntakeTermStartListenerTest.php`,
  `tests/Unit/AppInfo/ReceiptDatesRegistrationTest.php`.
- [x] 4.2 The created-event fallback writes only the fields it adds, through
  the partial write (D-7).
