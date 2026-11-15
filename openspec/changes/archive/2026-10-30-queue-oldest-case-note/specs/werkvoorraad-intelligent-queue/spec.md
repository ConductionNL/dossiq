# werkvoorraad-intelligent-queue Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [queue-oldest-case-note](../../)

## ADDED Requirements

### Requirement: The waiting-longest tile names the oldest case's type and deadline

The KPI answer SHALL carry `queueOldestNote`: the type of the case without a handler that has waited longest and the
days to its deadline ("Woo-verzoek, termijn over 4 dagen"; "termijn vandaag"; "N dagen te laat" once passed), and "de
oudste zaak in de wachtrij" when that case has no type or no deadline. The team queue's "Wacht het langst" tile SHALL
show it as its caption in the error tone.

#### Scenario: DqTeamwachtrij

- GIVEN the oldest unassigned case is a Woo-verzoek waiting 6 days with its deadline in 4 days
- THEN the tile reads "6 dagen" over "Woo-verzoek, termijn over 4 dagen" in red
