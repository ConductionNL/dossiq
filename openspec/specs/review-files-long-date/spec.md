# review-files-long-date Specification

## Purpose
What dossiq does for review files long date. The requirements were first written by the change `review-files-long-date` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-review-files-long-date/`) and cover: A review case dates its files in full.

## Requirements

### Requirement: A review case dates its files in full

On a case that reviews its documents the second line under a file SHALL end with the long date including the year.

#### Scenario: a PDF on a Woo case
- GIVEN a PDF modified 14 January 2026 on a review case
- THEN its second line reads "PDF · <size> · 14 januari 2026"
