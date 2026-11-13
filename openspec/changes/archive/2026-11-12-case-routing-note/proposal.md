# Case routing note

## Why

Board DqTeamwachtrij draws a line on a queue row saying why the case stands there: a refused move to another team, or a submission that opened several cases and missed a destination. Today only the person who acted sees the refusal, and the fan-out result is gone after the answer. Ruben accepted one routing note on the case (6 Nov).

## What changes

- The case gets one `routingNote` object: the facts of the last routing event, not a sentence, so each reader gets it in their own language.
- A refused handover and a fan-out that opened siblings or missed a destination write it.
- Picking the case up clears it.
- The team queue draws it as the row's note line.

## Impact

Register: `case` schema 1.54.0, one optional property. Library: CnObjectRow `noteTemplate` and `noteToneField`. No new route.
