# Proposal: queue-oldest-case-note

## Why

The team queue's "Wacht het langst" tile (DqTeamwachtrij) says under its number which case that is and how close its
deadline is, in red: "Woo-verzoek, termijn over 4 dagen". Live says "de oudste zaak in de wachtrij" whatever the case.

## What changes

- The queue figures name the oldest waiting case's type (`queueOldestType`) and the days to its deadline
  (`queueOldestDueDays`, negative once passed).
- `GET /api/dashboard/kpis` answers `queueOldestNote`: "{type}, termijn over N dagen", "{type}, termijn vandaag" or
  "{type}, N dagen te laat"; "de oudste zaak in de wachtrij" when the case has no type or no deadline.
- The tile's caption is that note, in the error tone as the board draws it.
