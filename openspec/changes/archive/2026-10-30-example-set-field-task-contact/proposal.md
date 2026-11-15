# Proposal: example-set-field-task-contact

## Why

Three boards draw example data the Zuiddrecht set did not hold:
- DqInspectie: the inspections an inspector has today (one done, one under way, two planned). Pieter had none, so the Today frame was empty.
- DqTaak: a task with a Checklist card ("2 van 4 klaar") and priority Hoog. No example task had a checklist.
- DqZaakContact: a conversation with the resident over more than one channel. Henk Bakker's parking permit case had one call.

## What changes

- Four `fieldInspection` records for Pieter, scheduled today, on four of the set's own cases.
- The task "Hoorzitting inplannen" for Pieter, in execution, priority high, with four checklist items of which two are ticked. This needs OpenRegister's example-set `checklist` task field (openregister#4758).
- Three more contact moments on Henk's case: a call back, a desk visit and a status question.
