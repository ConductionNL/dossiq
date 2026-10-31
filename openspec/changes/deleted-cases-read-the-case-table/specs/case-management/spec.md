# case-management Delta: deleted-cases-read-the-case-table

**Status**: draft

## MODIFIED Requirements

### Requirement: The deleted lens MUST list every deleted case the caller may see

The deleted lens SHALL read the soft-deleted rows of the case schema only, keep the rows the caller may see, and page after that, so a deleted case is listed however many other objects were deleted on the instance, and the total equals the number of rows the pages hold.

#### Scenario: Other apps deleted more objects than one page holds

- **GIVEN** 60 soft-deleted objects of other schemas and 1 soft-deleted case
- **WHEN** an administrator opens Deleted cases
- **THEN** the case is listed
- **AND** the total is 1

#### Scenario: A handler searches the deleted cases

- **GIVEN** two deleted cases, ZAAK-2026-0009 and ZAAK-2026-0010
- **WHEN** the handler searches for "0010"
- **THEN** only ZAAK-2026-0010 is listed
