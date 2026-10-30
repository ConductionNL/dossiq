# Proposal: seed-status-phases-backfill

## Why

The case-flow seed creates each status once. "Omgevingsvergunning kleine bouwactiviteit" was seeded before statuses
carried a phase, so its statuses kept none and DqProcesanalyse drew "In behandeling" and "Bij commissie" as phase columns
of their own. The board puts "Bij commissie" under Advies, where the seed said Besluit.

## What changes

- The seed repair step gives a stored status of the seeded case type the seed's phase when it has none. A phase that is
  set is left alone.
- `case_flow_seed_data.json`: "Bij commissie" is in the Advies phase.
