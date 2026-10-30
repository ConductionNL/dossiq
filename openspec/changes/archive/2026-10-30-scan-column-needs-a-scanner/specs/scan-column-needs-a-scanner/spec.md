## ADDED Requirements

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
