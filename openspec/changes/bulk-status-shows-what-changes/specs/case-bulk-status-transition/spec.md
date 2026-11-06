## ADDED Requirements

### Requirement: The status dialog shows what changes
The bulk status dialog SHALL offer only the transitions every selected case offers, and SHALL list each selected case with its current status, the new status and its term before the act runs. It SHALL NOT show the table for a selection wider than the page.

#### Scenario: Two cases with different offers
- GIVEN two selected cases, one offering A and B, the other offering B
- WHEN the dialog opens
- THEN the picker offers B and the table lists both cases

### Requirement: A clean rehearsal runs at once
The primary button SHALL rehearse the act first. The dialog SHALL commit without a second step only when the rehearsal covers exactly the cases in the table and every one of them is ready. In every other case it SHALL stop at the progress panel and write nothing.

#### Scenario: Every case is ready
- GIVEN a rehearsal of the two cases in the table with both ready
- WHEN the reader presses Apply
- THEN the job is committed

#### Scenario: A case would be skipped
- GIVEN a rehearsal in which one case would be skipped
- WHEN the reader presses Apply
- THEN nothing is committed and the progress panel shows the skip

#### Scenario: A selection wider than the page
- GIVEN a selection widened to the whole result
- WHEN the reader rehearses
- THEN nothing is committed

### Requirement: The result names every changed case
A job that finished with every case changed SHALL end on a panel with the count and one row per case. A job with any other outcome SHALL keep the progress panel with its counts and retry.

#### Scenario: Every case changed
- GIVEN a completed job with all cases applied
- WHEN the job finishes
- THEN the dialog shows "2 of 2 cases changed" and a row per case
