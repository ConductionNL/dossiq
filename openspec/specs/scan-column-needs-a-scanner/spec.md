# scan-column-needs-a-scanner Specification

## Purpose
What dossiq does for scan column needs a scanner. The requirements were first written by the change `scan-column-needs-a-scanner` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-scan-column-needs-a-scanner/`) and cover: The Scan column shows only with a virus scanner.

## Requirements

### Requirement: The Scan column shows only with a virus scanner

The Files tab SHALL declare its Scan result column only while files_antivirus
is enabled. Without it the table and the Columns menu SHALL leave the column out.

#### Scenario: no scanner
- GIVEN files_antivirus is not enabled
- WHEN a user opens a case's Files tab
- THEN there is no Scan column and Columns does not list it

#### Scenario: a scanner
- GIVEN files_antivirus is enabled
- THEN the Scan column shows as before (hidden by default on a review case)
