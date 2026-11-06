# burger-notifications Specification (delta)

**Status**: planned
**Scope**: dossiq
**OpenSpec changes**:
- [letters-print-every-date-the-same-way](../../) (this delta)

## ADDED Requirements

### Requirement: Every date in a letter reads the same way (REQ-LPD-001)

Every date dossiq prints in a letter to an applicant (receipt, extension, notice of default, request for missing information and its reminder) SHALL be written as the day, the month in full and the year ("10 augustus 2026"), in the letter's language, whether the store holds a date or a moment. A letter without a date SHALL keep its placeholder.

#### Scenario: A resident reads her receipt
- GIVEN Sanne's request was received on 15 June 2026 and must be decided by 10 August 2026
- WHEN she opens the Ontvangstbevestiging in her messages
- THEN it reads "ontvangen op 15 juni 2026 en de beslistermijn start op 15 juni 2026" and "uiterlijk op 10 augustus 2026"
- @e2e exclude pinned by `AcknowledgementDutyTest::testTheAcknowledgementNamesTheStartAndTheDueDate` and `TermLettersTest::testEveryDateInALetterReadsTheSameWay`
