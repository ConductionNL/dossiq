## ADDED Requirements

### Requirement: The status dialog shows what changes
The bulk status dialog SHALL offer only the transitions every selected case offers, and SHALL list each selected case with its current status, the new status and its term before the act runs. It SHALL NOT show the table for a selection wider than the page.

#### Scenario: Two cases with different offers
- GIVEN two selected cases, one offering A and B, the other offering B
- WHEN the dialog opens
- THEN the picker offers B and the table lists both cases

### Requirement: The reader confirms after the rehearsal
The primary button SHALL rehearse the act and SHALL write nothing. The dialog SHALL show the rehearsal in the progress panel, and the act SHALL commit only when the reader confirms there, also when every case is ready.

#### Scenario: Every case is ready
- GIVEN a selection in which every case is ready
- WHEN the reader presses the primary button
- THEN the rehearsal is shown, nothing is committed, and the panel offers the commit

#### Scenario: A case would be skipped
- GIVEN a rehearsal in which one case would be skipped
- WHEN the rehearsal is shown
- THEN the panel names the skip before the reader confirms

### Requirement: The result names every changed case
A job that finished with every case changed SHALL end on a panel with the count and one row per case. A job with any other outcome SHALL keep the progress panel with its counts and retry.

#### Scenario: Every case changed
- GIVEN a completed job with all cases applied
- WHEN the job finishes
- THEN the dialog shows "2 of 2 cases changed" and a row per case
