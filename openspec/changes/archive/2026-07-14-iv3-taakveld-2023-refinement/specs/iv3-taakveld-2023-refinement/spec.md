# iv3-taakveld-2023-refinement Specification

## Purpose

Ship the official 2023 BBV/Iv3 Wmo/Jeugd taakveld-6 refinement (18 new codes replacing 4 pre-2023
catch-all codes), keep the pre-2023 codes resolvable for backward compatibility, and aggregate
old- and new-code-classified cases into the same quarterly report bucket so trend reporting stays
continuous across the transition.

## ADDED Requirements

### Requirement: The shipped taakveld list SHALL include the 18 official 2023 Wmo/Jeugd refinement codes and SHALL flag their 4 pre-2023 parent codes as deprecated

`lib/Settings/iv3_taakvelden.json` SHALL contain, under category `6`, the 4 pre-2023 codes
(`6.71`, `6.72`, `6.81`, `6.82`) each marked `deprecated: true`, and the 18 official 2023-refinement
codes (`6.71a-d`, `6.72a-d`, `6.73a-c`, `6.74a-c`, `6.81a-b`, `6.82a-b`), each carrying an
`aggregatesUnder` value equal to its pre-2023 parent code. `Iv3TaakveldList::isValidCode()` and
`::labelFor()` SHALL resolve every one of these 22 codes (4 deprecated + 18 refinement).

#### Scenario: A deprecated pre-2023 code remains fully resolvable

- **GIVEN** the shipped taakveld list
- **WHEN** `Iv3TaakveldList::isValidCode('6.72')` and `::labelFor('6.72')` are called
- **THEN** `isValidCode()` SHALL return `true`
- **AND** `labelFor()` SHALL return `'Maatwerkdienstverlening 18-'`
- **AND** `Iv3TaakveldList::isDeprecated('6.72')` SHALL return `true`

#### Scenario: A 2023-refinement code is valid and not deprecated

- **GIVEN** the shipped taakveld list
- **WHEN** `Iv3TaakveldList::isValidCode('6.72a')`, `::labelFor('6.72a')`, and
  `::isDeprecated('6.72a')` are called
- **THEN** `isValidCode()` SHALL return `true`
- **AND** `labelFor()` SHALL return `'Jeugdhulp begeleiding'`
- **AND** `isDeprecated()` SHALL return `false`

#### Scenario: An unaffected code is never deprecated

- **GIVEN** a taakveld code outside the 2023 Wmo/Jeugd refinement (e.g. `8.1`)
- **WHEN** `Iv3TaakveldList::isDeprecated('8.1')` is called
- **THEN** it SHALL return `false`

---

### Requirement: Iv3TaakveldList::aggregationKeyFor() SHALL resolve every 2023-refinement code to its single pre-2023 parent code, and SHALL pass through every other code unchanged

`Iv3TaakveldList::aggregationKeyFor(string $code): string` SHALL return the taakveld's
`aggregatesUnder` value when set, else the code itself. This SHALL hold for every one of the 18
refinement codes (resolving to one of `6.71`/`6.72`/`6.81`/`6.82`), for the 4 deprecated parent
codes themselves (resolving to themselves), for any code outside the refinement, and for an
unrecognised code.

#### Scenario: Refinement codes under the split-into-four parent (6.71) aggregate under it

- **GIVEN** codes `6.71a`, `6.71b`, `6.71c`, `6.71d`
- **WHEN** `aggregationKeyFor()` is called on each
- **THEN** every call SHALL return `'6.71'`

#### Scenario: All ten refinement codes under the split-into-ten parent (6.72) aggregate under it

- **GIVEN** codes `6.72a`, `6.72b`, `6.72c`, `6.72d`, `6.73a`, `6.73b`, `6.73c`, `6.74a`, `6.74b`,
  `6.74c`
- **WHEN** `aggregationKeyFor()` is called on each
- **THEN** every call SHALL return `'6.72'`

#### Scenario: A deprecated parent code aggregates under itself

- **GIVEN** code `6.72`
- **WHEN** `aggregationKeyFor('6.72')` is called
- **THEN** it SHALL return `'6.72'`

#### Scenario: An unaffected or unknown code aggregates under itself

- **GIVEN** code `8.1` (unaffected) or `99.9` (unknown)
- **WHEN** `aggregationKeyFor()` is called on each
- **THEN** each call SHALL return the input code unchanged

---

### Requirement: Iv3ReportService quarterly aggregation SHALL bucket cases by aggregation key, so old- and new-code-classified cases land in the same bucket

`Iv3ReportService::accumulateBuckets()` SHALL resolve each case's `caseType.iv3Taakveld` value
through `Iv3TaakveldList::aggregationKeyFor()` before using it as the report bucket key.

#### Scenario: A case tagged with the deprecated parent and a case tagged with a refinement successor aggregate together

- **GIVEN** case A whose case type carries `iv3Taakveld = '6.72'`
- **AND** case B whose case type carries `iv3Taakveld = '6.72a'`
- **AND** both have qualifying cost activity in the same quarter
- **WHEN** `generateQuarterlyReport()` is called for that quarter
- **THEN** the report's `perTaakveld` SHALL contain exactly one entry keyed `'6.72'`
- **AND** that entry's `caseCount` SHALL be `2`
- **AND** its `totalCosts` SHALL be the sum of both cases' handling costs
- **AND** its `taakveldLabel` SHALL be `'Maatwerkdienstverlening 18-'` (the parent's label)
- **AND** `perTaakveld` SHALL NOT contain a `'6.72a'` key

#### Scenario: Two different refinement successors of the same parent aggregate together

- **GIVEN** case A whose case type carries `iv3Taakveld = '6.73a'`
- **AND** case B whose case type carries `iv3Taakveld = '6.74b'`
- **AND** both have qualifying cost activity in the same quarter
- **WHEN** `generateQuarterlyReport()` is called for that quarter
- **THEN** the report's `perTaakveld['6.72']` entry SHALL have `caseCount = 2`
