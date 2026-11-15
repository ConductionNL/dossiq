# Tasks: letters-print-every-date-the-same-way

### Task 1: One date format in every letter
- **spec_ref**: `openspec/changes/letters-print-every-date-the-same-way/specs/burger-notifications/spec.md#requirement-every-date-in-a-letter-reads-the-same-way-req-lpd-001`
- **files**: `lib/Service/Termijn/TermLetters.php`, `tests/Unit/Service/Termijn/TermLettersTest.php`, `tests/Unit/Service/AcknowledgementDutyTest.php`, `tests/Unit/Service/IntakeConfirmationTextTest.php`
- **acceptance_criteria**:
  - GIVEN a case received on 2026-06-15 with a term ending on 2026-08-10 WHEN its receipt is written THEN it reads "ontvangen op 15 juni 2026" and "uiterlijk op 10 augustus 2026", and holds no date in yyyy-mm-dd form
  - GIVEN an English case type WHEN a letter is written THEN its months are English
  - GIVEN no date WHEN a letter names one THEN the placeholder "–" stays
- [x] Implement
- [x] Test (through `AcknowledgementService::acknowledge()`, the real caller, and `TermLetters::render()`)
